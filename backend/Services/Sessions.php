<?php

declare(strict_types=1);

namespace GrandGala\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GrandGala\Models\UserSession;
use GrandGala\Utils\HttpError;
use Throwable;

/*
 * JWT plus a session row, so sign-out can revoke a token that would still verify.
 * Only sha256(token) is stored, so a database copy is not enough to sign in.
 */
class Sessions
{
    // How long a session lasts before it needs a fresh sign-in
    private const TTL_SECONDS = 60 * 60 * 24 * 14; // 14 days

    // Starts a new session for $userId and returns the JWT to send back to the browser
    public static function issue(int $userId): string
    {
        $expiresAt = self::toMysqlDateTime(self::nowTimestamp() + self::TTL_SECONDS);

        // 'jti' is a random id, so two sign-ins in the same second still get different tokens.
        // Without it the tokens match and the second one breaks us_token_hash's UNIQUE key.
        $jwt = JWT::encode(
            ['sub' => $userId, 'iat' => self::nowTimestamp(), 'exp' => strtotime($expiresAt), 'jti' => bin2hex(random_bytes(16))],
            self::secret(),
            'HS256'
        );

        UserSession::create($userId, self::hash($jwt), $expiresAt);

        return $jwt;
    }

    // The user for a bearer token, or null. Never throws: an invalid token means not signed in.
    public static function verify(string $rawJwt): ?array
    {
        try {
            // Check exp on our clock, so a frozen test date matches the token.
            JWT::$timestamp = self::nowTimestamp();
            JWT::decode($rawJwt, new Key(self::secret(), 'HS256'));
        } catch (Throwable) {
            return null;
        }

        // The JWT's own signature and exp are valid. Still check the session row: it might have
        // been revoked (signed out, account switched off, password or role changed) since issue().
        return UserSession::userFor(self::hash($rawJwt));
    }

    // Sign out: ends just this one token's session
    public static function revoke(string $rawJwt): void
    {
        UserSession::revoke(self::hash($rawJwt));
    }

    // Ends every session a user has open, e.g. after a password reset link is used or an
    // account is switched off
    public static function revokeAllForUser(int $userId): void
    {
        UserSession::revokeAllForUser($userId);
    }

    // Ends every session except the one making this call right now (e.g. changing your own
    // password from a device you're still using)
    public static function revokeAllForUserExcept(int $userId, string $exceptRawJwt): void
    {
        UserSession::revokeAllForUserExcept($userId, self::hash($exceptRawJwt));
    }

    private static function hash(string $rawJwt): string
    {
        return hash('sha256', $rawJwt);
    }

    // Clock::now(), so a frozen test date stays in step with the session row.
    private static function nowTimestamp(): int
    {
        return (int) strtotime(Clock::now());
    }

    private static function toMysqlDateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function secret(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new HttpError(500, 'JWT_SECRET is not configured');
        }

        return $secret;
    }
}
