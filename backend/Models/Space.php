<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

class Space
{
    // Visible when the space is on, and so is its villa, if it has one.
    public static function allActive(): array
    {
        $rows = Db::pdo()->query(
            'SELECT sp.sp_id, sp.sp_parent_id, sp.sp_name, sp.sp_type, sp.sp_description, sp.sp_capacity,
                    sp.sp_is_accommodation, sp.sp_is_hireable
             FROM space sp
             LEFT JOIN space parent ON parent.sp_id = sp.sp_parent_id
             WHERE sp.sp_is_active = 1 AND (parent.sp_id IS NULL OR parent.sp_is_active = 1)
             ORDER BY sp.sp_id'
        )->fetchAll();

        $spaces = [];
        foreach ($rows as $row) {
            $spaces[] = self::toSpace($row);
        }

        return $spaces;
    }

    // Same visibility rule as allActive(). null if hidden or missing.
    public static function findActive(int $spaceId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sp.sp_id, sp.sp_parent_id, sp.sp_name, sp.sp_type, sp.sp_description, sp.sp_capacity,
                    sp.sp_is_accommodation, sp.sp_is_hireable
             FROM space sp
             LEFT JOIN space parent ON parent.sp_id = sp.sp_parent_id
             WHERE sp.sp_id = ? AND sp.sp_is_active = 1 AND (parent.sp_id IS NULL OR parent.sp_is_active = 1)'
        );
        $statement->execute([$spaceId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toSpace($row);
    }

    // Includes switched-off spaces. null if the space does not exist.
    public static function venueAndKind(int $spaceId): ?array
    {
        $statement = Db::pdo()->prepare('SELECT sp_v_id, sp_is_accommodation FROM space WHERE sp_id = ?');
        $statement->execute([$spaceId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['venueId' => (int) $row['sp_v_id'], 'isAccommodation' => $row['sp_is_accommodation'] === 1];
    }

    // Bookings at one venue are checked one at a time. Call inside a transaction.
    public static function lockVenue(int $venueId): void
    {
        $statement = Db::pdo()->prepare('SELECT v_id FROM venue WHERE v_id = ? FOR UPDATE');
        $statement->execute([$venueId]);
    }

    // Returns false if the space does not exist. Call inside a transaction.
    public static function lock(int $spaceId): bool
    {
        $statement = Db::pdo()->prepare('SELECT sp_id FROM space WHERE sp_id = ? FOR UPDATE');
        $statement->execute([$spaceId]);

        return $statement->fetch() !== false;
    }

    // The space itself, its villa, and its rooms. Two rooms in one villa do not block each other.
    public static function linkedIds(int $spaceId): array
    {
        // Itself, its rooms, or its villa (the subquery finds the villa id).
        $statement = Db::pdo()->prepare(
            'SELECT sp_id FROM space
             WHERE sp_id = ?
                OR sp_parent_id = ?
                OR sp_id = (SELECT sp_parent_id FROM space WHERE sp_id = ?)'
        );
        $statement->execute([$spaceId, $spaceId, $spaceId]);

        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $ids[] = (int) $row['sp_id'];
        }

        return $ids;
    }

    public static function activeRoomsOf(int $spaceId): array
    {
        $statement = Db::pdo()->prepare('SELECT sp_id, sp_name FROM space WHERE sp_parent_id = ? AND sp_is_active = 1 ORDER BY sp_id');
        $statement->execute([$spaceId]);

        $rooms = [];
        foreach ($statement->fetchAll() as $row) {
            $rooms[] = ['id' => (int) $row['sp_id'], 'name' => $row['sp_name']];
        }

        return $rooms;
    }

    // Includes switched-off spaces. Same shape as toSpace(), plus venueId and isActive.
    public static function allForStaff(): array
    {
        $rows = Db::pdo()->query(
            'SELECT sp_id, sp_v_id, sp_parent_id, sp_name, sp_type, sp_description, sp_capacity,
                    sp_is_accommodation, sp_is_hireable, sp_is_active
             FROM space ORDER BY sp_id'
        )->fetchAll();

        $spaces = [];
        foreach ($rows as $row) {
            $spaces[] = self::toStaffSpace($row);
        }

        return $spaces;
    }

    // Includes switched-off spaces. null if missing.
    public static function findForStaff(int $spaceId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT sp_id, sp_v_id, sp_parent_id, sp_name, sp_type, sp_description, sp_capacity,
                    sp_is_accommodation, sp_is_hireable, sp_is_active
             FROM space WHERE sp_id = ?'
        );
        $statement->execute([$spaceId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toStaffSpace($row);
    }

    // Saved switched off. $space uses camelCase API names.
    public static function insert(array $space): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO space (sp_v_id, sp_parent_id, sp_name, sp_type, sp_description, sp_capacity,
                                sp_is_accommodation, sp_is_hireable, sp_is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $statement->execute([
            $space['venueId'],
            $space['parentId'],
            $space['name'],
            $space['type'],
            $space['description'],
            $space['capacity'],
            (int) $space['isAccommodation'], // true/false becomes 1/0
            (int) $space['isHireable'],
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // The venue and villa never change.
    public static function update(array $space): void
    {
        $statement = Db::pdo()->prepare(
            'UPDATE space
             SET sp_name = ?, sp_type = ?, sp_description = ?, sp_capacity = ?,
                 sp_is_accommodation = ?, sp_is_hireable = ?, sp_is_active = ?
             WHERE sp_id = ?'
        );
        $statement->execute([
            $space['name'],
            $space['type'],
            $space['description'],
            $space['capacity'],
            (int) $space['isAccommodation'],
            (int) $space['isHireable'],
            (int) $space['isActive'],
            $space['id'],
        ]);
    }

    public static function venueExists(int $venueId): bool
    {
        $statement = Db::pdo()->prepare('SELECT v_id FROM venue WHERE v_id = ?');
        $statement->execute([$venueId]);

        return $statement->fetch() !== false;
    }

    private static function toStaffSpace(array $row): array
    {
        $space = self::toSpace($row);
        $space['venueId'] = (int) $row['sp_v_id'];
        $space['isActive'] = $row['sp_is_active'] === 1;

        return $space;
    }

    private static function toSpace(array $row): array
    {
        $parentId = null;
        if ($row['sp_parent_id'] !== null) {
            $parentId = (int) $row['sp_parent_id'];
        }

        return [
            'id' => (int) $row['sp_id'],
            'name' => $row['sp_name'],
            'type' => $row['sp_type'],
            'capacity' => (int) $row['sp_capacity'],
            'isAccommodation' => $row['sp_is_accommodation'] === 1,
            'isHireable' => $row['sp_is_hireable'] === 1,
            'parentId' => $parentId,
            'description' => $row['sp_description'],
        ];
    }
}
