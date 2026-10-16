<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\BookingStatus;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use LogicException;

/*
 * The only place that changes booking status. Runs in the caller's transaction and locks the row.
 * Lock venue, then event, before calling. A held promo is used on confirm and returned on cancel.
 */
class BookingLifecycle
{
    // from => [to, ...]. Statuses not listed (completed, expired, refunded) are final.
    private const MOVES = [
        // Staff send the first quotation; the guest cancels their enquiry
        'enquiry' => ['quoted', 'cancelled'],
        // Guest accepts, asks for changes, declines or cancels; the nightly job expires it.
        'quoted' => ['pending_payment', 'enquiry', 'cancelled', 'expired'],
        // The guest pays or cancels; the hold runs out
        'pending_payment' => ['confirmed', 'cancelled', 'expired'],
        // Staff cancel a public event, or a guest cancels a paid stay. The job completes it.
        'confirmed' => ['cancelled', 'completed'],
        // The refund has gone through
        'cancelled' => ['refunded'],
    ];

    // Moves to $to (reason max 255). To wait for payment, use holdForPayment().
    // $changedBy is null for PayFast and the nightly job.
    public static function move(int $bookingId, string $to, ?int $changedBy, string $reason): string
    {
        // A booking waiting for payment with no hold would keep its dates until someone cancels it
        if ($to === 'pending_payment') {
            throw new LogicException('Use BookingLifecycle::holdForPayment() to make a booking wait for payment');
        }

        return self::change($bookingId, $to, null, $changedBy, $reason);
    }

    // Wait for payment until $holdUntil. Returns the status it had.
    public static function holdForPayment(int $bookingId, string $holdUntil, ?int $changedBy, string $reason): string
    {
        return self::change($bookingId, 'pending_payment', $holdUntil, $changedBy, $reason);
    }

    // A new pending_payment stay: hold until $holdUntil and write the first history row.
    public static function createdWaitingForPayment(int $bookingId, string $holdUntil, ?int $changedBy, string $reason): void
    {
        self::requireTransaction();

        $status = BookingStatus::lock($bookingId);
        if ($status === null) {
            throw new HttpError(404, 'Booking not found');
        }
        // Anything else would quietly give an older booking a new hold
        if ($status !== 'pending_payment' || BookingStatus::hasHistory($bookingId)) {
            throw new LogicException('Only a booking just created waiting for payment can start here');
        }

        BookingStatus::setHold($bookingId, $holdUntil);
        BookingStatus::addHistory($bookingId, null, 'pending_payment', $changedBy, $reason, Clock::now());
    }

    // The steps behind move() and holdForPayment()
    private static function change(int $bookingId, string $to, ?string $holdUntil, ?int $changedBy, string $reason): string
    {
        self::requireTransaction();

        $from = BookingStatus::lock($bookingId);
        if ($from === null) {
            throw new HttpError(404, 'Booking not found');
        }
        if (!self::isAllowed($from, $to)) {
            throw new HttpError(409, 'This booking can\'t go from ' . $from . ' to ' . $to);
        }

        BookingStatus::set($bookingId, $to);
        if ($to === 'pending_payment') {
            BookingStatus::setHold($bookingId, $holdUntil);
        } elseif ($from === 'pending_payment') {
            // Paid, cancelled or expired: the dates aren't held for payment any more
            BookingStatus::setHold($bookingId, null);
        }
        // Paying uses a held code. Cancel or expire returns it. A paid cancel waits for refund.
        if ($to === 'confirmed') {
            Promotions::markUsed($bookingId);
        } elseif ($to === 'cancelled' || $to === 'expired') {
            Promotions::release($bookingId);
        } elseif ($to === 'refunded') {
            Promotions::giveBack($bookingId);
        }
        BookingStatus::addHistory($bookingId, $from, $to, $changedBy, $reason, Clock::now());

        return $from;
    }

    // Outside a transaction the row lock ends at once, so two changes could both pass.
    private static function requireTransaction(): void
    {
        if (!Db::pdo()->inTransaction()) {
            throw new LogicException('BookingLifecycle must run inside Db::transaction()');
        }
    }

    private static function isAllowed(string $from, string $to): bool
    {
        if (!isset(self::MOVES[$from])) {
            return false;
        }

        return in_array($to, self::MOVES[$from], true);
    }
}
