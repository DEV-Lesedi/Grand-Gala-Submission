<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\CronJobs;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

// Scheduled jobs on the shared database. Now is frozen at 2020-01-02 12:00.
// Fixtures are dated around then, so other people's rows are not old enough to change.
class CronJobsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt('2020-01-02 12:00');
    }

    protected function tearDown(): void
    {
        Clock::freeze($_ENV['APP_TODAY']);
        parent::tearDown();
    }

    public function testRunExpiresLapsedHoldsAndCompletesFinishedBookings(): void
    {
        $room = $this->createSpace(['sp_is_accommodation' => 1]);
        $hall = $this->createSpace(['sp_type' => 'hall']);
        $lapsed = $this->createStay($room, '2020-02-01', '2020-02-02', 'pending_payment', '2020-01-02 11:59:00');
        $running = $this->createStay($room, '2020-02-03', '2020-02-04', 'pending_payment', '2020-01-02 12:01:00');
        $checkedOut = $this->createStay($room, '2020-01-01', '2020-01-02', 'confirmed');
        $stillStaying = $this->createStay($room, '2020-01-01', '2020-01-03', 'confirmed');
        $eventDone = $this->createEvent($hall, '2020-01-01', 'confirmed')['bookingId'];
        $eventToday = $this->createEvent($hall, '2020-01-02', 'confirmed')['bookingId'];
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => 'TEST' . strtoupper(bin2hex(random_bytes(4))), 'promo_type' => 'fixed', 'promo_value' => 10,
            'promo_applies_to' => '["stay"]', 'promo_valid_from' => '2019-01-01', 'promo_valid_to' => '2020-12-31',
        ]);
        $this->insert('promo_use', 'pu_id', ['pu_promo_id' => $promoId, 'pu_user_id' => $this->createGuest(), 'pu_booking_id' => $lapsed]);
        $quoted = $this->quotedEvent($hall, '2020-01-01');
        $stillValid = $this->quotedEvent($hall, '2020-01-02');
        $order = $this->ticketOrder($hall, '2020-01-02 12:00:00');

        $result = CronJobs::run();

        // Rate limit buckets run on real time, so how many were pruned isn't checked here
        unset($result['prunedRateLimitBuckets']);
        self::assertSame([
            'expiredBookings' => 1,
            'expiredQuotations' => 1,
            'expiredTicketOrders' => 1,
            'completedStays' => 1,
            'completedEvents' => 1,
        ], $result);
        self::assertSame('expired', $this->statusOf($lapsed));
        self::assertSame('released', $this->value('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', [$lapsed]));
        self::assertSame('pending_payment', $this->statusOf($running));
        self::assertSame('completed', $this->statusOf($checkedOut));
        self::assertSame('confirmed', $this->statusOf($stillStaying));
        self::assertSame('completed', $this->statusOf($eventDone));
        self::assertSame('confirmed', $this->statusOf($eventToday));
        self::assertSame('expired', $this->statusOf($quoted['bookingId']));
        self::assertSame('expired', $this->value('SELECT q_status FROM quotation WHERE q_id = ?', [$quoted['quotationId']]));
        self::assertSame('quoted', $this->statusOf($stillValid['bookingId']));
        self::assertSame('sent', $this->value('SELECT q_status FROM quotation WHERE q_id = ?', [$stillValid['quotationId']]));
        self::assertSame('expired', $this->value('SELECT to_status FROM ticket_order WHERE to_id = ?', [$order]));
        // Written by BookingLifecycle, by nobody (the job), with the hold cleared
        $history = $this->row('SELECT bsh_from_status, bsh_to_status, bsh_changed_by, bsh_reason FROM booking_status_history WHERE bsh_b_id = ?', [$lapsed]);
        self::assertSame(['bsh_from_status' => 'pending_payment', 'bsh_to_status' => 'expired', 'bsh_changed_by' => null, 'bsh_reason' => 'Unpaid hold expired'], $history);
        self::assertNull($this->value('SELECT b_hold_expires_at FROM booking WHERE b_id = ?', [$lapsed]));
    }

    public function testABookingPaidWhileTheJobRanIsLeftAlone(): void
    {
        $room = $this->createSpace(['sp_is_accommodation' => 1]);
        // It was on the list of lapsed holds, but PayFast confirmed it before the job got to it
        $paid = $this->createStay($room, '2020-02-01', '2020-02-02', 'confirmed');

        // And a hold that hasn't quite run out yet isn't expired, even if asked directly
        $running = $this->createStay($room, '2020-02-05', '2020-02-06', 'pending_payment', '2020-01-02 12:01:00');

        self::assertFalse(CronJobs::expireHold($paid));
        self::assertSame('confirmed', $this->statusOf($paid));
        self::assertFalse(CronJobs::expireHold($running));
        self::assertSame('pending_payment', $this->statusOf($running));
        self::assertFalse(CronJobs::complete($this->createStay($room, '2020-02-03', '2020-02-04', 'cancelled')));
    }

    // A hold ending now has run out; one with no end keeps going. The job must use that cut-off.
    public function testAHoldEndingNowIsExpiredAndOneWithNoEndKeepsGoing(): void
    {
        $room = $this->createSpace(['sp_is_accommodation' => 1]);
        $endsNow = $this->createStay($room, '2020-02-07', '2020-02-08', 'pending_payment', '2020-01-02 12:00:00');
        $noEnd = $this->createStay($room, '2020-02-09', '2020-02-10', 'pending_payment', null);

        $result = CronJobs::run();

        self::assertSame(1, $result['expiredBookings']);
        self::assertSame('expired', $this->statusOf($endsNow));
        self::assertSame('pending_payment', $this->statusOf($noEnd));
    }

    // An event booking waiting for the guest's answer to a quotation valid until $validUntil
    private function quotedEvent(int $spaceId, string $validUntil): array
    {
        $bookingId = $this->createEvent($spaceId, '2020-03-01', 'quoted')['bookingId'];
        $quotationId = $this->insert('quotation', 'q_id', [
            'q_b_id' => $bookingId, 'q_reference' => 'TQ-' . strtoupper(bin2hex(random_bytes(5))), 'q_amount' => 900,
            'q_status' => 'sent', 'q_valid_until' => $validUntil,
        ]);

        return ['bookingId' => $bookingId, 'quotationId' => $quotationId];
    }

    // An unpaid ticket order for a public event, held until $holdExpires
    private function ticketOrder(int $spaceId, string $holdExpires): int
    {
        $eventId = $this->createEvent($spaceId, '2020-03-01', 'confirmed', null, 'public')['eventId'];

        return $this->insert('ticket_order', 'to_id', [
            'to_reference' => 'TT-' . strtoupper(bin2hex(random_bytes(5))), 'to_eb_id' => $eventId, 'to_buyer_name' => '[test]',
            'to_buyer_surname' => 'Buyer', 'to_buyer_email' => 'buyer@example.com', 'to_hold_expires_at' => $holdExpires,
        ]);
    }

    private function statusOf(int $bookingId): string
    {
        return (string) $this->value('SELECT b_status FROM booking WHERE b_id = ?', [$bookingId]);
    }

    private function row(string $sql, array $params): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetch();
    }

    private function value(string $sql, array $params): mixed
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
