<?php

declare(strict_types=1);

namespace GrandGala\Tests\Support;

use GrandGala\Services\Clock;
use GrandGala\Services\Mailer;
use GrandGala\Services\PaymentGateway\PayFast;
use GrandGala\Utils\Db;

// Payment test setup. PayFast settings are restored after; the validation call is faked.
// Now is 2026-09-04 12:00. Payments and refunds are deleted with their booking or order.
abstract class PaymentsTestCase extends DatabaseTestCase
{
    // PayFast's own sandbox test merchant, and a passphrase for signing
    protected const MERCHANT_ID = '10000100';
    protected const PASSPHRASE = 'test phrase';
    protected const PAYFAST_IP = '127.0.0.1';

    private const SETTINGS = [
        'PAYFAST_MERCHANT_ID' => self::MERCHANT_ID,
        'PAYFAST_PASSPHRASE' => self::PASSPHRASE,
        'PAYFAST_VALID_IPS' => '127.0.0.1,198.51.100.0/24',
        'PAYFAST_SANDBOX' => 'true',
        'FRONTEND_URL' => 'http://localhost:5173',
        'APP_URL' => 'http://localhost:8000',
    ];

    // What $_ENV had before, so tearDown() can put it back
    private array $savedSettings = [];

    // Rows whose payments this test removes, e.g. ['column' => 'p_b_id', 'id' => 12].
    private array $paidFor = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::SETTINGS as $key => $value) {
            $this->savedSettings[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
        PayFast::fakeValidationAnswer('VALID');
        Mailer::clear();
        Clock::freezeAt('2026-09-04 12:00');
    }

    protected function tearDown(): void
    {
        // Refunds first, then payments, before the bookings and orders they point at
        foreach ($this->paidFor as $target) {
            $statement = Db::pdo()->prepare('DELETE r FROM refund r JOIN payment p ON p.p_id = r.rf_p_id WHERE p.' . $target['column'] . ' = ?');
            $statement->execute([$target['id']]);
            $statement = Db::pdo()->prepare('DELETE FROM payment WHERE ' . $target['column'] . ' = ?');
            $statement->execute([$target['id']]);
        }
        foreach ($this->savedSettings as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        PayFast::fakeValidationAnswer(null);
        Mailer::clear();
        Clock::freeze($_ENV['APP_TODAY']);

        parent::tearDown();
    }

    // Remove this booking's payments and refunds after the test
    protected function cleanUpPaymentsOfBooking(int $bookingId): void
    {
        $this->paidFor[] = ['column' => 'p_b_id', 'id' => $bookingId];
    }

    protected function cleanUpPaymentsOfOrder(int $orderId): void
    {
        $this->paidFor[] = ['column' => 'p_to_id', 'id' => $orderId];
    }

    // A stay waiting for payment until $holdExpires, costing $total.
    // Returns ['id', 'reference', 'userId'] and cleans up its payments afterwards.
    protected function stayWaitingForPayment(float $total, string $holdExpires = '2026-09-06 12:00:00'): array
    {
        $spaceId = $this->createSpace(['sp_is_accommodation' => 1]);
        $bookingId = $this->createStay($spaceId, '2026-12-12', '2026-12-13', 'pending_payment', $holdExpires);
        $this->update('booking', 'b_id', $bookingId, ['b_subtotal' => $total, 'b_total_price' => $total]);
        $this->cleanUpPaymentsOfBooking($bookingId);

        $row = $this->firstRow('SELECT b_reference, b_u_id FROM booking WHERE b_id = ?', [$bookingId]);

        return ['id' => $bookingId, 'reference' => $row['b_reference'], 'userId' => (int) $row['b_u_id']];
    }

    // Signed PayFast notice that $merchantReference was paid. $changes edits fields first.
    protected function notice(string $merchantReference, string $amount, array $changes = []): array
    {
        $payload = [
            'm_payment_id' => $merchantReference,
            'pf_payment_id' => (string) random_int(1000000, 9999999),
            'payment_status' => 'COMPLETE',
            'item_name' => 'Grand Gala',
            'item_description' => '', // real notices carry empty fields, and they count in the signature
            'amount_gross' => $amount,
            'amount_fee' => '-2.30',
            'amount_net' => '0.00',
            'custom_str1' => '',
            'merchant_id' => self::MERCHANT_ID,
        ];
        $payload = array_merge($payload, $changes);
        $payload['signature'] = PayFast::noticeSignature($payload);

        return $payload;
    }

    protected function firstRow(string $sql, array $params): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        self::assertIsArray($row, 'No row for: ' . $sql);

        return $row;
    }

    protected function valueOf(string $sql, array $params): mixed
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
