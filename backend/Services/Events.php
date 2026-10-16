<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\PublicEvent;
use GrandGala\Models\Space;
use GrandGala\Models\TicketOrder;
use GrandGala\Models\TicketType;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Ticketed public events. Date, times, venue and spaces are fixed after create.
 * EventForm checks what staff typed. EventView builds the JSON, including sale status.
 */
class Events
{
    // Fields that can't change once the event exists, and the ones that can
    private const FIXED_FIELDS = ['date', 'startTime', 'endTime', 'venueId', 'spaceIds'];
    private const EDITABLE_FIELDS = ['title', 'description', 'image', 'capacity'];

    // Upcoming public events that guests can see, soonest first
    public static function publicList(): array
    {
        $events = [];
        foreach (PublicEvent::upcoming(Clock::today(), true) as $event) {
            $events[] = EventView::present($event, false);
        }

        return $events;
    }

    // One event for guests. Cancelled events and events at a switched-off venue are not found.
    public static function publicDetail(int $eventId): array
    {
        $event = PublicEvent::find($eventId);
        if ($event === null || $event['status'] !== 'confirmed' || !$event['venueIsActive']) {
            throw new HttpError(404, 'Event not found');
        }

        return EventView::present($event, false);
    }

    // Upcoming public events for staff (not cancelled), soonest first, with sales and revenue
    public static function staffList(): array
    {
        $events = [];
        foreach (PublicEvent::upcoming(Clock::today(), false) as $event) {
            $events[] = EventView::present($event, true);
        }

        return $events;
    }

    // One event for staff, cancelled or not
    public static function staffDetail(int $eventId): array
    {
        $event = PublicEvent::find($eventId);
        if ($event === null) {
            throw new HttpError(404, 'Event not found');
        }

        return EventView::present($event, true);
    }

    // Creates the event and tiers. $staffId is stored as the booking owner.
    public static function create(array $input, int $staffId): array
    {
        $event = EventForm::checkNewEvent($input);
        $tiers = EventForm::checkNewTiers($input['tiers'] ?? null, self::startOf($event));
        EventForm::checkAllocations($tiers, $event['capacity']);
        EventForm::checkVenueAndSpaces($event);

        // reserve() locks the venue, checks the day is free, then saves. Errors roll back.
        $eventId = Availability::reserve($event['spaceIds'], $event['date'], $event['date'], 'public_event', function (string $reference) use ($event, $tiers, $staffId) {
            $eventId = PublicEvent::insert($reference, $staffId, $event);
            foreach ($tiers as $tier) {
                TicketType::insert($eventId, $tier);
            }

            return $eventId;
        });

        return self::staffDetail($eventId);
    }

    // Changes the title, description, image or capacity (PATCH). Everything else is fixed.
    public static function update(int $eventId, array $input): array
    {
        foreach (array_keys($input) as $field) {
            if (in_array($field, self::FIXED_FIELDS, true)) {
                throw new HttpError(422, 'The date, times, venue and spaces are fixed. Cancel the event and create a new one');
            }
            if (!in_array($field, self::EDITABLE_FIELDS, true)) {
                throw new HttpError(422, 'Unknown field: ' . $field);
            }
        }

        $event = self::findForStaff($eventId);

        Db::transaction(function () use ($event, $input) {
            $event = self::lockEvent($event);

            $title = $event['title'];
            if (array_key_exists('title', $input)) {
                $title = EventForm::checkTitle($input['title']);
            }
            $description = $event['description'];
            if (array_key_exists('description', $input)) {
                $description = EventForm::checkDescription($input['description']);
            }
            $image = $event['image'];
            if (array_key_exists('image', $input)) {
                $image = EventForm::checkImage($input['image']);
            }
            $capacity = $event['capacity'];
            if (array_key_exists('capacity', $input)) {
                $capacity = EventForm::checkCapacity($input['capacity']);
            }

            // The new capacity must still fit the venue and spaces, and hold every tier's tickets
            EventForm::checkCapacityFits($capacity, $event['venueId'], PublicEvent::spaceIds($event['id']));
            EventForm::checkAllocations(TicketType::forEvent($event['id'], true), $capacity);

            PublicEvent::update($event['id'], $title, $description, $image, $capacity);
        });

        return self::staffDetail($eventId);
    }

    // PUT tiers: an id updates that tier, no id adds one. Unsent tiers stay.
    public static function saveTiers(int $eventId, array $input): array
    {
        $list = $input['tiers'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) < 1 || count($list) > EventForm::MAX_TIERS) {
            throw new HttpError(422, 'Send between 1 and 20 tiers');
        }

