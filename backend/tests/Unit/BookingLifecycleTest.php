<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\BookingLifecycle;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
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

    // ---- Promo codes follow the move ----


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

    // ---- Helpers ----

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
