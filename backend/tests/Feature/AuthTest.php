<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use GrandGala\Tests\Support\CreatesTestAccounts;

// Guest register, sign in, sign out, and me.
class AuthTest extends FeatureTestCase
{
    use CreatesTestAccounts;

    public function testRegisterCreatesAnAccountAndSignsThemIn(): void
    {
        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.com';

        $response = $this->send('POST', '/api/auth/register', [
            'name' => 'Thandi Mabuza',
            'email' => $email,
            'password' => 'guest-2026',
        ]);
        $this->track('app_user', 'u_id', $response['body']['user']['id']);

        self::assertSame(201, $response['status']);
        self::assertSame('Thandi', $response['body']['user']['name']);
        self::assertSame('Mabuza', $response['body']['user']['surname']);
        self::assertSame('customer', $response['body']['user']['role']);
        self::assertNotEmpty($response['body']['token']);
    }

    public function testRegisterWithOneNameAsksForASurname(): void
    {
        $response = $this->send('POST', '/api/auth/register', [
            'name' => 'Thandi',
            'email' => 'nobody@example.com',
            'password' => 'guest-2026',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('Enter your surname.', $response['body']['error']);
    }

    public function testRegisterRefusesAnEmailAlreadyInUse(): void
    {
        $account = $this->createAccount('customer');

        $response = $this->send('POST', '/api/auth/register', [
            'name' => 'Another Guest',
            'email' => $account['email'],
            'password' => 'guest-2026',
        ]);

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('already has an account', $response['body']['error']);
    }

    public function testLoginWithTheRightPasswordWorks(): void
    {
        $account = $this->createAccount('customer', password: 'guest-2026');

        $response = $this->send('POST', '/api/auth/login', ['email' => $account['email'], 'password' => 'guest-2026']);

        self::assertSame(200, $response['status']);
        self::assertSame($account['id'], $response['body']['user']['id']);
        self::assertNotEmpty($response['body']['token']);
    }

    public function testLoginWithTheWrongPasswordIsRefused(): void
    {
        $account = $this->createAccount('customer', password: 'guest-2026');

        $response = $this->send('POST', '/api/auth/login', ['email' => $account['email'], 'password' => 'wrong']);

        self::assertSame(422, $response['status']);
    }

    public function testMeNeedsASignInToken(): void
    {
        $response = $this->send('GET', '/api/auth/me');

        self::assertSame(401, $response['status']);
    }

    public function testMeReturnsTheSignedInAccount(): void
    {
        $account = $this->createAccount('customer');

        $response = $this->sendAuthed('GET', '/api/auth/me', $this->tokenFor($account['id']));

        self::assertSame(200, $response['status']);
        self::assertSame($account['email'], $response['body']['email']);
    }

    public function testLogoutEndsTheSession(): void
    {
        $account = $this->createAccount('customer');
        $token = $this->tokenFor($account['id']);

        $loggedOut = $this->sendAuthed('POST', '/api/auth/logout', $token);
        self::assertSame(200, $loggedOut['status']);

        $afterLogout = $this->sendAuthed('GET', '/api/auth/me', $token);
        self::assertSame(401, $afterLogout['status']);
    }
}
