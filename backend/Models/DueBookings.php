<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Which bookings the scheduled jobs should expire or complete. Read only.
 * Status changes go through BookingLifecycle.
 */
class DueBookings
{
    // Waiting for payment, hold at or before $now.
    public static function lapsedHolds(string $now): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b_id FROM booking WHERE b_status = 'pending_payment' AND b_hold_expires_at <= ? ORDER BY b_id"
        );
        $statement->execute([$now]);

        return self::ids($statement->fetchAll());
    }

    // Ids of confirmed stays whose check-out day is $today or earlier
    public static function finishedStays(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id FROM booking b JOIN stay_booking sb ON sb.sb_b_id = b.b_id
             WHERE b.b_status = 'confirmed' AND sb.sb_check_out <= ? ORDER BY b.b_id"
        );
        $statement->execute([$today]);

        return self::ids($statement->fetchAll());
    }

    // Ids of confirmed events (private or public) whose day was before $today
    public static function finishedEvents(string $today): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id FROM booking b JOIN event_booking eb ON eb.eb_b_id = b.b_id
             WHERE b.b_status = 'confirmed' AND eb.eb_event_date < ? ORDER BY b.b_id"
        );
        $statement->execute([$today]);

        return self::ids($statement->fetchAll());
    }

    // Status and hold, locked until the transaction ends. null if missing.
    public static function lock(int $bookingId): ?array
    {
        $statement = Db::pdo()->prepare('SELECT b_status, b_hold_expires_at FROM booking WHERE b_id = ? FOR UPDATE');
        $statement->execute([$bookingId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['status' => $row['b_status'], 'holdExpires' => $row['b_hold_expires_at']];
    }

    private static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['b_id'];
        }

        return $ids;
    }
}
