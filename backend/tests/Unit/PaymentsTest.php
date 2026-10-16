<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\BookingLifecycle;
use GrandGala\Services\Clock;
use GrandGala\Services\Mailer;
use GrandGala\Services\PaymentGateway\PayFast;
use GrandGala\Services\PaymentGateway\Payments;
use GrandGala\Tests\Support\PaymentsTestCase;
use GrandGala\Utils\Db;

// Checkout, PayFast notices, and refunds for stays and quotations.
// Ticket orders are in PaymentsForTicketsTest. Now is 2026-09-04 12:00; validation is faked.
class PaymentsTest extends PaymentsTestCase
{
    public function testCheckoutSavesAPendingPaymentAndSignsPayFastsFields(): void
    {
        $stay = $this->stayWaitingForPayment(2800.5);

        $checkout = Payments::start('stay', $stay['reference'], $stay['userId']);
        $again = Payments::start('stay', $stay['reference'], $stay['userId']);

        $fields = $checkout['fields'];
        self::assertSame('https://sandbox.payfast.co.za/eng/process', $checkout['url']);
        self::assertSame('2800.50', $fields['amount']);
        self::assertSame(self::MERCHANT_ID, $fields['merchant_id']);
        self::assertSame('http://localhost:5173/payment/success', $fields['return_url']);
        self::assertSame('http://localhost:5173/payment/cancel', $fields['cancel_url']);
        self::assertSame('http://localhost:8000/api/payments/payfast/notify', $fields['notify_url']);
        self::assertSame('Grand Gala stay ' . $stay['reference'], $fields['item_name']);
        self::assertStringStartsWith($stay['reference'] . '-P-', $fields['m_payment_id']);
        self::assertTrue(PayFast::hasValidSignature($fields));
        // Opening checkout again reuses the payment already waiting
        self::assertSame($fields['m_payment_id'], $again['fields']['m_payment_id']);
        $payment = $this->firstRow('SELECT p_amount, p_status, p_u_id, COUNT(*) AS payments FROM payment WHERE p_b_id = ? GROUP BY p_id', [$stay['id']]);
        self::assertSame(['p_amount' => '2800.50', 'p_status' => 'pending', 'p_u_id' => $stay['userId'], 'payments' => 1], $payment);
    }

    public function testOnlyTheOwnerCanPayAndOnlyWhileTheHoldRuns(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $lapsed = $this->stayWaitingForPayment(1000, '2026-09-04 11:59:00');
        $someoneElse = $this->createGuest();

        self::assertSame(404, $this->startError('stay', $stay['reference'], $someoneElse));
        self::assertSame(401, $this->startError('stay', $stay['reference'], null));
        self::assertSame(422, $this->startError('booking', $stay['reference'], $stay['userId']));
        self::assertSame(404, $this->startError('stay', 'GGG-1999-0001', $stay['userId']));
        self::assertSame(409, $this->startError('stay', $lapsed['reference'], $lapsed['userId']));
        self::assertSame(0, (int) $this->valueOf('SELECT COUNT(*) FROM payment WHERE p_b_id IN (?, ?)', [$stay['id'], $lapsed['id']]));
    }

