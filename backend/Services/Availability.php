<?php

declare(strict_types=1);

namespace GrandGala\Services;

use DateTimeImmutable;
use GrandGala\Models\Blackout;
use GrandGala\Models\HeldDates;
use GrandGala\Models\Space;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use LogicException;

/*
 * Is a space free on these dates? The calendar and the booking check share one state list.
 * Strongest wins: blacked_out, booked, held, mode_locked, then free.
 */
class Availability
{
    // How strong each state is. A date can only move to a stronger state.
    private const STRENGTH = ['free' => 0, 'mode_locked' => 1, 'held' => 2, 'booked' => 3, 'blacked_out' => 4];

    // Check the dates before taking a reference, so a refusal never touches the counter.
    // Stay: check-in up to check-out. Event: every day from $from to $to. Failure rolls back.
    public static function reserve(array $spaceIds, string $from, string $to, string $mode, callable $save): mixed
    {
        // The same space twice would only be checked twice
        $spaceIds = array_values(array_unique($spaceIds));
        if (count($spaceIds) === 0) {
            throw new HttpError(422, 'Choose at least one space');
        }

        return Db::transaction(function () use ($spaceIds, $from, $to, $mode, $save) {
            $venueId = null;
            foreach ($spaceIds as $spaceId) {
                if (!is_int($spaceId) || Space::findActive($spaceId) === null) {
                    throw new HttpError(404, 'Space not found');
                }
                // One venue lock has to cover every space, so they must share a venue
                $space = Space::venueAndKind($spaceId);
                if ($venueId === null) {
                    $venueId = $space['venueId'];
                } elseif ($space['venueId'] !== $venueId) {
                    throw new HttpError(422, 'All spaces must be at the same venue');
                }
            }

            foreach ($spaceIds as $spaceId) {
                self::assertFree($spaceId, $from, $to, $mode);
            }

            $reference = References::next('booking');

            return $save($reference);
        });
    }

    // 409 unless free on every date. Inside a transaction; booking code should call reserve().
    // Stay skips check-out. 'event' is private; 'public_event' leaves rooms open.
    // $ignoreBookingId skips that booking, so it can change its own dates.
    public static function assertFree(int $spaceId, string $from, string $to, string $mode, ?int $ignoreBookingId = null): void
    {
        self::checkFree($spaceId, $from, $to, $mode, $ignoreBookingId, null);
    }

    // A stay for a private event's guests. Allowed on $eventDate; other nights follow stay rules.
    public static function assertFreeForEventGuests(int $spaceId, string $checkIn, string $checkOut, string $eventDate): void
    {
        self::checkFree($spaceId, $checkIn, $checkOut, 'stay', null, $eventDate);
    }

    // The check behind assertFree() and assertFreeForEventGuests().
    // $hostEventDate is the event day of the guests being given rooms, or null for everyone else.
    private static function checkFree(int $spaceId, string $from, string $to, string $mode, ?int $ignoreBookingId, ?string $hostEventDate): void
    {
        // Outside a transaction the venue lock ends at once, so two bookings could both pass.
        if (!Db::pdo()->inTransaction()) {
            throw new LogicException('Availability::assertFree() must run inside Db::transaction(). Booking code should use Availability::reserve().');
        }

        Dates::check($from, 'From');
        Dates::check($to, 'To');
        if ($from > $to) {
            throw new HttpError(422, 'End date must be on or after the start date');
        }
        if ($mode !== 'stay' && $mode !== 'event' && $mode !== 'public_event') {
            throw new HttpError(422, 'Mode must be stay, event or public_event');
        }

        // The last night of a stay is the day before check-out
        $lastDate = $to;
        if ($mode === 'stay') {
            if ($to === $from) {
                throw new HttpError(422, 'Check-out must be after check-in');
            }
            $lastDate = Dates::addDays($to, -1);
        }

        $space = Space::venueAndKind($spaceId);
        if ($space === null) {
            throw new HttpError(404, 'Space not found');
        }

        // Lock the venue, so a second booking at the same time has to wait for this one to finish
        Space::lockVenue($space['venueId']);

        $states = self::statesOn($spaceId, $space['venueId'], $from, $lastDate, $mode, $ignoreBookingId, $hostEventDate, true);

        self::refuseIfTaken($states, $mode);
    }

    // The state of each date from $from to $to.
    public static function calendar(int $spaceId, string $from, string $to): array
    {
        Dates::check($from, 'From');
        Dates::check($to, 'To');
        if ($from > $to) {
            throw new HttpError(422, 'End date must be on or after the start date');
        }
        $days = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
        if ($days > 366) {
            throw new HttpError(422, 'Availability range is too long');
        }

        $space = Space::venueAndKind($spaceId);
        if ($space === null) {
            throw new HttpError(404, 'Space not found');
        }

        // Rooms and villas are booked per night; other spaces per day
        $mode = 'event';
        if ($space['isAccommodation']) {
            $mode = 'stay';
        }

        $states = self::statesOn($spaceId, $space['venueId'], $from, $to, $mode, null, null, false);

        $calendar = [];
        foreach ($states as $date => $state) {
            $calendar[] = ['date' => $date, 'state' => $state];
        }

        return $calendar;
    }

