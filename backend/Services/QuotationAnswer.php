<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Quotation;
use GrandGala\Models\QuotationEvent;
use GrandGala\Models\QuotationItem;
use GrandGala\Models\QuotationPlan;
use GrandGala\Models\Space;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * The guest accepts, declines, or asks for changes.
 * Accept reserves that plan and holds the booking for payment, in one transaction.
 */
class QuotationAnswer
{
    // One transaction: reserve the plan, hold for payment, then give the rooms.
    // Rooms need the booking to be waiting for payment. Any failure rolls back.
    public static function accept(string $reference, int $userId): array
    {
        $result = Db::transaction(function () use ($reference, $userId) {
            $answer = self::answerable($reference, $userId);
            $quotation = $answer['quotation'];
            $booking = $answer['booking'];

            $plan = self::agreedPlan($quotation);
            $venueId = self::venueOf($plan['spaceIds']);
            // The occasion and how many are coming are what the guest told us in the enquiry
            $request = QuotationEvent::enquiryRequest($booking['id']);
            $occasion = null;
            $guests = null;
            if ($request !== null) {
                $occasion = $request['occasion'];
                $guests = $request['guests'];
            }
            // Checked again here: staff can make a space smaller after the quotation was sent
            QuotationForm::checkGuestsFit($plan['spaceIds'], $venueId, $guests);

            if (QuotationEvent::existsFor($booking['id'])) {
                throw new HttpError(409, 'This booking already has an event');
            }
            // The booking's own id is ignored, so an earlier hold of its own never blocks it
            foreach ($plan['spaceIds'] as $spaceId) {
                Availability::assertFree($spaceId, $plan['date'], $plan['date'], 'event', $booking['id']);
            }

            $eventId = QuotationEvent::insertPrivateEvent($booking['id'], $venueId, [
                'date' => $plan['date'],
                'startTime' => $plan['startTime'],
                'endTime' => $plan['endTime'],
                'occasion' => $occasion,
                'guests' => $guests,
            ]);
            $items = QuotationItem::forQuotation($quotation['id']);
            foreach ($plan['spaceIds'] as $spaceId) {
                QuotationEvent::insertSpace($eventId, $spaceId, self::linePrice($items, $spaceId));
            }

            $prices = QuotationForm::prices($items, $quotation['total']);
            // A private event's hold (Holds: 72 hours unless QUOTATION_HOLD_HOURS says otherwise)
            $holdUntil = Holds::endOfNewHold('event');
            Quotation::setStatus($quotation['id'], 'accepted');
            Quotation::freezePrice($booking['id'], $prices['subtotal'], $prices['discount'], $prices['total']);
            BookingLifecycle::holdForPayment($booking['id'], $holdUntil, $userId, 'Accepted quotation ' . $reference);

            foreach ($plan['rooms'] as $room) {
                EventAccommodation::add($booking['reference'], [
                    'spaceId' => $room['spaceId'],
                    'checkIn' => $room['checkIn'],
                    'checkOut' => $room['checkOut'],
                ]);
            }

            return [
                'reference' => $reference,
                'bookingReference' => $booking['reference'],
                'status' => 'accepted',
                'bookingStatus' => 'pending_payment',
                'total' => $prices['total'],
                'holdExpires' => str_replace(' ', 'T', substr($holdUntil, 0, 16)),
                'guestEmail' => $quotation['guestEmail'],
            ];
        });

        // Payment link is sent only after the acceptance is saved.
        Mailer::send('payment_link', $result['guestEmail'], [
            'reference' => $result['reference'],
            'bookingReference' => $result['bookingReference'],
            'total' => $result['total'],
            'holdExpires' => $result['holdExpires'],
        ]);
        unset($result['guestEmail']);

        return $result;
    }

    // The guest says no: the booking is cancelled
    public static function decline(string $reference, int $userId): array
    {
        return self::respond($reference, $userId, 'cancelled', 'Declined quotation ' . $reference);
    }

