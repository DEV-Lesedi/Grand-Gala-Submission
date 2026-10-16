<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Read-only queries for the staff dashboard. */
class Dashboard
{
    // Awaiting payment means unpaid and not over yet.
    public static function todaySummary(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT
                SUM(b.b_status IN ('quoted', 'pending_payment', 'confirmed')
                    AND COALESCE(sb.sb_check_in, eb.eb_event_date) = ?) AS arrivals,
                SUM(b.b_status IN ('quoted', 'pending_payment', 'confirmed')
                    AND sb.sb_check_out = ?) AS departures,
                SUM(b.b_status = 'pending_payment'
                    AND COALESCE(sb.sb_check_out, eb.eb_event_date, '1000-01-01') >= ?) AS awaiting_count,
                COALESCE(SUM(CASE WHEN b.b_status = 'pending_payment'
                    AND COALESCE(sb.sb_check_out, eb.eb_event_date, '1000-01-01') >= ?
                    THEN b.b_total_price ELSE 0 END), 0) AS awaiting_total,
                SUM(b.b_status = 'enquiry') AS open_enquiries
             FROM booking b
             LEFT JOIN stay_booking sb ON sb.sb_b_id = b.b_id
             LEFT JOIN event_booking eb ON eb.eb_b_id = b.b_id
             WHERE COALESCE(eb.eb_visibility, 'private') <> 'public'"
        );
        // Public events are not guest bookings. A stay has no event row,
        // so NULL visibility counts as not public.
        $statement->execute([$today, $today, $today, $today]);
        $row = $statement->fetch();

