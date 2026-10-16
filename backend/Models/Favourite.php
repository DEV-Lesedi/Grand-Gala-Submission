<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

class Favourite
{
    // Same shape as GET /api/spaces.
    public static function spacesOf(int $userId): array
    {
        $rows = Db::pdo()->prepare(
            'SELECT sp.sp_id, sp.sp_name, sp.sp_type, sp.sp_capacity, sp.sp_description,
                    sp.sp_is_accommodation, sp.sp_is_hireable
             FROM favourite f
             JOIN space sp ON sp.sp_id = f.f_sp_id
             WHERE f.f_u_id = ? AND sp.sp_is_active = 1
             ORDER BY f.f_date_created DESC'
        );
        $rows->execute([$userId]);

        $spaces = [];
        foreach ($rows->fetchAll() as $row) {
            $spaces[] = [
                'id' => (int) $row['sp_id'],
                'name' => $row['sp_name'],
                'type' => $row['sp_type'],
                'capacity' => (int) $row['sp_capacity'],
                'description' => $row['sp_description'],
                'isAccommodation' => $row['sp_is_accommodation'] === 1,
                'isHireable' => $row['sp_is_hireable'] === 1,
            ];
        }

        return $spaces;
    }

    public static function isSaved(int $userId, int $spaceId): bool
    {
        $statement = Db::pdo()->prepare('SELECT f_id FROM favourite WHERE f_u_id = ? AND f_sp_id = ?');
        $statement->execute([$userId, $spaceId]);

        return $statement->fetch() !== false;
    }

    // Saving twice is not an error. The unique key leaves the existing row.
    public static function add(int $userId, int $spaceId): void
    {
        $insert = Db::pdo()->prepare('INSERT IGNORE INTO favourite (f_u_id, f_sp_id) VALUES (?, ?)');
        $insert->execute([$userId, $spaceId]);
    }

    public static function remove(int $userId, int $spaceId): void
    {
        $delete = Db::pdo()->prepare('DELETE FROM favourite WHERE f_u_id = ? AND f_sp_id = ?');
        $delete->execute([$userId, $spaceId]);
    }
}
