<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Stay;
use GrandGala\Services\PaymentGateway\Payable;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Payable for a stay. The amount is the total frozen on the booking, never the request.
 * Who pays, and what payment and refund do, are shared with BookingPayment.
 */
class StayPayable implements Payable
{
    public function payer(string $reference, ?int $userId, ?string $accessToken): array
    {
        return BookingPayment::payer(Stay::guest($reference), $reference, $userId);
    }

    public function amountDue(string $reference): float
    {
        return Db::transaction(function () use ($reference) {
            $booking = self::lockedStay($reference);
            BookingPayment::checkCanBePaid($booking);

            return $booking['total'];
        });
    }

    public function canBePaidNow(string $reference): bool
    {
        return Db::transaction(function () use ($reference) {
            return BookingPayment::canBePaid(self::lockedStay($reference));
        });
    }

    public function label(string $reference): string
    {
        return 'Grand Gala stay ' . $reference;
    }

    public function referenceOf(?int $bookingId, ?int $orderId): ?string
    {
        if ($bookingId === null) {
            return null;
        }

        return Stay::referenceOf($bookingId);
    }

    public function onPaid(string $reference, int $paymentId): void
    {
        Db::transaction(function () use ($reference) {
            $booking = self::lockedStay($reference);
            $email = Stay::guest($reference)['email'];
            BookingPayment::confirm($booking, $email, ['reference' => $reference, 'bookingReference' => $reference], 'PayFast payment confirmed');
        });
    }

    public function onRefunded(string $reference, int $refundId): void
    {
        Db::transaction(function () use ($reference) {
            BookingPayment::finishRefund(self::lockedStay($reference), 'Refunded stay ' . $reference);
        });
    }

    // The stay booking, locked until the transaction ends, or 404
    private static function lockedStay(string $reference): array
    {
        $booking = Stay::lockByReference($reference);
        if ($booking === null) {
            throw new HttpError(404, 'Stay booking not found');
        }

        return $booking;
    }
}
