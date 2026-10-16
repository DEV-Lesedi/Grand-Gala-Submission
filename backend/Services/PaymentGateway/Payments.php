<?php

declare(strict_types=1);

namespace GrandGala\Services\PaymentGateway;

use GrandGala\Models\Payment;
use GrandGala\Services\Clock;
use GrandGala\Services\QuotationPayment;
use GrandGala\Services\StayPayable;
use GrandGala\Services\TicketOrderPayable;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use RuntimeException;

/*
 * Checkout and PayFast notices. The amount comes from the database.
 * payableFor() picks a stay, a quotation, or a ticket order.
 * A repeat notice is ignored. Late or duplicate money is recorded and queued for refund.
 * A browser return never confirms a payment. Only the notification does.
 */
class Payments
{
    // Starts checkout. $accessToken is a guest ticket order's receipt token.
    public static function start(string $kind, string $reference, ?int $userId, ?string $accessToken = null): array
    {
        $payable = self::payableFor($kind);
        $merchantId = PayFast::merchantId();
        $frontend = rtrim((string) ($_ENV['FRONTEND_URL'] ?? ''), '/');
        $api = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $returnUrl = self::url('PAYFAST_RETURN_URL', $frontend . '/payment/success');
        $cancelUrl = self::url('PAYFAST_CANCEL_URL', $frontend . '/payment/cancel');
        $notifyUrl = self::url('PAYFAST_NOTIFY_URL', $api . '/api/payments/payfast/notify');

        $checkout = Db::transaction(function () use ($payable, $reference, $userId, $accessToken) {
            // Only the owner may pay. Anyone else gets "not found".
            $payer = $payable->payer($reference, $userId, $accessToken);

            $amount = round($payable->amountDue($reference), 2);
            if ($amount <= 0) {
                throw new HttpError(409, 'There is nothing to pay');
            }

            // Opening checkout again reuses the payment already waiting.
            $pending = Payment::pendingFor($payer['bookingId'], $payer['orderId']);
            if ($pending !== null) {
                if (round($pending['amount'], 2) !== $amount) {
                    throw new HttpError(409, 'The saved payment amount does not match the amount due');
                }
                $merchantReference = $pending['merchantReference'];
            } else {
                $merchantReference = self::merchantReference($reference);
                Payment::insertPending($payer['bookingId'], $payer['orderId'], $userId, $amount, $merchantReference);
            }

            return ['payer' => $payer, 'amount' => $amount, 'merchantReference' => $merchantReference];
        });

        $fields = [
            'merchant_id' => $merchantId,
        ];
        // PayFast's form needs the merchant key next to the id. It is not a secret.
        $merchantKey = trim((string) ($_ENV['PAYFAST_MERCHANT_KEY'] ?? ''));
        if ($merchantKey !== '') {
            $fields['merchant_key'] = $merchantKey;
        }
        $fields = $fields + [
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => $notifyUrl,
            'name_first' => $checkout['payer']['name'],
            'name_last' => $checkout['payer']['surname'],
            'email_address' => $checkout['payer']['email'],
            'm_payment_id' => $checkout['merchantReference'],
            'amount' => number_format($checkout['amount'], 2, '.', ''),
            'item_name' => $payable->label($reference),
            'currency' => 'ZAR',
        ];
        $fields['signature'] = PayFast::signature($fields);

        return ['url' => PayFast::paymentUrl(), 'fields' => $fields];
    }

    // PayFast's payment notice. $sourceIp is where it came from.
    public static function processPayFastItn(array $payload, string $sourceIp): array
    {
        self::checkNotice($payload, $sourceIp);
        if (($payload['payment_status'] ?? null) !== 'COMPLETE') {
            return ['status' => 'ignored'];
        }
        if (isset($payload['currency']) && $payload['currency'] !== 'ZAR') {
            throw new HttpError(400, 'PayFast currency does not match ZAR');
        }

        $merchantReference = $payload['m_payment_id'] ?? null;
        $gatewayReference = $payload['pf_payment_id'] ?? null;
        $grossAmount = $payload['amount_gross'] ?? null;
        $detailsOk = is_string($merchantReference) && $merchantReference !== '' && strlen($merchantReference) <= 64
            && is_string($gatewayReference) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $gatewayReference) === 1
            && is_numeric($grossAmount) && is_finite((float) $grossAmount);
        if (!$detailsOk) {
            throw new HttpError(400, 'PayFast notification is missing payment details');
        }

        self::askPayFastToConfirm($payload);

