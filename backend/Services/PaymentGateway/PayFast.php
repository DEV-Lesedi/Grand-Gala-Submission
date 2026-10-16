<?php

declare(strict_types=1);

namespace GrandGala\Services\PaymentGateway;

use GrandGala\Utils\ClientIp;
use GrandGala\Utils\HttpError;
use RuntimeException;

/*
 * ITNs are trusted only from allowlisted IPs or hosts, with a matching signature,
 * and when PayFast answers VALID. Empty allowlists refuse every notification.
 */
class PayFast
{
    // Test stub for PayFast's answer. null really calls PayFast.
    private static ?string $fakeAnswer = null;

    public static function paymentUrl(): string
    {
        if (self::sandbox()) {
            return 'https://sandbox.payfast.co.za/eng/process';
        }

        return 'https://www.payfast.co.za/eng/process';
    }

    // MD5 of URL-encoded fields in order; blanks and signature skipped; passphrase last.
    public static function signature(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            if ($key === 'signature' || $value === null || $value === '') {
                continue;
            }
            $parts[] = urlencode((string) $key) . '=' . urlencode(trim((string) $value));
        }

        $passphrase = trim((string) ($_ENV['PAYFAST_PASSPHRASE'] ?? ''));
        if ($passphrase !== '') {
            $parts[] = 'passphrase=' . urlencode($passphrase);
        }

        return md5(implode('&', $parts));
    }

    // PayFast's payment notice: every posted field in the order posted, empty ones included, up to the
    // signature; passphrase last. Unlike the checkout form (signature() above), empty fields count here.
    public static function noticeSignature(array $payload): string
    {
        $parts = [];
        foreach ($payload as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            $parts[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $passphrase = trim((string) ($_ENV['PAYFAST_PASSPHRASE'] ?? ''));
        if ($passphrase !== '') {
            $parts[] = 'passphrase=' . urlencode($passphrase);
        }

        return md5(implode('&', $parts));
    }

    public static function hasValidSignature(array $payload): bool
    {
        // Every field must be plain text or a number (a list could hide extra fields)
        foreach ($payload as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }
        $signature = $payload['signature'] ?? null;
        if (!is_string($signature) || preg_match('/^[a-f0-9]{32}$/i', $signature) !== 1) {
            return false;
        }

        // Constant-time compare, so the signature cannot be guessed character by character.
        return hash_equals(self::noticeSignature($payload), strtolower($signature));
    }

    // Allowlisted IPs, or addresses PAYFAST_VALID_HOSTS resolve to now.
    public static function sourceIpAllowed(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach (explode(',', (string) ($_ENV['PAYFAST_VALID_IPS'] ?? '')) as $range) {
            if (trim($range) !== '' && ClientIp::inRange($ip, trim($range))) {
                return true;
            }
        }

        foreach (explode(',', (string) ($_ENV['PAYFAST_VALID_HOSTS'] ?? '')) as $host) {
            if (trim($host) === '') {
                continue;
            }
            $addresses = gethostbynamel(trim($host));
            if ($addresses !== false && in_array($ip, $addresses, true)) {
                return true;
            }
        }

        return false;
    }

    // 400 if PayFast says no. Unreachable throws; the caller maps that to 503 for a retry.
    public static function validateWithGateway(array $payload): void
    {
        if (self::$fakeAnswer !== null) {
            $body = self::$fakeAnswer;
            $status = 200;
        } else {
            $answer = self::askPayFast($payload);
            $body = $answer['body'];
            $status = $answer['status'];
        }

        if ($status !== 200 || trim($body) !== 'VALID') {
            throw new HttpError(400, 'PayFast could not validate this notification');
        }
    }

    // Test stub: answer without calling PayFast. null calls PayFast again.
    public static function fakeValidationAnswer(?string $answer): void
    {
        self::$fakeAnswer = $answer;
    }

    public static function merchantId(): string
    {
        $value = trim((string) ($_ENV['PAYFAST_MERCHANT_ID'] ?? ''));
        if ($value === '') {
            throw new HttpError(503, 'PAYFAST_MERCHANT_ID is not configured');
        }

        return $value;
    }

    // Posts the notification back to PayFast's validate endpoint.
    private static function askPayFast(array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is needed to check PayFast notifications');
        }
        $endpoint = 'https://www.payfast.co.za/eng/query/validate';
        if (self::sandbox()) {
            $endpoint = 'https://sandbox.payfast.co.za/eng/query/validate';
        }

        // Sent back as PayFast posted it, without the signature (PayFast's own example does the same)
        $fields = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'signature' && is_scalar($value)) {
                $fields[(string) $key] = (string) $value;
            }
        }

        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($curl);
        if (!is_string($body)) {
            throw new RuntimeException('PayFast validation request failed: ' . curl_error($curl));
        }

        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }

    public static function sandbox(): bool
    {
        return filter_var($_ENV['PAYFAST_SANDBOX'] ?? 'true', FILTER_VALIDATE_BOOL);
    }
}