    // Back to enquiry so staff can send a new version. The note is stored, at most 255 characters.
    public static function requestChanges(string $reference, int $userId, array $input): array
    {
        $note = $input['note'] ?? '';
        if (!is_string($note) || trim($note) === '') {
            throw new HttpError(422, 'Tell us what you would like changed');
        }
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            throw new HttpError(422, 'Keep the note to 255 characters');
        }

        return self::respond($reference, $userId, 'enquiry', $note);
    }

    // Declines the quotation and moves the booking to $bookingStatus, noting why
    private static function respond(string $reference, int $userId, string $bookingStatus, string $reason): array
    {
        return Db::transaction(function () use ($reference, $userId, $bookingStatus, $reason) {
            $answer = self::answerable($reference, $userId);
            $bookingId = $answer['booking']['id'];

            Quotation::setStatus($answer['quotation']['id'], 'declined');
            BookingLifecycle::move($bookingId, $bookingStatus, $userId, $reason);

            return ['reference' => $reference, 'status' => 'declined', 'bookingStatus' => $bookingStatus];
        });
    }

    // Booking locked. 404 if it is not theirs; 409 if it is no longer answerable.
    private static function answerable(string $reference, int $userId): array
    {
        $found = Quotation::find($reference);
        if ($found === null || $found['userId'] !== $userId) {
            throw new HttpError(404, 'Quotation not found');
        }

        // Lock the booking, then read its versions again: they can't change until we finish
        $booking = Quotation::lockBooking($found['bookingReference']);
        $versions = Quotation::forBooking($booking['id'], true);
        $latest = $versions[0];
        if ($latest['reference'] !== $reference || $latest['status'] !== 'sent') {
            throw new HttpError(409, 'Only the latest quotation sent to you can be answered');
        }
        // Valid until includes the whole of that day
        if ($latest['validUntil'] < Clock::today()) {
            throw new HttpError(409, 'This quotation has expired');
        }
        if ($booking['status'] !== 'quoted') {
            throw new HttpError(409, 'This booking is no longer waiting for an answer');
        }

        return ['quotation' => $latest, 'booking' => $booking];
    }

    // Plan being accepted. 409 if missing, or if its day has passed.
    private static function agreedPlan(array $quotation): array
    {
        $spaceIds = [];
        foreach (QuotationPlan::spacesOf($quotation['id']) as $space) {
            $spaceIds[] = $space['id'];
        }
        if ($quotation['eventDate'] === null || $quotation['startTime'] === null || count($spaceIds) === 0) {
            throw new HttpError(409, 'This quotation has no event plan. Ask us for a new version.');
        }
        if ($quotation['eventDate'] < Clock::today()) {
            throw new HttpError(409, 'The event day has passed');
        }

        return [
            'date' => $quotation['eventDate'],
            'startTime' => $quotation['startTime'],
            'endTime' => $quotation['endTime'],
            'spaceIds' => $spaceIds,
            'rooms' => QuotationPlan::roomsOf($quotation['id']),
        ];
    }

    // The venue every space is at. One event happens at one venue (event_booking has one venue).
    // Checked again at accept, because staff can switch a space off after the quotation was sent.
    private static function venueOf(array $spaceIds): int
    {
        $venueId = null;
        foreach ($spaceIds as $spaceId) {
            if (Space::findActive($spaceId) === null) {
                throw new HttpError(409, 'A space on the quotation can no longer be booked');
            }
            $space = Space::venueAndKind($spaceId);
            if ($venueId === null) {
                $venueId = $space['venueId'];
            } elseif ($space['venueId'] !== $venueId) {
                throw new HttpError(422, 'The quotation\'s spaces must be at one venue');
            }
        }

        return $venueId;
    }

    // Frozen space price is the lines that name it, or R0. The guest pays the quotation total.
    private static function linePrice(array $items, int $spaceId): float
    {
        $price = 0.0;
        foreach ($items as $item) {
            if ($item['spaceId'] === $spaceId) {
                $price = $price + round($item['quantity'] * $item['unitPrice'], 2);
            }
        }

        // Adding amounts with cents can leave tiny float leftovers, so round to the cent
        return round($price, 2);
    }
}
