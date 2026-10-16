<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Rooms staff give to a private event's guests. The event lookup is read only. */
class EventAllocation
{
    private const COLUMNS = 'esa.esa_id, esa.esa_eb_id, esa.esa_sp_id, sp.sp_name, esa.esa_check_in, esa.esa_check_out,
                             DATEDIFF(esa.esa_check_out, esa.esa_check_in) AS nights, esa.esa_price_snapshot
                             FROM event_space_allocation esa
                             JOIN space sp ON sp.sp_id = esa.esa_sp_id';

    // type is stay or event. Event fields are null for a stay.
    public static function findEvent(string $reference): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.b_id, b.b_status, bt.bt_name, eb.eb_id, eb.eb_event_date, eb.eb_v_id, eb.eb_visibility
             FROM booking b
             JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             LEFT JOIN event_booking eb ON eb.eb_b_id = b.b_id
             WHERE b.b_reference = ?'
        );
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $eventId = null;
        $venueId = null;
        if ($row['eb_id'] !== null) {
            $eventId = (int) $row['eb_id'];
            $venueId = (int) $row['eb_v_id'];
        }

        return [
            'bookingId' => (int) $row['b_id'],
            'type' => $row['bt_name'],
            'status' => $row['b_status'],
            'eventId' => $eventId,
            'eventDate' => $row['eb_event_date'],
            'venueId' => $venueId,
            'isPublic' => $row['eb_visibility'] === 'public',
        ];
    }

    public static function forEvent(int $eventId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' WHERE esa.esa_eb_id = ? ORDER BY esa.esa_check_in, esa.esa_id'
        );
        $statement->execute([$eventId]);

        $allocations = [];
        foreach ($statement->fetchAll() as $row) {
            $allocations[] = self::toAllocation($row);
        }

        return $allocations;
    }

    // Includes the event it belongs to. null if missing.
    public static function find(int $allocationId): ?array
    {
        $statement = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' WHERE esa.esa_id = ?');
        $statement->execute([$allocationId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toAllocation($row);
    }

    // $price is the frozen total for all its nights.
    public static function insert(int $eventId, int $spaceId, string $checkIn, string $checkOut, float $price): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO event_space_allocation (esa_eb_id, esa_sp_id, esa_check_in, esa_check_out, esa_price_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$eventId, $spaceId, $checkIn, $checkOut, $price]);

        return (int) Db::pdo()->lastInsertId();
    }

    public static function delete(int $allocationId): void
    {
        $statement = Db::pdo()->prepare('DELETE FROM event_space_allocation WHERE esa_id = ?');
        $statement->execute([$allocationId]);
    }

    private static function toAllocation(array $row): array
    {
        return [
            'id' => (int) $row['esa_id'],
            'eventId' => (int) $row['esa_eb_id'],
            'spaceId' => (int) $row['esa_sp_id'],
            'spaceName' => $row['sp_name'],
            'checkIn' => $row['esa_check_in'],
            'checkOut' => $row['esa_check_out'],
            'nights' => (int) $row['nights'],
            'price' => (float) $row['esa_price_snapshot'],
        ];
    }
}
