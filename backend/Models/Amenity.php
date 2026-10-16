<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

class Amenity
{
    // Extras with no price on $date are left out.
    public static function activeWithPriceOn(string $date): array
    {
        // Oldest price first. The loop below needs this order.
        $statement = Db::pdo()->prepare(
            'SELECT a.a_id, a.a_name, ar.ar_price
             FROM amenity a
             JOIN amenity_rate ar ON ar.ar_a_id = a.a_id
             WHERE a.a_is_active = 1
               AND ar.ar_is_active = 1
               AND ar.ar_valid_from <= ?
               AND (ar.ar_valid_to IS NULL OR ar.ar_valid_to >= ?)
             ORDER BY a.a_id, ar.ar_valid_from, ar.ar_id'
        );
        $statement->execute([$date, $date]);

        // Each row overwrites the one before, so the newest price that has started wins
        $amenities = [];
        foreach ($statement->fetchAll() as $row) {
            $amenityId = (int) $row['a_id'];
            $amenities[$amenityId] = ['id' => $amenityId, 'name' => $row['a_name'], 'price' => (float) $row['ar_price']];
        }

        return array_values($amenities);
    }

    // price and priceFrom are null when there is no price that day.
    public static function allWithPriceOn(string $date): array
    {
        // LEFT JOIN keeps rows with no price. Oldest first: the loop needs that order.
        $statement = Db::pdo()->prepare(
            'SELECT a.a_id, a.a_name, a.a_is_active, ar.ar_price, ar.ar_valid_from
             FROM amenity a
             LEFT JOIN amenity_rate ar ON ar.ar_a_id = a.a_id
                AND ar.ar_is_active = 1
                AND ar.ar_valid_from <= ?
                AND (ar.ar_valid_to IS NULL OR ar.ar_valid_to >= ?)
             ORDER BY a.a_id, ar.ar_valid_from, ar.ar_id'
        );
        $statement->execute([$date, $date]);

        // Each row overwrites the one before, so the newest price that has started wins
        $amenities = [];
        foreach ($statement->fetchAll() as $row) {
            $amenityId = (int) $row['a_id'];
            $price = null;
            if ($row['ar_price'] !== null) {
                $price = (float) $row['ar_price'];
            }
            $amenities[$amenityId] = [
                'id' => $amenityId,
                'name' => $row['a_name'],
                'isActive' => $row['a_is_active'] === 1,
                'price' => $price,
                'priceFrom' => $row['ar_valid_from'],
            ];
        }

        return array_values($amenities);
    }

    // Searches the full list; there are only a handful of amenities.
    public static function findWithPriceOn(int $amenityId, string $date): ?array
    {
        foreach (self::allWithPriceOn($date) as $amenity) {
            if ($amenity['id'] === $amenityId) {
                return $amenity;
            }
        }

        return null;
    }

    public static function insert(string $name): int
    {
        $statement = Db::pdo()->prepare('INSERT INTO amenity (a_name) VALUES (?)');
        $statement->execute([$name]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Older prices stay as history; the newest one that has started wins.
    public static function addPrice(int $amenityId, float $price, string $validFrom): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO amenity_rate (ar_a_id, ar_price, ar_valid_from) VALUES (?, ?, ?)');
        $statement->execute([$amenityId, $price, $validFrom]);
    }

    public static function update(int $amenityId, string $name, bool $isActive): void
    {
        $statement = Db::pdo()->prepare('UPDATE amenity SET a_name = ?, a_is_active = ? WHERE a_id = ?');
        $statement->execute([$name, (int) $isActive, $amenityId]);
    }
}
