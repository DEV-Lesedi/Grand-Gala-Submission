<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Next number: GGG-2026-0449, GGG-Q-0035, or GGG-TKT-2026-0001.
 * Call inside a transaction so a failed insert gives the number back.
 */
class References
{
    public static function next(string $kind): string
    {
        if ($kind !== 'booking' && $kind !== 'quotation' && $kind !== 'ticket_order') {
            throw new HttpError(422, 'Unknown reference kind');
        }

        return Db::transaction(function () use ($kind) {
            $pdo = Db::pdo();

            // FOR UPDATE locks the counter row, so two requests never get the same number
            $select = $pdo->prepare('SELECT rs_year, rs_next FROM reference_sequence WHERE rs_kind = ? FOR UPDATE');
            $select->execute([$kind]);
            $row = $select->fetch();
            if ($row === false) {
                throw new HttpError(500, 'The reference counter for ' . $kind . ' is missing');
            }

            $year = (int) $row['rs_year'];
            $number = (int) $row['rs_next'];

            // Bookings and tickets restart each year. Quotation numbers never do.
            $thisYear = (int) substr(Clock::today(), 0, 4);
            if ($kind !== 'quotation' && $year !== $thisYear) {
                $year = $thisYear;
                $number = 1;
            }

            $update = $pdo->prepare('UPDATE reference_sequence SET rs_year = ?, rs_next = ? WHERE rs_kind = ?');
            $update->execute([$year, $number + 1, $kind]);

            return self::format($kind, $year, $number);
        });
    }

    // format('booking', 2026, 449) gives GGG-2026-0449. Numbers are padded to 4 digits.
    private static function format(string $kind, int $year, int $number): string
    {
        $padded = str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        if ($kind === 'booking') {
            return 'GGG-' . $year . '-' . $padded;
        }
        if ($kind === 'quotation') {
            return 'GGG-Q-' . $padded;
        }

        return 'GGG-TKT-' . $year . '-' . $padded;
    }
}