        return [
            'arrivals' => (int) $row['arrivals'],
            'departures' => (int) $row['departures'],
            'awaitingPaymentCount' => (int) $row['awaiting_count'],
            'openEnquiries' => (int) $row['open_enquiries'],
            'awaitingPaymentTotal' => (float) $row['awaiting_total'],
        ];
    }

    // Only real arrivals, not future or cancelled work.
    public static function arrivalsOn(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_reference, b.b_status, bt.bt_name, u.u_name, u.u_surname,
                    COALESCE(sb.sb_check_in, eb.eb_event_date) AS start_date, eb.eb_occasion,
                    (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR ' + ')
                     FROM stay_space ss JOIN space sp ON sp.sp_id = ss.ss_sp_id
                     WHERE ss.ss_sb_id = sb.sb_id) AS stay_spaces,
                    (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR ' + ')
                     FROM event_space es JOIN space sp ON sp.sp_id = es.es_sp_id
                     WHERE es.es_eb_id = eb.eb_id) AS event_spaces
             FROM booking b
             JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             JOIN app_user u ON u.u_id = b.b_u_id
             LEFT JOIN stay_booking sb ON sb.sb_b_id = b.b_id
             LEFT JOIN event_booking eb ON eb.eb_b_id = b.b_id
             WHERE b.b_status IN ('quoted', 'pending_payment', 'confirmed')
               AND COALESCE(sb.sb_check_in, eb.eb_event_date) = ?
               AND COALESCE(eb.eb_visibility, 'private') <> 'public'
             ORDER BY b.b_reference"
        );
        $statement->execute([$today]);

        $bookings = [];
        foreach ($statement->fetchAll() as $row) {
            $bookings[] = self::toTodayRow($row);
        }

        return $bookings;
    }

    // Counts waiting in the three staff queues.
    public static function navigationCounts(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM booking WHERE b_status = 'enquiry') AS enquiries,
                (SELECT COUNT(*) FROM quotation q
                 WHERE q.q_status = 'sent'
                   AND q.q_id = (SELECT MAX(q2.q_id) FROM quotation q2 WHERE q2.q_b_id = q.q_b_id)) AS quotations,
                (SELECT COUNT(*) FROM event_booking eb
                 JOIN booking b ON b.b_id = eb.eb_b_id
                 WHERE eb.eb_is_venue_hosted = 1
                   AND eb.eb_visibility = 'public'
                   AND eb.eb_event_date >= ?
                   AND b.b_status <> 'cancelled') AS events"
        );
        $statement->execute([$today]);
        $row = $statement->fetch();

        return [
            'enquiries' => (int) $row['enquiries'],
            'quotations' => (int) $row['quotations'],
            'events' => (int) $row['events'],
        ];
    }

    // The service expands stays into their nights.
    public static function calendarBookings(string $from, string $to): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id, b.b_reference, b.b_status, b.b_hold_expires_at, bt.bt_name,
                    u.u_name, u.u_surname, sb.sb_check_in, sb.sb_check_out,
                    eb.eb_id, eb.eb_event_date, eb.eb_title, eb.eb_start_time, eb.eb_end_time,
                    eb.eb_visibility, eb.eb_is_venue_hosted, eb.eb_occasion,
                    (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR ' + ')
                     FROM stay_space ss JOIN space sp ON sp.sp_id = ss.ss_sp_id
                     WHERE ss.ss_sb_id = sb.sb_id) AS stay_spaces,
                    (SELECT GROUP_CONCAT(sp.sp_name ORDER BY sp.sp_id SEPARATOR ' + ')
                     FROM event_space es JOIN space sp ON sp.sp_id = es.es_sp_id
                     WHERE es.es_eb_id = eb.eb_id) AS event_spaces
             FROM booking b
             JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             JOIN app_user u ON u.u_id = b.b_u_id
             LEFT JOIN stay_booking sb ON sb.sb_b_id = b.b_id
             LEFT JOIN event_booking eb ON eb.eb_b_id = b.b_id
             WHERE b.b_status IN ('pending_payment', 'confirmed', 'completed')
               AND ((sb.sb_check_in <= ? AND sb.sb_check_out > ?)
                    OR eb.eb_event_date BETWEEN ? AND ?)
             ORDER BY COALESCE(sb.sb_check_in, eb.eb_event_date), b.b_reference"
        );
        $statement->execute([$to, $from, $from, $to]);

        $bookings = [];
        foreach ($statement->fetchAll() as $row) {
            $bookings[] = self::toCalendarBooking($row);
        }

        return $bookings;
    }

    // Per affected space, including both the start and end dates.
    public static function calendarBlackouts(string $from, string $to): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sbk.sbk_id, sbk.sbk_sp_id, sbk.sbk_start_date, sbk.sbk_end_date,
                    sbk.sbk_reason, sp.sp_name
             FROM space_blackout sbk
             JOIN space sp ON sp.sp_id = sbk.sbk_sp_id
             WHERE sbk.sbk_start_date <= ? AND sbk.sbk_end_date >= ?
             ORDER BY sbk.sbk_start_date, sbk.sbk_id'
        );
        $statement->execute([$to, $from]);

        $blackouts = [];
        foreach ($statement->fetchAll() as $row) {
            $blackouts[] = [
                'id' => (int) $row['sbk_id'],
                'spaceId' => (int) $row['sbk_sp_id'],
                'space' => $row['sp_name'],
                'reason' => $row['sbk_reason'],
                'start' => $row['sbk_start_date'],
                'end' => $row['sbk_end_date'],
            ];
        }

        return $blackouts;
    }

    // Stays have check-in and check-out. Events have a date and times.
    private static function toCalendarBooking(array $row): array
    {
        $description = (string) $row['stay_spaces'];
        $eventId = null;
        $startTime = null;
        $endTime = null;
        if ($row['bt_name'] === 'event') {
            $description = (string) $row['event_spaces'];
            if ($row['eb_occasion'] !== null && $row['eb_occasion'] !== '') {
                $description = $row['eb_occasion'] . ' · ' . $description;
            }
            $eventId = (int) $row['eb_id'];
            $startTime = substr($row['eb_start_time'], 0, 5);
            $endTime = substr($row['eb_end_time'], 0, 5);
        }

        return [
            'bookingId' => (int) $row['b_id'],
            'reference' => $row['b_reference'],
            'type' => $row['bt_name'],
            'status' => $row['b_status'],
            'holdExpires' => $row['b_hold_expires_at'],
            'guest' => mb_substr($row['u_name'], 0, 1) . '. ' . $row['u_surname'],
            'description' => $description,
            'checkIn' => $row['sb_check_in'],
            'checkOut' => $row['sb_check_out'],
            'eventId' => $eventId,
            'date' => $row['eb_event_date'],
            'title' => $row['eb_title'],
            'startTime' => $startTime,
            'endTime' => $endTime,
            'isPublic' => $row['eb_visibility'] === 'public',
            'isHosted' => (bool) $row['eb_is_venue_hosted'],
            'space' => (string) $row['event_spaces'],
        ];
    }

    private static function toTodayRow(array $row): array
    {
        $description = (string) $row['stay_spaces'];
        if ($row['bt_name'] === 'event') {
            $description = (string) $row['event_spaces'];
            if ($row['eb_occasion'] !== null && $row['eb_occasion'] !== '') {
                $description = $row['eb_occasion'] . ' · ' . $description;
            }
        }

        return [
            'reference' => $row['b_reference'],
            'guest' => mb_substr($row['u_name'], 0, 1) . '. ' . $row['u_surname'],
            'description' => $description,
            'startDate' => $row['start_date'],
            'status' => $row['b_status'],
        ];
    }
}
