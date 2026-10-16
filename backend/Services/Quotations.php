<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\BookingDetails;
use GrandGala\Models\Quotation;
use GrandGala\Models\QuotationItem;
use GrandGala\Models\QuotationPlan;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Staff price an enquiry, and read quotations back.
 * QuotationForm checks what staff typed. QuotationAnswer is the guest's yes, no, or changes.
 * Totals come from the lines. "Expired" is computed on read; the nightly job writes it later.
 */
class Quotations
{
    private const STATUS_FILTERS = ['sent', 'accepted', 'expired', 'declined'];

    // The staff list shows this many quotations per page
    public const PER_PAGE = 20;

    // ---- Staff ----

    // New version. Omitted plan fields come from the enquiry. Body totals are ignored.
    public static function create(array $input, int $staffId): array
    {
        $quotation = QuotationForm::checkInput($input);

        $saved = Db::transaction(function () use ($quotation, $input, $staffId) {
            $booking = Quotation::lockBooking($quotation['bookingReference']);
            if ($booking === null) {
                throw new HttpError(404, 'Booking not found');
            }
            if ($booking['type'] !== 'event') {
                throw new HttpError(422, 'Quotations are for event bookings');
            }
            // Checked first: an accepted booking is already waiting for payment.
            foreach (Quotation::forBooking($booking['id'], true) as $version) {
                if ($version['status'] === 'accepted') {
                    throw new HttpError(409, 'An accepted quotation can\'t be revised');
                }
            }
            if ($booking['status'] !== 'enquiry' && $booking['status'] !== 'quoted') {
                throw new HttpError(409, 'This booking can\'t be quoted any more');
            }

            $plan = QuotationForm::checkPlan($input, $booking['id']);
            QuotationForm::checkLineSpaces($quotation['items'], $plan);

            $reference = References::next('quotation');
            Quotation::supersedeSent($booking['id']);
            $quotationId = Quotation::insert(
                $booking['id'], $reference, $quotation['total'], $quotation['notes'], $quotation['validUntil'], $plan, Clock::now()
            );
            foreach ($plan['spaceIds'] as $spaceId) {
                QuotationPlan::insertSpace($quotationId, $spaceId);
            }
            foreach ($plan['rooms'] as $room) {
                QuotationPlan::insertRoom($quotationId, $room['spaceId'], $room['checkIn'], $room['checkOut']);
            }
            $position = 1;
            foreach ($quotation['items'] as $item) {
                QuotationItem::insert($quotationId, $position, $item['label'], $item['quantity'], $item['unitPrice'], $item['spaceId']);
                $position = $position + 1;
            }

            if ($booking['status'] !== 'quoted') {
                BookingLifecycle::move($booking['id'], 'quoted', $staffId, 'Quotation ' . $reference . ' sent');
            }

            return Quotation::find($reference);
        });

        // Emailed only once it's saved, so a failed save never sends a quotation
        Mailer::send('quotation_sent', $saved['guestEmail'], [
            'reference' => $saved['reference'],
            'bookingReference' => $saved['bookingReference'],
            'total' => $saved['total'],
            'validUntil' => $saved['validUntil'],
        ]);

        return self::present($saved);
    }

    // Latest version per booking, newest first, with a count per status.
    public static function staffList(array $query): array
    {
        $status = '';
        if (isset($query['status']) && is_string($query['status'])) {
            $status = $query['status'];
        }
        if ($status !== '' && !in_array($status, self::STATUS_FILTERS, true)) {
            throw new HttpError(422, 'Unknown status');
        }

        // A non-integer or a page below 1 means page 1.
        $page = 1;
        if (isset($query['page']) && is_string($query['page']) && ctype_digit($query['page']) && (int) $query['page'] >= 1) {
            $page = (int) $query['page'];
        }

        $result = Quotation::staffPage($status, Clock::today(), self::PER_PAGE, $page);
        $quotations = [];
        foreach ($result['quotations'] as $row) {
            $quotations[] = self::present($row);
        }

        return [
            'quotations' => $quotations,
            'page' => $page,
            // At least one page, even when nothing matches
            'pageCount' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'total' => $result['total'],
            'counts' => Quotation::staffCounts(Clock::today()),
        ];
    }

    public static function staffDetail(string $reference): array
    {
        $quotation = Quotation::find($reference);
        if ($quotation === null) {
            throw new HttpError(404, 'Quotation not found');
        }

        return self::present($quotation);
    }

    // Every version of the booking this quotation belongs to, newest first
    public static function versions(string $reference): array
    {
        $quotation = Quotation::find($reference);
        if ($quotation === null) {
            throw new HttpError(404, 'Quotation not found');
        }

        $versions = [];
        foreach (Quotation::forBooking($quotation['bookingId'], false) as $version) {
            $versions[] = self::present($version);
        }

        return $versions;
    }

    // Newest quotation for a booking, or null if it has none.
    public static function latestFor(string $bookingReference): ?array
    {
        $bookingId = Quotation::bookingId($bookingReference);
        if ($bookingId === null) {
            return null;
        }
        $versions = Quotation::forBooking($bookingId, false);
        if (count($versions) === 0) {
            return null;
        }

        return self::present($versions[0]);
    }

