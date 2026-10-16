<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Enquiry;
use GrandGala\Models\Media;
use GrandGala\Models\MyBookings;

/*
 * Guest booking cards, split into upcoming and past.
 * Dates and money stay raw; only status and the action label are derived here.
 */
class MyBookingsFeed
{
    // $tab upcoming: today or later, or no date yet. Past: earlier, or cancelled/expired/refunded.
    public static function forUser(int $userId, string $tab, string $today): array
    {
        $items = self::cardsOf($userId);

        $filtered = [];
        foreach ($items as $item) {
            $isPast = in_array($item['status'], ['cancelled', 'expired', 'refunded'], true)
                || ($item['startDate'] !== null && $item['startDate'] < $today);
            if ($isPast === ($tab === 'past')) {
                $filtered[] = $item;
            }
        }

        // Upcoming: soonest first. Past: most recent first. No date sorts last either way.
        if ($tab === 'past') {
            usort($filtered, [self::class, 'mostRecentFirst']);
        } else {
            usort($filtered, [self::class, 'soonestFirst']);
        }

        return $filtered;
    }

    // Owner only. Someone else's reference is null, never "forbidden".
    public static function findForUser(int $userId, string $reference): ?array
    {
        foreach (self::cardsOf($userId) as $item) {
            if ($item['reference'] === $reference) {
                $item['enquiry'] = self::enquiryOf($item);

                return $item;
            }
        }

        return null;
    }

    // The guest's enquiry and change requests, or null. Contact and staff-only facts are left out.
    private static function enquiryOf(array $item): ?array
    {
        if ($item['type'] !== 'event') {
            return null;
        }
        $enquiry = Enquiry::find($item['reference']);
        if ($enquiry === null) {
            return null;
        }

        return [
            'occasion' => $enquiry['occasion'],
            'spaces' => $enquiry['spaces'],
            'preferredDate' => $enquiry['preferredDate'],
            'alternateDate' => $enquiry['alternateDate'],
            'startTime' => $enquiry['startTime'],
            'endTime' => $enquiry['endTime'],
            'estimatedGuests' => $enquiry['estimatedGuests'],
            'accommodation' => $enquiry['accommodation'],
            'wantsCatering' => $enquiry['wantsCatering'],
            'selfCatering' => $enquiry['selfCatering'],
            'mealType' => $enquiry['mealType'],
            'cateringRequest' => $enquiry['cateringRequest'],
            'wantsDecor' => $enquiry['wantsDecor'],
            'selfDecor' => $enquiry['selfDecor'],
            'decorRequest' => $enquiry['decorRequest'],
            'requirements' => $enquiry['requirements'],
            'received' => $enquiry['received'],
            'changeRequests' => $enquiry['changeRequests'],
        ];
    }

    // Bookings, then ticket orders. Photos are loaded once for the whole list.
    private static function cardsOf(int $userId): array
    {
        $bookingRows = MyBookings::bookingsOf($userId);
        $orderRows = MyBookings::ticketOrdersOf($userId);

        // A stay's photo is its (first) space's photo; an event's is the event's own photo.
        $spaceIds = [];
        $eventIds = [];
        foreach ($bookingRows as $row) {
            if ($row['bt_name'] === 'event' && $row['eb_id'] !== null) {
                $eventIds[] = (int) $row['eb_id'];
            } elseif ($row['stay_photo_space_id'] !== null) {
                $spaceIds[] = (int) $row['stay_photo_space_id'];
            }
        }
        foreach ($orderRows as $row) {
            $eventIds[] = (int) $row['to_eb_id'];
        }
        $spacePhotos = Media::primaryPhotosOf('space', array_unique($spaceIds));
        $eventPhotos = Media::primaryPhotosOf('event', array_unique($eventIds));

        $items = [];
        foreach ($bookingRows as $row) {
            $image = null;
            if ($row['bt_name'] === 'event' && $row['eb_id'] !== null) {
                $image = $eventPhotos[(int) $row['eb_id']] ?? null;
            } elseif ($row['stay_photo_space_id'] !== null) {
                $image = $spacePhotos[(int) $row['stay_photo_space_id']] ?? null;
            }
            $items[] = self::toBookingItem($row, $image);
        }
        foreach ($orderRows as $row) {
            $items[] = self::toTicketOrderItem($row, $eventPhotos[(int) $row['to_eb_id']] ?? null);
        }

        return $items;
    }

