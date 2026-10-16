<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/* Space and amenity prices, and the rows a new stay saves.
 * Dates are taken by Availability::reserve(). Status changes are BookingLifecycle's.
 */
class Stay
{
    // null if there is no such space.
    public static function space(int $spaceId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sp_id, sp_name, sp_capacity, sp_is_accommodation, sp_is_active FROM space WHERE sp_id = ?'
        );
        $statement->execute([$spaceId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['sp_id'],
            'name' => $row['sp_name'],
            'capacity' => (int) $row['sp_capacity'],
            'isAccommodation' => (int) $row['sp_is_accommodation'] === 1,
            'isActive' => (int) $row['sp_is_active'] === 1,
        ];
    }

    // null if it is missing or switched off.
    public static function amenityName(int $amenityId): ?string
    {
        $statement = Db::pdo()->prepare('SELECT a_name FROM amenity WHERE a_id = ? AND a_is_active = 1');
        $statement->execute([$amenityId]);
        $name = $statement->fetchColumn();
        if ($name === false) {
            return null;
        }

        return $name;
    }

    // Newest price covering $date, or null if it has none.
    public static function amenityPrice(int $amenityId, string $date): ?float
    {
        $statement = Db::pdo()->prepare(
            'SELECT ar_price FROM amenity_rate
             WHERE ar_a_id = ? AND ar_is_active = 1 AND ar_valid_from <= ?
               AND (ar_valid_to IS NULL OR ar_valid_to >= ?)
             ORDER BY ar_valid_from DESC, ar_id DESC LIMIT 1'
        );
        $statement->execute([$amenityId, $date, $date]);
        $price = $statement->fetchColumn();
        if ($price === false) {
            return null;
        }

        return (float) $price;
    }

    public static function bookingTypeId(): int
    {
        $id = Db::pdo()->query("SELECT bt_id FROM booking_type WHERE bt_name = 'stay' AND bt_is_active = 1")->fetchColumn();
        if ($id === false) {
            throw new HttpError(500, 'The stay booking type is not set up');
        }

        return (int) $id;
    }

    // The hold and first history row are written by BookingLifecycle.
    public static function insertBooking(array $booking): int
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO booking (b_reference, b_bt_id, b_u_id, b_status, b_subtotal, b_promo_id, b_discount_amount,
                                  b_total_price, b_notes)
             VALUES (?, ?, ?, 'pending_payment', ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $booking['reference'],
            self::bookingTypeId(),
            $booking['userId'],
            $booking['subtotal'],
            $booking['promoId'],
            $booking['discount'],
            $booking['total'],
            $booking['notes'],
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Returns the stay_booking id, not the booking id.
    public static function insertStay(int $bookingId, string $checkIn, string $checkOut, int $guests): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO stay_booking (sb_b_id, sb_check_in, sb_check_out, sb_guests) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, $checkIn, $checkOut, $guests]);

        return (int) Db::pdo()->lastInsertId();
    }

    // $unitPrice is the nightly prices added up, frozen here.
    public static function insertSpaceLine(int $stayId, int $spaceId, int $nights, float $unitPrice, float $lineTotal): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO stay_space (ss_sb_id, ss_sp_id, ss_nights, ss_price_snapshot, ss_line_total) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$stayId, $spaceId, $nights, $unitPrice, $lineTotal]);
    }

    public static function insertAmenityLine(int $bookingId, int $amenityId, int $quantity, float $unitPrice, float $lineTotal): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO amenity_booking (ab_b_id, ab_a_id, ab_quantity, ab_price_snapshot, ab_line_total) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, $amenityId, $quantity, $unitPrice, $lineTotal]);
    }

    // Frozen at booking time. $kind is space or amenity.
    public static function insertBookingLine(int $bookingId, string $kind, string $label, float $quantity, float $unitPrice, float $lineTotal, int $createdBy, array $metadata): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO booking_line (bl_b_id, bl_kind, bl_label, bl_quantity, bl_unit_price, bl_line_total, bl_metadata, bl_created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, $kind, $label, $quantity, $unitPrice, $lineTotal, json_encode($metadata), $createdBy]);
    }

    // null if it is not a stay.
    public static function guest(string $reference): ?array
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_id, b.b_u_id, u.u_name, u.u_surname, u.u_email
             FROM booking b
             JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             JOIN app_user u ON u.u_id = b.b_u_id
             WHERE b.b_reference = ? AND bt.bt_name = 'stay'"
        );
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'bookingId' => (int) $row['b_id'],
            'userId' => (int) $row['b_u_id'],
            'name' => $row['u_name'],
            'surname' => $row['u_surname'],
            'email' => $row['u_email'],
        ];
    }

    public static function referenceOf(int $bookingId): ?string
    {
        $statement = Db::pdo()->prepare(
            "SELECT b.b_reference FROM booking b JOIN booking_type bt ON bt.bt_id = b.b_bt_id
             WHERE b.b_id = ? AND bt.bt_name = 'stay'"
        );
        $statement->execute([$bookingId]);
        $reference = $statement->fetchColumn();
        if ($reference === false) {
            return null;
        }

        return $reference;
    }

    // Locked until the transaction ends. null if it is not a stay.
    public static function lockByReference(string $reference): ?array
    {
        return self::findByReference($reference, true);
    }

    // Same row, without the lock.
    public static function findByReference(string $reference, bool $lock = false): ?array
    {
        $sql = "SELECT b.b_id, b.b_u_id, b.b_status, b.b_hold_expires_at, b.b_total_price
                FROM booking b
                JOIN booking_type bt ON bt.bt_id = b.b_bt_id
                WHERE b.b_reference = ? AND bt.bt_name = 'stay'";
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$reference]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['b_id'],
            'userId' => (int) $row['b_u_id'],
            'status' => $row['b_status'],
            'holdExpires' => $row['b_hold_expires_at'],
            'total' => (float) $row['b_total_price'],
        ];
    }
}
