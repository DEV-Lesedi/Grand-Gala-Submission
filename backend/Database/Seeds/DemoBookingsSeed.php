<?php

declare(strict_types=1);

namespace GrandGala\Database\Seeds;

use GrandGala\Utils\Db;
use RuntimeException;

/* Demo bookings and guest accounts. Password is DEMO_ACCOUNT_PASSWORD, never code.
 * Safe to re-run. Not seeded: quotations, catering, décor, payments, extras, event rooms.
 */
class DemoBookingsSeed
{
    // reference, name, surname, email, phone, type, status, start, end (stays),
    // hours (events), guests, space, total, discount, hold (unpaid), space amount, occasion.
    // Space amount is the price at booking time, which can differ from today's rate.
    private const BOOKINGS = [
        ['GGG-2026-0417', 'Mfundo', 'Dlamini', 'mfundo@email.com', '+27 82 000 0000', 'stay', 'confirmed', '2026-12-12', '2026-12-14', null, 4, 'The Villa', 5148, 572, null, 5600, null],
        ['GGG-2026-0448', 'Mfundo', 'Dlamini', 'mfundo@email.com', '+27 82 000 0000', 'stay', 'pending_payment', '2026-12-18', '2026-12-19', null, 2, 'The Villa', 3500, 0, '2026-12-11 23:59:00', 3500, null],
        ['GGG-2026-0421', 'Sarisha', 'Naidoo', 'sarisha@email.com', '+27 83 000 0000', 'stay', 'pending_payment', '2026-09-04', '2026-09-05', null, 6, 'The Villa', 2800, 0, '2026-09-04 14:00:00', 2800, null],
        ['GGG-2026-0419', 'Anika', 'Pillay', 'anika@email.com', '+27 84 000 0000', 'event', 'quoted', '2026-11-21', null, '18:00-23:00', 45, 'Glass Pangola', 18900, 0, null, 8500, '40th birthday'],
        ['GGG-2026-0418', 'Kagiso', 'Mokoena', 'kagiso@email.com', '+27 72 000 0000', 'event', 'quoted', '2027-03-14', null, '14:00-23:00', 80, 'Open Garden', 24500, 0, null, 12000, 'Wedding'],
        ['GGG-2026-0415', 'Sasol', 'Events team', 'events@email.com', '+27 11 000 0000', 'event', 'confirmed', '2026-10-09', null, '08:00-17:00', 90, 'Marquee Tent', 41200, 0, null, 15000, 'Team day'],
        ['GGG-2026-0414', 'Lize', 'Meyer', 'lize@email.com', '+27 76 000 0000', 'stay', 'confirmed', '2026-09-04', '2026-09-07', null, 2, 'Garden Room', 1950, 0, null, 1950, null],
        ['GGG-2026-0412', 'Pieter', 'van Wyk', 'pieter@email.com', '+27 79 000 0000', 'event', 'pending_payment', '2026-10-04', null, '11:00-15:00', 30, 'Glass Pangola', 6500, 0, '2026-09-11 23:59:00', 4500, 'Baby shower'],
        ['GGG-2026-0402', 'Nomsa', 'Sithole', 'nomsa@email.com', '+27 71 000 0000', 'event', 'completed', '2026-08-22', null, '12:00-23:30', 150, 'Open Garden', 161400, 0, null, 18000, 'Wedding'],
        ['GGG-2026-0423', 'Ashwin', 'Pillay', 'ashwin@email.com', '+27 82 100 0000', 'stay', 'confirmed', '2026-09-04', '2026-09-06', null, 2, 'Family Suite', 2400, 0, null, 2400, null],
        ['GGG-2026-0425', 'Johan', 'Botha', 'johan@email.com', '+27 83 100 0000', 'stay', 'confirmed', '2026-09-18', '2026-09-21', null, 8, 'The Villa', 8520, 0, null, 8400, null],
        ['GGG-2026-0427', 'Thabo', 'Khumalo', 'thabo@email.com', '+27 84 100 0000', 'stay', 'confirmed', '2026-09-25', '2026-09-27', null, 2, 'Garden Room', 1300, 0, null, 1300, null],
        ['GGG-2026-0430', 'Reshma', 'Govender', 'reshma@email.com', '+27 72 100 0000', 'event', 'confirmed', '2026-10-17', null, '12:00-16:00', 40, 'Glass Pangola', 11300, 0, null, 4500, 'Birthday lunch'],
        ['GGG-2026-0433', 'Buhle', 'Mahlangu', 'buhle@email.com', '+27 76 100 0000', 'event', 'confirmed', '2026-11-07', null, '14:00-23:00', 95, 'Marquee Tent', 54750, 0, null, 15000, 'Wedding'],
        ['GGG-2026-0436', 'Carla', 'Venter', 'carla@email.com', '+27 79 100 0000', 'stay', 'confirmed', '2026-12-24', '2026-12-27', null, 10, 'The Villa', 8400, 0, null, 8400, null],
        ['GGG-2026-0438', 'Fikile', 'Mthembu', 'fikile@email.com', '+27 81 100 0000', 'stay', 'pending_payment', '2026-10-10', '2026-10-11', null, 2, 'Family Suite', 1200, 0, '2026-09-12 23:59:00', 1200, null],
        ['GGG-2026-0324', 'Lesedi', 'Kutumela', 'lesedi@email.com', '+27 71 100 0000', 'event', 'quoted', '2027-06-30', null, '13:00-22:00', 120, 'Open Garden', 46500, 0, null, 12000, 'Wedding'],
        ['GGG-2026-0440', 'Marius', 'Coetzee', 'marius@email.com', '+27 82 200 0000', 'event', 'quoted', '2026-11-27', null, '18:00-23:00', 55, 'Glass Pangola', 23500, 0, null, 8500, 'Year-end'],
        ['GGG-2026-0398', 'Hlengiwe', 'Molefe', 'hlengiwe@email.com', '+27 83 200 0000', 'stay', 'completed', '2026-08-28', '2026-08-30', null, 6, 'The Villa', 5600, 0, null, 5600, null],
        ['GGG-2026-0371', 'Thandeka', 'Nkosi', 'thandeka@email.com', '+27 84 200 0000', 'stay', 'completed', '2026-07-10', '2026-07-12', null, 2, 'Garden Room', 1300, 0, null, 1300, null],
        ['GGG-2026-0386', 'Zanele', 'Dube', 'zanele@email.com', '+27 72 200 0000', 'stay', 'cancelled', '2026-09-11', '2026-09-13', null, 8, 'The Villa', 5600, 0, null, 5600, null],
        ['GGG-2026-0391', 'Sipho', 'Maseko', 'sipho@email.com', '+27 76 200 0000', 'stay', 'expired', '2026-09-19', '2026-09-20', null, 2, 'Family Suite', 1200, 0, null, 1200, null],
    ];

