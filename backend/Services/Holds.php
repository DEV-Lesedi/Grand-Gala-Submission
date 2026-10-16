<?php

declare(strict_types=1);

namespace GrandGala\Services;

use DateTimeImmutable;
use LogicException;

/*
 * How long an unpaid booking keeps its dates: stay 48h, private event 72h (1–168 via env).
 * No end time runs until paid or cancelled. Ticket orders use a shorter hold of their own.
 */
class Holds
{
    private const DEFAULT_HOURS = ['stay' => 48, 'event' => 72];
    private const SETTINGS = ['stay' => 'STAY_HOLD_HOURS', 'event' => 'QUOTATION_HOLD_HOURS'];
    // A week. Longer would keep dates away from other guests for too long.
    private const MAX_HOURS = 168;

    // How many hours a new hold lasts for $mode: 'stay' or 'event' (a private event)
    public static function hours(string $mode): int
    {
        if (!isset(self::DEFAULT_HOURS[$mode])) {
            // Public events are never held. Asking is a bug in the caller.
            throw new LogicException('Only stays and private events are held, not ' . $mode);
        }

        $setting = $_ENV[self::SETTINGS[$mode]] ?? '';
        if (is_string($setting) && ctype_digit($setting) && (int) $setting >= 1 && (int) $setting <= self::MAX_HOURS) {
            return (int) $setting;
        }

        return self::DEFAULT_HOURS[$mode];
    }

    // When a hold starting now ends for $mode, e.g. '2026-09-06 12:00:00'
    public static function endOfNewHold(string $mode): string
    {
        $now = new DateTimeImmutable(Clock::now());

        return $now->modify('+' . self::hours($mode) . ' hours')->format('Y-m-d H:i:s');
    }

    // Still running if $holdExpires is in the future. null keeps running.
    public static function isRunning(?string $holdExpires): bool
    {
        if ($holdExpires === null) {
            return true;
        }

        return $holdExpires > Clock::now();
    }

    // For SQL that finds holds by time: a hold ending at or before this has run out
    public static function cutOff(): string
    {
        return Clock::now();
    }
}
