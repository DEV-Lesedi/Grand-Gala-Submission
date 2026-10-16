<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* One row per bucket, counting hits since the window started.
 * Uses the database NOW(), not Clock, because tests freeze Clock.
 */
class RateLimit
{
    // Starts again at 1 when the window is older than $windowSeconds.
    // One statement, so two requests cannot both read the same count.
    public static function hit(string $bucket, int $windowSeconds): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO api_rate_limit (rl_bucket, rl_window_started_at, rl_hits)
             VALUES (?, NOW(), 1)
             ON DUPLICATE KEY UPDATE
               rl_hits = IF(rl_window_started_at <= NOW() - INTERVAL ? SECOND, 1, rl_hits + 1),
               rl_window_started_at = IF(rl_window_started_at <= NOW() - INTERVAL ? SECOND, NOW(), rl_window_started_at)'
        );
        $statement->execute([$bucket, $windowSeconds, $windowSeconds]);

        $read = Db::pdo()->prepare('SELECT rl_hits FROM api_rate_limit WHERE rl_bucket = ?');
        $read->execute([$bucket]);

        return (int) $read->fetchColumn();
    }

    // Hits start again from nothing.
    public static function forget(string $bucket): void
    {
        $statement = Db::pdo()->prepare('DELETE FROM api_rate_limit WHERE rl_bucket = ?');
        $statement->execute([$bucket]);
    }

    // Buckets unused for a day. Returns how many were deleted.
    public static function prune(): int
    {
        return Db::pdo()->exec('DELETE FROM api_rate_limit WHERE rl_window_started_at < NOW() - INTERVAL 1 DAY');
    }
}