    public function testAValidNotificationConfirmsTheStayOnce(): void
    {
        $stay = $this->stayWaitingForPayment(2800.5);
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => $code, 'promo_type' => 'fixed', 'promo_value' => 10, 'promo_applies_to' => '["stay"]',
            'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ]);
        $this->insert('promo_use', 'pu_id', ['pu_promo_id' => $promoId, 'pu_user_id' => $stay['userId'], 'pu_booking_id' => $stay['id']]);
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        $notice = $this->notice($reference, '2800.50');

        $first = Payments::processPayFastItn($notice, self::PAYFAST_IP);
        $second = Payments::processPayFastItn($notice, self::PAYFAST_IP);

        self::assertSame(['status' => 'paid'], $first);
        self::assertSame(['status' => 'already_processed'], $second);
        $payment = $this->firstRow('SELECT p_status, p_gateway_reference, p_paid_at FROM payment WHERE p_merchant_reference = ?', [$reference]);
        self::assertSame(['p_status' => 'paid', 'p_gateway_reference' => $notice['pf_payment_id'], 'p_paid_at' => '2026-09-04 12:00:00'], $payment);
        $booking = $this->firstRow('SELECT b_status, b_hold_expires_at FROM booking WHERE b_id = ?', [$stay['id']]);
        self::assertSame(['b_status' => 'confirmed', 'b_hold_expires_at' => null], $booking);
        // Written by BookingLifecycle in the name of the guest who paid
        $history = $this->firstRow('SELECT bsh_from_status, bsh_to_status, bsh_changed_by FROM booking_status_history WHERE bsh_b_id = ?', [$stay['id']]);
        self::assertSame(['bsh_from_status' => 'pending_payment', 'bsh_to_status' => 'confirmed', 'bsh_changed_by' => $stay['userId']], $history);
        self::assertCount(1, Mailer::sent());
        self::assertSame('booking_confirmed', Mailer::sent()[0]['template']);
        self::assertSame('used', $this->valueOf('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', [$stay['id']]));
        self::assertSame(1, (int) $this->valueOf('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promoId]));
    }

    public function testNotificationsThatFailACheckChangeNothing(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        $good = $this->notice($reference, '1000.00');
        $tampered = $good;
        $tampered['amount_gross'] = '1.00';
        // Everything right but the signature
        $forged = $good;
        $forged['signature'] = md5('forged');

        // Live: a notice from an address that isn't PayFast's is refused outright
        $_ENV['PAYFAST_SANDBOX'] = 'false';
        self::assertSame(403, $this->noticeError($good, '203.0.113.9'));
        $_ENV['PAYFAST_SANDBOX'] = 'true';
        self::assertSame(400, $this->noticeError($tampered, self::PAYFAST_IP));
        self::assertSame(400, $this->noticeError($forged, self::PAYFAST_IP));
        self::assertSame(400, $this->noticeError($this->notice($reference, '1000.00', ['merchant_id' => '999']), self::PAYFAST_IP));
        self::assertSame(400, $this->noticeError($this->notice($reference, '999.00'), self::PAYFAST_IP));
        self::assertSame(404, $this->noticeError($this->notice('NOT-OURS', '1000.00'), self::PAYFAST_IP));
        self::assertSame(['status' => 'ignored'], Payments::processPayFastItn($this->notice($reference, '1000.00', ['payment_status' => 'FAILED']), self::PAYFAST_IP));
        PayFast::fakeValidationAnswer('INVALID');
        self::assertSame(400, $this->noticeError($good, self::PAYFAST_IP));

        self::assertSame('pending', $this->valueOf('SELECT p_status FROM payment WHERE p_merchant_reference = ?', [$reference]));
        self::assertSame('pending_payment', $this->valueOf('SELECT b_status FROM booking WHERE b_id = ?', [$stay['id']]));
    }

    public function testMoneyAfterTheHoldRanOutIsKeptAndARefundIsQueued(): void
    {
        $stay = $this->stayWaitingForPayment(1500);
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        Clock::freezeAt('2026-09-06 12:01');

        $result = Payments::processPayFastItn($this->notice($reference, '1500.00'), self::PAYFAST_IP);

        self::assertSame(['status' => 'late_payment_refund_required'], $result);
        $payment = $this->firstRow('SELECT p_id, p_status, p_failure_reason FROM payment WHERE p_merchant_reference = ?', [$reference]);
        self::assertSame('paid', $payment['p_status']);
        self::assertStringContainsString('manual refund required', $payment['p_failure_reason']);
        $refund = $this->firstRow('SELECT rf_amount, rf_status, rf_requested_by FROM refund WHERE rf_p_id = ?', [$payment['p_id']]);
        self::assertSame(['rf_amount' => '1500.00', 'rf_status' => 'pending', 'rf_requested_by' => null], $refund);
        self::assertSame('pending_payment', $this->valueOf('SELECT b_status FROM booking WHERE b_id = ?', [$stay['id']]));
    }

    public function testASecondPaymentForAPaidStayIsKeptAndRefunded(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $first = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        // A second checkout from another tab, saved before the first was paid
        $second = 'TEST-' . strtoupper(bin2hex(random_bytes(6)));
        Db::pdo()->prepare("INSERT INTO payment (p_b_id, p_amount, p_merchant_reference, p_status) VALUES (?, 1000, ?, 'pending')")
            ->execute([$stay['id'], $second]);

        Payments::processPayFastItn($this->notice($first, '1000.00'), self::PAYFAST_IP);
        $result = Payments::processPayFastItn($this->notice($second, '1000.00'), self::PAYFAST_IP);

        self::assertSame(['status' => 'late_payment_refund_required'], $result);
        self::assertStringStartsWith('Paid twice', $this->valueOf('SELECT p_failure_reason FROM payment WHERE p_merchant_reference = ?', [$second]));
        self::assertSame(1, (int) $this->valueOf('SELECT COUNT(*) FROM refund r JOIN payment p ON p.p_id = r.rf_p_id WHERE p.p_b_id = ?', [$stay['id']]));
    }

    public function testACancelNoticeCancelsThePendingPaymentOnce(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        $notice = $this->notice($reference, '1000.00', ['payment_status' => 'CANCELLED']);

        self::assertSame(['status' => 'cancelled'], Payments::cancelPayFastItn($notice, self::PAYFAST_IP));
        self::assertSame(['status' => 'already_processed'], Payments::cancelPayFastItn($notice, self::PAYFAST_IP));
        self::assertSame('cancelled', $this->valueOf('SELECT p_status FROM payment WHERE p_merchant_reference = ?', [$reference]));
    }

    public function testRefundsAreQueuedThenFinishedByAnAdmin(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $staffId = $this->createGuest();
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        Payments::processPayFastItn($this->notice($reference, '1000.00'), self::PAYFAST_IP);
        $paymentId = (int) $this->valueOf('SELECT p_id FROM payment WHERE p_merchant_reference = ?', [$reference]);

        $part = Payments::refund($paymentId, 400.25, ' Broken geyser ', $staffId);
        $tooMuch = $this->errorFrom(function () use ($paymentId) {
            Payments::refund($paymentId, 600, 'Too much');
        });
        $noReason = $this->errorFrom(function () use ($paymentId) {
            Payments::refund($paymentId, 10, ' ');
        });
        Payments::markRefundProcessed($part, $staffId, 'PF-REFUND-1');
        $twice = $this->errorFrom(function () use ($part, $staffId) {
            Payments::markRefundProcessed($part, $staffId, 'PF-REFUND-1B');
        });

        self::assertSame(422, $tooMuch->status());
        self::assertSame(422, $noReason->status());
        self::assertSame(409, $twice->status());
        $refund = $this->firstRow('SELECT rf_amount, rf_reason, rf_status, rf_requested_by, rf_processed_by, rf_gateway_reference FROM refund WHERE rf_id = ?', [$part]);
        self::assertSame(['rf_amount' => '400.25', 'rf_reason' => 'Broken geyser', 'rf_status' => 'processed', 'rf_requested_by' => $staffId,
            'rf_processed_by' => $staffId, 'rf_gateway_reference' => 'PF-REFUND-1'], $refund);
        // Part of it back: the payment and the stay are still paid and confirmed
        self::assertSame('paid', $this->valueOf('SELECT p_status FROM payment WHERE p_id = ?', [$paymentId]));
        self::assertSame('confirmed', $this->valueOf('SELECT b_status FROM booking WHERE b_id = ?', [$stay['id']]));

        // The guest cancels, and the rest goes back: the payment and the stay become refunded
        Db::transaction(function () use ($stay) {
            BookingLifecycle::move($stay['id'], 'cancelled', $stay['userId'], '[test] cancelled');
        });
        $rest = Payments::refund($paymentId, 599.75, 'Cancelled', $staffId);
        Payments::markRefundProcessed($rest, $staffId, 'PF-REFUND-2');

        self::assertSame('refunded', $this->valueOf('SELECT p_status FROM payment WHERE p_id = ?', [$paymentId]));
        self::assertSame('refunded', $this->valueOf('SELECT b_status FROM booking WHERE b_id = ?', [$stay['id']]));
    }

    public function testRefundingALatePaymentLeavesTheBookingAlone(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $staffId = $this->createGuest();
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        Clock::freezeAt('2026-09-07 09:00');
        Payments::processPayFastItn($this->notice($reference, '1000.00'), self::PAYFAST_IP);
        $paymentId = (int) $this->valueOf('SELECT p_id FROM payment WHERE p_merchant_reference = ?', [$reference]);
        $refundId = (int) $this->valueOf('SELECT rf_id FROM refund WHERE rf_p_id = ?', [$paymentId]);

        Payments::markRefundProcessed($refundId, $staffId, 'PF-LATE-1');

        self::assertSame('refunded', $this->valueOf('SELECT p_status FROM payment WHERE p_id = ?', [$paymentId]));
        self::assertSame('pending_payment', $this->valueOf('SELECT b_status FROM booking WHERE b_id = ?', [$stay['id']]));
        // An unpaid payment can't be refunded
        $pending = $this->stayWaitingForPayment(500, '2026-09-10 12:00:00');
        Payments::start('stay', $pending['reference'], $pending['userId']);
        $unpaidId = (int) $this->valueOf('SELECT p_id FROM payment WHERE p_b_id = ?', [$pending['id']]);
        self::assertSame(409, $this->errorFrom(function () use ($unpaidId) {
            Payments::refund($unpaidId, 10, 'No');
        })->status());
    }

    public function testAnAcceptedQuotationIsPaidThroughItsPayable(): void
    {
        $quotation = $this->acceptedQuotation(5750.75);

        $checkout = Payments::start('quotation', $quotation['reference'], $quotation['userId']);
        $result = Payments::processPayFastItn($this->notice($checkout['fields']['m_payment_id'], '5750.75'), self::PAYFAST_IP);

        self::assertSame('5750.75', $checkout['fields']['amount']);
        self::assertSame(['status' => 'paid'], $result);
        self::assertSame('confirmed', $this->valueOf('SELECT b_status FROM booking WHERE b_id = ?', [$quotation['bookingId']]));
        self::assertSame('booking_confirmed', Mailer::sent()[0]['template']);
    }

    // Event waiting for payment, latest quote accepted. Returns reference, bookingId, userId.
    private function acceptedQuotation(float $total): array
    {
        $guestId = $this->createGuest();
        $eventType = (int) Db::pdo()->query("SELECT bt_id FROM booking_type WHERE bt_name = 'event'")->fetchColumn();
        $bookingId = $this->insert('booking', 'b_id', [
            'b_reference' => 'TEST-' . strtoupper(bin2hex(random_bytes(5))), 'b_bt_id' => $eventType, 'b_u_id' => $guestId,
            'b_status' => 'pending_payment', 'b_total_price' => $total, 'b_hold_expires_at' => '2026-09-07 12:00:00',
        ]);
        $this->cleanUpPaymentsOfBooking($bookingId);
        $reference = 'TQ-' . strtoupper(bin2hex(random_bytes(5)));
        $this->insert('quotation', 'q_id', [
            'q_b_id' => $bookingId, 'q_reference' => $reference, 'q_amount' => $total, 'q_status' => 'accepted', 'q_valid_until' => '2026-09-10',
        ]);

        return ['reference' => $reference, 'bookingId' => $bookingId, 'userId' => $guestId];
    }

    private function startError(string $kind, string $reference, ?int $userId): int
    {
        return $this->errorFrom(function () use ($kind, $reference, $userId) {
            Payments::start($kind, $reference, $userId);
        })->status();
    }

    // PayFast's sandbox sends notices from servers outside its published hosts. In sandbox mode the address
    // is only logged; the signature (passphrase) and PayFast's own VALID answer still decide.
    public function testSandboxAcceptsANoticeFromAnUnlistedAddressOnlyIfEverythingElseChecksOut(): void
    {
        $stay = $this->stayWaitingForPayment(1000);
        $reference = Payments::start('stay', $stay['reference'], $stay['userId'])['fields']['m_payment_id'];
        $forged = $this->notice($reference, '1000.00');
        $forged['signature'] = md5('forged');

        self::assertSame(400, $this->noticeError($forged, '203.0.113.9'));
        self::assertSame('pending', $this->valueOf('SELECT p_status FROM payment WHERE p_merchant_reference = ?', [$reference]));

        Payments::processPayFastItn($this->notice($reference, '1000.00'), '203.0.113.9');

        self::assertSame('paid', $this->valueOf('SELECT p_status FROM payment WHERE p_merchant_reference = ?', [$reference]));
    }

    private function noticeError(array $notice, string $ip): int
    {
        return $this->errorFrom(function () use ($notice, $ip) {
            Payments::processPayFastItn($notice, $ip);
        })->status();
    }
}
