<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\PublicEvent;
use GrandGala\Models\Space;
use GrandGala\Utils\Dates;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Money;

/*
 * Checks a public event and its ticket tiers before they are saved.
 * The first problem is a 422.
 */
class EventForm
{
    public const MAX_TIERS = 20;
    private const MAX_SPACES = 20;
    private const MAX_CAPACITY = 1000000;
    // The biggest amount a DECIMAL(12,2) column holds
    private const MAX_PRICE = 9999999999.99;
    private const DEFAULT_IMAGE = '/events/event.jpg';

    // Checks the fields of a new event (not its tiers) and returns them cleaned up
    public static function checkNewEvent(array $input): array
    {
        $title = self::checkTitle($input['title'] ?? null);

        $venueId = $input['venueId'] ?? null;
        if (!is_int($venueId) || $venueId < 1) {
            throw new HttpError(422, 'Choose a venue');
        }

        $date = $input['date'] ?? null;
        if (!is_string($date)) {
            throw new HttpError(422, 'Event date must be YYYY-MM-DD');
        }
        Dates::check($date, 'Event');
        $startTime = self::checkTime($input['startTime'] ?? null, 'Start');
        $endTime = self::checkTime($input['endTime'] ?? null, 'End');
        if ($endTime <= $startTime) {
            throw new HttpError(422, 'The end time must be after the start time');
        }
        if ($date . ' ' . $startTime . ':00' <= Clock::now()) {
            throw new HttpError(422, 'The event must start in the future');
        }
        // Same lead time as Enquiries::LEAD_DAYS, so staff cannot book inside the notice.
        if ($date < Dates::addDays(Clock::today(), Enquiries::LEAD_DAYS)) {
            throw new HttpError(422, 'Event dates must be at least ' . Enquiries::LEAD_DAYS . ' days from today');
        }

        $spaceIds = $input['spaceIds'] ?? null;
        if (!is_array($spaceIds) || !array_is_list($spaceIds) || count($spaceIds) === 0) {
            throw new HttpError(422, 'Choose at least one space');
        }
        if (count($spaceIds) > self::MAX_SPACES) {
            throw new HttpError(422, 'Choose at most 20 spaces');
        }
        foreach ($spaceIds as $spaceId) {
            if (!is_int($spaceId) || $spaceId < 1) {
                throw new HttpError(422, 'Space ids must be whole numbers');
            }
        }
        if (count(array_unique($spaceIds)) !== count($spaceIds)) {
            throw new HttpError(422, 'Choose each space only once');
        }

        $image = self::DEFAULT_IMAGE;
        if (isset($input['image'])) {
            $image = self::checkImage($input['image']);
        }

        return [
            'title' => $title,
            'venueId' => $venueId,
            'date' => $date,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'spaceIds' => $spaceIds,
            'capacity' => self::checkCapacity($input['capacity'] ?? null),
            'description' => self::checkDescription($input['description'] ?? null),
            'image' => $image,
        ];
    }

