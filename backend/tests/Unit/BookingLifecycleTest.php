<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\BookingLifecycle;
use GrandGala\Services\Events;
use GrandGala\Services\GuestCancel;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

// The one place a booking's status changes. Now is 2026-09-04 12:00.
// An allowed move writes one history row; any other move is a 409 and changes nothing.
class BookingLifecycleTest extends DatabaseTestCase
{
    private const STATUSES = ['enquiry', 'quoted', 'pending_payment', 'confirmed', 'completed', 'cancelled', 'expired', 'refunded'];

    // The hold an unpaid booking starts with in these tests
    private const HOLD = '2026-09-06 12:00:00';

    // Every status move the code allows, and what triggers it.
    public static function allowedMoves(): array
    {
        return [
            'staff send the first quotation' => ['enquiry', 'quoted'],
            'guest cancels an enquiry' => ['enquiry', 'cancelled'],
            'guest accepts a quotation' => ['quoted', 'pending_payment'],
            'guest asks for changes' => ['quoted', 'enquiry'],
            'guest declines a quotation or cancels' => ['quoted', 'cancelled'],
            'quotation runs out (nightly job)' => ['quoted', 'expired'],
            'guest pays a quotation or a stay' => ['pending_payment', 'confirmed'],
            'guest cancels before paying' => ['pending_payment', 'cancelled'],
            'hold runs out (nightly job, or a payment that came too late)' => ['pending_payment', 'expired'],
            'staff cancel a public event, guest cancels a paid stay' => ['confirmed', 'cancelled'],
            'the stay or event day has passed (nightly job)' => ['confirmed', 'completed'],
            'refund finished (quotations and stays)' => ['cancelled', 'refunded'],
        ];
    }

    #[DataProvider('allowedMoves')]
    public function testAnAllowedMoveChangesTheStatusAndWritesOneHistoryRow(string $from, string $to): void
    {
        $bookingId = $this->booking($from);
        $staffId = $this->createGuest();

        $old = Db::transaction(function () use ($bookingId, $to, $staffId) {
            return $this->moveTo($bookingId, $to, $staffId, '[test] reason');
        });

        self::assertSame($from, $old);
        self::assertSame($to, $this->statusOf($bookingId));
        self::assertSame([[
            'bsh_from_status' => $from,
            'bsh_to_status' => $to,
            'bsh_changed_by' => $staffId,
            'bsh_reason' => '[test] reason',
            'bsh_date_created' => '2026-09-04 12:00:00',
        ]], $this->historyOf($bookingId));
    }

    public function testEveryOtherMoveIsRefusedAndChangesNothing(): void
    {
        $allowed = [];
        foreach (self::allowedMoves() as $move) {
            $allowed[] = $move[0] . ' to ' . $move[1];
        }
        $bookingId = $this->booking('enquiry');

        $refused = 0;
        foreach (self::STATUSES as $from) {
            foreach (self::STATUSES as $to) {
                if (in_array($from . ' to ' . $to, $allowed, true)) {
                    continue;
                }
                $this->update('booking', 'b_id', $bookingId, ['b_status' => $from, 'b_hold_expires_at' => self::HOLD]);

                $error = $this->errorFrom(function () use ($bookingId, $to) {
                    Db::transaction(function () use ($bookingId, $to) {
                        $this->moveTo($bookingId, $to, null, '[test] reason');
                    });
                });

                self::assertSame(409, $error->status(), $from . ' to ' . $to);
                self::assertSame('This booking can\'t go from ' . $from . ' to ' . $to, $error->getMessage());
                self::assertSame($from, $this->statusOf($bookingId));
                self::assertSame(self::HOLD, $this->holdOf($bookingId));
                $refused = $refused + 1;
            }
        }

        self::assertSame([], $this->historyOf($bookingId));
        // 8 statuses × 8 statuses, less the 12 allowed moves
        self::assertSame(52, $refused);
    }

