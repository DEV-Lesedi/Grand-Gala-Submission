<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Availability;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

// Staff and guests at the same time. Two guests on one night is in ReservationTest.
class AvailabilityTwoAtOnceTest extends DatabaseTestCase
{
    public function testABlackoutAddedWhileAGuestIsBookingStillBlocksTheBooking(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);

        $error = $this->errorFrom(function () use ($villaId) {
            Db::transaction(function () use ($villaId) {
                // The guest's booking has already read, so MySQL has pinned its snapshot.
                Availability::calendar($villaId, '2026-12-12', '2026-12-12');

                // Meanwhile staff black out that night from another connection
                $staff = Db::connect();
                $staff->prepare(
                    'INSERT INTO space_blackout (sbk_sp_id, sbk_start_date, sbk_end_date, sbk_reason) VALUES (?, ?, ?, ?)'
                )->execute([$villaId, '2026-12-12', '2026-12-12', 'Burst pipe']);
                $this->track('space_blackout', 'sbk_id', (int) $staff->lastInsertId());

                // The booking check must see the new blackout, not the old snapshot
                Availability::assertFree($villaId, '2026-12-12', '2026-12-13', 'stay');
            });
        });

        self::assertSame(409, $error->status());
        self::assertSame('Not available on these dates', $error->getMessage());
    }
}
