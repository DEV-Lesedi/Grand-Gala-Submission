<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Availability;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use LogicException;
use RuntimeException;

// Availability::reserve() is the safe way to take dates. Today is 2026-09-04.
// It locks the venue, checks spaces, takes a reference, then saves. The counter is restored.
class ReservationTest extends DatabaseTestCase
{
    private array $savedCounter = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedCounter = Db::pdo()->query("SELECT rs_year, rs_next FROM reference_sequence WHERE rs_kind = 'booking'")->fetch();
        $this->setBookingCounter(2026, 449);
    }

    protected function tearDown(): void
    {
        $this->setBookingCounter((int) $this->savedCounter['rs_year'], (int) $this->savedCounter['rs_next']);
        parent::tearDown();
    }

    // second_guest.php is the second guest, in its own process.
    public function testOnlyOneOfTwoGuestsReservingTheSameNightAtOnceGetsIt(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);

        $secondGuest = null;
        // The & lets the save below fill in $secondGuest, so we can use it after the reservation
        Availability::reserve([$villaId], '2026-12-12', '2026-12-13', 'stay', function () use ($villaId, &$secondGuest) {
            // The first guest has the night. The second tries before that save, so it has to wait.
            $secondGuest = $this->startScript('second_guest.php', [$villaId, '2026-12-12', '2026-12-13']);
            sleep(3);

            // The first guest saves the booking. It's committed when reserve() finishes.
            $this->createStay($villaId, '2026-12-12', '2026-12-13', 'pending_payment', '2026-12-31 12:00:00');
        });

        self::assertSame('409', $this->waitForScript($secondGuest));
    }

    public function testSaveGetsTheNextReferenceAndReserveReturnsWhatSaveReturns(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);

        $result = Availability::reserve([$villaId], '2026-12-12', '2026-12-14', 'stay', function (string $reference) {
            return 'saved ' . $reference;
        });

        self::assertSame('saved GGG-2026-0449', $result);
    }

    public function testATakenSpaceStopsTheWholeReservationAndSaveNeverRuns(): void
    {
        $venueId = $this->createVenue();
        $gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden']);
        $poolId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'pool']);
        $this->createEvent($poolId, '2026-11-21', 'confirmed');

        $saveRan = false;
        $error = $this->errorFrom(function () use ($gardenId, $poolId, &$saveRan) {
            // The & lets the save below set $saveRan out here, so we can check it never ran
            Availability::reserve([$gardenId, $poolId], '2026-11-21', '2026-11-21', 'event', function () use (&$saveRan) {
                $saveRan = true;
            });
        });

        self::assertSame(409, $error->status());
        self::assertSame('Already booked on these dates', $error->getMessage());
        self::assertFalse($saveRan);
    }

    public function testARefusedReservationDoesNotUseAReferenceNumber(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $this->createStay($villaId, '2026-12-12', '2026-12-13', 'confirmed');

        $this->errorFrom(function () use ($villaId) {
            Availability::reserve([$villaId], '2026-12-12', '2026-12-13', 'stay', function () {
                return null;
            });
        });

        self::assertSame(449, $this->nextBookingNumber());
    }

    public function testWhenSaveFailsEverythingIsUndoneAndTheNumberIsGivenBack(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);

        $venueId = 0;
        try {
            // The & lets the save below set $venueId out here, so we can check the row is gone
            Availability::reserve([$villaId], '2026-12-12', '2026-12-13', 'stay', function () use (&$venueId) {
                $venueId = $this->insert('venue', 'v_id', ['v_name' => '[test] venue', 'v_capacity' => 10]);
                throw new RuntimeException('Payment set-up failed');
            });
            self::fail('Expected the error to be passed on');
        } catch (RuntimeException $error) {
            self::assertSame('Payment set-up failed', $error->getMessage());
        }

        $statement = Db::pdo()->prepare('SELECT COUNT(*) FROM venue WHERE v_id = ?');
        $statement->execute([$venueId]);
        self::assertSame(0, (int) $statement->fetchColumn());
        self::assertSame(449, $this->nextBookingNumber());
    }

    public function testSpacesMustBeAtOneVenue(): void
    {
        $gardenId = $this->createSpace(['sp_type' => 'garden']);
        $poolId = $this->createSpace(['sp_type' => 'pool']); // a different venue

        $error = $this->errorFrom(function () use ($gardenId, $poolId) {
            Availability::reserve([$gardenId, $poolId], '2026-11-21', '2026-11-21', 'event', function () {
                return null;
            });
        });

        self::assertSame(422, $error->status());
        self::assertSame('All spaces must be at the same venue', $error->getMessage());
    }

    public function testAtLeastOneSpaceIsNeeded(): void
    {
        $error = $this->errorFrom(function () {
            Availability::reserve([], '2026-11-21', '2026-11-21', 'event', function () {
                return null;
            });
        });

        self::assertSame(422, $error->status());
        self::assertSame('Choose at least one space', $error->getMessage());
    }

    public function testSpacesGuestsCantSeeAreNotFound(): void
    {
        $offId = $this->createSpace(['sp_is_active' => 0]);
        $offVillaId = $this->createSpace(['sp_is_active' => 0, 'sp_is_accommodation' => 1]);
        $roomOfOffVillaId = $this->createSpace(['sp_type' => 'room', 'sp_parent_id' => $offVillaId, 'sp_is_accommodation' => 1]);

        foreach ([$offId, $roomOfOffVillaId, 999999999] as $spaceId) {
            $error = $this->errorFrom(function () use ($spaceId) {
                Availability::reserve([$spaceId], '2026-12-12', '2026-12-13', 'stay', function () {
                    return null;
                });
            });

            self::assertSame(404, $error->status());
            self::assertSame('Space not found', $error->getMessage());
        }
    }

    public function testTheAvailabilityCheckRefusesToRunOutsideATransaction(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);

        // Outside a transaction the venue lock drops at once, so both bookings could pass.
        $this->expectException(LogicException::class);

        Availability::assertFree($villaId, '2026-12-12', '2026-12-13', 'stay');
    }

    private function nextBookingNumber(): int
    {
        return (int) Db::pdo()->query("SELECT rs_next FROM reference_sequence WHERE rs_kind = 'booking'")->fetchColumn();
    }

    private function setBookingCounter(int $year, int $next): void
    {
        $statement = Db::pdo()->prepare("UPDATE reference_sequence SET rs_year = ?, rs_next = ? WHERE rs_kind = 'booking'");
        $statement->execute([$year, $next]);
    }
}
