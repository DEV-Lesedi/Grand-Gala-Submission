<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Availability;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use PHPUnit\Framework\Attributes\DataProvider;

// Availability::calendar(): the state of each date for one space. Today is 2026-09-04.
// Each test has its own venue and spaces, so shared demo bookings cannot affect it.
class AvailabilityTest extends DatabaseTestCase
{
    public function testABlackoutBlocksItsDates(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $this->insert('space_blackout', 'sbk_id', [
            'sbk_sp_id' => $villaId, 'sbk_start_date' => '2026-12-24', 'sbk_end_date' => '2026-12-25', 'sbk_reason' => 'Family use',
        ]);

        $calendar = Availability::calendar($villaId, '2026-12-23', '2026-12-26');

        self::assertSame([
            '2026-12-23' => 'free',
            '2026-12-24' => 'blacked_out',
            '2026-12-25' => 'blacked_out',
            '2026-12-26' => 'free',
        ], $this->states($calendar));
    }

    public function testAConfirmedStayBooksItsNightsButNotTheCheckOutDay(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $this->createStay($villaId, '2026-12-12', '2026-12-14', 'confirmed');

        $calendar = Availability::calendar($villaId, '2026-12-11', '2026-12-14');

        self::assertSame([
            '2026-12-11' => 'free',
            '2026-12-12' => 'booked',
            '2026-12-13' => 'booked',
            '2026-12-14' => 'free',
        ], $this->states($calendar));
    }

    // [status, hold expiry, state of the night]. Now is always 2026-09-04 12:00.
    public static function statuses(): array
    {
        return [
            'completed stays still count' => ['completed', null, 'booked'],
            'waiting for payment, hold running' => ['pending_payment', '2026-09-04 14:00:00', 'held'],
            'waiting for payment, hold time passed' => ['pending_payment', '2026-09-04 09:00:00', 'free'],
            'waiting for payment, no expiry' => ['pending_payment', null, 'held'],
            'cancelled' => ['cancelled', null, 'free'],
            'expired' => ['expired', null, 'free'],
        ];
    }

    #[DataProvider('statuses')]
    public function testABookingsStatusDecidesWhetherItHoldsTheDate(string $status, ?string $holdExpires, string $state): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $this->createStay($villaId, '2026-12-12', '2026-12-13', $status, $holdExpires);

        $calendar = Availability::calendar($villaId, '2026-12-12', '2026-12-12');

