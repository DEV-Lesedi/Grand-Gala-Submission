<?php

declare(strict_types=1);

namespace GrandGala\Services\PaymentGateway;

use GrandGala\Models\Payment;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Local stand-in for PayFast's payment-complete notice (APP_ENV=local only).
 * It signs a notice and runs the real ITN path. Any other env answers 404.
 */
class LocalPayFast
{
    // Completes the waiting payment (PayFast's m_payment_id) through the real ITN path.
    public static function complete(string $merchantReference): array
    {
        if (($_ENV['APP_ENV'] ?? '') !== 'local') {
            throw new HttpError(404, 'Not found');
        }

        $payment = Db::transaction(function () use ($merchantReference) {
            return Payment::lockByMerchantReference($merchantReference);
        });
        if ($payment === null) {
            throw new HttpError(404, 'Payment reference not found');
        }

        $notice = [
            'merchant_id' => PayFast::merchantId(),
            'm_payment_id' => $merchantReference,
            'pf_payment_id' => 'LOCAL' . bin2hex(random_bytes(6)),
            'payment_status' => 'COMPLETE',
            'amount_gross' => number_format($payment['amount'], 2, '.', ''),
            'currency' => 'ZAR',
        ];
        $notice['signature'] = PayFast::noticeSignature($notice);

        // This call only: treat the source as local and answer VALID, then restore both.
        $savedIps = $_ENV['PAYFAST_VALID_IPS'] ?? null;
        $_ENV['PAYFAST_VALID_IPS'] = trim((string) $savedIps . ',127.0.0.1', ',');
        PayFast::fakeValidationAnswer('VALID');
        try {
            return Payments::processPayFastItn($notice, '127.0.0.1');
        } finally {
            PayFast::fakeValidationAnswer(null);
            if ($savedIps === null) {
                unset($_ENV['PAYFAST_VALID_IPS']);
            } else {
                $_ENV['PAYFAST_VALID_IPS'] = $savedIps;
            }
        }
    }
}