    // Expire each overdue sent quotation in its own transaction, booking locked first.
    public static function expireOverdue(): int
    {
        $expired = 0;
        foreach (Quotation::overdueSent(Clock::today()) as $overdue) {
            $changed = Db::transaction(function () use ($overdue) {
                $booking = Quotation::lockBooking($overdue['bookingReference']);
                $latest = Quotation::forBooking($booking['id'], true)[0];
                // The guest answered, or staff sent a new version, while we waited for the lock
                if ($latest['reference'] !== $overdue['reference'] || $latest['status'] !== 'sent') {
                    return false;
                }

                Quotation::setStatus($latest['id'], 'expired');
                if ($booking['status'] === 'quoted') {
                    BookingLifecycle::move($booking['id'], 'expired', null, 'Quotation ' . $latest['reference'] . ' expired');
                }

                return true;
            });
            if ($changed) {
                $expired = $expired + 1;
            }
        }

        return $expired;
    }

    // Guest cancel: decline a quotation still waiting. The booking is already locked.
    public static function closeForCancelledBooking(int $bookingId): void
    {
        Quotation::declineSent($bookingId);
    }

    // ---- Guest ----

    // The guest's own quotations, latest version of each booking, newest first
    public static function guestList(int $userId): array
    {
        return self::listOf(Quotation::latestOfEachBooking($userId), '');
    }

    // Someone else's quotation is "not found", so references cannot be probed.
    public static function guestDetail(string $reference, int $userId): array
    {
        $quotation = Quotation::find($reference);
        if ($quotation === null || $quotation['userId'] !== $userId) {
            throw new HttpError(404, 'Quotation not found');
        }

        return self::present($quotation);
    }

    // The guest says yes. The steps are in QuotationAnswer.
    public static function accept(string $reference, int $userId): array
    {
        return QuotationAnswer::accept($reference, $userId);
    }

    // The guest says no. The steps are in QuotationAnswer.
    public static function decline(string $reference, int $userId): array
    {
        return QuotationAnswer::decline($reference, $userId);
    }

    // The guest asks for changes. The steps are in QuotationAnswer.
    public static function requestChanges(string $reference, int $userId, array $input): array
    {
        return QuotationAnswer::requestChanges($reference, $userId, $input);
    }

    // Guest list for one status ('' is all), plus a count of each. Not paged.
    private static function listOf(array $rows, string $status): array
    {
        $counts = ['all' => 0, 'sent' => 0, 'accepted' => 0, 'expired' => 0, 'declined' => 0];
        $quotations = [];
        foreach ($rows as $row) {
            $quotation = self::present($row);
            $counts['all'] = $counts['all'] + 1;
            if (isset($counts[$quotation['status']])) {
                $counts[$quotation['status']] = $counts[$quotation['status']] + 1;
            }
            if ($status === '' || $quotation['status'] === $status) {
                $quotations[] = $quotation;
            }
        }

        return ['quotations' => $quotations, 'counts' => $counts];
    }

    // API shape: lines, money, older versions, payment and change requests.
    private static function present(array $quotation): array
    {
        $prices = QuotationForm::prices(QuotationItem::forQuotation($quotation['id']), $quotation['total']);

        $status = $quotation['status'];
        if ($status === 'sent' && $quotation['validUntil'] < Clock::today()) {
            $status = 'expired';
        }

        // Paid is a fact about the booking (BookingDetails reads the payment table)
        $paidDate = null;
        if ($status === 'accepted') {
            $paidAt = BookingDetails::payment($quotation['bookingId'])['paidAt'];
            if ($paidAt !== null) {
                $paidDate = substr($paidAt, 0, 10);
            }
        }

        // Older versions, oldest first.
        $previous = [];
        foreach (array_reverse(Quotation::forBooking($quotation['bookingId'], false)) as $version) {
            if ($version['id'] < $quotation['id']) {
                $previous[] = [
                    'reference' => $version['reference'],
                    'version' => $version['version'],
                    'status' => $version['status'],
                    'date' => $version['sentDate'],
                ];
            }
        }

        return [
            'reference' => $quotation['reference'],
            'bookingReference' => $quotation['bookingReference'],
            'version' => $quotation['version'],
            'status' => $status,
            'sentDate' => $quotation['sentDate'],
            'validUntil' => $quotation['validUntil'],
            'paidDate' => $paidDate,
            'items' => $prices['items'],
            'subtotal' => $prices['subtotal'],
            'discount' => $prices['discount'],
            'total' => $prices['total'],
            'vatIncluded' => $prices['vatIncluded'],
            'notes' => $quotation['notes'],
            'eventDate' => $quotation['eventDate'],
            'startTime' => $quotation['startTime'],
            'endTime' => $quotation['endTime'],
            'spaces' => QuotationPlan::spacesOf($quotation['id']),
            'rooms' => QuotationPlan::roomsOf($quotation['id']),
            'bookingStatus' => $quotation['bookingStatus'],
            'holdExpires' => $quotation['holdExpires'],
            'previousVersions' => $previous,
            'changeRequests' => Quotation::changeRequests($quotation['bookingId']),
        ];
    }
}
