<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\QuotationEvent;
use GrandGala\Models\Space;
use GrandGala\Utils\Dates;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Money;

/*
 * Checks a quotation before it is saved, and adds up its lines.
 * The first problem is a 422. A total sent in the request is ignored.
 */
class QuotationForm
{
    // How long a new quotation is valid if staff don't say (the frontend's DAYS_VALID)
    private const DAYS_VALID = 7;

    // The most spaces and rooms one quotation can have
    private const MAX_SPACES = 20;
    private const MAX_ROOMS = 50;

    // Checks the quotation and works out its totals. First problem is a 422.
    public static function checkInput(array $input): array
    {
        $bookingReference = $input['bookingReference'] ?? '';
        if (!is_string($bookingReference) || trim($bookingReference) === '') {
            throw new HttpError(422, 'Choose the booking to quote');
        }

        $items = $input['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new HttpError(422, 'Add at least one line');
        }
        if (count($items) > 100) {
            throw new HttpError(422, 'A quotation can have at most 100 lines');
        }
        $lines = [];
        foreach ($items as $item) {
            $lines[] = self::checkLine($item);
        }

        $discount = $input['discount'] ?? 0;
        Money::check($discount);
        $prices = self::prices($lines, null, (float) $discount);

        $validUntil = $input['validUntil'] ?? null;
        if ($validUntil === null) {
            $validUntil = Dates::addDays(Clock::today(), self::DAYS_VALID);
        }
        if (!is_string($validUntil)) {
            throw new HttpError(422, 'Valid until date must be YYYY-MM-DD');
        }
        Dates::check($validUntil, 'Valid until');
        if ($validUntil < Clock::today()) {
            throw new HttpError(422, 'Valid until can\'t be in the past');
        }

        $notes = $input['notes'] ?? '';
        if (!is_string($notes)) {
            throw new HttpError(422, 'Notes must be text');
        }
        if (mb_strlen($notes) > 5000) {
            throw new HttpError(422, 'Keep the notes to 5000 characters');
        }

        return [
            'bookingReference' => trim($bookingReference),
            'items' => $lines,
            'total' => $prices['total'],
            'notes' => trim($notes),
            'validUntil' => $validUntil,
        ];
    }

    // Plan for a new quotation. Omitted fields come from the enquiry. First problem is a 422.
    public static function checkPlan(array $input, int $bookingId): array
    {
        $request = QuotationEvent::enquiryRequest($bookingId);
        if ($request === null) {
            // No enquiry row: staff have to give the whole plan
            $request = ['date' => null, 'startTime' => null, 'endTime' => null, 'guests' => null, 'spaceIds' => null, 'accommodation' => null];
        }

        $missing = 'The enquiry has no event date, times or spaces, so give them on the quotation';
        $date = self::sentOrFromEnquiry($input, 'eventDate', $request['date'], $missing);
        $startTime = self::sentOrFromEnquiry($input, 'startTime', $request['startTime'], $missing);
        $endTime = self::sentOrFromEnquiry($input, 'endTime', $request['endTime'], $missing);
        $spaces = self::sentOrFromEnquiry($input, 'spaces', $request['spaceIds'], $missing);
        $rooms = self::sentOrFromEnquiry($input, 'rooms', self::roomsAskedFor($request['accommodation']), $missing);

        self::checkDate($date, 'Event');
        if ($date < Clock::today()) {
            throw new HttpError(422, 'The event date can\'t be in the past');
        }
        // Times are 24-hour HH:MM on the event day, so text comparison works ('09:00' < '14:00')
        if (!self::isTime($startTime) || !self::isTime($endTime)) {
            throw new HttpError(422, 'Times must be HH:MM');
        }
        if ($endTime <= $startTime) {
            throw new HttpError(422, 'The end time must be after the start time');
        }

        $spaceIds = self::checkPlanSpaces($spaces);
        $venueId = Space::venueAndKind($spaceIds[0])['venueId'];
        $rooms = self::checkPlanRooms($rooms, $date, $venueId);
        self::checkGuestsFit($spaceIds, $venueId, $request['guests']);

        return [
            'date' => $date,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'spaceIds' => $spaceIds,
            'rooms' => $rooms,
        ];
    }

