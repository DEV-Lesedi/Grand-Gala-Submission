<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Holds;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// How long an unpaid booking keeps its dates, and whether a hold is still running.
// Now is 2026-09-04 12:00. The .env settings are put back after each test.
class HoldsTest extends TestCase
{
    private const SETTINGS = ['STAY_HOLD_HOURS', 'QUOTATION_HOLD_HOURS'];

    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::SETTINGS as $key) {
            $this->savedSettings[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
        Clock::freezeAt('2026-09-04 12:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        Clock::freeze($_ENV['APP_TODAY']);
        parent::tearDown();
    }

    public function testAStayHolds48HoursAndAPrivateEvent72UnlessSet(): void
    {
        self::assertSame(48, Holds::hours('stay'));
        self::assertSame(72, Holds::hours('event'));
    }

    public function testEachModeHasItsOwnSetting(): void
    {
        $_ENV['STAY_HOLD_HOURS'] = '2';
        $_ENV['QUOTATION_HOLD_HOURS'] = '24';

        self::assertSame(2, Holds::hours('stay'));
        self::assertSame(24, Holds::hours('event'));
    }

    public static function settings(): array
    {
        return [
            'one hour' => ['1', 1],
            'a week' => ['168', 168],
            'over a week' => ['169', null],
            'zero' => ['0', null],
            'negative' => ['-5', null],
            'not a number' => ['two', null],
            'a fraction' => ['1.5', null],
            'empty' => ['', null],
        ];
    }

    // Both settings take a whole number of hours from 1 to 168. Anything else uses the default.
    #[DataProvider('settings')]
    public function testBothSettingsTakeTheSameRange(string $setting, ?int $expected): void
    {
        $_ENV['STAY_HOLD_HOURS'] = $setting;
        $_ENV['QUOTATION_HOLD_HOURS'] = $setting;

        $stay = $expected;
        $event = $expected;
        if ($expected === null) {
            $stay = 48;
            $event = 72;
        }
        self::assertSame($stay, Holds::hours('stay'));
        self::assertSame($event, Holds::hours('event'));
    }

    public function testANewHoldEndsItsHoursFromNow(): void
    {
        $_ENV['STAY_HOLD_HOURS'] = '30';

        self::assertSame('2026-09-05 18:00:00', Holds::endOfNewHold('stay'));
        self::assertSame('2026-09-07 12:00:00', Holds::endOfNewHold('event'));
    }

    public function testOnlyStaysAndPrivateEventsAreHeld(): void
    {
        $this->expectException(LogicException::class);

        Holds::hours('public_event');
    }

    public function testAHoldRunsUntilItsEndTime(): void
    {
        self::assertTrue(Holds::isRunning('2026-09-04 12:00:01'));
        self::assertFalse(Holds::isRunning('2026-09-04 12:00:00'));
        self::assertFalse(Holds::isRunning('2026-09-03 09:00:00'));
    }

    public function testAHoldWithNoEndKeepsRunning(): void
    {
        self::assertTrue(Holds::isRunning(null));
    }

    public function testTheCutOffIsNow(): void
    {
        self::assertSame('2026-09-04 12:00:00', Holds::cutOff());
        Clock::freezeAt('2026-09-05 08:30');
        self::assertSame('2026-09-05 08:30:00', Holds::cutOff());
    }
}
