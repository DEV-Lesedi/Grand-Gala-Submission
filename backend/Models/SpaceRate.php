<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

class SpaceRate
{
    // One basis, such as per_night, oldest first.
    public static function forSpaceBasis(int $spaceId, string $basis): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sr_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to, sr_is_active, sr_closed_for
             FROM space_rate
             WHERE sr_sp_id = ? AND sr_basis = ?
             ORDER BY sr_id'
        );
        $statement->execute([$spaceId, $basis]);

        $rates = [];
        foreach ($statement->fetchAll() as $row) {
            $rates[] = self::toRate($row);
        }

        return $rates;
    }

    // Switched-on prices, grouped by space id, oldest first.
    public static function activeForSpaces(array $spaceIds): array
    {
        $active = [];
        foreach (self::allForSpaces($spaceIds) as $spaceId => $rates) {
            foreach ($rates as $rate) {
                if ($rate['isActive']) {
                    $active[$spaceId][] = $rate;
                }
            }
        }

        return $active;
    }

    // Includes switched-off prices. Spaces with none are left out.
    public static function allForSpaces(array $spaceIds): array
    {
        if (count($spaceIds) === 0) {
            return [];
        }

        $marks = Db::questionMarks($spaceIds);
        $statement = Db::pdo()->prepare(
            "SELECT sr_sp_id, sr_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to, sr_is_active, sr_closed_for
             FROM space_rate
             WHERE sr_sp_id IN ($marks)
             ORDER BY sr_id"
        );
        $statement->execute($spaceIds);

        $ratesBySpace = [];
        foreach ($statement->fetchAll() as $row) {
            $ratesBySpace[(int) $row['sr_sp_id']][] = self::toRate($row);
        }

        return $ratesBySpace;
    }

    // Switched on, and applies on $date or later (already running or starting later).
    public static function hasPriceFrom(int $spaceId, string $date): bool
    {
        $statement = Db::pdo()->prepare(
            'SELECT sr_id FROM space_rate
             WHERE sr_sp_id = ? AND sr_is_active = 1 AND (sr_valid_to IS NULL OR sr_valid_to >= ?)
             LIMIT 1'
        );
        $statement->execute([$spaceId, $date]);

        return $statement->fetch() !== false;
    }

    // $lock adds FOR UPDATE. Call inside a transaction before changing the price.
    public static function find(int $rateId, bool $lock = false): ?array
    {
        $sql = 'SELECT sr_sp_id, sr_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to, sr_is_active, sr_closed_for
                FROM space_rate WHERE sr_id = ?';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$rateId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $rate = self::toRate($row);
        $rate['spaceId'] = (int) $row['sr_sp_id'];

        return $rate;
    }

    // Open-ended normal prices, locked, because a new normal price closes them.
    public static function openNormal(int $spaceId, string $basis): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sr_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to, sr_is_active, sr_closed_for
             FROM space_rate
             WHERE sr_sp_id = ? AND sr_basis = ? AND sr_is_active = 1 AND sr_valid_to IS NULL
             FOR UPDATE'
        );
        $statement->execute([$spaceId, $basis]);

        $rates = [];
        foreach ($statement->fetchAll() as $row) {
            $rates[] = self::toRate($row);
        }

        return $rates;
    }

    // Switched-on seasonal prices that overlap $from to $to.
    public static function seasonsOverlapping(int $spaceId, string $basis, string $from, string $to): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sr_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to, sr_is_active, sr_closed_for
             FROM space_rate
             WHERE sr_sp_id = ? AND sr_basis = ? AND sr_is_active = 1
               AND sr_valid_to IS NOT NULL AND sr_closed_for IS NULL
               AND sr_valid_from <= ? AND sr_valid_to >= ?
             ORDER BY sr_id'
        );
        $statement->execute([$spaceId, $basis, $to, $from]);

        $rates = [];
        foreach ($statement->fetchAll() as $row) {
            $rates[] = self::toRate($row);
        }

        return $rates;
    }

    // $validTo is null for a normal price.
    public static function insert(int $spaceId, string $basis, string $label, float $price, string $validFrom, ?string $validTo): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO space_rate (sr_sp_id, sr_basis, sr_label, sr_price, sr_valid_from, sr_valid_to)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$spaceId, $basis, $label, $price, $validFrom, $validTo]);

        return (int) Db::pdo()->lastInsertId();
    }

    // closedFor records which new price ended this one, so a cancel can reopen it.
    public static function closeFor(int $rateId, string $validTo, int $newRateId): void
    {
        $statement = Db::pdo()->prepare('UPDATE space_rate SET sr_valid_to = ?, sr_closed_for = ? WHERE sr_id = ?');
        $statement->execute([$validTo, $newRateId, $rateId]);
    }

    // The price that $cancelledRateId closed has no end date again.
    public static function reopenClosedBy(int $cancelledRateId): void
    {
        $statement = Db::pdo()->prepare('UPDATE space_rate SET sr_valid_to = NULL, sr_closed_for = NULL WHERE sr_closed_for = ?');
        $statement->execute([$cancelledRateId]);
    }

    public static function endOn(int $rateId, string $validTo): void
    {
        $statement = Db::pdo()->prepare('UPDATE space_rate SET sr_valid_to = ? WHERE sr_id = ?');
        $statement->execute([$validTo, $rateId]);
    }

    public static function switchOff(int $rateId): void
    {
        $statement = Db::pdo()->prepare('UPDATE space_rate SET sr_is_active = 0 WHERE sr_id = ?');
        $statement->execute([$rateId]);
    }

    private static function toRate(array $row): array
    {
        $closedFor = null;
        if ($row['sr_closed_for'] !== null) {
            $closedFor = (int) $row['sr_closed_for'];
        }

        return [
            'id' => (int) $row['sr_id'],
            'basis' => $row['sr_basis'],
            'label' => (string) $row['sr_label'],
            'price' => (float) $row['sr_price'],
            'validFrom' => $row['sr_valid_from'],
            'validTo' => $row['sr_valid_to'],
            'isActive' => (int) $row['sr_is_active'] === 1,
            'closedFor' => $closedFor,
        ];
    }
}
