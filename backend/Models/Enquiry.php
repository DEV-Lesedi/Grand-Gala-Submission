<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Event enquiries: the booking, the request, spaces, and catering or décor.
 * Inserts must run inside Db::transaction() so they are saved together.
 */
class Enquiry
{
    // closed means accepted, cancelled, or expired: no longer an open enquiry.
    public const CHIPS = [
        'enquiry' => ['enquiry'],
        'quoted' => ['quoted'],
        'closed' => ['pending_payment', 'confirmed', 'completed', 'cancelled', 'expired', 'refunded'],
    ];

    // At most one catering and one décor row. Subqueries are the latest quote and its count.
    private const COLUMNS = 'b.b_id, b.b_reference, b.b_status, b.b_date_created, u.u_name, u.u_surname, u.u_email, u.u_phone,
        ee.ee_id, ee.ee_preferred_date, ee.ee_alternate_date, ee.ee_start_time, ee.ee_end_time, ee.ee_estimated_guests,
        ee.ee_occasion, ee.ee_requirements, ee.ee_wants_catering, ee.ee_self_catering, ee.ee_wants_decor, ee.ee_self_decor,
        ee.ee_accommodation_check_in, ee.ee_accommodation_check_out, ee.ee_accommodation_rooms, ee.ee_accommodation_guests,
        acc.sp_name AS accommodation_space,
        c.c_meal_type, c.c_special_requests, d.d_requests,
        (SELECT q.q_reference FROM quotation q WHERE q.q_b_id = b.b_id ORDER BY q.q_id DESC LIMIT 1) AS quote_reference,
        (SELECT q.q_status FROM quotation q WHERE q.q_b_id = b.b_id ORDER BY q.q_id DESC LIMIT 1) AS quote_status,
        (SELECT COUNT(*) FROM quotation q WHERE q.q_b_id = b.b_id) AS quote_version';

    private const FROM = 'FROM booking b
        JOIN event_enquiry ee ON ee.ee_b_id = b.b_id
        JOIN app_user u ON u.u_id = b.b_u_id
        LEFT JOIN space acc ON acc.sp_id = ee.ee_accommodation_sp_id
        LEFT JOIN catering c ON c.c_id = (SELECT MIN(c_id) FROM catering WHERE c_b_id = b.b_id)
        LEFT JOIN decoration d ON d.d_id = (SELECT MIN(d_id) FROM decoration WHERE d_b_id = b.b_id)';

    // null if the account is missing or switched off.
    public static function activeGuest(int $userId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT u_name, u_surname, u_email, u_phone FROM app_user WHERE u_id = ? AND u_is_active = 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['name' => $row['u_name'], 'surname' => $row['u_surname'], 'email' => $row['u_email'], 'phone' => $row['u_phone']];
    }

    public static function eventTypeId(): ?int
    {
        $id = Db::pdo()->query("SELECT bt_id FROM booking_type WHERE bt_name = 'event'")->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    // No price yet. $receivedAt is venue time from Clock::now().
    public static function insertBooking(string $reference, int $typeId, int $userId, string $receivedAt): int
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO booking (b_reference, b_bt_id, b_u_id, b_status, b_date_created)
             VALUES (?, ?, ?, 'enquiry', ?)"
        );
        $statement->execute([$reference, $typeId, $userId, $receivedAt]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Accommodation columns stay NULL when the guest does not need rooms.
    public static function insertEnquiry(int $bookingId, array $brief): int
    {
        $accommodation = [
            'spaceId' => null, 'checkIn' => null, 'checkOut' => null, 'rooms' => null, 'guests' => null,
        ];
        if ($brief['accommodation'] !== null) {
            $accommodation = $brief['accommodation'];
        }

        $statement = Db::pdo()->prepare(
            'INSERT INTO event_enquiry (ee_b_id, ee_preferred_date, ee_alternate_date, ee_start_time, ee_end_time,
                                        ee_estimated_guests, ee_occasion, ee_requirements, ee_wants_catering,
                                        ee_self_catering, ee_wants_decor, ee_self_decor, ee_accommodation_sp_id,
                                        ee_accommodation_check_in, ee_accommodation_check_out, ee_accommodation_rooms,
                                        ee_accommodation_guests)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $bookingId,
            $brief['preferredDate'],
            $brief['alternateDate'],
            $brief['startTime'],
            $brief['endTime'],
            $brief['estimatedGuests'],
            $brief['occasion'],
            $brief['requirements'],
            (int) $brief['wantsCatering'],
            (int) $brief['selfCatering'],
            (int) $brief['wantsDecor'],
            (int) $brief['selfDecor'],
            $accommodation['spaceId'],
            $accommodation['checkIn'],
            $accommodation['checkOut'],
            $accommodation['rooms'],
            $accommodation['guests'],
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    public static function insertSpaces(int $enquiryId, array $spaceIds): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO event_enquiry_space (ees_ee_id, ees_sp_id) VALUES (?, ?)');
        foreach ($spaceIds as $spaceId) {
            $statement->execute([$enquiryId, $spaceId]);
        }
    }

    public static function insertRooms(int $enquiryId, array $roomIds): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO event_enquiry_room (eer_ee_id, eer_sp_id) VALUES (?, ?)');
        foreach ($roomIds as $roomId) {
            $statement->execute([$enquiryId, $roomId]);
        }
    }

    // Empty when they asked for the whole villa or a count of rooms instead.
    private static function roomNames(int $enquiryId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sp.sp_name FROM event_enquiry_room eer
             JOIN space sp ON sp.sp_id = eer.eer_sp_id
             WHERE eer.eer_ee_id = ?
             ORDER BY eer.eer_id'
        );
        $statement->execute([$enquiryId]);

