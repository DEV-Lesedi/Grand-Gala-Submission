<?php

declare(strict_types=1);

namespace GrandGala\Database\Seeds;

use GrandGala\Utils\Db;
use RuntimeException;

/* Demo spaces and prices. The venue and main spaces may already exist.
 * Safe to re-run: only adds what is missing.
 */
class DemoSeed
{
    // Sleeps per villa room. The total matches the whole villa.
    private const VILLA_ROOMS = [
        'Garden Room' => 2,
        'Mountain Room' => 1,
        'Pool Room' => 1,
        'Family Suite' => 2,
        'Rose Room' => 1,
        'Jacaranda Room' => 1,
        'Protea Room' => 1,
        'Acacia Room' => 1,
    ];

    // basis, label, price, valid from, valid to, switched on.
    private const RATES = [
        'The Villa' => [
            ['per_night', 'Standard', 2800, '2026-01-01', null, true],
            ['per_night', 'Peak season', 3500, '2026-10-01', '2026-12-31', true],
            ['per_night', "Women's Day special", 2200, '2026-08-07', '2026-08-10', false],
            ['per_event', 'Day hire', 15000, '2026-01-01', null, true],
        ],
        'Garden Room' => [['per_night', 'Standard', 650, '2026-01-01', null, true]],
        'Mountain Room' => [['per_night', 'Standard', 700, '2026-01-01', null, true]],
        'Pool Room' => [['per_night', 'Standard', 700, '2026-01-01', null, true]],
        'Family Suite' => [['per_night', 'Standard', 1200, '2026-01-01', null, true]],
        'Rose Room' => [['per_night', 'Standard', 500, '2026-01-01', null, true]],
        'Jacaranda Room' => [['per_night', 'Standard', 500, '2026-01-01', null, true]],
        'Protea Room' => [['per_night', 'Standard', 500, '2026-01-01', null, true]],
        'Acacia Room' => [['per_night', 'Standard', 500, '2026-01-01', null, true]],
        'Open Garden' => [
            ['per_event', 'Event hire', 8000, '2026-01-01', null, true],
            ['per_head', 'Ceremony & reception', 100, '2026-01-01', null, true],
        ],
        'Marquee Tent' => [['per_event', 'Event hire', 11500, '2026-01-01', null, true]],
        'Glass Pangola' => [['per_event', 'Event hire', 9200, '2026-01-01', null, true]],
        'Pool & Jacuzzi' => [['per_event', 'Pool party hire', 2500, '2026-01-01', null, true]],
    ];

    // start, end, reason.
    private const BLACKOUTS = [
        'The Villa' => [
            ['2026-12-24', '2026-12-26', 'Family use'],
            ['2027-02-03', '2027-02-05', 'Pool re-tiling'],
        ],
        'Pool Room' => [['2027-02-03', '2027-02-05', 'Pool re-tiling']],
        'Glass Pangola' => [['2026-09-01', '2026-09-07', 'Glass repairs']],
        'Pool & Jacuzzi' => [['2027-02-03', '2027-02-05', 'Pool re-tiling']],
    ];

    // name => price, valid from.
    private const AMENITIES = [
        'Braai pack' => [120, '2026-01-01'],
        'Extra bedding' => [240, '2026-03-01'],
        'Late check-out' => [400, '2026-06-01'],
    ];

    // Mocks already use up to GGG-2026-0448, GGG-Q-0034, and GGG-TKT-2026-0903.
    // kind => the next number after those.
    private const FIRST_FREE_NUMBERS = [
        'booking' => 449,
        'quotation' => 35,
        'ticket_order' => 904,
    ];

    // Main photo per space. Files live in the frontend public/photos.
    private const PHOTOS = [
        'The Villa' => '/photos/villa.jpg',
        'Garden Room' => '/photos/room1.jpg',
        'Mountain Room' => '/photos/room2.jpg',
        'Pool Room' => '/photos/pool.jpg',
        'Family Suite' => '/photos/lounge.jpg',
        'Rose Room' => '/photos/room2.jpg',
        'Jacaranda Room' => '/photos/room1.jpg',
        'Protea Room' => '/photos/room2.jpg',
        'Acacia Room' => '/photos/room1.jpg',
        'Open Garden' => '/photos/garden.jpg',
        'Marquee Tent' => '/photos/marquee.jpg',
        'Glass Pangola' => '/photos/hall.jpg',
        'Pool & Jacuzzi' => '/photos/pool.jpg',
    ];

    public static function run(): void
    {
        self::seedVillaRooms();
        self::seedRates();
        self::seedBlackouts();
        self::seedAmenities();
        self::moveReferenceCounters();
        self::seedPhotos();
    }

