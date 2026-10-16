<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\RateSchedule;
use GrandGala\Tests\Support\DatabaseTestCase;

class RateScheduleTest extends DatabaseTestCase
{
    public function testANormalPriceAppliesFromItsStartDate(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 2800, '2026-01-01', null);

        $rate = RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04');

        self::assertNotNull($rate);
        self::assertSame(2800.0, $rate['price']);
    }

    public function testASeasonalPriceBeatsANormalPriceThatStartedAfterIt(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 3500, '2026-09-01', '2026-09-30');
        $this->addRate($spaceId, 'per_night', 2800, '2026-09-02', null);

        self::assertSame(3500.0, RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04')['price'] ?? null);
    }

    public function testANormalPriceClosedByAScheduledChangeIsStillANormalPrice(): void
    {
        $spaceId = $this->createSpace();
        $original = $this->addRate($spaceId, 'per_night', 2800, '2026-01-01', null);
        $scheduled = $this->addRate($spaceId, 'per_night', 3000, '2026-09-16', null);
        $this->update('space_rate', 'sr_id', $original, ['sr_valid_to' => '2026-09-15', 'sr_closed_for' => $scheduled]);
        $this->update('space_rate', 'sr_id', $scheduled, ['sr_is_active' => 0]);
        $this->addRate($spaceId, 'per_night', 2900, '2026-09-01', null);

        self::assertSame(2900.0, RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04')['price'] ?? null);
    }

    public function testWhenTwoSeasonsOverlapTheOneThatStartedLaterWins(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 3500, '2026-09-01', '2026-09-30');
        $this->addRate($spaceId, 'per_night', 4200, '2026-09-03', '2026-09-06');

        self::assertSame(4200.0, RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04')['price'] ?? null);
    }

    public function testWhenTwoPricesStartTheSameDayTheOneAddedLastWins(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 3500, '2026-09-01', '2026-09-30');
        $this->addRate($spaceId, 'per_night', 3900, '2026-09-01', '2026-09-30');

        self::assertSame(3900.0, RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04')['price'] ?? null);
    }

    public function testACancelledPriceNeverApplies(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 2800, '2026-01-01', null);
        $cancelled = $this->addRate($spaceId, 'per_night', 3500, '2026-09-01', '2026-09-30');
        $this->update('space_rate', 'sr_id', $cancelled, ['sr_is_active' => 0]);

        self::assertSame(2800.0, RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04')['price'] ?? null);
    }

    public function testThereIsNoPriceBeforeTheFirstPriceStarts(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 2800, '2026-10-01', null);

        self::assertNull(RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04'));
    }

    public function testOnlyPricesOfTheAskedBasisCount(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_event', 15000, '2026-01-01', null);
        $this->addRate($spaceId, 'per_night', 2800, '2026-01-01', null);

        self::assertSame(15000.0, RateSchedule::rateOn($spaceId, 'per_event', '2026-09-04')['price'] ?? null);
    }

    public function testAPriceKeepsItsCents(): void
    {
        $spaceId = $this->createSpace();
        $this->addRate($spaceId, 'per_night', 2800.50, '2026-01-01', null);

        self::assertSame(2800.5, RateSchedule::rateOn($spaceId, 'per_night', '2026-09-04')['price'] ?? null);
    }

    public function testAnUnknownBasisIsRejectedWith422(): void
    {
        $error = $this->errorFrom(function () {
            RateSchedule::rateOn(1, 'per_week', '2026-09-04');
        });

        self::assertSame(422, $error->status());
    }

    public function testAnImpossibleDateIsRejectedWith422(): void
    {
        $error = $this->errorFrom(function () {
            RateSchedule::rateOn(1, 'per_night', '2026-02-30');
        });

        self::assertSame(422, $error->status());
    }
}
