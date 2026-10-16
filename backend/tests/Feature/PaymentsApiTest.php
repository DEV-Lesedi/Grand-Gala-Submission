<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use GrandGala\Tests\Support\ApiServer;
use GrandGala\Tests\Support\CreatesTestAccounts;

// Checkout, PayFast, and refund routes over HTTP. This server has no PayFast settings.
// Only the checks before PayFast is needed. The payment rules are in Unit/PaymentsTest.php.
class PaymentsApiTest extends FeatureTestCase
{
    use CreatesTestAccounts;

    public function testCheckoutNeedsATypeAndAReference(): void
    {
        $response = $this->send('POST', '/api/payments/checkout', ['type' => 'stay']);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'Payment type and reference are required'], $response['body']);
    }

    // The test server runs in sandbox mode, where an unlisted address is only logged (PaymentsTest covers live mode's 403):
    // a made-up notice is still refused, by its missing signature
    public function testNotificationsFromAnyoneButPayFastAreRefused(): void
    {
        $response = $this->postForm('/api/payments/payfast/notify', 'm_payment_id=GGG-2026-0001-P-1&payment_status=COMPLETE');

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'PayFast notification signature is invalid'], $response['body']);
    }

    public function testAFormPostIsReadLikeJson(): void
    {
        // PayFast posts a form, not JSON. The promo lookup shows the fields arrived.
        $response = $this->postForm('/api/promotions/validate', 'code=NO-SUCH-CODE&kind=stay&amount=100');

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'Promotion code is not available'], $response['body']);
    }

    public function testStaffAskForARefundOfAPaidPaymentOnly(): void
    {
        $token = $this->tokenFor($this->createAccount('staff')['id']);

        $noAmount = $this->sendAuthed('POST', '/api/staff/payments/999999999/refunds', $token, ['reason' => 'Ill']);
        $notPaid = $this->sendAuthed('POST', '/api/staff/payments/999999999/refunds', $token, ['amount' => 100, 'reason' => 'Ill']);

        self::assertSame(422, $noAmount['status']);
        self::assertSame(409, $notPaid['status']);
        self::assertSame(['error' => 'Only paid payments can be refunded'], $notPaid['body']);
    }

    private function postForm(string $path, string $form): array
    {
        $curl = curl_init(ApiServer::baseUrl() . $path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $form,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($curl);
        self::assertIsString($raw);

        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => json_decode($raw, true)];
    }
}
