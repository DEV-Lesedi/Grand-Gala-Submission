<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Reads the enquiry to prefill a quotation. Those rows are read only.
 * Writes the private event when the guest accepts.
 */
class QuotationEvent
{
    // null if there is no enquiry. Missing times or spaces (pre-006) stay null.
    public static function enquiryRequest(int $bookingId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT ee_id, ee_preferred_date, ee_start_time, ee_end_time, ee_estimated_guests, ee_occasion,
                    ee_accommodation_sp_id, ee_accommodation_check_in, ee_accommodation_check_out, ee_accommodation_rooms
             FROM event_enquiry
             WHERE ee_b_id = ?'
        );
        $statement->execute([$bookingId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $guests = null;
        if ($row['ee_estimated_guests'] !== null) {
            $guests = (int) $row['ee_estimated_guests'];
        }

        $accommodation = null;
        if ($row['ee_accommodation_sp_id'] !== null) {
            // Rooms the guest picked, if any.
            $picked = Db::pdo()->prepare('SELECT eer_sp_id FROM event_enquiry_room WHERE eer_ee_id = ? ORDER BY eer_id');
            $picked->execute([$row['ee_id']]);
            $roomIds = [];
            foreach ($picked->fetchAll() as $room) {
                $roomIds[] = (int) $room['eer_sp_id'];
            }

            $accommodation = [
                'spaceId' => (int) $row['ee_accommodation_sp_id'],
                'checkIn' => $row['ee_accommodation_check_in'],
                'checkOut' => $row['ee_accommodation_check_out'],
                'rooms' => (int) $row['ee_accommodation_rooms'],
                'roomIds' => $roomIds,
            ];
        }

        // null, not [], when the guest named no spaces.
        $spaces = Db::pdo()->prepare('SELECT ees_sp_id FROM event_enquiry_space WHERE ees_ee_id = ? ORDER BY ees_id');
        $spaces->execute([$row['ee_id']]);
        $spaceIds = [];
        foreach ($spaces->fetchAll() as $space) {
            $spaceIds[] = (int) $space['ees_sp_id'];
        }
        if (count($spaceIds) === 0) {
            $spaceIds = null;
        }

        return [
            'date' => $row['ee_preferred_date'],
            'startTime' => self::shortTime($row['ee_start_time']),
            'endTime' => self::shortTime($row['ee_end_time']),
            'guests' => $guests,
            'occasion' => $row['ee_occasion'],
            'spaceIds' => $spaceIds,
            'accommodation' => $accommodation,
        ];
    }

    public static function venueCapacity(int $venueId): int
    {
        $statement = Db::pdo()->prepare('SELECT v_capacity FROM venue WHERE v_id = ?');
        $statement->execute([$venueId]);

        return (int) $statement->fetchColumn();
    }

    public static function existsFor(int $bookingId): bool
    {
        $statement = Db::pdo()->prepare('SELECT eb_id FROM event_booking WHERE eb_b_id = ?');
        $statement->execute([$bookingId]);

        return $statement->fetch() !== false;
    }

    // Private: on that day the venue's rooms are kept for this event's guests.
    public static function insertPrivateEvent(int $bookingId, int $venueId, array $event): int
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO event_booking (eb_b_id, eb_v_id, eb_event_date, eb_start_time, eb_end_time, eb_visibility,
                                        eb_is_venue_hosted, eb_occasion, eb_guest_count)
             VALUES (?, ?, ?, ?, ?, 'private', 0, ?, ?)"
        );
        $statement->execute([
            $bookingId, $venueId, $event['date'], $event['startTime'], $event['endTime'], $event['occasion'], $event['guests'],
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // The space price is frozen on the link.
    public static function insertSpace(int $eventId, int $spaceId, float $price): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO event_space (es_eb_id, es_sp_id, es_price_snapshot) VALUES (?, ?, ?)');
        $statement->execute([$eventId, $spaceId, $price]);
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
