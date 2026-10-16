<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Staff bookings list. Enquiries and public events never appear here.
 * Read only. Dates come from the stay or event row.
 */
class Booking
{
    // Open through check-out or event day. No dates yet uses 1000-01-01, so it counts as past.
    private const IS_ACTIVE = "(b.b_status IN ('quoted', 'pending_payment', 'confirmed')
                               AND COALESCE(sb.sb_check_out, eb.eb_event_date, '1000-01-01') >= ?)";

    private const COLUMNS = "b.b_id, b.b_reference, bt.bt_name, b.b_status, b.b_total_price, u.u_name, u.u_surname,
        COALESCE(sb.sb_check_in, eb.eb_event_date) AS start_date, sb.sb_check_out AS end_date, eb.eb_occasion,
        (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR ' + ')
         FROM stay_space ss JOIN space sp ON sp.sp_id = ss.ss_sp_id WHERE ss.ss_sb_id = sb.sb_id) AS stay_spaces,
        (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR ' + ')
         FROM event_space es JOIN space sp ON sp.sp_id = es.es_sp_id WHERE es.es_eb_id = eb.eb_id) AS event_spaces";

    // A stay and an event are different rows, so one of the two date joins is always NULL.
    private const FROM = "FROM booking b
                          JOIN booking_type bt ON bt.bt_id = b.b_bt_id
                          JOIN app_user u ON u.u_id = b.b_u_id
                          LEFT JOIN stay_booking sb ON sb.sb_b_id = b.b_id
                          LEFT JOIN event_booking eb ON eb.eb_b_id = b.b_id";

    // Enquiries and public events are excluded. Public events are bookings owned by staff.
    // A stay has no event row, so NULL visibility counts as not public.
    private const ONLY_BOOKINGS = "bt.bt_name IN ('stay', 'event') AND b.b_status <> 'enquiry'
                                   AND COALESCE(eb.eb_visibility, 'private') <> 'public'";

    // $filters are view, status, type, and q, already checked.
    public static function staffPage(array $filters, string $today, int $perPage, int $page): array
    {
        $where = self::whereForFilters($filters, $today);
        $from = self::FROM . ' WHERE ' . $where['sql'];

        $statement = Db::pdo()->prepare('SELECT COUNT(*) ' . $from);
        $statement->execute($where['params']);
        $total = (int) $statement->fetchColumn();

        // Oldest first, except Past, which is newest first.
        $direction = 'ASC';
        if ($filters['q'] === '' && $filters['view'] === 'past') {
            $direction = 'DESC';
        }
        // LIMIT and OFFSET are ints we cast ourselves, so they are safe in the SQL.
        $offset = ($page - 1) * $perPage;
        $statement = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' ' . $from . "
             ORDER BY start_date $direction, b.b_reference $direction
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $statement->execute($where['params']);

        $bookings = [];
        foreach ($statement->fetchAll() as $row) {
            $bookings[] = self::toRow($row);
        }

        return ['bookings' => $bookings, 'total' => $total];
    }

    // $type is all, stay, or event.
    public static function staffCounts(string $type, string $today): array
    {
        $sql = "SELECT
                    SUM(" . self::IS_ACTIVE . ") AS active,
                    SUM(" . self::IS_ACTIVE . " AND b.b_status = 'confirmed') AS confirmed,
                    SUM(" . self::IS_ACTIVE . " AND b.b_status = 'pending_payment') AS pending_payment,
                    SUM(" . self::IS_ACTIVE . " AND b.b_status = 'quoted') AS quoted,
                    SUM(NOT " . self::IS_ACTIVE . ") AS past
                " . self::FROM . "
                WHERE " . self::ONLY_BOOKINGS;
        // IS_ACTIVE is in the SQL five times, so today is bound five times.
        $params = [$today, $today, $today, $today, $today];
        if ($type !== 'all') {
            $sql = $sql . ' AND bt.bt_name = ?';
            $params[] = $type;
        }

        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        // SUM is null when there are no rows; (int) null is 0.
        return [
            'active' => (int) $row['active'],
            'confirmed' => (int) $row['confirmed'],
            'pending_payment' => (int) $row['pending_payment'],
            'quoted' => (int) $row['quoted'],
            'past' => (int) $row['past'],
        ];
    }

    // null when the reference is an enquiry or a public event.
    public static function findForStaff(string $reference): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ",
                    u.u_email, u.u_phone, b.b_subtotal, b.b_discount_amount, b.b_hold_expires_at,
                    sb.sb_guests, eb.eb_start_time, eb.eb_end_time, eb.eb_guest_count
             " . self::FROM . "
             WHERE b.b_reference = ? AND " . self::ONLY_BOOKINGS
        );
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        // Stays count their guests on the stay, events on the event
        $guests = $row['sb_guests'];
        $hours = null;
        if ($row['bt_name'] === 'event') {
            $guests = $row['eb_guest_count'];
            // No event times yet: leave hours empty.
            if ($row['eb_start_time'] !== null && $row['eb_end_time'] !== null) {
                $hours = substr($row['eb_start_time'], 0, 5) . ' – ' . substr($row['eb_end_time'], 0, 5);
            }
        }
        if ($guests !== null) {
            $guests = (int) $guests;
        }

        $booking = self::toRow($row);
        $booking['id'] = (int) $row['b_id'];
        $booking['email'] = $row['u_email'];
        $booking['phone'] = $row['u_phone'];
        $booking['guests'] = $guests;
        $booking['hours'] = $hours;
        $booking['subtotal'] = (float) $row['b_subtotal'];
        $booking['discount'] = (float) $row['b_discount_amount'];
        $booking['holdExpires'] = $row['b_hold_expires_at'];

        return $booking;
    }

    // While q is set, view and status are ignored.
    private static function whereForFilters(array $filters, string $today): array
    {
        $conditions = [self::ONLY_BOOKINGS];
        $params = [];

        if ($filters['type'] !== 'all') {
            $conditions[] = 'bt.bt_name = ?';
            $params[] = $filters['type'];
        }

        if ($filters['q'] !== '') {
            // % and _ are LIKE wildcards, so escape them.
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $conditions[] = "(b.b_reference LIKE ? OR CONCAT(u.u_name, ' ', u.u_surname) LIKE ?
                              OR CONCAT(LEFT(u.u_name, 1), '. ', u.u_surname) LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        } else {
            if ($filters['view'] === 'active') {
                $conditions[] = self::IS_ACTIVE;
            } else {
                $conditions[] = 'NOT ' . self::IS_ACTIVE;
            }
            $params[] = $today;

            if ($filters['status'] !== '') {
                $conditions[] = 'b.b_status = ?';
                $params[] = $filters['status'];
            }
        }

        return ['sql' => implode(' AND ', $conditions), 'params' => $params];
    }

    private static function toRow(array $row): array
    {
        // A stay lists its spaces. An event is "occasion · spaces".
        $description = (string) $row['stay_spaces'];
        if ($row['bt_name'] === 'event') {
            $description = (string) $row['event_spaces'];
            if ($row['eb_occasion'] !== null && $row['eb_occasion'] !== '') {
                $description = $row['eb_occasion'] . ' · ' . $description;
            }
        }

        return [
            'reference' => $row['b_reference'],
            'type' => $row['bt_name'],
            'status' => $row['b_status'],
            'guest' => mb_substr($row['u_name'], 0, 1) . '. ' . $row['u_surname'],
            'fullName' => $row['u_name'] . ' ' . $row['u_surname'],
            'description' => $description,
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'total' => (float) $row['b_total_price'],
        ];
    }
}
