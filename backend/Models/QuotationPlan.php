<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Spaces for the event, and rooms for its guests. Date and times live on the quotation.
 * Each version has its own plan.
 */
class QuotationPlan
{
    public static function insertSpace(int $quotationId, int $spaceId): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO quotation_space (qs_q_id, qs_sp_id) VALUES (?, ?)');
        $statement->execute([$quotationId, $spaceId]);
    }

    public static function insertRoom(int $quotationId, int $spaceId, string $checkIn, string $checkOut): void
    {
        $statement = Db::pdo()->prepare('INSERT INTO quotation_room (qr_q_id, qr_sp_id, qr_check_in, qr_check_out) VALUES (?, ?, ?, ?)');
        $statement->execute([$quotationId, $spaceId, $checkIn, $checkOut]);
    }

    // In the order staff gave them.
    public static function spacesOf(int $quotationId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sp.sp_id, sp.sp_name FROM quotation_space qs
             JOIN space sp ON sp.sp_id = qs.qs_sp_id
             WHERE qs.qs_q_id = ?
             ORDER BY qs.qs_id'
        );
        $statement->execute([$quotationId]);

        $spaces = [];
        foreach ($statement->fetchAll() as $row) {
            $spaces[] = ['id' => (int) $row['sp_id'], 'name' => $row['sp_name']];
        }

        return $spaces;
    }

    // In the order staff gave them.
    public static function roomsOf(int $quotationId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT qr.qr_sp_id, sp.sp_name, qr.qr_check_in, qr.qr_check_out FROM quotation_room qr
             JOIN space sp ON sp.sp_id = qr.qr_sp_id
             WHERE qr.qr_q_id = ?
             ORDER BY qr.qr_id'
        );
        $statement->execute([$quotationId]);

        $rooms = [];
        foreach ($statement->fetchAll() as $row) {
            $rooms[] = [
                'spaceId' => (int) $row['qr_sp_id'],
                'space' => $row['sp_name'],
                'checkIn' => $row['qr_check_in'],
                'checkOut' => $row['qr_check_out'],
            ];
        }

        return $rooms;
    }
}
