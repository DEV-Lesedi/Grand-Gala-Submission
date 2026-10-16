<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

// Rate limits on sign-in, register, forgot password, and the promo check.
// Every request is 127.0.0.1 and the counts reset each test. Wrong details create nothing.
class RateLimitsTest extends FeatureTestCase
{
    public function testGuestSignInAllowsTenTriesAMinute(): void
    {
        $this->assertLimit('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'], 10);
    }

    public function testStaffSignInAllowsTenTriesAMinute(): void
    {
        $this->assertLimit('/api/staff/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'], 10);
    }

    public function testRegisterAllowsFiveEveryTenMinutes(): void
    {
        // A bad email is refused before any account is made
        $this->assertLimit('/api/auth/register', ['name' => 'Test', 'email' => 'not-an-email', 'password' => 'Test1234'], 5);
    }

    public function testForgotPasswordAllowsFiveEveryTenMinutes(): void
    {
        $this->assertLimit('/api/auth/forgot-password', ['email' => 'not-an-email'], 5);
    }

    public function testThePromoCheckAllowsThirtyAMinute(): void
    {
        $this->assertLimit('/api/promotions/validate', ['code' => 'NO-SUCH-CODE', 'kind' => 'stay', 'amount' => 100], 30);
    }

    // The first $limit requests pass; the next one is 429 with a plain message.
    private function assertLimit(string $path, array $body, int $limit): void
    {
        for ($i = 1; $i <= $limit; $i++) {
            $response = $this->send('POST', $path, $body);
            self::assertNotSame(429, $response['status'], 'Request ' . $i . ' to ' . $path);
        }

        $over = $this->send('POST', $path, $body);

        self::assertSame(429, $over['status']);
        self::assertSame(['error' => 'Too many requests. Please try again later.'], $over['body']);
    }
}
