<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Space;
use GrandGala\Models\Stay;

/*
 * Dates are taken under the venue lock, then priced again. The request cannot set the price.
 * A promo that no longer matches the quote is a 409. Every price is frozen on the booking.
 */
class StayBookings
{
    // Creates the stay for $userId. Same input as Pricing::stay(), plus optional notes.
    public static function create(array $input, int $userId): array
    {
        // Priced once first, so bad input is a 422 before anything is locked
        $quote = Pricing::stay($input, $userId);
        $notes = null;
        if (isset($input['notes']) && is_string($input['notes']) && trim($input['notes']) !== '') {
            $notes = trim($input['notes']);
        }
        $promoCode = null;
        if (isset($input['promoCode']) && is_string($input['promoCode'])) {
            $promoCode = $input['promoCode'];
        }

        $spaceId = $quote['spaceId'];

        return Availability::reserve([$spaceId], $quote['checkIn'], $quote['checkOut'], 'stay', function (string $reference) use ($input, $userId, $notes, $promoCode, $spaceId) {
            return self::saveReservedStay($reference, $input, $userId, $notes, $promoCode, $spaceId);
        });
    }

    // Under the date lock: price again, start the hold, write history, hold the quoted promo.
    private static function saveReservedStay(string $reference, array $input, int $userId, ?string $notes, ?string $promoCode, int $spaceId): array
    {
        // Lock the space, then price again. Prices cannot change until this finishes.
        Space::lock($spaceId);
        $quote = Pricing::stay($input, $userId);

        $holdExpires = Holds::endOfNewHold('stay');
        $bookingId = Stay::insertBooking([
            'reference' => $reference,
            'userId' => $userId,
            'subtotal' => $quote['subtotal'],
            'promoId' => $quote['promoId'],
            'discount' => $quote['discount'],
            'total' => $quote['total'],
            'notes' => $notes,
        ]);
        BookingLifecycle::createdWaitingForPayment($bookingId, $holdExpires, $userId, 'Stay booked');

        // Hold the code. It may have changed, or its last use may be gone, since the quote.
        Promotions::reserveQuoted($promoCode, 'stay', $quote['subtotal'], $userId, $bookingId, $quote['promoId'], $quote['discount']);

        self::saveLines($bookingId, $quote, $userId);

        return [
            'reference' => $reference,
            'status' => 'pending_payment',
            'holdExpires' => str_replace(' ', 'T', substr($holdExpires, 0, 16)),
            'subtotal' => $quote['subtotal'],
            'discount' => $quote['discount'],
            'total' => $quote['total'],
        ];
    }

    // Freeze the stay, each amenity, and one booking line per night and per amenity.
    private static function saveLines(int $bookingId, array $quote, int $userId): void
    {
        $stayId = Stay::insertStay($bookingId, $quote['checkIn'], $quote['checkOut'], $quote['guests']);

        $spaceTotal = 0.0;
        foreach ($quote['lines'] as $line) {
            if ($line['kind'] === 'space') {
                $spaceTotal = round($spaceTotal + $line['total'], 2);
                Stay::insertBookingLine($bookingId, 'space', $line['description'], 1, $line['unitPrice'], $line['total'], $userId, $line['metadata']);
            } else {
                Stay::insertAmenityLine($bookingId, $line['metadata']['amenityId'], $line['quantity'], $line['unitPrice'], $line['total']);
                Stay::insertBookingLine($bookingId, 'amenity', $line['description'], $line['quantity'], $line['unitPrice'], $line['total'], $userId, $line['metadata']);
            }
        }
        // Nights added up are stored as both the price and the line total.
        Stay::insertSpaceLine($stayId, $quote['spaceId'], $quote['nights'], $spaceTotal, $spaceTotal);
    }
}
