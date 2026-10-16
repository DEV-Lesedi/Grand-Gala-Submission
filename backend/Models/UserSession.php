<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Services\Clock;
use GrandGala\Utils\Db;

// The raw JWT is never stored. Lookups use sha256 of the token.
class UserSession
{
    public static function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $insert = Db::pdo()->prepare(
            'INSERT INTO user_session (us_u_id, us_token_hash, us_expires_at) VALUES (?, ?, ?)'
        );
        $insert->execute([$userId, $tokenHash, $expiresAt]);

        return (int) Db::pdo()->lastInsertId();
    }

    // null if the token is unknown, revoked, expired, or the account is switched off.
    public static function userFor(string $tokenHash): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT u.u_id, u.u_name, u.u_surname, u.u_email, u.u_phone, ut.ut_name
             FROM user_session s
             JOIN app_user u ON u.u_id = s.us_u_id
             JOIN user_type ut ON ut.ut_id = u.u_ut_id
             WHERE s.us_token_hash = ?
               AND s.us_revoked_at IS NULL
               AND s.us_expires_at > ?
               AND u.u_is_active = 1'
        );
        // Clock::now(), not SQL NOW(): expiry was computed from Clock, so frozen tests agree.
        $statement->execute([$tokenHash, Clock::now()]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['u_id'],
            'name' => $row['u_name'],
            'surname' => $row['u_surname'],
            'email' => $row['u_email'],
            'phone' => $row['u_phone'],
            'role' => $row['ut_name'],
        ];
    }

    // Safe to call with an unknown hash: it matches nothing.
    public static function revoke(string $tokenHash): void
    {
        $update = Db::pdo()->prepare('UPDATE user_session SET us_revoked_at = NOW() WHERE us_token_hash = ? AND us_revoked_at IS NULL');
        $update->execute([$tokenHash]);
    }

    // Used when an account is switched off or its role changes, so old devices stop working.
    public static function revokeAllForUser(int $userId): void
    {
        $update = Db::pdo()->prepare('UPDATE user_session SET us_revoked_at = NOW() WHERE us_u_id = ? AND us_revoked_at IS NULL');
        $update->execute([$userId]);
    }

    // Keeps the session that just made the change, so that tab stays signed in.
    public static function revokeAllForUserExcept(int $userId, string $exceptTokenHash): void
    {
        $update = Db::pdo()->prepare(
            'UPDATE user_session SET us_revoked_at = NOW()
             WHERE us_u_id = ? AND us_token_hash <> ? AND us_revoked_at IS NULL'
        );
        $update->execute([$userId, $exceptTokenHash]);
    }
}
