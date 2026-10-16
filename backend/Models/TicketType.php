<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* A tier has its own price, allocation, per-order limit, and sale window. */
class TicketType
{
    // $lock holds them. Lock the venue and the event first.
    public static function forEvent(int $eventId, bool $lock = false): array
    {
        $sql = 'SELECT tt_id, tt_eb_id, tt_name, tt_description, tt_price, tt_quantity_allocated, tt_max_per_order,
                       tt_sales_open_at, tt_sales_close_at, tt_is_active
                FROM ticket_type WHERE tt_eb_id = ? ORDER BY tt_id';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$eventId]);

        $tiers = [];
        foreach ($statement->fetchAll() as $row) {
            $tiers[] = self::toTier($row);
        }

        return $tiers;
    }

    // $tier uses the names from toTier().
    public static function insert(int $eventId, array $tier): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO ticket_type (tt_eb_id, tt_name, tt_description, tt_price, tt_quantity_allocated, tt_max_per_order,
                                      tt_sales_open_at, tt_sales_close_at, tt_is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $eventId,
            $tier['name'],
            $tier['description'],
            $tier['price'],
            $tier['allocation'],
            $tier['maxPerOrder'],
            $tier['salesOpen'],
            $tier['salesClose'],
            (int) $tier['isActive'], // true/false becomes 1/0
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Orders already placed keep the price they were sold at.
    public static function update(int $tierId, array $tier): void
    {
        $statement = Db::pdo()->prepare(
            'UPDATE ticket_type
             SET tt_name = ?, tt_description = ?, tt_price = ?, tt_quantity_allocated = ?, tt_max_per_order = ?,
                 tt_sales_open_at = ?, tt_sales_close_at = ?, tt_is_active = ?
             WHERE tt_id = ?'
        );
        $statement->execute([
            $tier['name'],
            $tier['description'],
            $tier['price'],
            $tier['allocation'],
            $tier['maxPerOrder'],
            $tier['salesOpen'],
            $tier['salesClose'],
            (int) $tier['isActive'],
            $tierId,
        ]);
    }

    // Sale times stay 'YYYY-MM-DD HH:MM:SS' so they compare with Clock::now().
    private static function toTier(array $row): array
    {
        return [
            'id' => (int) $row['tt_id'],
            'eventId' => (int) $row['tt_eb_id'],
            'name' => $row['tt_name'],
            'description' => $row['tt_description'],
            'price' => (float) $row['tt_price'],
            'allocation' => (int) $row['tt_quantity_allocated'],
            'maxPerOrder' => (int) $row['tt_max_per_order'],
            'salesOpen' => $row['tt_sales_open_at'],
            'salesClose' => $row['tt_sales_close_at'],
            'isActive' => (int) $row['tt_is_active'] === 1,
        ];
    }
}