    private static function toBookingItem(array $row, ?string $image): array
    {
        $title = (string) ($row['stay_spaces'] ?? '');
        if ($row['bt_name'] === 'event') {
            $title = (string) $row['event_spaces'];
            if ($row['eb_occasion'] !== null && $row['eb_occasion'] !== '') {
                $title = $row['eb_occasion'];
            } elseif (($row['enquiry_occasion'] ?? null) !== null && $row['enquiry_occasion'] !== '') {
                // Still an enquiry (or a quote): the occasion the guest typed, e.g. 'Wedding'
                $title = $row['enquiry_occasion'];
            }
        }

        $guests = $row['bt_name'] === 'event' ? $row['eb_guest_count'] : $row['sb_guests'];
        if ($guests !== null) {
            $guests = (int) $guests;
        }

        [$status, $actionLabel, $price, $quotationReference] = self::bookingStatus(
            $row['b_status'],
            $row['b_reference'],
            (float) $row['b_total_price']
        );

        return [
            'reference' => $row['b_reference'],
            'kind' => 'booking',
            'type' => $row['bt_name'],
            'status' => $status,
            'title' => $title !== '' ? $title : ucfirst($row['bt_name']),
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'guests' => $guests,
            'ticketCount' => null,
            'price' => $price,
            'image' => $image,
            'actionLabel' => $actionLabel,
            'actionTo' => self::actionTo($status, $row['b_reference'], $quotationReference),
        ];
    }

    // b_status -> [card status, action label, price to show, quotation reference if any].
    // 'quoted' shows the quotation's amount, not the booking's (still 0 until it's accepted).
    private static function bookingStatus(string $bStatus, string $bookingReference, float $totalPrice): array
    {
        if ($bStatus === 'enquiry') {
            return ['enquiry', 'View', null, null];
        }
        if ($bStatus === 'quoted') {
            // Latest version, so a revised quote shows its new total.
            $quotation = Quotations::latestFor($bookingReference);
            if ($quotation === null) {
                return ['quote_sent', 'Review quote', null, null];
            }

            return ['quote_sent', 'Review quote', $quotation['total'], $quotation['reference']];
        }
        if ($bStatus === 'pending_payment') {
            return ['pending_payment', 'Pay now', $totalPrice, null];
        }
        if ($bStatus === 'confirmed' || $bStatus === 'completed') {
            return ['confirmed', 'View', $totalPrice, null];
        }

        return [$bStatus, 'View', $totalPrice, null]; // cancelled, expired, refunded
    }

    private static function actionTo(string $status, string $reference, ?string $quotationReference): string
    {
        if ($status === 'quote_sent') {
            return '/quotations/' . ($quotationReference ?? $reference);
        }
        if ($status === 'pending_payment') {
            return '/checkout?booking=' . $reference;
        }

        return '/my-bookings/' . $reference;
    }

    private static function toTicketOrderItem(array $row, ?string $image): array
    {
        // A paid order shows as 'tickets'. Other statuses keep their names.
        $status = $row['to_status'];
        if ($status === 'paid') {
            $status = 'tickets';
        }
        $actionLabel = 'View';
        $actionTo = '/tickets/' . $row['to_reference'];
        if ($status === 'tickets') {
            $actionLabel = 'Show tickets';
        }
        if ($status === 'pending_payment') {
            $actionLabel = 'Pay now';
            $actionTo = '/checkout?order=' . $row['to_reference'];
        }

        return [
            'reference' => $row['to_reference'],
            'kind' => 'ticket_order',
            'type' => 'ticket_order',
            'status' => $status,
            'title' => $row['eb_title'] ?? ($row['eb_occasion'] ?? 'Event tickets'),
            'startDate' => $row['eb_event_date'],
            'endDate' => $row['eb_event_date'],
            'guests' => null,
            'ticketCount' => (int) $row['ticket_count'],
            'price' => (float) $row['to_total_price'],
            'image' => $image,
            'actionLabel' => $actionLabel,
            'actionTo' => $actionTo,
        ];
    }

    // usort() order for upcoming: soonest start date first, no date last
    private static function soonestFirst(array $a, array $b): int
    {
        return self::compareDates($a['startDate'], $b['startDate'], false);
    }

    // usort() order for past: most recent start date first, no date still last
    private static function mostRecentFirst(array $a, array $b): int
    {
        return self::compareDates($a['startDate'], $b['startDate'], true);
    }

    // Null sorts after any real date, both for "soonest first" and "most recent first"
    private static function compareDates(?string $a, ?string $b, bool $newestFirst): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        if ($newestFirst) {
            return $b <=> $a;
        }

        return $a <=> $b;
    }
}