        return Db::transaction(function () use ($merchantReference, $gatewayReference, $grossAmount, $payload) {
            // Locked first: a second copy of this notification waits here, then sees 'paid'
            $payment = Payment::lockByMerchantReference($merchantReference);
            if ($payment === null) {
                throw new HttpError(404, 'Payment reference not found');
            }
            if ($payment['status'] === 'paid') {
                return ['status' => 'already_processed'];
            }
            if ($payment['status'] !== 'pending' && $payment['status'] !== 'processing') {
                throw new HttpError(409, 'Payment is no longer pending');
            }
            if (Payment::gatewayReferenceInUse($gatewayReference)) {
                throw new HttpError(409, 'PayFast payment reference was already used');
            }
            if (round($payment['amount'], 2) !== round((float) $grossAmount, 2)) {
                throw new HttpError(400, 'PayFast amount does not match the saved payment');
            }

            $target = self::targetOf($payment);
            $payable = $target['payable'];

            // Paid twice, or after it stopped waiting: record the money and queue a refund.
            $problem = null;
            if (Payment::otherPaidFor($payment)) {
                $problem = 'Paid twice for ' . $target['reference'] . '; manual refund required';
            } elseif (!$payable->canBePaidNow($target['reference'])) {
                $problem = 'Payment completed after ' . $target['reference'] . ' stopped waiting for payment; manual refund required';
            }
            if ($problem !== null) {
                Payment::markPaidLate($payment['id'], $gatewayReference, $payload, Clock::now(), $problem);
                Payment::insertRefund($payment['id'], $payment['amount'], $problem, null);

                return ['status' => 'late_payment_refund_required'];
            }
            $due = $payable->amountDue($target['reference']);
            if (round($due, 2) !== round($payment['amount'], 2)) {
                throw new HttpError(400, 'Payment amount does not match the amount due');
            }

            Payment::markPaid($payment['id'], $gatewayReference, $payload, Clock::now());
            $payable->onPaid($target['reference'], $payment['id']);

            return ['status' => 'paid'];
        });
    }

    // Guest cancelled at PayFast. The payment is cancelled; the hold runs out on its own.
    public static function cancelPayFastItn(array $payload, string $sourceIp): array
    {
        self::checkNotice($payload, $sourceIp);
        $merchantReference = $payload['m_payment_id'] ?? null;
        if (!is_string($merchantReference) || $merchantReference === '') {
            throw new HttpError(400, 'PayFast notification is missing a payment reference');
        }
        if (isset($payload['payment_status']) && $payload['payment_status'] !== 'CANCELLED') {
            return ['status' => 'ignored'];
        }

        self::askPayFastToConfirm($payload);

        return Db::transaction(function () use ($merchantReference, $payload) {
            $payment = Payment::lockByMerchantReference($merchantReference);
            if ($payment === null) {
                throw new HttpError(404, 'Payment reference not found');
            }
            if ($payment['status'] === 'cancelled') {
                return ['status' => 'already_processed'];
            }
            if ($payment['status'] !== 'pending') {
                return ['status' => 'ignored'];
            }
            Payment::markCancelled($payment['id'], $payload);

            return ['status' => 'cancelled'];
        });
    }

    // Queues a refund for staff to make in PayFast. $actorId is null when the system asks.
    public static function refund(int $paymentId, float $amount, string $reason, ?int $actorId = null): int
    {
        $amount = round($amount, 2);
        $reason = trim($reason);
        if (!is_finite($amount) || $amount <= 0 || $reason === '' || mb_strlen($reason) > 255) {
            throw new HttpError(422, 'Refund amount and a reason of at most 255 characters are required');
        }

        return Db::transaction(function () use ($paymentId, $amount, $reason, $actorId) {
            $payment = Payment::lock($paymentId);
            if ($payment === null || $payment['status'] !== 'paid') {
                throw new HttpError(409, 'Only paid payments can be refunded');
            }
            $left = round($payment['amount'] - Payment::refundedOrPendingAmount($paymentId), 2);
            if ($amount > $left) {
                throw new HttpError(422, 'Refund amount exceeds the unrefunded payment balance');
            }

            return Payment::insertRefund($paymentId, $amount, $reason, $actorId);
        });
    }

    // Records the refund reference. A full refund notifies the booking, unless it never paid.
    public static function markRefundProcessed(int $refundId, int $actorId, string $gatewayReference): void
    {
        if ($gatewayReference === '' || strlen($gatewayReference) > 64) {
            throw new HttpError(422, 'A valid external refund reference is required');
        }

        Db::transaction(function () use ($refundId, $actorId, $gatewayReference) {
            $refund = Payment::lockRefund($refundId);
            if ($refund === null) {
                throw new HttpError(404, 'Refund request not found');
            }
            if ($refund['status'] !== 'pending') {
                throw new HttpError(409, 'Refund request is no longer pending');
            }
            $payment = Payment::lock($refund['paymentId']);
            if ($payment === null || $payment['status'] !== 'paid') {
                throw new HttpError(409, 'Refund payment is not in a refundable state');
            }

            Payment::markRefundProcessed($refundId, $actorId, $gatewayReference);
            $fullyRefunded = Payment::markRefundedIfFullyRefunded($payment['id']);
            // A late or second payment never paid for the booking, so nothing is marked refunded.
            if ($fullyRefunded && $payment['problem'] === null) {
                $target = self::targetOf($payment);
                $target['payable']->onRefunded($target['reference'], $refundId);
            }
        });
    }

    // A stay, a quotation, or a ticket order. Anything else is a 422.
    private static function payableFor(string $kind): Payable
    {
        return match ($kind) {
            'stay' => new StayPayable(),
            'quotation' => new QuotationPayment(),
            'ticket_order' => new TicketOrderPayable(),
            default => throw new HttpError(422, 'Choose what to pay for: stay, quotation or ticket_order'),
        };
    }

    // Which of those this payment row is, and the reference that class knows.
    // A stay is asked first, then a quotation, then a ticket order. The first one that knows the row wins.
    private static function targetOf(array $payment): array
    {
        $stay = new StayPayable();
        $reference = $stay->referenceOf($payment['bookingId'], $payment['orderId']);
        if ($reference !== null) {
            return ['payable' => $stay, 'reference' => $reference];
        }

        $quotation = new QuotationPayment();
        $reference = $quotation->referenceOf($payment['bookingId'], $payment['orderId']);
        if ($reference !== null) {
            return ['payable' => $quotation, 'reference' => $reference];
        }

        $tickets = new TicketOrderPayable();
        $reference = $tickets->referenceOf($payment['bookingId'], $payment['orderId']);
        if ($reference !== null) {
            return ['payable' => $tickets, 'reference' => $reference];
        }

        throw new HttpError(404, 'Payment reference not found');
    }

    // Before reading a notice: PayFast's address, its signature, and our merchant id.
    private static function checkNotice(array $payload, string $sourceIp): void
    {
        if (!PayFast::sourceIpAllowed($sourceIp)) {
            // Logged either way, so a new PayFast address can be added to PAYFAST_VALID_IPS
            error_log('PayFast notification from an unlisted address: ' . $sourceIp);
            // PayFast's sandbox sends from servers outside its published hosts. There the address is only
            // logged: the signature and PayFast's own VALID answer (askPayFastToConfirm) still decide.
            if (!PayFast::sandbox()) {
                throw new HttpError(403, 'PayFast notification source is not allowed');
            }
        }
        if (!PayFast::hasValidSignature($payload)) {
            error_log('PayFast notification refused: the signature does not match (check PAYFAST_PASSPHRASE)');
            throw new HttpError(400, 'PayFast notification signature is invalid');
        }
        if (!isset($payload['merchant_id']) || !hash_equals(PayFast::merchantId(), (string) $payload['merchant_id'])) {
            throw new HttpError(400, 'PayFast merchant does not match');
        }
    }

    // PayFast must say VALID. If it cannot be reached, 503 makes PayFast retry later.
    private static function askPayFastToConfirm(array $payload): void
    {
        try {
            PayFast::validateWithGateway($payload);
        } catch (HttpError $error) {
            // PayFast said no: pass the 400 on. HttpError is caught before RuntimeException.
            error_log('PayFast notification refused: PayFast did not answer VALID');
            throw $error;
        } catch (RuntimeException $error) {
            error_log('PayFast validation unavailable: ' . $error->getMessage());
            throw new HttpError(503, 'PayFast notification validation is temporarily unavailable');
        }
    }

    // One attempt: the booking reference plus 20 random characters.
    private static function merchantReference(string $reference): string
    {
        return substr($reference . '-P-' . bin2hex(random_bytes(10)), 0, 64);
    }

    // Address from .env, or $fallback. Must be a full http(s) URL or PayFast gets a 503.
    private static function url(string $key, string $fallback): string
    {
        $value = trim((string) ($_ENV[$key] ?? ''));
        if ($value === '') {
            $value = $fallback;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new HttpError(503, $key . ' must be an absolute HTTP or HTTPS URL');
        }

        return $value;
    }
}
