<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\API\Auth\AuthController;
use GrandGala\Models\PasswordReset;
use GrandGala\Models\User;
use GrandGala\Tests\Support\CreatesTestAccounts;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use GrandGala\Utils\Request;

// One reset link used from two tabs at once. Only the first may change the password.
// Without a lock both read "not used yet". second_reset.php is the second tab.
class PasswordResetTwoAtOnceTest extends DatabaseTestCase
{
    use CreatesTestAccounts;

    public function testAResetLinkUsedTwiceAtOnceChangesThePasswordOnce(): void
    {
        $guest = $this->createAccount('customer');
        $token = bin2hex(random_bytes(32));
        PasswordReset::create($guest['id'], hash('sha256', $token), '2099-01-01 00:00:00');

        $secondTab = null;
        // The & lets the function below fill in $secondTab, so we can use it after the transaction
        Db::transaction(function () use ($token, &$secondTab) {
            // The first tab resets the password. It's saved when this transaction ends.
            ob_start();
            (new AuthController())->resetPassword(new Request('POST', '/api/auth/reset-password', [], [
                'token' => $token, 'password' => 'FirstTab123',
            ]));
            ob_end_clean();

            // The second tab uses the same link before the first has saved, so it has to wait
            $secondTab = $this->startScript('second_reset.php', [$token, 'SecondTab123']);
            sleep(8); // remote DB: long enough for the second tab to reach the lock
        });

        self::assertSame('422', $this->waitForScript($secondTab));
        $hash = (string) User::passwordHashOf($guest['id']);
        self::assertTrue(password_verify('FirstTab123', $hash));
    }
}