        $event = self::findForStaff($eventId);

        Db::transaction(function () use ($event, $list) {
            // Lock order: venue, event, tiers, then orders (same as ticket sales).
            $event = self::lockEvent($event);
            $current = [];
            foreach (TicketType::forEvent($event['id'], true) as $tier) {
                $current[$tier['id']] = $tier;
            }
            $seats = TicketOrder::seatsTaken($event['id'], Holds::cutOff(), true);
            $start = self::startOf($event);

            $changes = [];
            $newTiers = [];
            foreach ($list as $raw) {
                if (!is_array($raw)) {
                    throw new HttpError(422, 'Each tier must be an object');
                }
                $tierId = $raw['id'] ?? null;
                if ($tierId === null) {
                    $newTiers[] = EventForm::checkTier($raw, $start);
                    continue;
                }
                if (!is_int($tierId) || !isset($current[$tierId]) || isset($changes[$tierId])) {
                    throw new HttpError(422, "A tier in the list doesn't belong to this event, or is listed twice");
                }

                $tier = EventForm::checkTier($raw, $start);
                // Tickets already paid for, or held for someone paying now, can't be taken away
                $taken = 0;
                if (isset($seats[$tierId])) {
                    $taken = $seats[$tierId]['taken'];
                }
                if ($tier['allocation'] < $taken) {
                    throw new HttpError(409, $tier['name'] . " can't have fewer than the " . $taken . ' tickets already sold or held');
                }
                $changes[$tierId] = $tier;
            }

            // Check the event's tiers as they will be after the change
            $after = [];
            foreach ($current as $tierId => $tier) {
                if (isset($changes[$tierId])) {
                    $after[] = $changes[$tierId];
                } else {
                    $after[] = $tier;
                }
            }
            foreach ($newTiers as $tier) {
                $after[] = $tier;
            }
            if (count($after) > EventForm::MAX_TIERS) {
                throw new HttpError(422, 'An event can have at most 20 tiers');
            }
            EventForm::checkNamesAreDifferent($after);
            EventForm::checkAllocations($after, $event['capacity']);

            foreach ($changes as $tierId => $tier) {
                TicketType::update($tierId, $tier);
            }
            foreach ($newTiers as $tier) {
                TicketType::insert($event['id'], $tier);
            }
        });

        return self::staffDetail($eventId);
    }

    // Refunds paid orders, cancels the rest, and keeps the event. $staffId goes on the history.
    public static function cancel(int $eventId, array $input, int $staffId): array
    {
        $reason = EventForm::checkReason($input['reason'] ?? null);
        $event = self::findForStaff($eventId);

        $refunds = Db::transaction(function () use ($event, $reason, $staffId) {
            // Lock order: venue, event, tiers, then orders (same as ticket sales).
            Space::lockVenue($event['venueId']);
            $event = PublicEvent::find($event['id'], true);
            if ($event['status'] === 'cancelled') {
                throw new HttpError(409, 'This event is already cancelled');
            }
            TicketType::forEvent($event['id'], true);

            $refunds = Tickets::cancelOrdersForEvent($event['id'], $reason);
            BookingLifecycle::move($event['bookingId'], 'cancelled', $staffId, $reason);

            return $refunds;
        });

        return ['eventId' => $eventId, 'status' => 'cancelled', 'refundsAskedFor' => $refunds];
    }

    // DB 'YYYY-MM-DD HH:MM:SS' becomes API 'YYYY-MM-DDTHH:MM'. Null stays null.
    public static function apiDateTime(?string $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return substr($dateTime, 0, 10) . 'T' . substr($dateTime, 11, 5);
    }

    // When the event starts, as 'YYYY-MM-DD HH:MM:00', to compare with Clock::now()
    public static function startOf(array $event): string
    {
        return $event['date'] . ' ' . $event['startTime'] . ':00';
    }

    private static function findForStaff(int $eventId): array
    {
        $event = PublicEvent::find($eventId);
        if ($event === null) {
            throw new HttpError(404, 'Event not found');
        }

        return $event;
    }

    // Lock venue, then event. Cancelled events cannot change. Inside a transaction.
    private static function lockEvent(array $event): array
    {
        Space::lockVenue($event['venueId']);
        $event = PublicEvent::find($event['id'], true);
        if ($event['status'] !== 'confirmed') {
            throw new HttpError(409, "A cancelled event can't be changed");
        }

        return $event;
    }
}