    public static function run(): void
    {
        foreach (self::BOOKINGS as $booking) {
            // Each part is only added if missing, so an interrupted run gets completed next time
            $bookingId = self::findBooking($booking[0]);
            if ($bookingId === null) {
                $userId = self::findOrCreateGuest($booking[1], $booking[2], $booking[3], $booking[4]);
                $bookingId = self::createBooking($booking, $userId);
            }

            if ($booking[5] === 'stay' && !self::hasRow('stay_booking', 'sb_b_id', $bookingId)) {
                self::createStay($booking, $bookingId);
            }
            if ($booking[5] === 'event' && !self::hasRow('event_booking', 'eb_b_id', $bookingId)) {
                self::createEvent($booking, $bookingId);
            }
        }
    }

    private static function findBooking(string $reference): ?int
    {
        $statement = Db::pdo()->prepare('SELECT b_id FROM booking WHERE b_reference = ?');
        $statement->execute([$reference]);
        $bookingId = $statement->fetchColumn();
        if ($bookingId === false) {
            return null;
        }

        return (int) $bookingId;
    }

    private static function createStay(array $booking, int $bookingId): void
    {
        $checkIn = $booking[7];
        $checkOut = $booking[8];
        $nights = (int) (new \DateTime($checkIn))->diff(new \DateTime($checkOut))->days;
        $spaceAmount = $booking[15];

        $insertStay = Db::pdo()->prepare('INSERT INTO stay_booking (sb_b_id, sb_check_in, sb_check_out, sb_guests) VALUES (?, ?, ?, ?)');
        $insertStay->execute([$bookingId, $checkIn, $checkOut, $booking[10]]);
        $stayId = (int) Db::pdo()->lastInsertId();

        $insertSpace = Db::pdo()->prepare(
            'INSERT INTO stay_space (ss_sb_id, ss_sp_id, ss_nights, ss_price_snapshot, ss_line_total) VALUES (?, ?, ?, ?, ?)'
        );
        $insertSpace->execute([
            $stayId,
            self::idByName('space', 'sp_id', 'sp_name', $booking[11]),
            $nights,
            $spaceAmount / $nights, // price per night
            $spaceAmount,
        ]);
    }

