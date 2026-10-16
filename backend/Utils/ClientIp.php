<?php

declare(strict_types=1);

namespace GrandGala\Utils;

/* Client IP for rate limits and PayFast. Trust X-Forwarded-For only from TRUSTED_PROXY_CIDRS.
 * The result is the rightmost hop that is not one of our proxies.
 */
class ClientIp
{
    public static function remote(): string
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            throw new HttpError(400, 'Client IP address is unavailable');
        }

        $proxies = [];
        foreach (explode(',', (string) ($_ENV['TRUSTED_PROXY_CIDRS'] ?? '')) as $range) {
            if (trim($range) !== '') {
                $proxies[] = trim($range);
            }
        }
        if (!self::inAnyRange($peer, $proxies)) {
            return $peer;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || trim($forwarded) === '') {
            return $peer;
        }

        // client, proxy1, proxy2, then the peer that connected to us
        $chain = [];
        foreach (explode(',', $forwarded) as $address) {
            $address = trim($address);
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new HttpError(400, 'Forwarded client IP address is invalid');
            }
            $chain[] = $address;
        }
        $chain[] = $peer;

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!self::inAnyRange($chain[$i], $proxies)) {
                return $chain[$i];
            }
        }

        return $chain[0];
    }

    // Exact address, or a CIDR prefix that must match.
    public static function inRange(string $ip, string $range): bool
    {
        $parts = explode('/', $range);
        if (count($parts) === 1) {
            return $parts[0] === $ip;
        }
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }

        $ipBits = self::bits($ip);
        $networkBits = self::bits($parts[0]);
        // IPv4 is 32 bits and IPv6 is 128; mixed families never match.
        if ($ipBits === null || $networkBits === null || strlen($ipBits) !== strlen($networkBits)) {
            return false;
        }
        $length = (int) $parts[1];
        if ($length > strlen($ipBits)) {
            return false;
        }

        return substr($ipBits, 0, $length) === substr($networkBits, 0, $length);
    }

    private static function inAnyRange(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    // Binary text of the address, or null if it is not an IP.
    private static function bits(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bits = '';
        $bytes = inet_pton($ip);
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits = $bits . sprintf('%08b', ord($bytes[$i]));
        }

        return $bits;
    }
}
