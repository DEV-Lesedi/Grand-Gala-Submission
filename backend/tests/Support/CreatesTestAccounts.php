<?php

declare(strict_types=1);

namespace GrandGala\Tests\Support;

use GrandGala\Services\Sessions;
use GrandGala\Utils\Db;

// Signed-in accounts for feature tests. Use the trait; it is not part of DatabaseTestCase.
trait CreatesTestAccounts
{
    // A real account with a hashed password, tracked for tearDown() like any other insert().
    protected function createAccount(string $role, bool $isActive = true, string $password = 'Test1234'): array
    {
        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.com';
        $userId = $this->insert('app_user', 'u_id', [
            'u_ut_id' => $this->userTypeId($role),
            'u_name' => '[test]',
            'u_surname' => ucfirst($role),
            'u_email' => $email,
            'u_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'u_is_active' => (int) $isActive,
        ]);

        return ['id' => $userId, 'email' => $email, 'password' => $password, 'role' => $role];
    }

    // A real bearer token for $userId, through the same Services\Sessions code a real sign-in uses
    protected function tokenFor(int $userId): string
    {
        return Sessions::issue($userId);
    }

    // Like FeatureTestCase::send(), but with "Authorization: Bearer $token" set
    protected function sendAuthed(string $method, string $path, string $token, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $token];

        $curl = curl_init(ApiServer::baseUrl() . $path);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($curl);
        self::assertIsString($raw, 'Request failed: ' . curl_error($curl));

        return [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'body' => json_decode($raw, true),
        ];
    }

    private function userTypeId(string $role): int
    {
        $statement = Db::pdo()->prepare('SELECT ut_id FROM user_type WHERE ut_name = ?');
        $statement->execute([$role]);

        return (int) $statement->fetchColumn();
    }
}
