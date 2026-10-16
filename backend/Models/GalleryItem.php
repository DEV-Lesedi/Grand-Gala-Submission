<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

class GalleryItem
{
    // Lowercase database category to the chip label the frontend filters by.
    private const CATEGORY_LABELS = [
        'villa' => 'The Villa',
        'venues' => 'Venues',
        'grounds' => 'Grounds',
        'events' => 'Events',
    ];

    // $category is a chip label, or null for all. Switched-off photos are left out.
    public static function active(?string $category): array
    {
        $sql = 'SELECT g_id, g_title, g_short_title, g_space_label, g_category, g_url, g_is_wide
                FROM gallery_item WHERE g_is_active = 1';
        $params = [];

        if ($category !== null) {
            $dbCategory = array_search($category, self::CATEGORY_LABELS, true);
            if ($dbCategory === false) {
                return []; // an unknown chip has no photos, rather than an error
            }
            $sql .= ' AND g_category = ?';
            $params[] = $dbCategory;
        }

        $sql .= ' ORDER BY g_sort_order, g_id';
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['g_id'],
                'title' => $row['g_title'],
                'shortTitle' => $row['g_short_title'],
                'space' => $row['g_space_label'],
                'category' => self::CATEGORY_LABELS[$row['g_category']],
                'src' => $row['g_url'],
                'wide' => $row['g_is_wide'] === 1,
            ];
        }

        return $items;
    }
}
