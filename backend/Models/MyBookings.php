<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Rows for My bookings, including ticket orders that have no booking row.
 * Cancel uses only lockForCancel() and bookingIdForUser().
 */
class MyBookings
{
    // enquiry_occasion is the form text; an event has no event_booking until it is accepted.
    public static function bookingsOf(int $userId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.b_id, b.b_reference, bt.bt_name, b.b_status, b.b_total_price, eb.eb_id,
                    COALESCE(sb.sb_check_in, eb.eb_event_date) AS start_date, sb.sb_check_out AS end_date,
                    sb.sb_guests, eb.eb_guest_count, eb.eb_occasion,
                    (SELECT ee.ee_occasion FROM event_enquiry ee WHERE ee.ee_b_id = b.b_id LIMIT 1) AS enquiry_occasion,
                    (SELECT ss.ss_sp_id FROM stay_space ss WHERE ss.ss_sb_id = sb.sb_id ORDER BY ss.ss_sp_id LIMIT 1) AS stay_photo_space_id,
                    (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR \' + \')
                     FROM stay_space ss JOIN space sp ON sp.sp_id = ss.ss_sp_id WHERE ss.ss_sb_id = sb.sb_id) AS stay_spaces,
                    (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR \' + \')
                     FROM event_space es JOIN space sp ON sp.sp_id = es.es_sp_id WHERE es.es_eb_id = eb.eb_id) AS event_spaces
             FROM booking b
             JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             LEFT JOIN stay_booking sb ON sb.sb_b_id = b.b_id
             LEFT JOIN event_booking eb ON eb.eb_b_id = b.b_id
             WHERE b.b_u_id = ?
             ORDER BY start_date IS NULL, start_date DESC'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    public static function ticketOrdersOf(int $userId): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT to_reference, to_status, to_total_price, to_eb_id,
                    eb.eb_event_date, eb.eb_title, eb.eb_occasion,
                    (SELECT COUNT(*) FROM ticket tk WHERE tk.tk_to_id = ticket_order.to_id) AS ticket_count
             FROM ticket_order
             JOIN event_booking eb ON eb.eb_id = ticket_order.to_eb_id
             WHERE ticket_order.to_u_id = ?
             ORDER BY eb.eb_event_date IS NULL, eb.eb_event_date DESC"
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    // Owner, status, and type, locked until the transaction ends.
    public static function lockForCancel(int $bookingId): ?array
    {
        // Two cancel requests are checked one after the other.
        $statement = Db::pdo()->prepare(
            'SELECT b.b_u_id, b.b_status, bt.bt_name FROM booking b JOIN booking_type bt ON bt.bt_id = b.b_bt_id WHERE b.b_id = ? FOR UPDATE'
        );
        $statement->execute([$bookingId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['userId' => (int) $row['b_u_id'], 'status' => $row['b_status'], 'type' => $row['bt_name']];
    }

    // Any status, so a 404 (not yours) is distinct from a 409 (not cancellable).
    public static function bookingIdForUser(string $reference, int $userId): ?int
    {
        $statement = Db::pdo()->prepare('SELECT b_id FROM booking WHERE b_reference = ? AND b_u_id = ?');
        $statement->execute([$reference, $userId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