        self::assertSame(['2026-12-12' => $state], $this->states($calendar));
    }

    public function testAVillaStayBlocksItsRooms(): void
    {
        $spaces = $this->villaWithTwoRooms();
        $this->createStay($spaces['villa'], '2026-12-12', '2026-12-13', 'confirmed');

        self::assertSame(['2026-12-12' => 'booked'], $this->states(Availability::calendar($spaces['room'], '2026-12-12', '2026-12-12')));
    }

    public function testARoomStayBlocksTheWholeVilla(): void
    {
        $spaces = $this->villaWithTwoRooms();
        $this->createStay($spaces['room'], '2026-12-12', '2026-12-13', 'confirmed');

        self::assertSame(['2026-12-12' => 'booked'], $this->states(Availability::calendar($spaces['villa'], '2026-12-12', '2026-12-12')));
    }

    public function testARoomStayDoesNotBlockTheRoomNextDoor(): void
    {
        $spaces = $this->villaWithTwoRooms();
        $this->createStay($spaces['room'], '2026-12-12', '2026-12-13', 'confirmed');

        self::assertSame(['2026-12-12' => 'free'], $this->states(Availability::calendar($spaces['otherRoom'], '2026-12-12', '2026-12-12')));
    }

    public function testAVillaBlackoutBlocksItsRooms(): void
    {
        $spaces = $this->villaWithTwoRooms();
        $this->insert('space_blackout', 'sbk_id', [
            'sbk_sp_id' => $spaces['villa'], 'sbk_start_date' => '2026-12-24', 'sbk_end_date' => '2026-12-24', 'sbk_reason' => 'Family use',
        ]);

        self::assertSame(['2026-12-24' => 'blacked_out'], $this->states(Availability::calendar($spaces['room'], '2026-12-24', '2026-12-24')));
    }

    public function testAConfirmedEventBooksItsSpaceOnItsDate(): void
    {
        $gardenId = $this->createSpace(['sp_type' => 'garden', 'sp_is_accommodation' => 0]);
        $this->createEvent($gardenId, '2026-11-21', 'confirmed');

        self::assertSame(
            ['2026-11-20' => 'free', '2026-11-21' => 'booked', '2026-11-22' => 'free'],
            $this->states(Availability::calendar($gardenId, '2026-11-20', '2026-11-22')),
        );
    }

    public function testAnEventAnywhereAtTheVenueLocksThatDateForStays(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden', 'sp_is_accommodation' => 0]);
        $this->createEvent($gardenId, '2026-11-21', 'confirmed');

        self::assertSame(
            ['2026-11-21' => 'mode_locked'],
            $this->states(Availability::calendar($villaId, '2026-11-21', '2026-11-21')),
        );
    }

    public function testAStayAnywhereAtTheVenueLocksThatDateForEvents(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden', 'sp_is_accommodation' => 0]);
        $this->createStay($villaId, '2026-12-12', '2026-12-13', 'confirmed');

        self::assertSame(
            ['2026-12-12' => 'mode_locked', '2026-12-13' => 'free'],
            $this->states(Availability::calendar($gardenId, '2026-12-12', '2026-12-13')),
        );
    }

    public function testAPublicEventLeavesTheRoomsOpen(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden']);
        $this->createEvent($gardenId, '2026-11-21', 'confirmed', null, 'public');

        $calendar = Availability::calendar($villaId, '2026-11-21', '2026-11-21');

        self::assertSame(['2026-11-21' => 'free'], $this->states($calendar));
    }

    public function testGuestsStayingOverDontStopAPublicEvent(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden']);
        $this->createStay($villaId, '2026-11-21', '2026-11-22', 'confirmed');

        // A private event that night is refused, a public one goes ahead
        $private = $this->errorFrom(function () use ($gardenId) {
            Db::transaction(function () use ($gardenId) {
                Availability::assertFree($gardenId, '2026-11-21', '2026-11-21', 'event');
            });
        });
        Db::transaction(function () use ($gardenId) {
            Availability::assertFree($gardenId, '2026-11-21', '2026-11-21', 'public_event');
        });

        self::assertSame(409, $private->status());
    }

    public function testACancelledEventLocksNothing(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden', 'sp_is_accommodation' => 0]);
        $this->createEvent($gardenId, '2026-11-21', 'cancelled');

        self::assertSame(['2026-11-21' => 'free'], $this->states(Availability::calendar($villaId, '2026-11-21', '2026-11-21')));
    }

    public function testVillaNightsGivenToEventGuestsAreBooked(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden', 'sp_is_accommodation' => 0]);
        $event = $this->createEvent($gardenId, '2026-11-21', 'confirmed');
        $this->insert('event_space_allocation', 'esa_id', [
            'esa_eb_id' => $event['eventId'], 'esa_sp_id' => $villaId, 'esa_check_in' => '2026-11-21', 'esa_check_out' => '2026-11-23', 'esa_price_snapshot' => 0,
        ]);

        self::assertSame(
            ['2026-11-21' => 'booked', '2026-11-22' => 'booked', '2026-11-23' => 'free'],
            $this->states(Availability::calendar($villaId, '2026-11-21', '2026-11-23')),
        );
    }

    public function testTheStrongestStateWinsWhenSeveralApply(): void
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden', 'sp_is_accommodation' => 0]);
        // 24 Dec is booked and blacked out; 26 Dec is booked and there's an event at the venue
        $this->createStay($villaId, '2026-12-24', '2026-12-25', 'confirmed');
        $this->insert('space_blackout', 'sbk_id', [
            'sbk_sp_id' => $villaId, 'sbk_start_date' => '2026-12-24', 'sbk_end_date' => '2026-12-24', 'sbk_reason' => 'Family use',
        ]);
        $this->createStay($villaId, '2026-12-26', '2026-12-27', 'confirmed');
        $this->createEvent($gardenId, '2026-12-26', 'confirmed');

        self::assertSame(
            ['2026-12-24' => 'blacked_out', '2026-12-25' => 'free', '2026-12-26' => 'booked'],
            $this->states(Availability::calendar($villaId, '2026-12-24', '2026-12-26')),
        );
    }

    // A villa and two rooms in a new venue: keys villa, room, otherRoom.
    private function villaWithTwoRooms(): array
    {
        $venueId = $this->createVenue();
        $villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1]);
        $roomId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'room', 'sp_parent_id' => $villaId, 'sp_is_accommodation' => 1]);
        $otherRoomId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'room', 'sp_parent_id' => $villaId, 'sp_is_accommodation' => 1]);

        return ['villa' => $villaId, 'room' => $roomId, 'otherRoom' => $otherRoomId];
    }

    // Turns the calendar into ['2026-12-23' => 'free', ...], which is easier to compare in a test
    private function states(array $calendar): array
    {
        $states = [];
        foreach ($calendar as $day) {
            $states[$day['date']] = $day['state'];
        }

        return $states;
    }
}
