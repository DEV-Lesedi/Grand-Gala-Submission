<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Status, hold, and history. Only BookingLifecycle should call these. */
class BookingStatus
{
    // Locked until the transaction ends. null if there is no such booking.
    public static function lock(int $bookingId): ?string
    {
        $statement = Db::pdo()->prepare('SELECT b_status FROM booking WHERE b_id = ? FOR UPDATE');
        $statement->execute([$bookingId]);
        $status = $statement->fetchColumn();
        if ($status === false) {
            return null;
        }

        return $status;
    }

    public static function set(int $bookingId, string $status): void
    {
        $statement = Db::pdo()->prepare('UPDATE booking SET b_status = ? WHERE b_id = ?');
        $statement->execute([$status, $bookingId]);
    }

    // When an unpaid booking releases its dates. null means no hold.
    public static function setHold(int $bookingId, ?string $holdUntil): void
    {
        $statement = Db::pdo()->prepare('UPDATE booking SET b_hold_expires_at = ? WHERE b_id = ?');
        $statement->execute([$holdUntil, $bookingId]);
    }

    public static function hasHistory(int $bookingId): bool
    {
        $statement = Db::pdo()->prepare('SELECT 1 FROM booking_status_history WHERE bsh_b_id = ? LIMIT 1');
        $statement->execute([$bookingId]);

        return $statement->fetchColumn() !== false;
    }

    // $changedBy is null for PayFast and the nightly job.
    // $from is null for the first status. $reason is at most 255 characters.
    public static function addHistory(int $bookingId, ?string $from, string $to, ?int $changedBy, string $reason, string $now): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO booking_status_history (bsh_b_id, bsh_from_status, bsh_to_status, bsh_changed_by, bsh_reason, bsh_date_created)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, $from, $to, $changedBy, $reason, $now]);
    }
}