    // A line can name the space or room it prices, but only one that is on this quotation
    public static function checkLineSpaces(array $items, array $plan): void
    {
        $onQuotation = $plan['spaceIds'];
        foreach ($plan['rooms'] as $room) {
            $onQuotation[] = $room['spaceId'];
        }

        foreach ($items as $item) {
            if ($item['spaceId'] !== null && !in_array($item['spaceId'], $onQuotation, true)) {
                throw new HttpError(422, 'A line can only price a space or room on the quotation');
            }
        }
    }

    // Guests must fit the spaces and the venue. A null count skips the check.
    public static function checkGuestsFit(array $spaceIds, int $venueId, ?int $guests): void
    {
        if ($guests === null) {
            return;
        }

        $capacity = 0;
        foreach ($spaceIds as $spaceId) {
            $space = Space::findForStaff($spaceId);
            $capacity = $capacity + $space['capacity'];
        }
        if ($guests > $capacity) {
            throw new HttpError(422, 'The spaces hold fewer guests than are coming');
        }
        if ($guests > QuotationEvent::venueCapacity($venueId)) {
            throw new HttpError(422, 'The venue holds fewer guests than are coming');
        }
    }

    // On create, $discount is checked against the lines. On read, $total implies the discount.
    public static function prices(array $lines, ?float $total, float $discount = 0.0): array
    {
        $items = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $line['amount'] = round($line['quantity'] * $line['unitPrice'], 2);
            $subtotal = $subtotal + $line['amount'];
            $items[] = $line;
        }
        // Adding many amounts with cents can leave tiny float leftovers, so round to the cent
        $subtotal = round($subtotal, 2);

