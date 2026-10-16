<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* One row per seat: the code the gate scans, and the price that seat was sold at. */
class Ticket
{
    public static function insert(int $orderId, int $tierId, string $code, float $price): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO ticket (tk_to_id, tk_tt_id, tk_code, tk_price_snapshot) VALUES (?, ?, ?, ?)');
        $statement->execute([$orderId, $tierId, $code, $price]);
    }

    // $lock so two payment notifications both see the newest tickets.
    public static function forOrder(int $orderId, bool $lock = false): array
    {
        $sql = 'SELECT tk.tk_id, tk.tk_code, tk.tk_tt_id, tk.tk_price_snapshot, tk.tk_status, tk.tk_used_at, tt.tt_name
                FROM ticket tk JOIN ticket_type tt ON tt.tt_id = tk.tk_tt_id
                WHERE tk.tk_to_id = ? ORDER BY tk.tk_id';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$orderId]);

        $tickets = [];
        foreach ($statement->fetchAll() as $row) {
            $tickets[] = [
                'id' => (int) $row['tk_id'],
                'code' => $row['tk_code'],
                'tierId' => (int) $row['tk_tt_id'],
                'tier' => $row['tt_name'],
                'price' => (float) $row['tk_price_snapshot'],
                'status' => $row['tk_status'],
                'usedAt' => $row['tk_used_at'],
            ];
        }

        return $tickets;
    }

    public static function forConfirmation(string $code): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT tk.tk_status, tk.tk_used_at, tk.tk_attendee_name, tt.tt_name, o.to_buyer_name, o.to_buyer_surname,
                    eb.eb_title, eb.eb_event_date, eb.eb_start_time
             FROM ticket tk
             JOIN ticket_order o ON o.to_id = tk.tk_to_id
             JOIN ticket_type tt ON tt.tt_id = tk.tk_tt_id
             JOIN event_booking eb ON eb.eb_id = o.to_eb_id
             WHERE tk.tk_code = ?'
        );
        $statement->execute([$code]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'status' => $row['tk_status'],
            'usedAt' => $row['tk_used_at'],
            'attendeeName' => $row['tk_attendee_name'],
            'tier' => $row['tt_name'],
            'buyerName' => $row['to_buyer_name'],
            'buyerSurname' => $row['to_buyer_surname'],
            'eventTitle' => $row['eb_title'],
            'eventDate' => $row['eb_event_date'],
            'eventStartTime' => substr($row['eb_start_time'], 0, 5),
        ];
    }

    // $lock holds the ticket until the transaction ends.
    public static function findByCode(string $code, bool $lock = false): ?array
    {
        $sql = 'SELECT tk.tk_id, tk.tk_code, tk.tk_status, tt.tt_name, o.to_eb_id, o.to_status
                FROM ticket tk
                JOIN ticket_order o ON o.to_id = tk.tk_to_id
                JOIN ticket_type tt ON tt.tt_id = tk.tk_tt_id
                WHERE tk.tk_code = ?';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$code]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['tk_id'],
            'code' => $row['tk_code'],
            'status' => $row['tk_status'],
            'tier' => $row['tt_name'],
            'eventId' => (int) $row['to_eb_id'],
            'orderStatus' => $row['to_status'],
        ];
    }

    // Returns false if it was no longer valid (someone scanned it first).
    public static function markUsed(int $ticketId, string $now): bool
    {
        $statement = Db::pdo()->prepare("UPDATE ticket SET tk_status = 'used', tk_used_at = ? WHERE tk_id = ? AND tk_status = 'valid'");
        $statement->execute([$now, $ticketId]);

        return $statement->rowCount() === 1;
    }

    // Every ticket on the order, for example cancelled on refund.
    public static function setStatusForOrder(int $orderId, string $status): void
    {
        $statement = Db::pdo()->prepare('UPDATE ticket SET tk_status = ? WHERE tk_to_id = ?');
        $statement->execute([$status, $orderId]);
    }

    // One row per seat of a paid order.
    public static function attendeesPage(int $eventId, int $perPage, int $page): array
    {
        $from = "FROM ticket tk
                 JOIN ticket_order o ON o.to_id = tk.tk_to_id
                 JOIN ticket_type tt ON tt.tt_id = tk.tk_tt_id
                 WHERE o.to_eb_id = ? AND o.to_status = 'paid'";

        $statement = Db::pdo()->prepare('SELECT COUNT(*) ' . $from);
        $statement->execute([$eventId]);
        $total = (int) $statement->fetchColumn();

        // LIMIT and OFFSET are ints we cast ourselves, so they are safe in the SQL.
        $offset = ($page - 1) * $perPage;
        $statement = Db::pdo()->prepare(
            'SELECT tk.tk_code, tk.tk_status, tk.tk_attendee_name, tt.tt_name, o.to_reference,
                    o.to_buyer_name, o.to_buyer_surname, o.to_buyer_email ' . $from . '
             ORDER BY tk.tk_id LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $statement->execute([$eventId]);

        $attendees = [];
        foreach ($statement->fetchAll() as $row) {
            $attendees[] = [
                'code' => $row['tk_code'],
                'status' => $row['tk_status'],
                'attendeeName' => $row['tk_attendee_name'],
                'tier' => $row['tt_name'],
                'orderReference' => $row['to_reference'],
                'buyerName' => $row['to_buyer_name'],
                'buyerSurname' => $row['to_buyer_surname'],
                'buyerEmail' => $row['to_buyer_email'],
            ];
        }

        return ['attendees' => $attendees, 'total' => $total];
    }
}
