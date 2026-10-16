<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\PaymentGateway\Payments;
use GrandGala\Tests\Support\PaymentsTestCase;
use GrandGala\Utils\Db;

// PayFast sends the same notice twice at once. The stay is confirmed once.
// second_itn.php is the second copy, in its own process and connection.
class PaymentsTwoAtOnceTest extends PaymentsTestCase
{
    public function testTheSameNotificationTwiceAtOnceConfirmsTheStayOnce(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        $notice = $this->notice($reference, '1000.00');

        $first = null;
        $second = null;
        // The & fills these in. The outer transaction keeps the payment row locked until it ends,
        // so the second copy has to wait for the first to finish.
        Db::transaction(function () use ($notice, &$first, &$second) {
            $first = Payments::processPayFastItn($notice, self::PAYFAST_IP);
            $second = $this->startScript('second_itn.php', [json_encode($notice)]);
            sleep(2);
        });

        self::assertSame(['status' => 'paid'], $first);
        self::assertSame('already_processed', $this->waitForScript($second));
        self::assertSame(1, (int) $this->valueOf(
            "SELECT COUNT(*) FROM booking_status_history WHERE bsh_b_id = ? AND bsh_to_status = 'confirmed'", [$stay['id']]
        ));
        self::assertSame(0, (int) $this->valueOf('SELECT COUNT(*) FROM refund r JOIN payment p ON p.p_id = r.rf_p_id WHERE p.p_b_id = ?', [$stay['id']]));
    }
}
