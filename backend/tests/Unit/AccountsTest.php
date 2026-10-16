<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Models\User;
use GrandGala\Services\Accounts;
use GrandGala\Services\Mailer;
use GrandGala\Services\Sessions;
use GrandGala\Tests\Support\CreatesTestAccounts;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

class AccountsTest extends DatabaseTestCase
{
    use CreatesTestAccounts;

    private array $oldUrls = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_URL', 'FRONTEND_URL'] as $name) {
            $this->oldUrls[$name] = $_ENV[$name] ?? null;
        }
        $_ENV['APP_URL'] = 'https://api.example.test';
        $_ENV['FRONTEND_URL'] = 'https://site.example.test/';
        Mailer::clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->oldUrls as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        Mailer::clear();
        parent::tearDown();
    }

    public function testRegisterCreatesAndSignsInAGuest(): void
    {
        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.com';

        $result = Accounts::register('Thandi Mabuza', $email, 'Guest1234');
        $this->track('app_user', 'u_id', $result['user']['id']);

        self::assertSame($email, $result['user']['email']);
        self::assertSame('customer', $result['user']['role']);
        self::assertSame($result['user']['id'], Sessions::verify($result['token'])['id']);
    }

    public function testRegisterRefusesATakenEmailRegardlessOfCaseAndSpaces(): void
    {
        $guest = $this->createAccount('customer');
        $email = '  ' . strtoupper($guest['email']) . '  ';

        $error = $this->errorFrom(fn () => Accounts::register('Another Guest', $email, 'Guest1234'));

        self::assertSame(422, $error->status());
        self::assertSame(trim($email) . ' already has an account.', $error->getMessage());
    }

    public function testGuestSignInChecksThePasswordBeforeRefusingStaff(): void
    {
        $staff = $this->createAccount('staff');

        $wrongPassword = $this->errorFrom(fn () => Accounts::signInGuest($staff['email'], 'Wrong1234'));
        self::assertSame(422, $wrongPassword->status());
        self::assertSame('That email and password don\'t match an account.', $wrongPassword->getMessage());

        $staffPage = $this->errorFrom(fn () => Accounts::signInGuest($staff['email'], $staff['password']));
        self::assertSame(403, $staffPage->status());
        self::assertSame('Staff sign in on the staff sign-in page.', $staffPage->getMessage());
    }

    public function testStaffSignInRefusesCustomers(): void
    {
        $guest = $this->createAccount('customer');

        $error = $this->errorFrom(fn () => Accounts::signInStaff($guest['email'], $guest['password']));

        self::assertSame(422, $error->status());
        self::assertSame('This is a guest account. Guests sign in on the main site.', $error->getMessage());
    }

    public function testInviteCreatesAnAccountWithNoUsablePasswordAndAWebsiteLinkFor72Hours(): void
    {
        $adminAccount = $this->createAccount('admin');
        $admin = User::findActiveById($adminAccount['id']);
        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.com';

        $person = Accounts::invite($admin, [
            'name' => 'New',
            'surname' => 'Staff',
            'email' => $email,
            'phone' => '',
            'role' => 'staff',
        ]);
        $this->track('app_user', 'u_id', $person['id']);

        $account = User::findByEmailWithHash($email);
        self::assertFalse(password_verify('anything-at-all', $account['passwordHash']));
        self::assertCount(1, Mailer::sent());
        $emailSent = Mailer::sent()[0];
        self::assertSame('staff_invite', $emailSent['template']);
        self::assertStringStartsWith('https://site.example.test/reset-password?token=', $emailSent['data']['inviteUrl']);
        self::assertSame(72, $emailSent['data']['expiresInHours']);
        self::assertSame(72 * 60, $this->minutesTheLinkWorks($person['id']));
    }

    public function testTeamResetSendsAWebsiteLinkFor60Minutes(): void
    {
        $adminAccount = $this->createAccount('admin');
        $staffAccount = $this->createAccount('staff');
        $admin = User::findActiveById($adminAccount['id']);

        $result = Accounts::sendTeamReset($admin, (string) $staffAccount['id']);

        self::assertSame(['ok' => true], $result);
        $emailSent = Mailer::sent()[0];
        self::assertSame('password_reset', $emailSent['template']);
        self::assertStringStartsWith('https://site.example.test/reset-password?token=', $emailSent['data']['resetUrl']);
        self::assertSame(60, $emailSent['data']['expiresInMinutes']);
        self::assertSame(60, $this->minutesTheLinkWorks($staffAccount['id']));
    }

    public function testResetWithTokenWorksOnceAndEndsEverySession(): void
    {
        $guest = $this->createAccount('customer');
        $firstSession = Sessions::issue($guest['id']);
        $secondSession = Sessions::issue($guest['id']);
        Accounts::requestReset($guest['email']);
        $token = self::tokenFrom(Mailer::sent()[0]['data']['resetUrl']);

        self::assertSame(['ok' => true], Accounts::resetWithToken($token, 'Changed1234'));
        self::assertTrue(password_verify('Changed1234', (string) User::passwordHashOf($guest['id'])));
        self::assertNull(Sessions::verify($firstSession));
        self::assertNull(Sessions::verify($secondSession));

        $used = $this->errorFrom(fn () => Accounts::resetWithToken($token, 'Again1234'));
        self::assertSame(422, $used->status());
        self::assertSame('This reset link has expired or was already used.', $used->getMessage());
    }

    public function testChangePasswordEndsEverySessionExceptTheCurrentOne(): void
    {
        $staff = $this->createAccount('staff');
        $currentSession = Sessions::issue($staff['id']);
        $otherSession = Sessions::issue($staff['id']);
        $me = User::findActiveById($staff['id']);

        $result = Accounts::changePassword(
            $me,
            $staff['password'],
            'Changed1234',
            'Changed1234',
            $currentSession
        );

        self::assertSame(['ok' => true], $result);
        self::assertNotNull(Sessions::verify($currentSession));
        self::assertNull(Sessions::verify($otherSession));
    }

    public function testSwitchingSomeoneOffOrChangingTheirRoleEndsTheirSessions(): void
    {
        $superAccount = $this->createAccount('superadmin');
        $superadmin = User::findActiveById($superAccount['id']);
        $switchedOff = $this->createAccount('staff');
        $roleChanged = $this->createAccount('staff');
        $switchedOffSession = Sessions::issue($switchedOff['id']);
        $roleChangedSession = Sessions::issue($roleChanged['id']);

        Accounts::updateTeamMember($superadmin, (string) $switchedOff['id'], null, false);
        Accounts::updateTeamMember($superadmin, (string) $roleChanged['id'], 'admin', null);

        self::assertNull(Sessions::verify($switchedOffSession));
        self::assertNull(Sessions::verify($roleChangedSession));
    }

    public function testStaffCannotChangeTheirEmailThroughTheGuestProfile(): void
    {
        $staffAccount = $this->createAccount('staff');
        $staff = User::findActiveById($staffAccount['id']);

        $error = $this->errorFrom(fn () => Accounts::updateGuestProfile(
            $staff,
            'Updated Staff',
            'different@example.com',
            ''
        ));

        self::assertSame(422, $error->status());
        self::assertSame('Staff can\'t change their email here.', $error->getMessage());
    }

    private static function tokenFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    // Saved expiry, not the email. Written a moment later, so it can be a second short.
    private function minutesTheLinkWorks(int $userId): int
    {
        $statement = Db::pdo()->prepare(
            'SELECT ROUND(TIMESTAMPDIFF(SECOND, pr_date_created, pr_expires_at) / 60) FROM password_reset WHERE pr_u_id = ? ORDER BY pr_id DESC LIMIT 1'
        );
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }
}
