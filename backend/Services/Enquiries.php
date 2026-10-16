<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Enquiry;
use GrandGala\Models\Space;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * An enquiry charges nothing and holds no dates: status 'enquiry', plus catering and decor rows.
 * The form is checked again here, because the browser cannot be trusted.
 */
class Enquiries
{
    public const PER_PAGE = 20;

    // Preferred date, alternate date and stay check-in must be this many days after today.
    public const LEAD_DAYS = 12;

    private const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'all'];

    // $userId is the signed-in guest, never taken from the body.
    public static function submit(int $userId, array $input): array
    {
        $brief = self::checkBrief($input);

        $guest = Enquiry::activeGuest($userId);
        if ($guest === null) {
            throw new HttpError(401, 'Sign in with an active account');
        }
        $eventTypeId = Enquiry::eventTypeId();
        if ($eventTypeId === null) {
            throw new HttpError(500, 'The event booking type is missing');
        }

        // The booking, the enquiry and its extras are saved together or not at all.
        // The reference is taken inside the transaction, so a failed save gives the number back.
        $reference = Db::transaction(function () use ($userId, $eventTypeId, $brief) {
            $reference = References::next('booking');
            $bookingId = Enquiry::insertBooking($reference, $eventTypeId, $userId, Clock::now());
            $enquiryId = Enquiry::insertEnquiry($bookingId, $brief);
            Enquiry::insertSpaces($enquiryId, $brief['spaceIds']);
            if ($brief['accommodation'] !== null) {
                Enquiry::insertRooms($enquiryId, $brief['accommodation']['roomIds']);
            }
            if ($brief['wantsCatering']) {
                Enquiry::insertCatering($bookingId, $brief['mealType'], $brief['estimatedGuests'], $brief['cateringRequest']);
            }
            if ($brief['wantsDecor']) {
                Enquiry::insertDecoration($bookingId, $brief['decorRequest']);
            }

            return $reference;
        });

        // Email only after the save, so a rolled-back enquiry is never announced.
        $mail = self::mailData($reference, $guest, $brief);
        self::sendEmail('enquiry_received', $guest['email'], $mail);
        // Staff see every enquiry on the Enquiries page; STAFF_ENQUIRY_EMAIL, if set, also gets a heads-up
        $staffEmail = trim((string) ($_ENV['STAFF_ENQUIRY_EMAIL'] ?? ''));
        if ($staffEmail !== '') {
            self::sendEmail('enquiry_staff', $staffEmail, $mail);
        }

        return ['reference' => $reference, 'status' => 'enquiry'];
    }

    // Already saved, so a mail failure is logged. A 500 would make the guest resubmit.
    private static function sendEmail(string $template, string $to, array $data): void
    {
        try {
            Mailer::send($template, $to, $data);
        } catch (\Throwable $error) {
            error_log('Enquiry ' . $data['reference'] . ': the ' . $template . ' email was not sent: ' . $error->getMessage());
        }
    }

    // One status chip (enquiry if omitted), newest first, plus a count for each chip.
    public static function list(array $query): array
    {
        $status = 'enquiry';
        if (isset($query['status']) && is_string($query['status'])) {
            $status = $query['status'];
        }
        if (!isset(Enquiry::CHIPS[$status])) {
            throw new HttpError(422, 'Unknown status');
        }

        // A non-integer or a page below 1 means page 1.
        $page = 1;
        if (isset($query['page']) && is_string($query['page']) && ctype_digit($query['page']) && (int) $query['page'] >= 1) {
            $page = (int) $query['page'];
        }

        $result = Enquiry::page(Enquiry::CHIPS[$status], self::PER_PAGE, $page);

        // At least one page, even when nothing matches
        $pageCount = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return [
            'enquiries' => $result['enquiries'],
            'page' => $page,
            'pageCount' => $pageCount,
            'total' => $result['total'],
            'counts' => Enquiry::counts(),
        ];
    }

    public static function detail(string $reference): array
    {
        $enquiry = Enquiry::find($reference);
        if ($enquiry === null) {
            throw new HttpError(404, 'Enquiry not found');
        }

        return $enquiry;
    }

    // First problem is a 422 the page can show. Returns the cleaned brief.
    private static function checkBrief(array $input): array
    {
        $occasion = self::text($input, 'occasion', 'The occasion', 150);
        if ($occasion === '') {
            throw new HttpError(422, 'Tell us the occasion');
        }

        // Preferred and alternate dates need the lead time. Alternate is optional.
        $preferredDate = self::date($input['preferredDate'] ?? null, 'Preferred');
        $alternateDate = null;
        if (isset($input['alternateDate']) && $input['alternateDate'] !== '') {
            $alternateDate = self::date($input['alternateDate'], 'Alternate');
        }
        $today = Clock::today();
        $earliest = Dates::addDays($today, self::LEAD_DAYS);
        if ($preferredDate < $earliest || ($alternateDate !== null && $alternateDate < $earliest)) {
            throw new HttpError(422, 'Event dates must be at least 12 days from today');
        }

        // Times are 24-hour HH:MM on the event day, so text comparison works ('09:00' < '14:00')
        $startTime = $input['startTime'] ?? null;
        $endTime = $input['endTime'] ?? null;
        if (!self::isTime($startTime) || !self::isTime($endTime)) {
            throw new HttpError(422, 'Times must be HH:MM');
        }
        if ($endTime <= $startTime) {
            throw new HttpError(422, 'The end time must be after the start time');
        }

        // 65535 is the most the catering headcount column can hold
        $estimatedGuests = $input['estimatedGuests'] ?? null;
        if (!self::isWholeNumber($estimatedGuests, 1, 65535)) {
            throw new HttpError(422, 'Estimated guests must be a whole number from 1 to 65535');
        }

        // Each space once, as id => name.
        $spaces = self::checkSpaces($input['requestedSpaces'] ?? null);
        $spaceIds = [];
        $spaceNames = [];
        foreach ($spaces as $id => $name) {
            $spaceIds[] = $id;
            $spaceNames[] = $name;
        }

        // Yes/no answers must be real true or false: "false" as text would count as yes in PHP
        $flags = [];
        foreach (['wantsCatering', 'selfCatering', 'wantsDecor', 'selfDecor', 'contactConsent'] as $key) {
            $flags[$key] = self::flag($input, $key);
        }
        // POPIA: we may only contact the guest about the enquiry if they agree
        if (!$flags['contactConsent']) {
            throw new HttpError(422, 'Please agree to be contacted about your enquiry');
        }
        // The venue provides it, or the guest brings their own: not both
        if ($flags['wantsCatering'] && $flags['selfCatering']) {
            throw new HttpError(422, 'Choose venue catering or your own, not both');
        }
        if ($flags['wantsDecor'] && $flags['selfDecor']) {
            throw new HttpError(422, 'Choose venue décor or your own, not both');
        }

        $mealType = $input['mealType'] ?? 'all';
        if (!is_string($mealType) || !in_array($mealType, self::MEAL_TYPES, true)) {
            throw new HttpError(422, 'Choose breakfast, lunch, dinner or all');
        }

        return [
            'occasion' => $occasion,
            'preferredDate' => $preferredDate,
            'alternateDate' => $alternateDate,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'estimatedGuests' => $estimatedGuests,
            'spaceIds' => $spaceIds,
            'spaces' => $spaceNames,
            'accommodation' => self::checkAccommodation($input['accommodation'] ?? null, $earliest),
            'wantsCatering' => $flags['wantsCatering'],
            'selfCatering' => $flags['selfCatering'],
            'mealType' => $mealType,
            'cateringRequest' => self::text($input, 'cateringRequest', 'The catering request', 10000),
            'wantsDecor' => $flags['wantsDecor'],
            'selfDecor' => $flags['selfDecor'],
            'decorRequest' => self::text($input, 'decorRequest', 'The décor request', 10000),
            'requirements' => self::text($input, 'requirements', 'Requirements', 10000),
        ];
    }

    // 1 to 20 visible spaces, by id or name. Each once, in the order given, as id => name.
    private static function checkSpaces(mixed $requested): array
    {
        if (!is_array($requested) || !array_is_list($requested) || count($requested) < 1 || count($requested) > 20) {
            throw new HttpError(422, 'Choose between 1 and 20 spaces');
        }

        $names = [];
        foreach ($requested as $item) {
            $space = self::findVisibleSpace($item);
            if ($space === null) {
                throw new HttpError(422, 'Choose spaces from the list');
            }
            // Keyed by id, so the same space asked for twice (by id and by name) counts once
            $names[$space['id']] = $space['name'];
        }

        return $names;
    }

    // A visible space by id or name (any case), or null.
    private static function findVisibleSpace(mixed $item): ?array
    {
        if (is_int($item)) {
            return Space::findActive($item);
        }
        if (!is_string($item) || trim($item) === '') {
            return null;
        }

        $wanted = mb_strtolower(trim($item));
        foreach (Space::allActive() as $space) {
            if (mb_strtolower($space['name']) === $wanted) {
                return $space;
            }
        }

        return null;
    }

    // A request only. The quotation decides the rooms, and they are given on accept.
    // Either spaceId plus a room count, or roomIds from one villa.
    private static function checkAccommodation(mixed $request, string $earliest): ?array
    {
        if ($request === null || $request === []) {
            return null;
        }
        if (!is_array($request) || array_is_list($request)) {
            throw new HttpError(422, 'Accommodation must have a space, check-in, check-out, rooms and guests');
        }

        // Picked rooms: the villa stands for the request, and the count is how many were picked.
        $roomIds = [];
        if (array_key_exists('roomIds', $request)) {
            $roomIds = self::checkPickedRooms($request['roomIds']);
            $request['spaceId'] = Space::findActive($roomIds[0])['parentId'];
            $request['rooms'] = count($roomIds);
        }

        $space = null;
        if (isset($request['spaceId']) && is_int($request['spaceId'])) {
            $space = Space::findActive($request['spaceId']);
        }
        if ($space === null || !$space['isAccommodation']) {
            throw new HttpError(422, 'Choose a room or villa for accommodation');
        }

        $checkIn = self::date($request['checkIn'] ?? null, 'Check-in');
        $checkOut = self::date($request['checkOut'] ?? null, 'Check-out');
        if ($checkIn < $earliest) {
            throw new HttpError(422, 'Check-in must be at least 12 days from today');
        }
        if ($checkOut <= $checkIn) {
            throw new HttpError(422, 'Check-out must be after check-in');
        }

        $rooms = $request['rooms'] ?? null;
        if (!self::isWholeNumber($rooms, 1, 100)) {
            throw new HttpError(422, 'Rooms must be a whole number from 1 to 100');
        }
        $guests = $request['guests'] ?? null;
        if (!self::isWholeNumber($guests, 1, 65535)) {
            throw new HttpError(422, 'Guests staying must be a whole number from 1 to 65535');
        }

        return [
            'spaceId' => $space['id'],
            'space' => $space['name'],
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'rooms' => $rooms,
            'guests' => $guests,
            'roomIds' => $roomIds,
        ];
    }

    // 1 to 20 visible rooms, all in one villa, each once. Returns their ids.
    private static function checkPickedRooms(mixed $roomIds): array
    {
        $problem = 'Choose rooms from one villa';
        if (!is_array($roomIds) || !array_is_list($roomIds) || count($roomIds) < 1 || count($roomIds) > 20) {
            throw new HttpError(422, $problem);
        }

        $picked = [];
        $villaId = null;
        foreach ($roomIds as $roomId) {
            $room = null;
            if (is_int($roomId)) {
                $room = Space::findActive($roomId);
            }
            // Only rooms have a villa above them (the spaces editor keeps it that way)
            if ($room === null || !$room['isAccommodation'] || $room['parentId'] === null) {
                throw new HttpError(422, $problem);
            }
            if ($villaId !== null && $room['parentId'] !== $villaId) {
                throw new HttpError(422, $problem);
            }
            $villaId = $room['parentId'];
            if (!in_array($roomId, $picked, true)) {
                $picked[] = $roomId;
            }
        }

        return $picked;
    }

    // What the two emails need: the guest, the reference and the whole brief (spaces by name)
    private static function mailData(string $reference, array $guest, array $brief): array
    {
        $accommodation = null;
        if ($brief['accommodation'] !== null) {
            $accommodation = [
                'space' => $brief['accommodation']['space'],
                'checkIn' => $brief['accommodation']['checkIn'],
                'checkOut' => $brief['accommodation']['checkOut'],
                'rooms' => $brief['accommodation']['rooms'],
                'guests' => $brief['accommodation']['guests'],
            ];
        }

        return [
            'reference' => $reference,
            'guestName' => $guest['name'] . ' ' . $guest['surname'],
            'guestEmail' => $guest['email'],
            'guestPhone' => $guest['phone'],
            'occasion' => $brief['occasion'],
            'preferredDate' => $brief['preferredDate'],
            'alternateDate' => $brief['alternateDate'],
            'startTime' => $brief['startTime'],
            'endTime' => $brief['endTime'],
            'estimatedGuests' => $brief['estimatedGuests'],
            'spaces' => $brief['spaces'],
            'accommodation' => $accommodation,
            'wantsCatering' => $brief['wantsCatering'],
            'selfCatering' => $brief['selfCatering'],
            'mealType' => $brief['mealType'],
            'cateringRequest' => $brief['cateringRequest'],
            'wantsDecor' => $brief['wantsDecor'],
            'selfDecor' => $brief['selfDecor'],
            'decorRequest' => $brief['decorRequest'],
            'requirements' => $brief['requirements'],
        ];
    }

    // YYYY-MM-DD, or a 422. Impossible dates such as 2026-02-30 are refused.
    private static function date(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || !Dates::isValid($value)) {
            throw new HttpError(422, $label . ' date must be YYYY-MM-DD');
        }

        return $value;
    }

    // True for a 24-hour time like '09:30' or '23:59'
    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    // True for a whole number (not text like "80", not 1.5) from $min to $max
    private static function isWholeNumber(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }

    // A yes/no answer from the body. Missing means no.
    private static function flag(array $input, string $key): bool
    {
        $value = $input[$key] ?? false;
        if (!is_bool($value)) {
            throw new HttpError(422, $key . ' must be true or false');
        }

        return $value;
    }

    // Optional text from the body, trimmed, at most $max characters (missing means '')
    private static function text(array $input, string $key, string $label, int $max): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            throw new HttpError(422, $label . ' must be text');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new HttpError(422, $label . ' can be at most ' . $max . ' characters');
        }

        return $value;
    }
}
