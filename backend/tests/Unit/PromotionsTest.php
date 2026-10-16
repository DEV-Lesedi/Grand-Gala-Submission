<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Promotions;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use LogicException;

// Promo codes. Today is 2026-09-04.
// quote() checks a code and records nothing; reserve() also holds it for a booking.
class PromotionsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Clock::freeze($_ENV['APP_TODAY']);
        parent::tearDown();
    }

    public function testAValidCodeGivesItsDiscountAndRecordsNothing(): void
    {
        $promo = $this->createPromo(['promo_type' => 'percentage', 'promo_value' => 10]);

        $quote = Promotions::quote(' ' . strtolower($promo['code']) . ' ', 'stay', 2800, null);

        self::assertSame(['discount' => 280.0, 'promoId' => $promo['id']], $quote);
        self::assertSame(0, $this->usesOf($promo['id']));
    }

    public function testNoCodeGivesNoDiscount(): void
    {
        self::assertSame(['discount' => 0.0, 'promoId' => null], Promotions::quote(null, 'stay', 100, null));
        self::assertSame(['discount' => 0.0, 'promoId' => null], Promotions::quote('  ', 'tickets', 100, null));
    }

    public function testACodeOnlyWorksForItsKindsWhileSwitchedOnAndInsideItsDates(): void
    {
        $staysOnly = $this->createPromo(['promo_applies_to' => '["stay"]']);
        $off = $this->createPromo(['promo_is_active' => 0]);
        $later = $this->createPromo(['promo_valid_from' => '2026-09-05', 'promo_valid_to' => '2026-12-31']);
        $over = $this->createPromo(['promo_valid_from' => '2026-08-01', 'promo_valid_to' => '2026-09-03']);

        self::assertSame('Promotion code does not apply to this booking', $this->quoteError($staysOnly['code'], 'tickets'));
        self::assertSame('Promotion code is not available', $this->quoteError($off['code'], 'stay'));
        self::assertSame('Promotion code is not available', $this->quoteError('NO-SUCH-CODE', 'stay'));
        self::assertSame('Promotion code is outside its valid dates', $this->quoteError($later['code'], 'stay'));
        self::assertSame('Promotion code is outside its valid dates', $this->quoteError($over['code'], 'stay'));
        self::assertSame('Unknown promotion category', $this->quoteError($staysOnly['code'], 'ticket'));
        // The last valid day still counts
        Clock::freeze('2026-09-03');
        self::assertSame(100.0, Promotions::quote($over['code'], 'stay', 1000, null)['discount']);
    }

    public function testBelowTheMinimumSpendThereIsNoDiscount(): void
    {
        $promo = $this->createPromo(['promo_min_spend' => 1000]);

        self::assertSame(['discount' => 0.0, 'promoId' => null], Promotions::quote($promo['code'], 'stay', 999.99, null));
        self::assertSame(100.0, Promotions::quote($promo['code'], 'stay', 1000, null)['discount']);
    }

    public function testTheUsageLimitCountsPaidUsesAndBookingsHoldingTheCode(): void
    {
        $promo = $this->createPromo(['promo_usage_limit' => 2, 'promo_usage_count' => 1]);
        $guestId = $this->createGuest();

        Promotions::reserve($promo['code'], 'stay', 1000, $guestId, $this->createBookingRow());

        self::assertSame('Promotion code has reached its usage limit', $this->quoteError($promo['code'], 'stay'));
    }

    public function testEachGuestCanOnlyUseACodeAsOftenAsItsPerGuestLimit(): void
    {
        $promo = $this->createPromo(['promo_limit_per_user' => 1]);
        $guestId = $this->createGuest();
        $otherGuestId = $this->createGuest();

        Promotions::reserve($promo['code'], 'stay', 1000, $guestId, $this->createBookingRow());

        self::assertSame('You have reached the usage limit for this promotion', $this->quoteError($promo['code'], 'stay', $guestId));
        self::assertSame(100.0, Promotions::quote($promo['code'], 'stay', 1000, $otherGuestId)['discount']);
    }

    public function testReserveHoldsTheCodeThenPayingUsesItAndCancellingGivesItBack(): void
    {
        $promo = $this->createPromo();
        $guestId = $this->createGuest();
        $paidBooking = $this->createBookingRow();
        $cancelledBooking = $this->createBookingRow();

        $held = Promotions::reserve($promo['code'], 'stay', 1000, $guestId, $paidBooking);
        Promotions::reserve($promo['code'], 'stay', 1000, $guestId, $cancelledBooking);
        Promotions::markUsed($paidBooking);
        Promotions::markUsed($paidBooking);
        Promotions::release($cancelledBooking);

        self::assertSame(['discount' => 100.0, 'promoId' => $promo['id']], $held);
        self::assertSame('used', $this->useStatus($paidBooking));
        self::assertSame('released', $this->useStatus($cancelledBooking));
        // Paying twice (a repeated payment notice) only counts once
        self::assertSame(1, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promo['id']]));
    }

    // Only a paid use is taken off the count, and only once. The count never goes below 0.
    public function testARefundTakesAPaidUseOffTheCountOnce(): void
    {
        $promo = $this->createPromo(['promo_usage_count' => 4]);
        $guestId = $this->createGuest();
        $refunded = $this->createBookingRow();
        $cancelledUnpaid = $this->createBookingRow();
        Promotions::reserve($promo['code'], 'stay', 1000, $guestId, $refunded);
        Promotions::reserve($promo['code'], 'stay', 1000, $guestId, $cancelledUnpaid);
        Promotions::markUsed($refunded);
        Promotions::release($cancelledUnpaid);

        Promotions::giveBack($refunded);
        Promotions::giveBack($refunded);
        Promotions::giveBack($cancelledUnpaid);

        self::assertSame('released', $this->useStatus($refunded));
        self::assertSame(4, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promo['id']]));

        // Staff may have set the count to 0 by hand
        $zeroed = $this->createPromo();
        $paid = $this->createBookingRow();
        Promotions::reserve($zeroed['code'], 'stay', 1000, $guestId, $paid);
        Promotions::markUsed($paid);
        Db::pdo()->prepare('UPDATE promo SET promo_usage_count = 0 WHERE promo_id = ?')->execute([$zeroed['id']]);
        Promotions::giveBack($paid);
        self::assertSame(0, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$zeroed['id']]));
    }

    // A ticket order uses the same happenings as a Booking: markUsed, release, giveBack
    public function testTheSameHappeningsWorkForATicketOrder(): void
    {
        $promo = $this->createPromo();
        $paid = $this->createTicketOrderRow('buyer@example.com');
        $cancelled = $this->createTicketOrderRow('other@example.com');
        Promotions::reserveOrder($promo['code'], 'tickets', 1000, null, 'buyer@example.com', $paid);
        Promotions::reserveOrder($promo['code'], 'tickets', 1000, null, 'other@example.com', $cancelled);

        Promotions::markUsed(null, $paid);
        Promotions::markUsed(null, $paid);
        self::assertSame('used', $this->orderUseStatus($paid));
        self::assertSame(1, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promo['id']]));

        Promotions::release(null, $cancelled);
        Promotions::giveBack(null, $paid);

        self::assertSame('released', $this->orderUseStatus($cancelled));
        self::assertSame('released', $this->orderUseStatus($paid));
        self::assertSame(0, (int) $this->value('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promo['id']]));
    }

    public function testAHappeningNeedsABookingOrATicketOrder(): void
    {
        $this->expectException(LogicException::class);
        Promotions::markUsed(null, null);
    }

    public function testAHappeningCannotBeAboutBoth(): void
    {
        $this->expectException(LogicException::class);
        Promotions::release(1, 1);
    }

    // A ticket buyer without an account is counted by email, ignoring letter case.
    public function testReservingForATicketOrderCountsTheBuyerByEmail(): void
    {
        $promo = $this->createPromo(['promo_limit_per_user' => 1]);
        $first = $this->createTicketOrderRow('naledi@example.com');
        $second = $this->createTicketOrderRow('NALEDI@example.com');
        $other = $this->createTicketOrderRow('lerato@example.com');
        Promotions::reserveOrder($promo['code'], 'tickets', 1000, null, 'naledi@example.com', $first);

        $error = $this->errorFrom(function () use ($promo, $second) {
            Promotions::reserveOrder($promo['code'], 'tickets', 1000, null, 'NALEDI@example.com', $second);
        });

        self::assertSame(422, $error->status());
        self::assertSame('You have reached the usage limit for this promotion', $error->getMessage());
        self::assertSame(100.0, Promotions::reserveOrder($promo['code'], 'tickets', 1000, null, 'lerato@example.com', $other)['discount']);
    }

    // Quoted one discount. If the code no longer gives it, the stay must be priced again.
    public function testACodeIsOnlyHeldWhenItStillMatchesTheQuote(): void
    {
        $promo = $this->createPromo(['promo_type' => 'fixed', 'promo_value' => 100]);
        $guestId = $this->createGuest();
        $bookingId = $this->createBookingRow();
        $quoted = Promotions::quote($promo['code'], 'stay', 1000, $guestId);

        $this->update('promo', 'promo_id', $promo['id'], ['promo_value' => 40]);
        $changed = $this->errorFrom(function () use ($promo, $guestId, $bookingId, $quoted) {
            Promotions::reserveQuoted($promo['code'], 'stay', 1000, $guestId, $bookingId, $quoted['promoId'], $quoted['discount']);
        });

        self::assertSame(409, $changed->status());
        self::assertSame('Promotion changed; please price the stay again', $changed->getMessage());
        self::assertSame(0, $this->usesOf($promo['id']));

        $this->update('promo', 'promo_id', $promo['id'], ['promo_value' => 100]);
        $held = Promotions::reserveQuoted($promo['code'], 'stay', 1000, $guestId, $bookingId, $quoted['promoId'], $quoted['discount']);

        self::assertSame($quoted, $held);
        self::assertSame('reserved', $this->useStatus($bookingId));
    }

    public function testABookingCanOnlyHoldOneCode(): void
    {
        $first = $this->createPromo();
        $second = $this->createPromo();
        $guestId = $this->createGuest();
        $bookingId = $this->createBookingRow();
        Promotions::reserve($first['code'], 'stay', 1000, $guestId, $bookingId);

        $error = $this->errorFrom(function () use ($second, $guestId, $bookingId) {
            Promotions::reserve($second['code'], 'stay', 1000, $guestId, $bookingId);
        });

        self::assertSame(409, $error->status());
    }

    public function testStaffCreateAndChangeAPromotion(): void
    {
        $staffId = $this->createGuest();
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $input = [
            'code' => strtolower($code), 'type' => 'fixed', 'value' => 150, 'appliesTo' => ['stay', 'tickets'],
            'validFrom' => '2026-09-01', 'validTo' => '2026-12-31', 'usageLimit' => 20,
        ];

        $id = Promotions::create($input, $staffId);
        $this->track('promo', 'promo_id', $id);
        $again = $this->errorFrom(function () use ($input, $staffId) {
            Promotions::create($input, $staffId);
        });
        Promotions::update($id, array_merge($input, ['value' => 200, 'isActive' => false]), $staffId);
        $missing = $this->errorFrom(function () use ($input, $staffId) {
            Promotions::update(999999999, $input, $staffId);
        });

        $saved = null;
        foreach (Promotions::all() as $promotion) {
            if ($promotion['id'] === $id) {
                $saved = $promotion;
            }
        }
        self::assertSame($code, $saved['code']);
        self::assertSame(200.0, $saved['value']);
        self::assertFalse($saved['isActive']);
        self::assertSame(['stay', 'tickets'], $saved['appliesTo']);
        self::assertSame($staffId, $saved['createdBy']);
        self::assertSame(409, $again->status());
        self::assertSame(404, $missing->status());
    }

    // September to December, 10% off stays, events, and tickets, unless $changes says otherwise.
    private function createPromo(array $changes = []): array
    {
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $values = [
            'promo_code' => $code,
            'promo_type' => 'percentage',
            'promo_value' => 10,
            'promo_applies_to' => '["stay", "event", "tickets"]',
            'promo_valid_from' => '2026-09-01',
            'promo_valid_to' => '2026-12-31',
        ];
        $id = $this->insert('promo', 'promo_id', array_merge($values, $changes));

        return ['id' => $id, 'code' => $code];
    }

    // An unpaid stay to hold a code. Cleanup removes the promo use before the booking.
    private function createBookingRow(): int
    {
        $spaceId = $this->createSpace(['sp_is_accommodation' => 1]);

        return $this->createStay($spaceId, '2026-12-12', '2026-12-13', 'pending_payment', '2026-09-06 12:00:00');
    }

    // An unpaid ticket order for a public event bought by $email, to hold a code
    private function createTicketOrderRow(string $email): int
    {
        $spaceId = $this->createSpace();
        $eventId = $this->createEvent($spaceId, '2026-12-12', 'confirmed', null, 'public')['eventId'];

        return $this->insert('ticket_order', 'to_id', [
            'to_reference' => 'TT-' . strtoupper(bin2hex(random_bytes(5))), 'to_eb_id' => $eventId, 'to_buyer_name' => '[test]',
            'to_buyer_surname' => 'Buyer', 'to_buyer_email' => $email, 'to_hold_expires_at' => '2026-09-06 12:00:00',
        ]);
    }

    private function quoteError(string $code, string $kind, ?int $userId = null): string
    {
        $error = $this->errorFrom(function () use ($code, $kind, $userId) {
            Promotions::quote($code, $kind, 1000, $userId);
        });
        self::assertSame(422, $error->status());

        return $error->getMessage();
    }

    private function usesOf(int $promoId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM promo_use WHERE pu_promo_id = ?', [$promoId]);
    }

    private function useStatus(int $bookingId): string
    {
        return (string) $this->value('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', [$bookingId]);
    }

    private function orderUseStatus(int $orderId): string
    {
        return (string) $this->value('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$orderId]);
    }

    private function value(string $sql, array $params): mixed
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