        if ($total === null) {
            if ($discount > $subtotal) {
                throw new HttpError(422, 'The discount can\'t be more than the lines add up to');
            }
            $total = round($subtotal - $discount, 2);
        } else {
            $discount = round($subtotal - $total, 2);
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            // Prices include VAT at 15%, so the VAT part is 15/115 of the total
            'vatIncluded' => round($total * 15 / 115, 2),
        ];
    }

    // What staff sent for $key, or the enquiry's value if they left it out (missing or null).
    // A 422 with $missing if neither has it.
    private static function sentOrFromEnquiry(array $input, string $key, mixed $fromEnquiry, string $missing): mixed
    {
        if (isset($input[$key])) {
            return $input[$key];
        }
        if ($fromEnquiry === null) {
            throw new HttpError(422, $missing);
        }

        return $fromEnquiry;
    }

    // Rooms when staff omit them. A villa request becomes the whole villa.
    private static function roomsAskedFor(?array $accommodation): array
    {
        if ($accommodation === null) {
            return [];
        }

        // Rooms the guest picked: each of them for the asked nights
        if (count($accommodation['roomIds']) > 0) {
            $rooms = [];
            foreach ($accommodation['roomIds'] as $roomId) {
                $rooms[] = ['spaceId' => $roomId, 'checkIn' => $accommodation['checkIn'], 'checkOut' => $accommodation['checkOut']];
            }

            return $rooms;
        }

        return [[
            'spaceId' => $accommodation['spaceId'],
            'checkIn' => $accommodation['checkIn'],
            'checkOut' => $accommodation['checkOut'],
        ]];
    }

    // 1 to 20 visible spaces, all at one venue. The same space twice counts once.
    private static function checkPlanSpaces(mixed $spaces): array
    {
        if (!is_array($spaces) || !array_is_list($spaces) || count($spaces) < 1 || count($spaces) > self::MAX_SPACES) {
            throw new HttpError(422, 'Choose between 1 and 20 spaces');
        }

        $spaceIds = [];
        $venueId = null;
        foreach ($spaces as $spaceId) {
            $space = null;
            if (is_int($spaceId)) {
                $space = Space::findActive($spaceId);
            }
            if ($space === null) {
                throw new HttpError(422, 'Choose spaces from the list');
            }
            if (in_array($spaceId, $spaceIds, true)) {
                continue;
            }
            // One event happens at one venue (event_booking has one venue)
            $where = Space::venueAndKind($spaceId);
            if ($venueId === null) {
                $venueId = $where['venueId'];
            } elseif ($where['venueId'] !== $venueId) {
                throw new HttpError(422, 'The spaces must be at one venue');
            }
            $spaceIds[] = $spaceId;
        }

        return $spaceIds;
    }

    // Each room or villa once, at the venue. The stay covers the event day or the night before.
    private static function checkPlanRooms(mixed $rooms, string $eventDate, int $venueId): array
    {
        $shape = 'Rooms must be a list of { spaceId, checkIn, checkOut }';
        if (!is_array($rooms) || !array_is_list($rooms) || count($rooms) > self::MAX_ROOMS) {
            throw new HttpError(422, $shape);
        }

        $checked = [];
        $seen = [];
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                throw new HttpError(422, $shape);
            }
            $space = null;
            if (isset($room['spaceId']) && is_int($room['spaceId'])) {
                $space = Space::findActive($room['spaceId']);
            }
            if ($space === null || !$space['isAccommodation']) {
                throw new HttpError(422, 'Choose rooms or the villa from the list');
            }
            if (Space::venueAndKind($space['id'])['venueId'] !== $venueId) {
                throw new HttpError(422, 'The rooms must be at the event\'s venue');
            }
            if (in_array($space['id'], $seen, true)) {
                throw new HttpError(422, 'Each room can be on the quotation once');
            }

            $checkIn = $room['checkIn'] ?? null;
            $checkOut = $room['checkOut'] ?? null;
            self::checkDate($checkIn, 'Check-in');
            self::checkDate($checkOut, 'Check-out');
            if ($checkOut <= $checkIn) {
                throw new HttpError(422, 'Check-out must be after check-in');
            }
            // Event on 17 Oct: 16–17 and 17–19 are fine; 18–20 is not.
            if ($checkIn > $eventDate || $checkOut < $eventDate) {
                throw new HttpError(422, 'Each stay must include the event day or the night before it');
            }

            $seen[] = $space['id'];
            $checked[] = ['spaceId' => $space['id'], 'checkIn' => $checkIn, 'checkOut' => $checkOut];
        }

        return $checked;
    }

    // YYYY-MM-DD, or a 422. Impossible dates and unpadded days are refused.
    private static function checkDate(mixed $value, string $label): void
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || !Dates::isValid($value)) {
            throw new HttpError(422, $label . ' date must be YYYY-MM-DD');
        }
    }

    // 'HH:MM', e.g. '14:00'
    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) === 1;
    }

    // A label, a quantity from 0 with at most 2 decimals, a rand price, and an optional space id.
    private static function checkLine(mixed $item): array
    {
        if (!is_array($item)) {
            throw new HttpError(422, 'Every line needs a label, quantity and unit price');
        }

        $label = $item['label'] ?? '';
        if (!is_string($label) || trim($label) === '') {
            throw new HttpError(422, 'Every line needs a label');
        }
        if (mb_strlen(trim($label)) > 255) {
            throw new HttpError(422, 'Keep each label to 255 characters');
        }

        $quantity = $item['quantity'] ?? null;
        $isNumber = is_int($quantity) || is_float($quantity);
        // != (not !==) because round() always gives a float, and 2 should equal 2.0
        if (!$isNumber || $quantity < 0 || $quantity > 1000000 || round($quantity, 2) != $quantity) {
            throw new HttpError(422, 'Quantities must be numbers from 0 up, with at most 2 decimals');
        }

        $unitPrice = $item['unitPrice'] ?? null;
        Money::check($unitPrice);

        // Optional space or room. Checked against the plan once that is known.
        $spaceId = $item['spaceId'] ?? null;

        return ['label' => trim($label), 'quantity' => (float) $quantity, 'unitPrice' => (float) $unitPrice, 'spaceId' => $spaceId];
    }
}
