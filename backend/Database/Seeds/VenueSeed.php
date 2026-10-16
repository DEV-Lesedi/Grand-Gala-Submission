<?php

declare(strict_types=1);

namespace GrandGala\Database\Seeds;

use GrandGala\Utils\Db;

/*
 * The venue itself: Grand Gala Gardens, its two booking types and its five main spaces.
 * The other seeds build on these (DemoSeed adds the villa's rooms, rates and photos), so this runs first.
 * Safe to run again: it only adds what's missing, found by name.
 */
class VenueSeed
{
    private const VENUE = ['Grand Gala Gardens', 'Premium guest villa and event gardens.', 'Centurion, Gauteng', 150];

    // [name, description]
    private const BOOKING_TYPES = [
        ['stay', 'Overnight accommodation at the villa'],
        ['event', 'Hire of venue spaces for an event'],
    ];

    // [name, type, capacity, isAccommodation, isHireable]
    private const SPACES = [
        ['The Villa', 'villa', 10, true, true],
        ['Open Garden', 'garden', 150, false, true],
        ['Marquee Tent', 'marquee', 100, false, true],
        ['Glass Pangola', 'pangola', 60, false, true],
        ['Pool & Jacuzzi', 'pool', 20, false, true],
    ];

    public static function run(): void
    {
        $venueId = self::seedVenue();
        self::seedBookingTypes();
        self::seedSpaces($venueId);
    }

    private static function seedVenue(): int
    {
        [$name, $description, $location, $capacity] = self::VENUE;
        $find = Db::pdo()->prepare('SELECT v_id FROM venue WHERE v_name = ?');
        $find->execute([$name]);
        $venueId = $find->fetchColumn();
        if ($venueId !== false) {
            return (int) $venueId; // already there
        }

        $insert = Db::pdo()->prepare('INSERT INTO venue (v_name, v_description, v_location, v_capacity) VALUES (?, ?, ?, ?)');
        $insert->execute([$name, $description, $location, $capacity]);

        return (int) Db::pdo()->lastInsertId();
    }

    private static function seedBookingTypes(): void
    {
        foreach (self::BOOKING_TYPES as [$name, $description]) {
            $find = Db::pdo()->prepare('SELECT COUNT(*) FROM booking_type WHERE bt_name = ?');
            $find->execute([$name]);
            if ((int) $find->fetchColumn() > 0) {
                continue; // already there
            }

            $insert = Db::pdo()->prepare('INSERT INTO booking_type (bt_name, bt_description) VALUES (?, ?)');
            $insert->execute([$name, $description]);
        }
    }

    private static function seedSpaces(int $venueId): void
    {
        foreach (self::SPACES as [$name, $type, $capacity, $isAccommodation, $isHireable]) {
            $find = Db::pdo()->prepare('SELECT COUNT(*) FROM space WHERE sp_name = ?');
            $find->execute([$name]);
            if ((int) $find->fetchColumn() > 0) {
                continue; // already there
            }

            $insert = Db::pdo()->prepare(
                'INSERT INTO space (sp_v_id, sp_name, sp_type, sp_capacity, sp_is_accommodation, sp_is_hireable)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$venueId, $name, $type, $capacity, (int) $isAccommodation, (int) $isHireable]);
        }
    }
}
