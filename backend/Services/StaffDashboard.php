<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Booking;
use GrandGala\Models\Dashboard;
use GrandGala\Utils\Dates;
use GrandGala\Utils\HttpError;

// Today page, sidebar counts and the calendar.
class StaffDashboard
{
    // One response contains every figure and row needed by the Today page.
    public static function today(): array
    {
        $today = Clock::today();
        $summary = Dashboard::todaySummary($today);

        return [
            'date' => $today,
            'arrivals' => $summary['arrivals'],
            'departures' => $summary['departures'],
            'awaitingPaymentCount' => $summary['awaitingPaymentCount'],
            'openEnquiries' => $summary['openEnquiries'],
            'awaitingPaymentTotal' => $summary['awaitingPaymentTotal'],
            'bookings' => Dashboard::arrivalsOn($today),
        ];
    }

    // Sidebar badges use the same queues as their staff pages.
    public static function navCounts(): array
    {
        $today = Clock::today();
        $counts = Dashboard::navigationCounts($today);
        $bookingCounts = Booking::staffCounts('all', $today);

        return [
            'bookings' => $bookingCounts['active'],
            'enquiries' => $counts['enquiries'],
            'quotations' => $counts['quotations'],
            'events' => $counts['events'],
        ];
    }

    // A date-by-date operational calendar, including both ends of the requested range.
    public static function calendar(array $query): array
    {
        $range = self::calendarRange($query);
        $days = self::emptyDays($range['from'], $range['to']);

        foreach (Dashboard::calendarBookings($range['from'], $range['to']) as $booking) {
            if (self::holdsDates($booking)) {
                $days = self::addCalendarBooking($days, $booking);
            }
        }
        foreach (Dashboard::calendarBlackouts($range['from'], $range['to']) as $blackout) {
            $days = self::addBlackout($days, $blackout);
        }

        return ['from' => $range['from'], 'to' => $range['to'], 'days' => array_values($days)];
    }

    // Real dates, from before to, at most 366 days. Otherwise 422.
    private static function calendarRange(array $query): array
    {
        $from = self::queryText($query, 'from');
        $to = self::queryText($query, 'to');
        Dates::check($from, 'From');
        Dates::check($to, 'To');
        if ($from > $to) {
            throw new HttpError(422, 'From date must be on or before to date');
        }

        $date = $from;
        $numberOfDays = 0;
        while ($date <= $to) {
            $numberOfDays++;
            if ($numberOfDays > 366) {
                throw new HttpError(422, 'Calendar range cannot exceed 366 days');
            }
            $date = Dates::addDays($date, 1);
        }

        return ['from' => $from, 'to' => $to];
    }

    // A query string value as text, trimmed. Missing or not text (e.g. ?from[]=x) gives ''.
    private static function queryText(array $query, string $key): string
    {
        if (!isset($query[$key]) || !is_string($query[$key])) {
            return '';
        }

        return trim($query[$key]);
    }

    // One empty day for every date from $from to $to, keyed by date, ready to be filled in
    private static function emptyDays(string $from, string $to): array
    {
        $days = [];
        $date = $from;
        while ($date <= $to) {
            $days[$date] = [
                'date' => $date,
                'mode' => null,
                'bookings' => [],
                'events' => [],
                'blackouts' => [],
            ];
            $date = Dates::addDays($date, 1);
        }

        return $days;
    }

    // Taking its dates: confirmed, completed, or unpaid with a hold still running.
    private static function holdsDates(array $booking): bool
    {
        if ($booking['status'] === 'confirmed' || $booking['status'] === 'completed') {
            return true;
        }
        if ($booking['status'] === 'pending_payment' && Holds::isRunning($booking['holdExpires'])) {
            return true;
        }

        return false;
    }

    // A stay on its nights, an event on its day, and sets that day's mode.
    private static function addCalendarBooking(array $days, array $booking): array
    {
        if ($booking['type'] === 'stay') {
            $date = $booking['checkIn'];
            while ($date < $booking['checkOut']) {
                if (isset($days[$date])) {
                    $days[$date]['bookings'][] = self::bookingEntry($booking);
                    // A private event's day stays an event day. That night is only for its guests.
                    if ($days[$date]['mode'] !== 'event') {
                        $days[$date]['mode'] = 'stay';
                    }
                }
                $date = Dates::addDays($date, 1);
            }
            return $days;
        }

        $date = $booking['date'];
        if ($booking['isHosted'] && $booking['isPublic']) {
            $days[$date]['events'][] = self::eventEntry($booking);
        } else {
            $days[$date]['bookings'][] = self::bookingEntry($booking);
        }
        // Only a private event makes an event day. A public event leaves the rooms open.
        if (!$booking['isPublic']) {
            $days[$date]['mode'] = 'event';
        }

        return $days;
    }

    // How a booking shows on a calendar day
    private static function bookingEntry(array $booking): array
    {
        return [
            'reference' => $booking['reference'],
            'guest' => $booking['guest'],
            'description' => $booking['description'],
            'status' => $booking['status'],
        ];
    }

    // How a public (ticketed) event shows on a calendar day
    private static function eventEntry(array $booking): array
    {
        return [
            'id' => $booking['eventId'],
            'reference' => $booking['reference'],
            'title' => $booking['title'],
            'space' => $booking['space'],
            'startTime' => $booking['startTime'],
            'endTime' => $booking['endTime'],
            'status' => $booking['status'],
        ];
    }

    // Puts a blackout on every day it covers, end date included
    private static function addBlackout(array $days, array $blackout): array
    {
        $date = $blackout['start'];
        while ($date <= $blackout['end']) {
            if (isset($days[$date])) {
                $days[$date]['blackouts'][] = [
                    'id' => $blackout['id'],
                    'spaceId' => $blackout['spaceId'],
                    'space' => $blackout['space'],
                    'reason' => $blackout['reason'],
                ];
            }
            $date = Dates::addDays($date, 1);
        }

        return $days;
    }
}
