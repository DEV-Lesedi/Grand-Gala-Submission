<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* One row per line. Amount is quantity times unit price, computed on read, never stored.
 * A space id is set when the line prices a space or room, otherwise null.
 */
class QuotationItem
{
    public static function insert(int $quotationId, int $position, string $label, float $quantity, float $unitPrice, ?int $spaceId): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO quotation_item (qi_q_id, qi_sp_id, qi_position, qi_label, qi_quantity, qi_unit_price) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$quotationId, $spaceId, $position, $label, $quantity, $unitPrice]);
    }

    public static function forQuotation(int $quotationId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT qi_position, qi_label, qi_quantity, qi_unit_price, qi_sp_id FROM quotation_item WHERE qi_q_id = ? ORDER BY qi_position'
        );
        $statement->execute([$quotationId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $spaceId = null;
            if ($row['qi_sp_id'] !== null) {
                $spaceId = (int) $row['qi_sp_id'];
            }
            $items[] = [
                'position' => (int) $row['qi_position'],
                'label' => $row['qi_label'],
                'quantity' => (float) $row['qi_quantity'],
                'unitPrice' => (float) $row['qi_unit_price'],
                'spaceId' => $spaceId,
            ];
        }

        return $items;
    }
}
