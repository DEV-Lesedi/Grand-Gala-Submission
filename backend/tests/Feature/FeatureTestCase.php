<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use GrandGala\Services\RateLimiter;
use GrandGala\Tests\Support\ApiServer;
use GrandGala\Tests\Support\DatabaseTestCase;

// Endpoint tests send real HTTP requests. get() and send() return status, headers, and JSON.
abstract class FeatureTestCase extends DatabaseTestCase
{
    // From 127.0.0.1, so sign-in, register, forgot-password, and promo limits reset each test.
    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(RateLimiter::LIMITS) as $scope) {
            RateLimiter::forget($scope, '127.0.0.1');
        }
    }

    protected function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    // Any method, with an optional JSON body, e.g. send('POST', '/api/staff/blackouts', [...]).
    protected function send(string $method, string $path, ?array $body = null): array
    {
        $headers = [];
        $curl = curl_init(ApiServer::baseUrl() . $path);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            // cSpell:ignore HEADERFUNCTION
            // curl calls this once per header line, e.g. "Content-Type: application/json".
            // The & lets it add to $headers out here.
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $raw = curl_exec($curl);
        self::assertIsString($raw, 'Request failed: ' . curl_error($curl));

        return [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => json_decode($raw, true),
        ];
    }
}