    public function testAMoveOutsideATransactionIsRefused(): void
    {
        $bookingId = $this->booking('quoted');

        // Outside a transaction the row lock drops at once, so both changes could pass.
        $moved = $this->logicErrorFrom(function () use ($bookingId) {
            BookingLifecycle::move($bookingId, 'cancelled', null, '[test] reason');
        });
        $held = $this->logicErrorFrom(function () use ($bookingId) {
            BookingLifecycle::holdForPayment($bookingId, '2026-09-07 12:00:00', null, '[test] reason');
        });

        self::assertStringContainsString('Db::transaction()', $moved->getMessage());
        self::assertStringContainsString('Db::transaction()', $held->getMessage());
        self::assertSame('quoted', $this->statusOf($bookingId));
        self::assertSame([], $this->historyOf($bookingId));
    }

    public function testLeavingPendingPaymentClearsTheHold(): void
    {
        foreach (['confirmed', 'cancelled', 'expired'] as $to) {
            $bookingId = $this->booking('pending_payment');

            Db::transaction(function () use ($bookingId, $to) {
                BookingLifecycle::move($bookingId, $to, null, '[test] reason');
            });

            self::assertNull($this->holdOf($bookingId), $to);
        }
    }

    public function testWaitingForPaymentHoldsTheDatesUntilTheGivenTime(): void
    {
        $bookingId = $this->booking('quoted');

        Db::transaction(function () use ($bookingId) {
            BookingLifecycle::holdForPayment($bookingId, '2026-09-07 12:00:00', null, '[test] accepted');
        });

        self::assertSame('pending_payment', $this->statusOf($bookingId));
        self::assertSame('2026-09-07 12:00:00', $this->holdOf($bookingId));
    }

    public function testWaitingForPaymentAlwaysNeedsAHold(): void
    {
        $bookingId = $this->booking('quoted');

        // A booking waiting for payment with no hold would keep its dates until someone cancels it
        $error = $this->logicErrorFrom(function () use ($bookingId) {
            Db::transaction(function () use ($bookingId) {
                BookingLifecycle::move($bookingId, 'pending_payment', null, '[test] reason');
            });
        });

        self::assertStringContainsString('holdForPayment()', $error->getMessage());
        self::assertSame('quoted', $this->statusOf($bookingId));
        self::assertSame([], $this->historyOf($bookingId));
    }

    public function testABookingThatDoesNotExistIsNotFound(): void
    {
        $error = $this->errorFrom(function () {
            Db::transaction(function () {
                BookingLifecycle::move(0, 'cancelled', null, '[test] reason');
            });
        });

        self::assertSame(404, $error->status());
        self::assertSame('Booking not found', $error->getMessage());
    }

    public function testTwoMovesAtOnceAreCheckedOneAfterTheOther(): void
    {
        $bookingId = $this->booking('quoted');

        $second = null;
        // The & lets the work below fill in $second, so we can wait for it afterwards
        Db::transaction(function () use ($bookingId, &$second) {
            BookingLifecycle::move($bookingId, 'cancelled', null, '[test] declined');
            // The second change starts before the first is saved, so it waits on the row lock.
            $second = $this->startScript('second_move.php', [$bookingId, 'enquiry']);
            sleep(2);
        });

        // It then sees the booking is cancelled, and cancelled cannot return to enquiry.
        self::assertSame('409', $this->waitForScript($second));
        self::assertSame('cancelled', $this->statusOf($bookingId));
        self::assertCount(1, $this->historyOf($bookingId));
    }

    // ---- The callers that wrote no history before ----

    public function testCancellingAPublicEventWritesItsHistory(): void
    {
        $spaceId = $this->createSpace();
        $event = $this->createEvent($spaceId, '2026-11-14', 'confirmed', null, 'public');
        $this->update('event_booking', 'eb_id', $event['eventId'], ['eb_is_venue_hosted' => 1]);

        $staffId = $this->createGuest();

        Events::cancel($event['eventId'], ['reason' => 'Storm warning'], $staffId);

        self::assertSame('cancelled', $this->statusOf($event['bookingId']));
        // The staff member who cancelled it is named in the history
        self::assertSame([[
            'bsh_from_status' => 'confirmed',
            'bsh_to_status' => 'cancelled',
            'bsh_changed_by' => $staffId,
            'bsh_reason' => 'Storm warning',
            'bsh_date_created' => '2026-09-04 12:00:00',
        ]], $this->historyOf($event['bookingId']));
    }