    // Checks the tiers of a new event: 1 to 20, each valid, no ids, names different
    public static function checkNewTiers(mixed $list, string $eventStart): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) < 1 || count($list) > self::MAX_TIERS) {
            throw new HttpError(422, 'Add between 1 and 20 ticket tiers');
        }

        $tiers = [];
        foreach ($list as $raw) {
            if (!is_array($raw)) {
                throw new HttpError(422, 'Each tier must be an object');
            }
            if (isset($raw['id'])) {
                throw new HttpError(422, "A new event's tiers can't have ids");
            }
            $tiers[] = self::checkTier($raw, $eventStart);
        }
        self::checkNamesAreDifferent($tiers);

        return $tiers;
    }

    // Sale times arrive as YYYY-MM-DDTHH:MM and are stored as YYYY-MM-DD HH:MM:00.
    public static function checkTier(array $raw, string $eventStart): array
    {
        $name = $raw['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80) {
            throw new HttpError(422, 'Give each tier a name of up to 80 characters');
        }

        $description = $raw['description'] ?? null;
        if ($description !== null && (!is_string($description) || mb_strlen($description) > 255)) {
            throw new HttpError(422, 'A tier description can be up to 255 characters');
        }
        if ($description !== null && trim($description) === '') {
            $description = null;
        }

        // Rand with cents (Utils/Money): 120 or 120.5, never text
        $price = $raw['price'] ?? null;
        Money::check($price);
        if ($price > self::MAX_PRICE) {
            throw new HttpError(422, 'The price is too high');
        }

        $allocation = $raw['allocation'] ?? null;
        if (!is_int($allocation) || $allocation < 0 || $allocation > self::MAX_CAPACITY) {
            throw new HttpError(422, 'A tier needs a ticket allocation of 0 or more');
        }

        $maxPerOrder = $raw['maxPerOrder'] ?? 10;
        if (!is_int($maxPerOrder) || $maxPerOrder < 1 || $maxPerOrder > 255) {
            throw new HttpError(422, 'The per-order limit must be from 1 to 255');
        }

        $salesOpen = self::checkSaleTime($raw['salesOpen'] ?? null, 'Sales open');
        $salesClose = self::checkSaleTime($raw['salesClose'] ?? null, 'Sales close');
        if ($salesOpen !== null && $salesClose !== null && $salesClose <= $salesOpen) {
            throw new HttpError(422, 'Ticket sales must close after they open');
        }
        if ($salesClose !== null && $salesClose > $eventStart) {
            throw new HttpError(422, 'Ticket sales must close by the time the event starts');
        }
        if ($salesOpen !== null && $salesOpen >= $eventStart) {
            throw new HttpError(422, 'Ticket sales must open before the event starts');
        }

        $isActive = $raw['active'] ?? true;
        if (!is_bool($isActive)) {
            throw new HttpError(422, 'active must be true or false');
        }

        return [
            'name' => trim($name),
            'description' => $description,
            'price' => (float) $price,
            'allocation' => $allocation,
            'maxPerOrder' => $maxPerOrder,
            'salesOpen' => $salesOpen,
            'salesClose' => $salesClose,
            'isActive' => $isActive,
        ];
    }

    // Tier names must be different, ignoring case and spaces, so guests can tell them apart
    public static function checkNamesAreDifferent(array $tiers): void
    {
        $seen = [];
        foreach ($tiers as $tier) {
            // "Early Bird" and "early  bird" both become "earlybird"
            $key = preg_replace('/\s+/u', '', mb_strtolower($tier['name']));
            if (isset($seen[$key])) {
                throw new HttpError(422, 'Tier names must be different (ignoring case and spaces)');
            }
            $seen[$key] = true;
        }
    }

    // All the tiers' tickets together must fit in the event
    public static function checkAllocations(array $tiers, int $capacity): void
    {
        $total = 0;
        foreach ($tiers as $tier) {
            $total = $total + $tier['allocation'];
        }
        if ($total > $capacity) {
            throw new HttpError(422, 'The tiers have more tickets than the event has seats');
        }
    }

    // Venue must be on. Spaces must be public, hireable, at that venue, and fit capacity.
    public static function checkVenueAndSpaces(array $event): void
    {
        $venue = PublicEvent::venue($event['venueId']);
        if ($venue === null || !$venue['isActive']) {
            throw new HttpError(404, 'Venue not found');
        }

        foreach ($event['spaceIds'] as $spaceId) {
            $space = Space::findActive($spaceId);
            if ($space === null) {
                throw new HttpError(404, 'Space not found');
            }
            if (Space::venueAndKind($spaceId)['venueId'] !== $event['venueId']) {
                throw new HttpError(422, "Choose spaces at the event's venue");
            }
            if (!$space['isHireable']) {
                throw new HttpError(422, "This space can't be hired for events");
            }
        }

        self::checkCapacityFits($event['capacity'], $event['venueId'], $event['spaceIds']);
    }

    // The capacity can't be more than the venue holds, or than the spaces hold together
    public static function checkCapacityFits(int $capacity, int $venueId, array $spaceIds): void
    {
        $venue = PublicEvent::venue($venueId);
        if ($capacity > $venue['capacity']) {
            throw new HttpError(422, "The capacity can't be more than the venue holds (" . $venue['capacity'] . ')');
        }

        $spacesHold = 0;
        foreach ($spaceIds as $spaceId) {
            $space = Space::findForStaff($spaceId);
            if ($space !== null) {
                $spacesHold = $spacesHold + $space['capacity'];
            }
        }
        if ($capacity > $spacesHold) {
            throw new HttpError(422, "The capacity can't be more than the chosen spaces hold (" . $spacesHold . ')');
        }
    }

    public static function checkTitle(mixed $title): string
    {
        if (!is_string($title) || trim($title) === '') {
            throw new HttpError(422, 'Give the event a title');
        }
        if (mb_strlen(trim($title)) > 150) {
            throw new HttpError(422, 'The title can be up to 150 characters');
        }

        return trim($title);
    }

    // An optional description of up to 10 000 characters. Empty means none (null).
    public static function checkDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }
        if (!is_string($description) || mb_strlen($description) > 10000) {
            throw new HttpError(422, 'The description can be up to 10 000 characters');
        }
        if (trim($description) === '') {
            return null;
        }

        return trim($description);
    }

    // Own-site path or https only. javascript:, http: and // URLs are refused.
    public static function checkImage(mixed $image): string
    {
        $message = 'The image must be a path like /events/photo.jpg or an https:// link';
        if (!is_string($image) || trim($image) === '' || strlen(trim($image)) > 255) {
            throw new HttpError(422, $message);
        }
        $image = trim($image);

        $isPath = str_starts_with($image, '/') && !str_starts_with($image, '//');
        $isHttps = str_starts_with($image, 'https://') && filter_var($image, FILTER_VALIDATE_URL) !== false;
        if (!$isPath && !$isHttps) {
            throw new HttpError(422, $message);
        }

        return $image;
    }

    public static function checkCapacity(mixed $capacity): int
    {
        if (!is_int($capacity) || $capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new HttpError(422, 'The capacity must be a whole number from 1');
        }

        return $capacity;
    }

    // 'HH:MM' from 00:00 to 23:59, e.g. checkTime('18:30', 'Start')
    private static function checkTime(mixed $time, string $label): string
    {
        if (!is_string($time) || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) !== 1) {
            throw new HttpError(422, $label . ' time must be HH:MM');
        }

        return $time;
    }

    // Optional YYYY-MM-DDTHH:MM. Returns YYYY-MM-DD HH:MM:00, or null if omitted.
    private static function checkSaleTime(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) !== 1) {
            throw new HttpError(422, $label . ' must be YYYY-MM-DDTHH:MM');
        }
        $date = substr($value, 0, 10);
        if (!Dates::isValid($date)) {
            throw new HttpError(422, $label . ' must be YYYY-MM-DDTHH:MM');
        }

        return $date . ' ' . substr($value, 11, 5) . ':00';
    }

    public static function checkReason(mixed $reason): string
    {
        if (!is_string($reason) || trim($reason) === '') {
            throw new HttpError(422, 'Give a reason for cancelling');
        }
        if (mb_strlen(trim($reason)) > 255) {
            throw new HttpError(422, 'The reason can be up to 255 characters');
        }

        return trim($reason);
    }
}
