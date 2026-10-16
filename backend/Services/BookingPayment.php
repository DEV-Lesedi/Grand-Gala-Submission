<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Utils\HttpError;

/*
 * Shared by stays and quotations. Only the guest who made it pays, and only while the hold runs.
 * A second payment or refund notice changes nothing. Refunds require the booking to be cancelled.
 */
class BookingPayment
{
    // Payer from the guest who made it. 401 if nobody is signed in; 404 for anyone else.
    public static function payer(?array $guest, string $reference, ?int $userId): array
    {
        if ($guest === null) {
            throw new HttpError(404, 'Nothing to pay was found for ' . $reference);
        }
        if ($userId === null) {
            throw new HttpError(401, 'Please sign in');
        }
        // Someone else's booking is "not found".
        if ($guest['userId'] !== $userId) {
            throw new HttpError(404, 'Nothing to pay was found for ' . $reference);
        }

        return [
            'bookingId' => $guest['bookingId'],
            'orderId' => null,
            'userId' => $guest['userId'],
            'name' => $guest['name'],
            'surname' => $guest['surname'],
            'email' => $guest['email'],
        ];
    }

    // True if the booking is still waiting for payment and its hold hasn't run out
    public static function canBePaid(array $booking): bool
    {
        return $booking['status'] === 'pending_payment' && Holds::isRunning($booking['holdExpires']);
    }

    // Throws a 409 unless the booking can be paid
    public static function checkCanBePaid(array $booking): void
    {
        if (!self::canBePaid($booking)) {
            throw new HttpError(409, 'The hold on this booking has run out or it was cancelled');
        }
    }

    // Confirm and email the guest. Inside the payment transaction, with the booking locked.
    public static function confirm(array $booking, string $email, array $mailData, string $reason): void
    {
        // PayFast can send the same notice twice: the second time there's nothing to do
        if ($booking['status'] === 'confirmed') {
            return;
        }
        self::checkCanBePaid($booking);

        BookingLifecycle::move($booking['id'], 'confirmed', $booking['userId'], $reason);
        Mailer::send('booking_confirmed', $email, $mailData);
    }

    // The cancelled booking becomes refunded. Staff finish refunds, so nobody is named.
    public static function finishRefund(array $booking, string $reason): void
    {
        // A second notice for the same refund: there's nothing to do
        if ($booking['status'] === 'refunded') {
            return;
        }
        if ($booking['status'] !== 'cancelled') {
            throw new HttpError(409, 'Cancel the booking before finishing its refund');
        }

        BookingLifecycle::move($booking['id'], 'refunded', null, $reason);
    }
}
