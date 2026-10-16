<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Quotation;
use GrandGala\Services\PaymentGateway\Payable;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Payable for an accepted quotation. The amount is frozen when the guest accepted.
 * Only the latest accepted one can be paid. Payment and refund follow BookingPayment.
 */
class QuotationPayment implements Payable
{
    public function payer(string $reference, ?int $userId, ?string $accessToken): array
    {
        return BookingPayment::payer(Quotation::guest($reference), $reference, $userId);
    }

    public function amountDue(string $reference): float
    {
        return Db::transaction(function () use ($reference) {
            $booking = $this->bookingToPay($reference);
            BookingPayment::checkCanBePaid($booking);

            return $booking['total'];
        });
    }

    public function canBePaidNow(string $reference): bool
    {
        return Db::transaction(function () use ($reference) {
            $booking = $this->lockedBooking($reference);

            return $this->isLatestAccepted($booking, $reference) && BookingPayment::canBePaid($booking);
        });
    }

    public function label(string $reference): string
    {
        return 'Grand Gala event ' . $reference;
    }

    // The booking's latest quotation. A stay has none.
    public function referenceOf(?int $bookingId, ?int $orderId): ?string
    {
        if ($bookingId === null) {
            return null;
        }
        $versions = Quotation::forBooking($bookingId, false);
        if (count($versions) === 0) {
            return null;
        }

        return $versions[0]['reference'];
    }

    public function onPaid(string $reference, int $paymentId): void
    {
        Db::transaction(function () use ($reference) {
            $booking = $this->bookingToPay($reference);
            $mailData = ['reference' => $reference, 'bookingReference' => $booking['reference']];
            BookingPayment::confirm($booking, $booking['guestEmail'], $mailData, 'Paid quotation ' . $reference);
        });
    }

    public function onRefunded(string $reference, int $refundId): void
    {
        Db::transaction(function () use ($reference) {
            BookingPayment::finishRefund($this->bookingToPay($reference), 'Refunded quotation ' . $reference);
        });
    }

    // Booking of the latest accepted quotation, locked, with the guest email. 409 otherwise.
    private function bookingToPay(string $reference): array
    {
        $booking = $this->lockedBooking($reference);
        if (!$this->isLatestAccepted($booking, $reference)) {
            throw new HttpError(409, 'Only the latest accepted quotation can be paid');
        }

        return $booking;
    }

    // The quotation's booking, locked, with the guest email. 404 if it does not exist.
    private function lockedBooking(string $reference): array
    {
        $quotation = Quotation::find($reference);
        if ($quotation === null) {
            throw new HttpError(404, 'Quotation not found');
        }

        $booking = Quotation::lockBooking($quotation['bookingReference']);
        $booking['guestEmail'] = $quotation['guestEmail'];

        return $booking;
    }

    // True if $reference is the booking's latest quotation and the guest accepted it
    private function isLatestAccepted(array $booking, string $reference): bool
    {
        $latest = Quotation::forBooking($booking['id'], true)[0];

        return $latest['reference'] === $reference && $latest['status'] === 'accepted';
    }
}
