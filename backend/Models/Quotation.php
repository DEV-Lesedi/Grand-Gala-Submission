<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Quotation rows, and the price frozen when the guest accepts.
 * Status changes go through BookingLifecycle. Lines are in QuotationItem.
 */
class Quotation
{
    // version counts this booking's quotations up to and including this one.
    private const COLUMNS = 'q.q_id, q.q_b_id, q.q_reference, q.q_amount, q.q_breakdown, q.q_status, q.q_valid_until,
                             q.q_event_date, q.q_start_time, q.q_end_time, q.q_date_created, b.b_reference, b.b_u_id, b.b_status, b.b_hold_expires_at, u.u_email,
                             (SELECT COUNT(*) FROM quotation older WHERE older.q_b_id = q.q_b_id AND older.q_id <= q.q_id) AS version
                             FROM quotation q
                             JOIN booking b ON b.b_id = q.q_b_id
                             JOIN app_user u ON u.u_id = b.b_u_id';

    public static function find(string $reference): ?array
    {
        $statement = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' WHERE q.q_reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toQuotation($row);
    }

    // $lock holds these rows until the transaction ends.
    public static function forBooking(int $bookingId, bool $lock): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' WHERE q.q_b_id = ? ORDER BY q.q_id DESC';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$bookingId]);

        $quotations = [];
        foreach ($statement->fetchAll() as $row) {
            $quotations[] = self::toQuotation($row);
        }

        return $quotations;
    }

    private const LATEST = 'NOT EXISTS (SELECT 1 FROM quotation newer WHERE newer.q_b_id = q.q_b_id AND newer.q_id > q.q_id)';

    // Omits a declined quotation whose booking is cancelled.
    // A decline that returned the booking to enquiry stays, so the next version can be sent.
    private const NOT_ENDED = "NOT (q.q_status = 'declined'
        AND EXISTS (SELECT 1 FROM booking ended WHERE ended.b_id = q.q_b_id AND ended.b_status = 'cancelled'))";

    // Latest per booking. Blank status means all. $today marks sent rows past valid-until.
    public static function staffPage(string $status, string $today, int $perPage, int $page): array
    {
        // Sent rows past valid-until show as expired before the nightly job writes it.
        $where = self::LATEST . ' AND ' . self::NOT_ENDED;
        $parameters = [];
        if ($status === 'sent') {
            $where = $where . " AND q.q_status = 'sent' AND q.q_valid_until >= ?";
            $parameters[] = $today;
        } elseif ($status === 'expired') {
            $where = $where . " AND (q.q_status = 'expired' OR (q.q_status = 'sent' AND q.q_valid_until < ?))";
            $parameters[] = $today;
        } elseif ($status !== '') {
            $where = $where . ' AND q.q_status = ?';
            $parameters[] = $status;
        }

        $statement = Db::pdo()->prepare('SELECT COUNT(*) FROM quotation q WHERE ' . $where);
        $statement->execute($parameters);
        $total = (int) $statement->fetchColumn();

        // LIMIT and OFFSET are ints we cast ourselves, so they are safe in the SQL.
        $offset = ($page - 1) * $perPage;
        $statement = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' WHERE ' . $where . ' ORDER BY q.q_id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $statement->execute($parameters);

        $quotations = [];
        foreach ($statement->fetchAll() as $row) {
            $quotations[] = self::toQuotation($row);
        }

        return ['quotations' => $quotations, 'total' => $total];
    }

    // Chip counts for the latest version, with the same expiry rule as staffPage().
    public static function staffCounts(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT COUNT(*) AS all_count,
                    SUM(q.q_status = 'sent' AND q.q_valid_until >= ?) AS sent_count,
                    SUM(q.q_status = 'accepted') AS accepted_count,
                    SUM(q.q_status = 'expired' OR (q.q_status = 'sent' AND q.q_valid_until < ?)) AS expired_count,
                    SUM(q.q_status = 'declined') AS declined_count
             FROM quotation q
             WHERE " . self::LATEST . ' AND ' . self::NOT_ENDED
        );
        $statement->execute([$today, $today]);
        $row = $statement->fetch();

        // SUM() is NULL when there are no rows; (int) turns that into 0.
        return [
            'all' => (int) $row['all_count'],
            'sent' => (int) $row['sent_count'],
            'accepted' => (int) $row['accepted_count'],
            'expired' => (int) $row['expired_count'],
            'declined' => (int) $row['declined_count'],
        ];
    }

    // Pass a guest id to limit this to that guest's bookings.
    public static function latestOfEachBooking(?int $userId): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' WHERE ' . self::LATEST;
        $parameters = [];
        if ($userId !== null) {
            $sql = $sql . ' AND b.b_u_id = ?';
            $parameters[] = $userId;
        }
        $statement = Db::pdo()->prepare($sql . ' ORDER BY q.q_id DESC');
        $statement->execute($parameters);

        $quotations = [];
        foreach ($statement->fetchAll() as $row) {
            $quotations[] = self::toQuotation($row);
        }

        return $quotations;
    }

    // Locked until the transaction ends, so two callers cannot change one quotation at once.
    public static function lockBooking(string $reference): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.b_id, b.b_reference, bt.bt_name, b.b_status, b.b_u_id, b.b_hold_expires_at, b.b_total_price
             FROM booking b
             JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             WHERE b.b_reference = ?
             FOR UPDATE'
        );
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['b_id'],
            'reference' => $row['b_reference'],
            'type' => $row['bt_name'],
            'status' => $row['b_status'],
            'userId' => (int) $row['b_u_id'],
            'holdExpires' => $row['b_hold_expires_at'],
            'total' => (float) $row['b_total_price'],
        ];
    }

    public static function guest(string $reference): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.b_id, b.b_u_id, u.u_name, u.u_surname, u.u_email
             FROM quotation q
             JOIN booking b ON b.b_id = q.q_b_id
             JOIN app_user u ON u.u_id = b.b_u_id
             WHERE q.q_reference = ?'
        );
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'bookingId' => (int) $row['b_id'],
            'userId' => (int) $row['b_u_id'],
            'name' => $row['u_name'],
            'surname' => $row['u_surname'],
            'email' => $row['u_email'],
        ];
    }

    public static function bookingId(string $reference): ?int
    {
        $statement = Db::pdo()->prepare('SELECT b_id FROM booking WHERE b_reference = ?');
        $statement->execute([$reference]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    // Notes are plain text. Spaces and rooms are saved separately by QuotationPlan.
    public static function insert(int $bookingId, string $reference, float $total, string $notes, string $validUntil, array $plan, string $now): int
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO quotation (q_b_id, q_reference, q_amount, q_breakdown, q_status, q_valid_until, q_event_date,
                                    q_start_time, q_end_time, q_date_created)
             VALUES (?, ?, ?, ?, 'sent', ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $bookingId, $reference, $total, $notes, $validUntil, $plan['date'], $plan['startTime'], $plan['endTime'], $now,
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    public static function supersedeSent(int $bookingId): void
    {
        $statement = Db::pdo()->prepare("UPDATE quotation SET q_status = 'superseded' WHERE q_b_id = ? AND q_status = 'sent'");
        $statement->execute([$bookingId]);
    }

    // Sent quotations past valid-until, oldest first. Used by the scheduled job.
    public static function overdueSent(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT q.q_reference, b.b_reference FROM quotation q JOIN booking b ON b.b_id = q.q_b_id
             WHERE q.q_status = 'sent' AND q.q_valid_until < ? ORDER BY q.q_id"
        );
        $statement->execute([$today]);

        $overdue = [];
        foreach ($statement->fetchAll() as $row) {
            $overdue[] = ['reference' => $row['q_reference'], 'bookingReference' => $row['b_reference']];
        }

        return $overdue;
    }

    // A sent quotation counts as declined when the guest cancels the booking.
    public static function declineSent(int $bookingId): void
    {
        $statement = Db::pdo()->prepare("UPDATE quotation SET q_status = 'declined' WHERE q_b_id = ? AND q_status = 'sent'");
        $statement->execute([$bookingId]);
    }

    public static function setStatus(int $quotationId, string $status): void
    {
        $statement = Db::pdo()->prepare('UPDATE quotation SET q_status = ? WHERE q_id = ?');
        $statement->execute([$status, $quotationId]);
    }

    // Freeze the accepted price. Status and hold stay with BookingLifecycle.
    public static function freezePrice(int $bookingId, float $subtotal, float $discount, float $total): void
    {
        $statement = Db::pdo()->prepare('UPDATE booking SET b_subtotal = ?, b_discount_amount = ?, b_total_price = ? WHERE b_id = ?');
        $statement->execute([$subtotal, $discount, $total, $bookingId]);
    }

    // Change notes are quoted-to-enquiry history rows, newest first.
    public static function changeRequests(int $bookingId): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT bsh_reason, bsh_date_created FROM booking_status_history
             WHERE bsh_b_id = ? AND bsh_from_status = 'quoted' AND bsh_to_status = 'enquiry'
             ORDER BY bsh_id DESC"
        );
        $statement->execute([$bookingId]);

        $requests = [];
        foreach ($statement->fetchAll() as $row) {
            $requests[] = ['note' => $row['bsh_reason'], 'at' => self::dateTime($row['bsh_date_created'])];
        }

        return $requests;
    }

    private static function toQuotation(array $row): array
    {
        return [
            'id' => (int) $row['q_id'],
            'bookingId' => (int) $row['q_b_id'],
            'reference' => $row['q_reference'],
            'bookingReference' => $row['b_reference'],
            'userId' => (int) $row['b_u_id'],
            'guestEmail' => $row['u_email'],
            'version' => (int) $row['version'],
            'status' => $row['q_status'],
            'total' => (float) $row['q_amount'],
            'notes' => (string) $row['q_breakdown'],
            'validUntil' => $row['q_valid_until'],
            // null on quotations saved before migration 006.
            'eventDate' => $row['q_event_date'],
            'startTime' => self::shortTime($row['q_start_time']),
            'endTime' => self::shortTime($row['q_end_time']),
            'sentDate' => substr($row['q_date_created'], 0, 10),
            'bookingStatus' => $row['b_status'],
            'holdExpires' => self::dateTime($row['b_hold_expires_at']),
        ];
    }

    // '2026-09-07 12:00:00' becomes '2026-09-07T12:00'. null stays null.
    private static function dateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(' ', 'T', substr($value, 0, 16));
    }

    // '14:00:00' from the database becomes '14:00'. null stays null.
    private static function shortTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return substr($time, 0, 5);
    }
}