    public function testAGuestCancellingAnUnpaidBookingWritesItsHistory(): void
    {
        foreach (['enquiry', 'quoted', 'pending_payment'] as $status) {
            $bookingId = $this->booking($status);
            $guestId = $this->ownerOf($bookingId);

            GuestCancel::cancel($this->referenceOf($bookingId), $guestId);

            self::assertSame('cancelled', $this->statusOf($bookingId));
            self::assertNull($this->holdOf($bookingId));
            self::assertSame([[
                'bsh_from_status' => $status,
                'bsh_to_status' => 'cancelled',
                'bsh_changed_by' => $guestId,
                'bsh_reason' => 'Cancelled by guest',
                'bsh_date_created' => '2026-09-04 12:00:00',
            ]], $this->historyOf($bookingId));
        }
    }

    public function testAGuestStillCantCancelAPaidBookingOrSomeoneElses(): void
    {
        // confirmed to cancelled is allowed here; a guest's paid event is refused in GuestCancel.
        $paid = $this->booking('confirmed');
        $someoneElses = $this->booking('enquiry');

        $paidError = $this->errorFrom(function () use ($paid) {
            GuestCancel::cancel($this->referenceOf($paid), $this->ownerOf($paid));
        });
        $notTheirs = $this->errorFrom(function () use ($paid, $someoneElses) {
            GuestCancel::cancel($this->referenceOf($someoneElses), $this->ownerOf($paid));
        });

        self::assertSame(409, $paidError->status());
        self::assertSame(404, $notTheirs->status());

        self::assertSame('confirmed', $this->statusOf($paid));
        self::assertSame('enquiry', $this->statusOf($someoneElses));
        self::assertSame([], $this->historyOf($paid));
        self::assertSame([], $this->historyOf($someoneElses));
    }

    // ---- Promo codes follow the move ----

    public static function promoEffects(): array
    {
        return [
            'paying uses the code' => ['pending_payment', 'confirmed', 'used'],
            'cancelling before paying gives the code back' => ['pending_payment', 'cancelled', 'released'],
            'the hold running out gives the code back' => ['pending_payment', 'expired', 'released'],
            'cancelling an enquiry gives the code back' => ['enquiry', 'cancelled', 'released'],
            'a quotation running out gives the code back' => ['quoted', 'expired', 'released'],
            'declining a quotation gives the code back' => ['quoted', 'cancelled', 'released'],
            'sending a quotation leaves the code held' => ['enquiry', 'quoted', 'reserved'],
            'accepting a quotation leaves the code held' => ['quoted', 'pending_payment', 'reserved'],
            'asking for changes leaves the code held' => ['quoted', 'enquiry', 'reserved'],
            'finishing the day leaves a held code held' => ['confirmed', 'completed', 'reserved'],
            'a refund leaves a held code held' => ['cancelled', 'refunded', 'reserved'],
        ];
    }

