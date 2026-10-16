<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

// Only a hash of the token is stored. The raw token lives only in the emailed link.
class PasswordReset
{
    public static function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $insert = Db::pdo()->prepare(
            'INSERT INTO password_reset (pr_u_id, pr_token_hash, pr_expires_at) VALUES (?, ?, ?)'
        );
        $insert->execute([$userId, $tokenHash, $expiresAt]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Still unused and unexpired, or null. Call inside a transaction.
    // A second tab waits, then sees the link as already used.
    public static function findUsable(string $tokenHash): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT pr_id, pr_u_id FROM password_reset
             WHERE pr_token_hash = ? AND pr_used_at IS NULL AND pr_expires_at > NOW()
             FOR UPDATE'
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['pr_id'], 'userId' => (int) $row['pr_u_id']];
    }

    // The same link cannot be used twice.
    public static function markUsed(int $id): void
    {
        $update = Db::pdo()->prepare('UPDATE password_reset SET pr_used_at = NOW() WHERE pr_id = ?');
        $update->execute([$id]);
    }
}