        $names = [];
        foreach ($statement->fetchAll() as $row) {
            $names[] = $row['sp_name'];
        }

        return $names;
    }

    private static function spaceNames(int $enquiryId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sp.sp_name FROM event_enquiry_space ees
             JOIN space sp ON sp.sp_id = ees.ees_sp_id
             WHERE ees.ees_ee_id = ?
             ORDER BY ees.ees_id'
        );
        $statement->execute([$enquiryId]);

        $names = [];
        foreach ($statement->fetchAll() as $row) {
            $names[] = $row['sp_name'];
        }

        return $names;
    }

    // Not priced until the quotation.
    public static function insertCatering(int $bookingId, string $mealType, int $headcount, string $request): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO catering (c_b_id, c_meal_type, c_headcount, c_special_requests) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, $mealType, $headcount, $request]);
    }

    public static function insertDecoration(int $bookingId, string $request): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO decoration (d_b_id, d_requests) VALUES (?, ?)');
        $statement->execute([$bookingId, $request]);
    }

    // $statuses comes from CHIPS.
    public static function page(array $statuses, int $perPage, int $page): array
    {
        $marks = Db::questionMarks($statuses);

        $statement = Db::pdo()->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE b.b_status IN ($marks)");
        $statement->execute($statuses);
        $total = (int) $statement->fetchColumn();

        // LIMIT and OFFSET are ints we cast ourselves, so they are safe in the SQL.
        $offset = ($page - 1) * $perPage;
        $statement = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . " WHERE b.b_status IN ($marks)
             ORDER BY b.b_date_created DESC, b.b_id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $statement->execute($statuses);

        $enquiries = [];
        foreach ($statement->fetchAll() as $row) {
            $enquiries[] = self::toEnquiry($row);
        }

        return ['enquiries' => $enquiries, 'total' => $total];
    }

    public static function counts(): array
    {
        $counts = [];
        foreach (self::CHIPS as $chip => $statuses) {
            $statement = Db::pdo()->prepare(
                'SELECT COUNT(*) FROM booking b JOIN event_enquiry ee ON ee.ee_b_id = b.b_id
                 WHERE b.b_status IN (' . Db::questionMarks($statuses) . ')'
            );
            $statement->execute($statuses);
            $counts[$chip] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    // null for a booking with no event_enquiry row, such as a stay.
    public static function find(string $reference): ?array
    {
        $statement = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' ' . self::FROM . ' WHERE b.b_reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toEnquiry($row);
    }

    // Times are HH:MM; null before migration 006. No rooms means accommodation is null.
    // changeRequests is newest first, or empty if the guest never asked.
    private static function toEnquiry(array $row): array
    {
        // Email only when there is no phone.
        $contact = $row['u_email'];
        if ($row['u_phone'] !== null && $row['u_phone'] !== '') {
            $contact = $contact . ' · ' . $row['u_phone'];
        }

        $estimatedGuests = null;
        if ($row['ee_estimated_guests'] !== null) {
            $estimatedGuests = (int) $row['ee_estimated_guests'];
        }

        // Meal type only when the venue caters. Requests are '' when there are none.
        $cateringRequest = '';
        if ($row['c_special_requests'] !== null) {
            $cateringRequest = $row['c_special_requests'];
        }
        $decorRequest = '';
        if ($row['d_requests'] !== null) {
            $decorRequest = $row['d_requests'];
        }

        $accommodation = null;
        if ($row['accommodation_space'] !== null) {
            $accommodation = [
                'space' => $row['accommodation_space'],
                'checkIn' => $row['ee_accommodation_check_in'],
                'checkOut' => $row['ee_accommodation_check_out'],
                'rooms' => (int) $row['ee_accommodation_rooms'],
                'guests' => (int) $row['ee_accommodation_guests'],
                'roomNames' => self::roomNames((int) $row['ee_id']),
            ];
        }

        $quote = null;
        if ($row['quote_reference'] !== null) {
            $quote = [
                'reference' => $row['quote_reference'],
                'version' => (int) $row['quote_version'],
                'status' => $row['quote_status'],
            ];
        }

        return [
            'reference' => $row['b_reference'],
            'guest' => mb_substr($row['u_name'], 0, 1) . '. ' . $row['u_surname'],
            'fullName' => $row['u_name'] . ' ' . $row['u_surname'],
            'contact' => $contact,
            'occasion' => $row['ee_occasion'],
            'spaces' => self::spaceNames((int) $row['ee_id']),
            'preferredDate' => $row['ee_preferred_date'],
            'alternateDate' => $row['ee_alternate_date'],
            'startTime' => self::shortTime($row['ee_start_time']),
            'endTime' => self::shortTime($row['ee_end_time']),
            'estimatedGuests' => $estimatedGuests,
            'status' => $row['b_status'],
            // Trimmed to minutes, with a T between date and time.
            'received' => str_replace(' ', 'T', substr($row['b_date_created'], 0, 16)),
            'accommodation' => $accommodation,
            'wantsCatering' => $row['ee_wants_catering'] === 1,
            'selfCatering' => $row['ee_self_catering'] === 1,
            'mealType' => $row['c_meal_type'],
            'cateringRequest' => $cateringRequest,
            'wantsDecor' => $row['ee_wants_decor'] === 1,
            'selfDecor' => $row['ee_self_decor'] === 1,
            'decorRequest' => $decorRequest,
            'requirements' => $row['ee_requirements'],
            'quote' => $quote,
            'changeRequests' => Quotation::changeRequests((int) $row['b_id']),
        ];
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
