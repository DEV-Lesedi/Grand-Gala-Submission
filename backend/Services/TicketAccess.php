<?php

declare(strict_types=1);

namespace GrandGala\Services;

use RuntimeException;

/*
 * Receipt token for a guest ticket order, sent back in X-Ticket-Access.
 * HMAC-SHA256. Never put the token in a URL or an email.
 */
class TicketAccess
{
    // A token for $reference that works until $expires ('YYYY-MM-DD HH:MM:SS', venue time)
    public static function issue(string $reference, string $expires): string
    {
        $payload = self::base64UrlEncode(json_encode(['reference' => $reference, 'expires' => $expires], JSON_THROW_ON_ERROR));

        return $payload . '.' . self::sign($payload);
    }

    // True only if the token was signed by us, is for $reference, and hasn't expired
    public static function verify(?string $token, string $reference): bool
    {
        if ($token === null || strlen($token) > 500) {
            return false;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        // Constant-time compare, so the signature cannot be guessed character by character.
        if (!hash_equals(self::sign($parts[0]), $parts[1])) {
            return false;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false) {
            return false;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['reference']) || !isset($data['expires'])) {
            return false;
        }
        if ($data['reference'] !== $reference) {
            return false;
        }

        // Both are 'YYYY-MM-DD HH:MM:SS', so comparing the text compares the times
        return Clock::now() < $data['expires'];
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::secret());
    }

    // Signing key from .env. A short or missing key is a setup mistake, so it is a 500.
    private static function secret(): string
    {
        $secret = $_ENV['TICKET_ACCESS_SECRET'] ?? '';
        if (strlen($secret) < 32) {
            throw new RuntimeException('Set TICKET_ACCESS_SECRET in .env to at least 32 random characters');
        }

        return $secret;
    }

    // Base64 that is safe in a header: + and / become - and _, and the = padding is dropped
    private static function base64UrlEncode(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
