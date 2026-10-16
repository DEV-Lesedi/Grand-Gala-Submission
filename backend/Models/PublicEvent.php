<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Ticketed events: a confirmed booking, a public event, and its spaces.
 * Private events come from quotations and are not read here.
 */
class PublicEvent
{
    private const SELECT = "SELECT eb.eb_id, eb.eb_b_id, eb.eb_v_id, eb.eb_title, eb.eb_description, eb.eb_image_url,
                                   eb.eb_event_date, eb.eb_start_time, eb.eb_end_time, eb.eb_capacity, eb.eb_visibility,
                                   eb.eb_is_venue_hosted, b.b_reference, b.b_status, v.v_name, v.v_is_active
                            FROM event_booking eb
                            JOIN booking b ON b.b_id = eb.eb_b_id
                            JOIN venue v ON v.v_id = eb.eb_v_id";

    private const ONLY_PUBLIC = "eb.eb_visibility = 'public' AND eb.eb_is_venue_hosted = 1";

    // Confirmed, so it holds its spaces. Space price is 0.
    // Call inside the transaction that already checked the spaces are free.
    public static function insert(string $reference, int $staffId, array $event): int
    {
        $pdo = Db::pdo();
        $typeId = (int) $pdo->query("SELECT bt_id FROM booking_type WHERE bt_name = 'event'")->fetchColumn();

        $statement = $pdo->prepare("INSERT INTO booking (b_reference, b_bt_id, b_u_id, b_status) VALUES (?, ?, ?, 'confirmed')");
        $statement->execute([$reference, $typeId, $staffId]);
        $bookingId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare(
            "INSERT INTO event_booking (eb_b_id, eb_v_id, eb_title, eb_description, eb_image_url, eb_event_date,
                                        eb_start_time, eb_end_time, eb_visibility, eb_is_venue_hosted, eb_capacity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'public', 1, ?)"
        );
        $statement->execute([
            $bookingId,
            $event['venueId'],
            $event['title'],
            $event['description'],
            $event['image'],
            $event['date'],
            $event['startTime'],
            $event['endTime'],
            $event['capacity'],
        ]);
        $eventId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare('INSERT INTO event_space (es_eb_id, es_sp_id, es_price_snapshot) VALUES (?, ?, 0)');
        foreach ($event['spaceIds'] as $spaceId) {
            $statement->execute([$eventId, $spaceId]);
        }

        return $eventId;
    }

    // null for a private event. $lock holds the event and booking rows.
    // Lock the venue first so two requests never wait on each other in a circle.
    public static function find(int $eventId, bool $lock = false): ?array
    {
        $sql = self::SELECT . ' WHERE eb.eb_id = ? AND ' . self::ONLY_PUBLIC;
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$eventId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toEvent($row);
    }

    // On or after $today, not cancelled.
    // $onSaleOnly also skips a switched-off venue and anything not confirmed.
    public static function upcoming(string $today, bool $onSaleOnly): array
    {
        $sql = self::SELECT . ' WHERE ' . self::ONLY_PUBLIC . " AND eb.eb_event_date >= ? AND b.b_status <> 'cancelled'";
        if ($onSaleOnly) {
            $sql = $sql . " AND b.b_status = 'confirmed' AND v.v_is_active = 1";
        }
        $sql = $sql . ' ORDER BY eb.eb_event_date, eb.eb_start_time, eb.eb_id';

        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$today]);

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            $events[] = self::toEvent($row);
        }

        return $events;
    }

    public static function spaceIds(int $eventId): array
    {
        $statement = Db::pdo()->prepare('SELECT es_sp_id FROM event_space WHERE es_eb_id = ? ORDER BY es_sp_id');
        $statement->execute([$eventId]);

        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $ids[] = (int) $row['es_sp_id'];
        }

        return $ids;
    }

    // Includes switched-off venues. null if it does not exist.
    public static function venue(int $venueId): ?array
    {
        $statement = Db::pdo()->prepare('SELECT v_id, v_name, v_capacity, v_is_active FROM venue WHERE v_id = ?');
        $statement->execute([$venueId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['v_id'],
            'name' => $row['v_name'],
            'capacity' => (int) $row['v_capacity'],
            'isActive' => (int) $row['v_is_active'] === 1,
        ];
    }

    // Fields that can still change after creation.
    public static function update(int $eventId, string $title, ?string $description, ?string $image, int $capacity): void
    {
        $statement = Db::pdo()->prepare(
            'UPDATE event_booking SET eb_title = ?, eb_description = ?, eb_image_url = ?, eb_capacity = ? WHERE eb_id = ?'
        );
        $statement->execute([$title, $description, $image, $capacity, $eventId]);
    }

    private static function toEvent(array $row): array
    {
        $capacity = 0;
        if ($row['eb_capacity'] !== null) {
            $capacity = (int) $row['eb_capacity'];
        }

        return [
            'id' => (int) $row['eb_id'],
            'bookingId' => (int) $row['eb_b_id'],
            'reference' => $row['b_reference'],
            'status' => $row['b_status'],
            'title' => (string) $row['eb_title'],
            'description' => $row['eb_description'],
            'image' => $row['eb_image_url'],
            'date' => $row['eb_event_date'],
            'startTime' => substr($row['eb_start_time'], 0, 5), // '18:30:00' becomes '18:30'
            'endTime' => substr($row['eb_end_time'], 0, 5),
            'capacity' => $capacity,
            'venueId' => (int) $row['eb_v_id'],
            'venueName' => $row['v_name'],
            'venueIsActive' => (int) $row['v_is_active'] === 1,
        ];
    }
}
