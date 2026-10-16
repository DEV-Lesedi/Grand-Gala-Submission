<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

class Blackout
{
    // $lock adds FOR SHARE so a booking sees blackouts added a moment ago.
    public static function forSpacesBetween(array $spaceIds, string $from, string $to, bool $lock = false): array
    {
        $marks = Db::questionMarks($spaceIds);
        $sql = "SELECT sbk_start_date, sbk_end_date FROM space_blackout
                WHERE sbk_sp_id IN ($marks) AND sbk_start_date <= ? AND sbk_end_date >= ?";
        if ($lock) {
            $sql = $sql . ' FOR SHARE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute(array_merge($spaceIds, [$to, $from]));

        $blackouts = [];
        foreach ($statement->fetchAll() as $row) {
            $blackouts[] = ['start' => $row['sbk_start_date'], 'end' => $row['sbk_end_date']];
        }

        return $blackouts;
    }

    // Grouped by space id, soonest first. Spaces with none are left out.
    public static function forSpaces(array $spaceIds): array
    {
        if (count($spaceIds) === 0) {
            return [];
        }

        $marks = Db::questionMarks($spaceIds);
        $statement = Db::pdo()->prepare(
            "SELECT sbk_id, sbk_sp_id, sbk_start_date, sbk_end_date, sbk_reason FROM space_blackout
             WHERE sbk_sp_id IN ($marks)
             ORDER BY sbk_start_date, sbk_id"
        );
        $statement->execute($spaceIds);

        $bySpace = [];
        foreach ($statement->fetchAll() as $row) {
            $bySpace[(int) $row['sbk_sp_id']][] = [
                'id' => (int) $row['sbk_id'],
                'start' => $row['sbk_start_date'],
                'end' => $row['sbk_end_date'],
                'reason' => $row['sbk_reason'],
            ];
        }

        return $bySpace;
    }

    public static function insert(int $spaceId, string $start, string $end, string $reason): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO space_blackout (sbk_sp_id, sbk_start_date, sbk_end_date, sbk_reason) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$spaceId, $start, $end, $reason]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Returns false if the blackout did not exist.
    public static function delete(int $blackoutId): bool
    {
        $statement = Db::pdo()->prepare('DELETE FROM space_blackout WHERE sbk_id = ?');
        $statement->execute([$blackoutId]);

        return $statement->rowCount() === 1;
    }
}
