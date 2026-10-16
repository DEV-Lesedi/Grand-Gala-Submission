<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\DueBookings;
use GrandGala\Models\RateLimit;
use GrandGala\Utils\Db;

/*
 * Each booking is its own transaction, so one failure does not stop the rest.
 * A booking paid at the last moment is skipped.
 */
class CronJobs
{
    // Runs every job and returns how many of each it changed
    public static function run(): array
    {
        $expiredBookings = 0;
        foreach (DueBookings::lapsedHolds(Holds::cutOff()) as $bookingId) {
            if (self::expireHold($bookingId)) {
                $expiredBookings = $expiredBookings + 1;
            }
        }

        $completedStays = 0;
        foreach (DueBookings::finishedStays(Clock::today()) as $bookingId) {
            if (self::complete($bookingId)) {
                $completedStays = $completedStays + 1;
            }
        }

        $completedEvents = 0;
        foreach (DueBookings::finishedEvents(Clock::today()) as $bookingId) {
            if (self::complete($bookingId)) {
                $completedEvents = $completedEvents + 1;
            }
        }

        return [
            'expiredBookings' => $expiredBookings,
            'expiredQuotations' => Quotations::expireOverdue(),
            'expiredTicketOrders' => Tickets::expireLapsedHolds(),
            'completedStays' => $completedStays,
            'completedEvents' => $completedEvents,
            'prunedRateLimitBuckets' => RateLimit::prune(),
        ];
    }

    // Expire only if the hold is still out under the lock. A late payment is skipped.
    public static function expireHold(int $bookingId): bool
    {
        return Db::transaction(function () use ($bookingId) {
            $booking = DueBookings::lock($bookingId);
            if ($booking === null || $booking['status'] !== 'pending_payment') {
                return false;
            }
            if (Holds::isRunning($booking['holdExpires'])) {
                return false;
            }

            BookingLifecycle::move($bookingId, 'expired', null, 'Unpaid hold expired');

            return true;
        });
    }

    // Completes one confirmed booking whose stay or event is over. Returns true if it changed.
    public static function complete(int $bookingId): bool
    {
        return Db::transaction(function () use ($bookingId) {
            $booking = DueBookings::lock($bookingId);
            if ($booking === null || $booking['status'] !== 'confirmed') {
                return false;
            }

            BookingLifecycle::move($bookingId, 'completed', null, 'Stay or event date passed');

            return true;
        });
    }
}