    // Confirm uses a held code; cancel or expiry gives it back. Only paying counts the use.
    #[DataProvider('promoEffects')]
    public function testEachMoveAppliesTheRightPromoEffect(string $from, string $to, string $useStatus): void
    {
        $bookingId = $this->booking($from);
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => 'TEST' . strtoupper(bin2hex(random_bytes(4))),
            'promo_type' => 'fixed',
            'promo_value' => 10,
            'promo_applies_to' => '["stay","event"]',
            'promo_valid_from' => '2026-09-01',
            'promo_valid_to' => '2026-12-31',
        ]);
        $this->insert('promo_use', 'pu_id', [
            'pu_promo_id' => $promoId,
            'pu_user_id' => $this->ownerOf($bookingId),
            'pu_booking_id' => $bookingId,
        ]);

        Db::transaction(function () use ($bookingId, $to) {
            $this->moveTo($bookingId, $to, null, '[test] move');
        });

        self::assertSame($useStatus, $this->value('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', $bookingId));
        $counted = 0;
        if ($useStatus === 'used') {
            $counted = 1;
        }
        self::assertSame($counted, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', $promoId));
    }

    // A refund gives the money back, so the code it used is given back too and stops counting
    public function testARefundGivesBackTheCodeTheBookingUsed(): void
    {
        $bookingId = $this->booking('pending_payment');
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => 'TEST' . strtoupper(bin2hex(random_bytes(4))),
            'promo_type' => 'fixed',
            'promo_value' => 10,
            'promo_applies_to' => '["stay","event"]',
            'promo_valid_from' => '2026-09-01',
            'promo_valid_to' => '2026-12-31',
        ]);
        $this->insert('promo_use', 'pu_id', [
            'pu_promo_id' => $promoId,
            'pu_user_id' => $this->ownerOf($bookingId),
            'pu_booking_id' => $bookingId,
        ]);

        Db::transaction(function () use ($bookingId) {
            BookingLifecycle::move($bookingId, 'confirmed', null, '[test] paid');
            BookingLifecycle::move($bookingId, 'cancelled', null, '[test] cancelled');
        });
        $usedAfterCancel = $this->value('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', $bookingId);
        Db::transaction(function () use ($bookingId) {
            BookingLifecycle::move($bookingId, 'refunded', null, '[test] refunded');
        });

        // Cancelling a paid booking keeps the use until the money is back
        self::assertSame('used', $usedAfterCancel);
        self::assertSame('released', $this->value('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', $bookingId));
        self::assertSame(0, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', $promoId));
    }

    // No promo row is not an error: most bookings have no code
    public function testAMoveWithNoPromoUseStillGoesThrough(): void
    {
        $cancelled = $this->booking('pending_payment');
        $paid = $this->booking('pending_payment');

        Db::transaction(function () use ($cancelled, $paid) {
            BookingLifecycle::move($cancelled, 'cancelled', null, '[test] cancel');
            BookingLifecycle::move($paid, 'confirmed', null, '[test] pay');
        });

        self::assertSame('cancelled', $this->statusOf($cancelled));
        self::assertSame('confirmed', $this->statusOf($paid));
        self::assertFalse($this->value('SELECT pu_id FROM promo_use WHERE pu_booking_id = ?', $cancelled));
        self::assertFalse($this->value('SELECT pu_id FROM promo_use WHERE pu_booking_id = ?', $paid));
    }


    public function testANewBookingWaitingForPaymentGetsItsHoldAndFirstHistoryRow(): void
    {
        $bookingId = $this->booking('pending_payment');
        $this->update('booking', 'b_id', $bookingId, ['b_hold_expires_at' => null]);
        $guestId = $this->ownerOf($bookingId);

        Db::transaction(function () use ($bookingId, $guestId) {
            BookingLifecycle::createdWaitingForPayment($bookingId, '2026-09-06 12:00:00', $guestId, '[test] booked');
        });

        self::assertSame('pending_payment', $this->statusOf($bookingId));
        self::assertSame('2026-09-06 12:00:00', $this->holdOf($bookingId));
        self::assertSame([[
            'bsh_from_status' => null,
            'bsh_to_status' => 'pending_payment',
            'bsh_changed_by' => $guestId,
            'bsh_reason' => '[test] booked',
            'bsh_date_created' => '2026-09-04 12:00:00',
        ]], $this->historyOf($bookingId));
    }

    public function testOnlyABookingJustCreatedCanStartWaitingForPayment(): void
    {
        // A quotation accepted earlier: it already waits for payment and has its history
        $accepted = $this->booking('quoted');
        Db::transaction(function () use ($accepted) {
            BookingLifecycle::holdForPayment($accepted, self::HOLD, null, '[test] accepted');
        });
        $quoted = $this->booking('quoted');

        $again = $this->logicErrorFrom(function () use ($accepted) {
            Db::transaction(function () use ($accepted) {
                BookingLifecycle::createdWaitingForPayment($accepted, '2026-09-30 12:00:00', null, '[test] again');
            });
        });
        $notWaiting = $this->logicErrorFrom(function () use ($quoted) {
            Db::transaction(function () use ($quoted) {
                BookingLifecycle::createdWaitingForPayment($quoted, self::HOLD, null, '[test] wrong status');
            });
        });
        $outside = $this->logicErrorFrom(function () use ($quoted) {
            BookingLifecycle::createdWaitingForPayment($quoted, self::HOLD, null, '[test] no transaction');
        });

        self::assertStringContainsString('just created', $again->getMessage());
        self::assertStringContainsString('just created', $notWaiting->getMessage());
        self::assertStringContainsString('Db::transaction()', $outside->getMessage());
        self::assertSame(self::HOLD, $this->holdOf($accepted));
        self::assertCount(1, $this->historyOf($accepted));
        self::assertSame('quoted', $this->statusOf($quoted));
        self::assertCount(0, $this->historyOf($quoted));
    }

    // ---- Helpers ----

    private function referenceOf(int $bookingId): string
    {
        $statement = Db::pdo()->prepare('SELECT b_reference FROM booking WHERE b_id = ?');
        $statement->execute([$bookingId]);

        return (string) $statement->fetchColumn();
    }

    // An event booking with this status, owned by a new guest. One waiting for payment has a hold.
    // Its history rows are deleted with it (ON DELETE CASCADE).
    private function booking(string $status): int
    {
        $typeId = (int) Db::pdo()->query("SELECT bt_id FROM booking_type WHERE bt_name = 'event'")->fetchColumn();
        $hold = null;
        if ($status === 'pending_payment') {
            $hold = self::HOLD;
        }

        return $this->insert('booking', 'b_id', [
            'b_reference' => 'TEST-' . strtoupper(bin2hex(random_bytes(5))),
            'b_bt_id' => $typeId,
            'b_u_id' => $this->createGuest(),
            'b_status' => $status,
            'b_hold_expires_at' => $hold,
        ]);
    }

    // Moving to pending_payment always goes through holdForPayment(), which sets the hold
    private function moveTo(int $bookingId, string $to, ?int $changedBy, string $reason): string
    {
        if ($to === 'pending_payment') {
            return BookingLifecycle::holdForPayment($bookingId, '2026-09-07 12:00:00', $changedBy, $reason);
        }

        return BookingLifecycle::move($bookingId, $to, $changedBy, $reason);
    }

    // Runs $work and returns the LogicException it throws. Fails the test if nothing is thrown.
    private function logicErrorFrom(callable $work): LogicException
    {
        try {
            $work();
        } catch (LogicException $error) {
            return $error;
        }
        self::fail('Expected a LogicException');
    }

    private function statusOf(int $bookingId): string
    {
        return (string) $this->value('SELECT b_status FROM booking WHERE b_id = ?', $bookingId);
    }

    private function holdOf(int $bookingId): ?string
    {
        return $this->value('SELECT b_hold_expires_at FROM booking WHERE b_id = ?', $bookingId);
    }

    private function ownerOf(int $bookingId): int
    {
        return (int) $this->value('SELECT b_u_id FROM booking WHERE b_id = ?', $bookingId);
    }

    private function historyOf(int $bookingId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT bsh_from_status, bsh_to_status, bsh_changed_by, bsh_reason, bsh_date_created
             FROM booking_status_history WHERE bsh_b_id = ? ORDER BY bsh_id'
        );
        $statement->execute([$bookingId]);

        return $statement->fetchAll();
    }

    private function value(string $sql, int $bookingId): mixed
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$bookingId]);

        return $statement->fetchColumn();
    }
}
