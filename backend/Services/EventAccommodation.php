<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\EventAllocation;
use GrandGala\Models\Space;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Rooms for a private event's guests. Public-event guests book their own.
 * The stay covers the event day or the night before. Only a future allocation can be removed.
 */
class EventAccommodation
{
    // The event's allocations, soonest first
    public static function list(string $reference): array
    {
        $event = self::findEvent($reference);

        return EventAllocation::forEvent($event['eventId']);
    }

    // Gives a room or the villa. Needs spaceId, checkIn and checkOut.
    public static function add(string $reference, array $input): array
    {
        $event = self::findEvent($reference);
        if ($event['isPublic']) {
            throw new HttpError(422, 'Public event guests book rooms themselves');
        }
        if ($event['status'] !== 'pending_payment' && $event['status'] !== 'confirmed') {
            throw new HttpError(422, 'Accommodation can be added once the quotation is accepted');
        }

        $spaceId = $input['spaceId'] ?? null;
        $checkIn = $input['checkIn'] ?? '';
        $checkOut = $input['checkOut'] ?? '';
        if (!is_int($spaceId)) {
            throw new HttpError(422, 'Choose a room or villa');
        }
        if (!is_string($checkIn) || !is_string($checkOut)) {
            throw new HttpError(422, 'Check-in and check-out must be YYYY-MM-DD');
        }
        Dates::check($checkIn, 'Check-in');
        Dates::check($checkOut, 'Check-out');
        if ($checkOut <= $checkIn) {
            throw new HttpError(422, 'Check-out must be after check-in');
        }
        // Event on 17 Oct: 16–17 and 17–19 are fine; 18–20 is not.
        if ($checkIn > $event['eventDate'] || $checkOut < $event['eventDate']) {
            throw new HttpError(422, 'The stay must include the event day or the night before it');
        }

        self::checkRoom($spaceId, $event['venueId']);

        $price = self::priceOfNights($spaceId, $checkIn, $checkOut);

        $allocationId = Db::transaction(function () use ($event, $spaceId, $checkIn, $checkOut, $price) {
            // Locks the venue and checks the room and its villa are free (409 if not)
            Availability::assertFreeForEventGuests($spaceId, $checkIn, $checkOut, $event['eventDate']);

            return EventAllocation::insert($event['eventId'], $spaceId, $checkIn, $checkOut, $price);
        });

        return EventAllocation::find($allocationId);
    }

    // Takes a room back from the event's guests, so its nights are free again
    public static function remove(string $reference, int $allocationId): void
    {
        $event = self::findEvent($reference);

        $allocation = EventAllocation::find($allocationId);
        if ($allocation === null || $allocation['eventId'] !== $event['eventId']) {
            throw new HttpError(404, 'Accommodation not found');
        }
        if ($allocation['checkIn'] <= Clock::today()) {
            throw new HttpError(422, "Only accommodation that hasn't started can be removed");
        }

        EventAllocation::delete($allocationId);
    }

    // Throws unless the space is a room or villa at the event's venue
    private static function checkRoom(int $spaceId, int $venueId): void
    {
        $space = Space::findForStaff($spaceId);
        if ($space === null) {
            throw new HttpError(404, 'Space not found');
        }
        if (!$space['isAccommodation']) {
            throw new HttpError(422, 'Only rooms and villas can be given to event guests');
        }
        if ($space['venueId'] !== $venueId) {
            throw new HttpError(422, "The space must be at the event's venue");
        }
    }

    // The event booking behind a reference. 404 if there's no booking, 422 if it's a stay.
    private static function findEvent(string $reference): array
    {
        $event = EventAllocation::findEvent($reference);
        if ($event === null) {
            throw new HttpError(404, 'Booking not found');
        }
        if ($event['type'] !== 'event' || $event['eventId'] === null) {
            throw new HttpError(422, 'Only event bookings have accommodation');
        }

        return $event;
    }

    // Each night's own rate, up to the night before check-out. 422 if a night has no price.
    private static function priceOfNights(int $spaceId, string $checkIn, string $checkOut): float
    {
        $total = 0.0;
        $night = $checkIn;
        while ($night < $checkOut) {
            $rate = RateSchedule::rateOn($spaceId, 'per_night', $night);
            if ($rate === null) {
                throw new HttpError(422, 'This space has no price for these nights');
            }
            $total = $total + $rate['price'];
            $night = Dates::addDays($night, 1);
        }

        // Adding many prices with cents can leave tiny float leftovers, so round to the cent
        return round($total, 2);
    }
}
