<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Bookings that occupy a space's dates. Read only; only the availability engine uses this.
 * $lock adds FOR SHARE inside a booking transaction: newest rows, held until commit.
 */
class HeldDates
{
    // At least one night from $from to $to.
    public static function staysOf(array $spaceIds, string $from, string $to, bool $lock = false): array
    {
        $marks = Db::questionMarks($spaceIds);
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id, b.b_status, b.b_hold_expires_at, sb.sb_check_in AS check_in, sb.sb_check_out AS check_out
             FROM stay_space ss
             JOIN stay_booking sb ON sb.sb_id = ss.ss_sb_id
             JOIN booking b ON b.b_id = sb.sb_b_id
             WHERE ss.ss_sp_id IN ($marks) AND sb.sb_check_in <= ? AND sb.sb_check_out > ?" . self::lockSql($lock)
        );
        $statement->execute(array_merge($spaceIds, [$to, $from]));

        return self::toStays($statement->fetchAll());
    }

    // Villa nights given to an event's guests. Same shape as staysOf().
    public static function allocationsOf(array $spaceIds, string $from, string $to, bool $lock = false): array
    {
        $marks = Db::questionMarks($spaceIds);
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id, b.b_status, b.b_hold_expires_at, esa.esa_check_in AS check_in, esa.esa_check_out AS check_out
             FROM event_space_allocation esa
             JOIN event_booking eb ON eb.eb_id = esa.esa_eb_id
             JOIN booking b ON b.b_id = eb.eb_b_id
             WHERE esa.esa_sp_id IN ($marks) AND esa.esa_check_in <= ? AND esa.esa_check_out > ?" . self::lockSql($lock)
        );
        $statement->execute(array_merge($spaceIds, [$to, $from]));

        return self::toStays($statement->fetchAll());
    }

    // Any stay at the venue. Used for the one-mode-per-date rule.
    public static function staysAtVenue(int $venueId, string $from, string $to, bool $lock = false): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.b_id, b.b_status, b.b_hold_expires_at, sb.sb_check_in AS check_in, sb.sb_check_out AS check_out
             FROM stay_space ss
             JOIN stay_booking sb ON sb.sb_id = ss.ss_sb_id
             JOIN booking b ON b.b_id = sb.sb_b_id
             JOIN space sp ON sp.sp_id = ss.ss_sp_id
             WHERE sp.sp_v_id = ? AND sb.sb_check_in <= ? AND sb.sb_check_out > ?' . self::lockSql($lock)
        );
        $statement->execute([$venueId, $to, $from]);

        return self::toStays($statement->fetchAll());
    }

    // Any date from $from to $to.
    public static function eventsOf(array $spaceIds, string $from, string $to, bool $lock = false): array
    {
        $marks = Db::questionMarks($spaceIds);
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id, b.b_status, b.b_hold_expires_at, eb.eb_event_date
             FROM event_space es
             JOIN event_booking eb ON eb.eb_id = es.es_eb_id
             JOIN booking b ON b.b_id = eb.eb_b_id
             WHERE es.es_sp_id IN ($marks) AND eb.eb_event_date BETWEEN ? AND ?" . self::lockSql($lock)
        );
        $statement->execute(array_merge($spaceIds, [$from, $to]));

        return self::toEvents($statement->fetchAll());
    }

    // Private events anywhere at the venue. On that day, rooms are only for that event's guests.
    // Public events do not count: rooms stay open.
    public static function privateEventsAtVenue(int $venueId, string $from, string $to, bool $lock = false): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id, b.b_status, b.b_hold_expires_at, eb.eb_event_date
             FROM event_booking eb
             JOIN booking b ON b.b_id = eb.eb_b_id
             WHERE eb.eb_v_id = ? AND eb.eb_visibility = 'private' AND eb.eb_event_date BETWEEN ? AND ?" . self::lockSql($lock)
        );
        $statement->execute([$venueId, $from, $to]);

        return self::toEvents($statement->fetchAll());
    }

    // Guest rooms anywhere at the venue, plus eventDate. Used for one-mode-per-date.
    public static function allocationsAtVenue(int $venueId, string $from, string $to, bool $lock = false): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.b_id, b.b_status, b.b_hold_expires_at, esa.esa_check_in AS check_in, esa.esa_check_out AS check_out,
                    eb.eb_event_date
             FROM event_space_allocation esa
             JOIN event_booking eb ON eb.eb_id = esa.esa_eb_id
             JOIN booking b ON b.b_id = eb.eb_b_id
             JOIN space sp ON sp.sp_id = esa.esa_sp_id
             WHERE sp.sp_v_id = ? AND esa.esa_check_in <= ? AND esa.esa_check_out > ?' . self::lockSql($lock)
        );
        $statement->execute([$venueId, $to, $from]);

        $stays = [];
        foreach ($statement->fetchAll() as $row) {
            $stays[] = [
                'bookingId' => (int) $row['b_id'],
                'checkIn' => $row['check_in'],
                'checkOut' => $row['check_out'],
                'status' => $row['b_status'],
                'holdExpires' => $row['b_hold_expires_at'],
                'eventDate' => $row['eb_event_date'],
            ];
        }

        return $stays;
    }

    private static function toStays(array $rows): array
    {
        $stays = [];
        foreach ($rows as $row) {
            $stays[] = [
                'bookingId' => (int) $row['b_id'],
                'checkIn' => $row['check_in'],
                'checkOut' => $row['check_out'],
                'status' => $row['b_status'],
                'holdExpires' => $row['b_hold_expires_at'],
            ];
        }

        return $stays;
    }

    private static function toEvents(array $rows): array
    {
        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'bookingId' => (int) $row['b_id'],
                'date' => $row['eb_event_date'],
                'status' => $row['b_status'],
                'holdExpires' => $row['b_hold_expires_at'],
            ];
        }

        return $events;
    }

    private static function lockSql(bool $lock): string
    {
        if ($lock) {
            return ' FOR SHARE';
        }

        return '';
    }
}