    // State of each date. $lock keeps the rows fixed until the booking is saved.
    private static function statesOn(int $spaceId, int $venueId, string $from, string $to, string $mode, ?int $ignoreBookingId, ?string $hostEventDate, bool $lock): array
    {
        // Start with every date free
        $states = [];
        $date = $from;
        while ($date <= $to) {
            $states[$date] = 'free';
            $date = Dates::addDays($date, 1);
        }

        // A villa and its rooms block each other, so look at all of them (3.2)
        $spaceIds = Space::linkedIds($spaceId);

        // 1. Blackouts (3.3). The end date is included.
        foreach (Blackout::forSpacesBetween($spaceIds, $from, $to, $lock) as $blackout) {
            $states = self::mark($states, $blackout['start'], Dates::addDays($blackout['end'], 1), 'blacked_out');
        }

        // 2. Stays hold their nights. So do villa nights given to event guests (3.7).
        $stays = array_merge(
            HeldDates::staysOf($spaceIds, $from, $to, $lock),
            HeldDates::allocationsOf($spaceIds, $from, $to, $lock),
        );
        foreach ($stays as $stay) {
            $state = self::bookingState($stay, $ignoreBookingId);
            $states = self::mark($states, $stay['checkIn'], $stay['checkOut'], $state);
        }

        // 3. Events hold their space for the day
        foreach (HeldDates::eventsOf($spaceIds, $from, $to, $lock) as $event) {
            $state = self::bookingState($event, $ignoreBookingId);
            $states = self::mark($states, $event['date'], Dates::addDays($event['date'], 1), $state);
        }

        // Private-event days are only for its guests. Public events leave rooms open.
        if ($mode === 'stay') {
            foreach (HeldDates::privateEventsAtVenue($venueId, $from, $to, $lock) as $event) {
                // Event guests are allowed on their own event's day. Events don't share spaces,
                // so another event that day doesn't stop them either.
                $isHostDay = $event['date'] === $hostEventDate;
                if (!$isHostDay && self::bookingState($event, $ignoreBookingId) !== 'free') {
                    $states = self::mark($states, $event['date'], Dates::addDays($event['date'], 1), 'mode_locked');
                }
            }
        }
        if ($mode === 'event') {
            foreach (HeldDates::staysAtVenue($venueId, $from, $to, $lock) as $stay) {
                if (self::bookingState($stay, $ignoreBookingId) !== 'free') {
                    $states = self::mark($states, $stay['checkIn'], $stay['checkOut'], 'mode_locked');
                }
            }
            // Event guests sleeping over count as guests staying, except on their own event's day:
            // that day is already an event day, so another event can share it
            foreach (HeldDates::allocationsAtVenue($venueId, $from, $to, $lock) as $stay) {
                if (self::bookingState($stay, $ignoreBookingId) !== 'free') {
                    $states = self::mark($states, $stay['checkIn'], $stay['eventDate'], 'mode_locked');
                    $states = self::mark($states, Dates::addDays($stay['eventDate'], 1), $stay['checkOut'], 'mode_locked');
                }
            }
        }

        return $states;
    }

    // 409 for the first date that is not free.
    private static function refuseIfTaken(array $states, string $mode): void
    {
        foreach ($states as $state) {
            if ($state === 'blacked_out') {
                throw new HttpError(409, 'Not available on these dates');
            }
            if ($state === 'booked' || $state === 'held') {
                throw new HttpError(409, 'Already booked on these dates');
            }
            // Private event day vs guests staying. Public events never get this state.
            if ($state === 'mode_locked') {
                if ($mode === 'stay') {
                    throw new HttpError(409, 'These dates are reserved for an event');
                }
                throw new HttpError(409, 'Guests are staying over on these dates');
            }
        }
    }

    // Gives every date from $start up to (not including) $end the new state,
    // unless the date already has a stronger state
    private static function mark(array $states, string $start, string $end, string $newState): array
    {
        foreach ($states as $date => $state) {
            $inRange = $date >= $start && $date < $end;
            if ($inRange && self::STRENGTH[$newState] > self::STRENGTH[$state]) {
                $states[$date] = $newState;
            }
        }

        return $states;
    }

    // What a booking does to its dates: booked, held, or free.
    private static function bookingState(array $booking, ?int $ignoreBookingId): string
    {
        if ($booking['bookingId'] === $ignoreBookingId) {
            return 'free';
        }
        if ($booking['status'] === 'confirmed' || $booking['status'] === 'completed') {
            return 'booked';
        }
        // Unpaid holds until expiry. No expiry holds until paid or cancelled.
        if ($booking['status'] === 'pending_payment' && Holds::isRunning($booking['holdExpires'])) {
            return 'held';
        }

        // Cancelled, expired, or the hold ran out
        return 'free';
    }
}
