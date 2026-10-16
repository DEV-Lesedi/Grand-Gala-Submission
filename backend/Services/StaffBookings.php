<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Booking;
use GrandGala\Models\BookingDetails;
use GrandGala\Utils\HttpError;

/*
 * Active: quoted, waiting for payment or confirmed, and not over. Past: everything else.
 * Search ignores those chips but keeps Stays/Events. Staff cannot create bookings here.
 */
class StaffBookings
{
    public const PER_PAGE = 9;

    private const STATUSES = ['quoted', 'pending_payment', 'confirmed', 'completed', 'cancelled', 'expired', 'refunded'];

    // One page, plus a count per chip. view, status, type, q and page are all optional.
    public static function list(array $query): array
    {
        $filters = [
            'view' => self::text($query, 'view', 'active'),
            'status' => self::text($query, 'status', ''),
            'type' => self::text($query, 'type', 'all'),
            'q' => trim(self::text($query, 'q', '')),
        ];
        if ($filters['view'] !== 'active' && $filters['view'] !== 'past') {
            throw new HttpError(422, 'Unknown view');
        }
        if ($filters['status'] !== '' && !in_array($filters['status'], self::STATUSES, true)) {
            throw new HttpError(422, 'Unknown status');
        }
        if (!in_array($filters['type'], ['all', 'stay', 'event'], true)) {
            throw new HttpError(422, 'Unknown booking type');
        }

        // Anything that isn't a whole number from 1 up means page 1
        $page = 1;
        $pageText = self::text($query, 'page', '1');
        if (ctype_digit($pageText) && (int) $pageText >= 1) {
            $page = (int) $pageText;
        }

        $today = Clock::today();
        $result = Booking::staffPage($filters, $today, self::PER_PAGE, $page);

        // At least one page, even when nothing matches
        $pageCount = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return [
            'bookings' => $result['bookings'],
            'page' => $page,
            'pageCount' => $pageCount,
            'total' => $result['total'],
            'counts' => Booking::staffCounts($filters['type'], $today),
        ];
    }

    // Everything the detail drawer shows for one booking. 404 if there's no such booking.
    public static function detail(string $reference): array
    {
        $booking = Booking::findForStaff($reference);
        if ($booking === null) {
            throw new HttpError(404, 'Booking not found');
        }

        $bookingId = $booking['id'];
        if ($booking['type'] === 'stay') {
            $booking['lines'] = BookingDetails::stayLines($bookingId);
            $booking['enquiry'] = null;
        } else {
            $booking['lines'] = BookingDetails::eventLines($bookingId);
            $booking['enquiry'] = BookingDetails::enquiry($bookingId);
        }
        $booking['quotation'] = BookingDetails::latestQuotation($bookingId);

        // Everything about money owed or paid in one place
        $payment = BookingDetails::payment($bookingId);
        $payment['holdExpires'] = $booking['holdExpires'];
        $booking['payment'] = $payment;
        unset($booking['holdExpires']);

        return $booking;
    }

    // A query string value as text, or $default if it's missing or not text (e.g. ?view[]=x)
    private static function text(array $query, string $key, string $default): string
    {
        $value = $query[$key] ?? $default;
        if (!is_string($value)) {
            return $default;
        }

        return $value;
    }
}
