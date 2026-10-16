<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

// A url ending in .mp4 is a video. Only a photo can be the main one.
class Media
{
    private const SELECT = 'SELECT m_id, m_owner_type, m_owner_id, m_url, m_caption, m_alt_text, m_is_primary, m_sort_order FROM media';

    // Photos only; videos cannot be the main image.
    private const ONLY_PHOTOS = "LOWER(m_url) NOT LIKE '%.mp4'";

    // Spaces with no photo are left out.
    public static function primaryPhotosOfSpaces(array $spaceIds): array
    {
        if (count($spaceIds) === 0) {
            return [];
        }

        $marks = Db::questionMarks($spaceIds);
        $statement = Db::pdo()->prepare(
            "SELECT m_owner_id, m_url FROM media
             WHERE m_owner_type = 'space' AND m_is_primary = 1 AND m_owner_id IN ($marks)
             ORDER BY m_sort_order"
        );
        $statement->execute($spaceIds);

        // Sorted by m_sort_order; keep the first row for each space.
        $photos = [];
        foreach ($statement->fetchAll() as $row) {
            $spaceId = (int) $row['m_owner_id'];
            if (!isset($photos[$spaceId])) {
                $photos[$spaceId] = $row['m_url'];
            }
        }

        return $photos;
    }

    // One query for a card list. Owners with no main photo are left out.
    public static function primaryPhotosOf(string $ownerType, array $ownerIds): array
    {
        if (count($ownerIds) === 0) {
            return [];
        }

        $marks = Db::questionMarks($ownerIds);
        $statement = Db::pdo()->prepare(
            "SELECT m_owner_id, m_url FROM media
             WHERE m_owner_type = ? AND m_is_primary = 1 AND " . self::ONLY_PHOTOS . " AND m_owner_id IN ($marks)
             ORDER BY m_sort_order, m_id"
        );
        $parameters = array_merge([$ownerType], array_values($ownerIds));
        $statement->execute($parameters);

        // Keep the first row for each owner.
        $photos = [];
        foreach ($statement->fetchAll() as $row) {
            $ownerId = (int) $row['m_owner_id'];
            if (!isset($photos[$ownerId])) {
                $photos[$ownerId] = $row['m_url'];
            }
        }

        return $photos;
    }

    public static function find(int $mediaId): ?array
    {
        $statement = Db::pdo()->prepare(self::SELECT . ' WHERE m_id = ?');
        $statement->execute([$mediaId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toMedia($row);
    }

    public static function forOwner(string $ownerType, int $ownerId): array
    {
        $statement = Db::pdo()->prepare(
            self::SELECT . ' WHERE m_owner_type = ? AND m_owner_id = ? ORDER BY m_is_primary DESC, m_sort_order, m_id'
        );
        $statement->execute([$ownerType, $ownerId]);

        $list = [];
        foreach ($statement->fetchAll() as $row) {
            $list[] = self::toMedia($row);
        }

        return $list;
    }

    // Locked until the transaction ends, so two staff cannot both set the main photo.
    public static function lockOwner(string $ownerType, int $ownerId): void
    {
        $statement = Db::pdo()->prepare('SELECT m_id FROM media WHERE m_owner_type = ? AND m_owner_id = ? FOR UPDATE');
        $statement->execute([$ownerType, $ownerId]);
    }

    public static function hasPrimary(string $ownerType, int $ownerId): bool
    {
        $statement = Db::pdo()->prepare('SELECT COUNT(*) FROM media WHERE m_owner_type = ? AND m_owner_id = ? AND m_is_primary = 1');
        $statement->execute([$ownerType, $ownerId]);

        return (int) $statement->fetchColumn() > 0;
    }

    // One past the owner's last item, so a new upload goes at the end.
    public static function nextSortOrder(string $ownerType, int $ownerId): int
    {
        $statement = Db::pdo()->prepare('SELECT MAX(m_sort_order) FROM media WHERE m_owner_type = ? AND m_owner_id = ?');
        $statement->execute([$ownerType, $ownerId]);
        $last = $statement->fetchColumn();
        if ($last === null) {
            return 0;
        }

        return (int) $last + 1;
    }

    public static function insert(string $ownerType, int $ownerId, string $url, ?string $caption, ?string $altText, bool $isPrimary, int $sortOrder): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO media (m_owner_type, m_owner_id, m_url, m_caption, m_alt_text, m_is_primary, m_sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$ownerType, $ownerId, $url, $caption, $altText, (int) $isPrimary, $sortOrder]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Clear the main mark before giving it to another photo.
    public static function clearPrimary(string $ownerType, int $ownerId): void
    {
        $statement = Db::pdo()->prepare('UPDATE media SET m_is_primary = 0 WHERE m_owner_type = ? AND m_owner_id = ?');
        $statement->execute([$ownerType, $ownerId]);
    }

    // $media uses camelCase API names.
    public static function update(array $media): void
    {
        $statement = Db::pdo()->prepare(
            'UPDATE media SET m_caption = ?, m_alt_text = ?, m_is_primary = ?, m_sort_order = ? WHERE m_id = ?'
        );
        $statement->execute([$media['caption'], $media['altText'], (int) $media['isPrimary'], $media['sortOrder'], $media['id']]);
    }

    public static function delete(int $mediaId): void
    {
        $statement = Db::pdo()->prepare('DELETE FROM media WHERE m_id = ?');
        $statement->execute([$mediaId]);
    }

    // Videos are skipped. null if the owner has no photo.
    public static function firstPhotoOf(string $ownerType, int $ownerId): ?int
    {
        $statement = Db::pdo()->prepare(
            'SELECT m_id FROM media WHERE m_owner_type = ? AND m_owner_id = ? AND ' . self::ONLY_PHOTOS . '
             ORDER BY m_sort_order, m_id LIMIT 1'
        );
        $statement->execute([$ownerType, $ownerId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    // Call clearPrimary() first so only one photo is main.
    public static function markPrimary(int $mediaId): void
    {
        $statement = Db::pdo()->prepare('UPDATE media SET m_is_primary = 1 WHERE m_id = ?');
        $statement->execute([$mediaId]);
    }

    // Only .mp4 counts as a video.
    public static function isVideoUrl(string $url): bool
    {
        return str_ends_with(strtolower($url), '.mp4');
    }

    private static function toMedia(array $row): array
    {
        $type = 'photo';
        if (self::isVideoUrl($row['m_url'])) {
            $type = 'video';
        }

        return [
            'id' => (int) $row['m_id'],
            'ownerType' => $row['m_owner_type'],
            'ownerId' => (int) $row['m_owner_id'],
            'url' => $row['m_url'],
            'type' => $type,
            'caption' => $row['m_caption'],
            'altText' => $row['m_alt_text'],
            'isPrimary' => (int) $row['m_is_primary'] === 1,
            'sortOrder' => (int) $row['m_sort_order'],
        ];
    }
}