    private static function createEvent(array $booking, int $bookingId): void
    {
        $spaceId = self::idByName('space', 'sp_id', 'sp_name', $booking[11]);
        $hours = explode('-', $booking[9]); // '08:00-17:00' becomes ['08:00', '17:00']

        $venue = Db::pdo()->prepare('SELECT sp_v_id FROM space WHERE sp_id = ?');
        $venue->execute([$spaceId]);

        $insertEvent = Db::pdo()->prepare(
            'INSERT INTO event_booking (eb_b_id, eb_v_id, eb_event_date, eb_start_time, eb_end_time, eb_visibility, eb_occasion, eb_guest_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertEvent->execute([
            $bookingId,
            (int) $venue->fetchColumn(),
            $booking[7],
            $hours[0],
            $hours[1],
            'private', // every mock event is a private client event
            $booking[16],
            $booking[10],
        ]);
        $eventId = (int) Db::pdo()->lastInsertId();

        $insertSpace = Db::pdo()->prepare('INSERT INTO event_space (es_eb_id, es_sp_id, es_price_snapshot) VALUES (?, ?, ?)');
        $insertSpace->execute([$eventId, $spaceId, $booking[15]]);
    }

    private static function hasRow(string $table, string $column, int $id): bool
    {
        $statement = Db::pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
        $statement->execute([$id]);

        return (int) $statement->fetchColumn() > 0;
    }

    // One account per email, so two bookings share one guest.
    private static function findOrCreateGuest(string $name, string $surname, string $email, string $phone): int
    {
        $find = Db::pdo()->prepare('SELECT u_id FROM app_user WHERE u_email = ?');
        $find->execute([$email]);
        $userId = $find->fetchColumn();
        if ($userId !== false) {
            return (int) $userId;
        }

        $insert = Db::pdo()->prepare(
            'INSERT INTO app_user (u_ut_id, u_name, u_surname, u_email, u_phone, u_password_hash) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            self::idByName('user_type', 'ut_id', 'ut_name', 'customer'),
            $name,
            $surname,
            $email,
            $phone,
            password_hash(AccountsSeed::demoPassword(), PASSWORD_DEFAULT),
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    private static function createBooking(array $booking, int $userId): int
    {
        $total = $booking[12];
        $discount = $booking[13];

        $insert = Db::pdo()->prepare(
            'INSERT INTO booking (b_reference, b_bt_id, b_u_id, b_status, b_subtotal, b_discount_amount, b_total_price, b_hold_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $booking[0],
            self::idByName('booking_type', 'bt_id', 'bt_name', $booking[5]),
            $userId,
            $booking[6],
            $total + $discount, // subtotal is the price before the discount
            $discount,
            $total,
            $booking[14],
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Table and column names are interpolated, so only pass names written in this file.
    private static function idByName(string $table, string $idColumn, string $nameColumn, string $name): int
    {
        $statement = Db::pdo()->prepare('SELECT ' . $idColumn . ' FROM ' . $table . ' WHERE ' . $nameColumn . ' = ?');
        $statement->execute([$name]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException($table . ' has no row named ' . $name);
        }

        return (int) $id;
    }
}
