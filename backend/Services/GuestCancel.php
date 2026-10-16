<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\MyBookings;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Guest cancel. Unpaid bookings cancel at once. A paid stay cancels and needs a refund review.
 * A paid event is refused (409); staff cancel those. Anything already finished is 409.
 */
class GuestCancel
{
    private const UNPAID = ['enquiry', 'quoted', 'pending_payment'];

    // Someone else's booking is "not found". A paid stay sets refundReviewRequired.
    public static function cancel(string $reference, int $userId): array
    {
        $bookingId = MyBookings::bookingIdForUser($reference, $userId);
        if ($bookingId === null) {
            throw new HttpError(404, 'Booking not found');
        }

        return Db::transaction(function () use ($reference, $userId, $bookingId) {
            $booking = MyBookings::lockForCancel($bookingId);
            if ($booking === null || $booking['userId'] !== $userId) {
                throw new HttpError(404, 'Booking not found');
            }

            $isUnpaid = in_array($booking['status'], self::UNPAID, true);
            $isPaidStay = $booking['status'] === 'confirmed' && $booking['type'] === 'stay';
            if ($booking['status'] === 'confirmed' && !$isPaidStay) {
                throw new HttpError(409, 'A confirmed event can\'t be cancelled here. Contact us and we will cancel and refund it.');
            }
            if (!$isUnpaid && !$isPaidStay) {
                throw new HttpError(409, 'This booking can no longer be cancelled');
            }

            BookingLifecycle::move($bookingId, 'cancelled', $userId, 'Cancelled by guest');
            if ($isUnpaid) {
                Quotations::closeForCancelledBooking($bookingId);
            }

            return ['reference' => $reference, 'status' => 'cancelled', 'refundReviewRequired' => $isPaidStay];
        });
    }
}