    private static function seedVillaRooms(): void
    {
        $villa = self::spaceNamed('The Villa');

        foreach (self::VILLA_ROOMS as $name => $capacity) {
            if (self::countRows('SELECT COUNT(*) FROM space WHERE sp_name = ?', $name) > 0) {
                continue; // already seeded
            }

            $insert = Db::pdo()->prepare(
                'INSERT INTO space (sp_v_id, sp_parent_id, sp_name, sp_type, sp_capacity, sp_is_accommodation, sp_is_hireable)
                 VALUES (?, ?, ?, ?, ?, 1, 0)'
            );
            $insert->execute([$villa['sp_v_id'], $villa['sp_id'], $name, 'room', $capacity]);
        }
    }

    private static function seedRates(): void
    {
        foreach (self::RATES as $spaceName => $rates) {
            $space = self::spaceNamed($spaceName);

            // Skip spaces that already have prices, so prices changed by staff are kept
            if (self::countRows('SELECT COUNT(*) FROM space_rate WHERE sr_sp_id = ?', $space['sp_id']) > 0) {
                continue;
            }

            foreach ($rates as $rate) {
                $insert = Db::pdo()->prepare(
                    'INSERT INTO space_rate (sr_sp_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to, sr_is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                // Bool stored as 1 or 0.
                $insert->execute([
                    $space['sp_id'], $rate[0], $rate[1], $rate[2], $rate[3], $rate[4], (int) $rate[5],
                ]);
            }
        }
    }

    private static function seedBlackouts(): void
    {
        foreach (self::BLACKOUTS as $spaceName => $blackouts) {
            $space = self::spaceNamed($spaceName);

            // Skip spaces that already have blackouts
            if (self::countRows('SELECT COUNT(*) FROM space_blackout WHERE sbk_sp_id = ?', $space['sp_id']) > 0) {
                continue;
            }

            foreach ($blackouts as $blackout) {
                $insert = Db::pdo()->prepare(
                    'INSERT INTO space_blackout (sbk_sp_id, sbk_start_date, sbk_end_date, sbk_reason) VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$space['sp_id'], $blackout[0], $blackout[1], $blackout[2]]);
            }
        }
    }

    private static function seedAmenities(): void
    {
        foreach (self::AMENITIES as $name => $amenity) {
            if (self::countRows('SELECT COUNT(*) FROM amenity WHERE a_name = ?', $name) > 0) {
                continue; // already seeded
            }

            $insert = Db::pdo()->prepare('INSERT INTO amenity (a_name) VALUES (?)');
            $insert->execute([$name]);
            $amenityId = (int) Db::pdo()->lastInsertId();

            $insertRate = Db::pdo()->prepare('INSERT INTO amenity_rate (ar_a_id, ar_price, ar_valid_from) VALUES (?, ?, ?)');
            $insertRate->execute([$amenityId, $amenity[0], $amenity[1]]);
        }
    }

    // GREATEST only ever moves a counter forward, so seeding again never reuses a reference
    private static function moveReferenceCounters(): void
    {
        foreach (self::FIRST_FREE_NUMBERS as $kind => $number) {
            $update = Db::pdo()->prepare('UPDATE reference_sequence SET rs_next = GREATEST(rs_next, ?) WHERE rs_kind = ?');
            $update->execute([$number, $kind]);
        }
    }

    private static function seedPhotos(): void
    {
        foreach (self::PHOTOS as $spaceName => $url) {
            $space = self::spaceNamed($spaceName);

            // Skip spaces that already have a main photo
            $sql = "SELECT COUNT(*) FROM media WHERE m_owner_type = 'space' AND m_owner_id = ? AND m_is_primary = 1";
            if (self::countRows($sql, $space['sp_id']) > 0) {
                continue;
            }

            $insert = Db::pdo()->prepare("INSERT INTO media (m_owner_type, m_owner_id, m_url, m_alt_text, m_is_primary) VALUES ('space', ?, ?, ?, 1)");
            $insert->execute([$space['sp_id'], $url, $spaceName]);
        }
    }

    // Stops the seed if the space is missing.
    private static function spaceNamed(string $name): array
    {
        $statement = Db::pdo()->prepare('SELECT sp_id, sp_v_id FROM space WHERE sp_name = ?');
        $statement->execute([$name]);
        $space = $statement->fetch();
        if ($space === false) {
            throw new RuntimeException($name . ' is missing from the space table');
        }

        return $space;
    }

    private static function countRows(string $sql, mixed $value): int
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$value]);

        return (int) $statement->fetchColumn();
    }
}
