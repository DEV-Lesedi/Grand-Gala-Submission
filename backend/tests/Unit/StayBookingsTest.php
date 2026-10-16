<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Pricing;
use GrandGala\Services\StayBookings;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

// Pricing and booking a stay. Now is 2026-09-04 12:00.
// Own villa: sleeps 4, R2800 a night, R3500 on 24–26 Dec. A held promo use is removed with it.
class StayBookingsTest extends DatabaseTestCase
{
    private int $villaId;
    private int $guestId;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt('2026-09-04 12:00');
        $this->villaId = $this->createSpace(['sp_is_accommodation' => 1, 'sp_capacity' => 4, 'sp_name' => '[test] Villa']);
        $this->addRate($this->villaId, 'per_night', 2800, '2026-01-01', null);
        $this->addRate($this->villaId, 'per_night', 3500, '2026-12-24', '2026-12-26', 'Festive');
        $this->guestId = $this->createGuest();
    }

    protected function tearDown(): void
    {
        unset($_ENV['STAY_HOLD_HOURS']);
        Clock::freeze($_ENV['APP_TODAY']);
        parent::tearDown();
    }

    public function testEachNightIsPricedOnItsOwnAndAmenitiesOnTheCheckInDay(): void
    {
        $amenityId = $this->createAmenity(150.5);

        $price = Pricing::stay($this->input([
            'checkIn' => '2026-12-23', 'checkOut' => '2026-12-25', 'amenities' => [['amenityId' => $amenityId, 'quantity' => 2]],
        ]), null);

        self::assertSame(2, $price['nights']);
        self::assertSame([2800.0, 3500.0, 150.5], [$price['lines'][0]['unitPrice'], $price['lines'][1]['unitPrice'], $price['lines'][2]['unitPrice']]);
        self::assertSame(301.0, $price['lines'][2]['total']);
        self::assertSame(6601.0, $price['subtotal']);
        self::assertSame(0.0, $price['discount']);
        self::assertSame(6601.0, $price['total']);
    }

    public function testAPromoCodeComesOffTheStay(): void
    {
        $code = $this->createPromo(['promo_type' => 'fixed', 'promo_value' => 300.25]);

        $price = Pricing::stay($this->input(['promoCode' => $code]), $this->guestId);

        self::assertSame(2800.0, $price['subtotal']);
        self::assertSame(300.25, $price['discount']);
        self::assertSame(2499.75, $price['total']);
    }

    public function testBadStaysAreRefused(): void
    {
        $hall = $this->createSpace(['sp_type' => 'hall']);
        $noPrice = $this->createSpace(['sp_is_accommodation' => 1]);

        self::assertSame('Check-in cannot be in the past', $this->priceError(['checkIn' => '2026-09-03']));
        self::assertSame('Check-out must be after check-in', $this->priceError(['checkOut' => '2026-12-12']));
        self::assertSame('Guest count exceeds the space capacity', $this->priceError(['guests' => 5]));
        self::assertSame('The selected space is not available for overnight stays', $this->priceError(['spaceId' => $hall]));
        self::assertSame('No stay rate is available for 2026-12-12', $this->priceError(['spaceId' => $noPrice]));
        self::assertSame('Space must be a positive integer', $this->priceError(['spaceId' => 'x']));
        self::assertSame('Check-in must be a valid date (YYYY-MM-DD)', $this->priceError(['checkIn' => '2026-02-30']));
        self::assertSame('Amenity quantity must be at most 10 and each amenity may appear once',
            $this->priceError(['amenities' => [['amenityId' => $this->createAmenity(10), 'quantity' => 11]]]));
    }

    public function testBookingAStayHoldsItsDatesAndFreezesEveryPrice(): void
    {
        $amenityId = $this->createAmenity(150);
        $code = $this->createPromo(['promo_type' => 'percentage', 'promo_value' => 10]);

        $booking = $this->book($this->input([
            'checkIn' => '2026-12-23', 'checkOut' => '2026-12-25', 'promoCode' => $code, 'notes' => ' Late arrival ',
            'amenities' => [['amenityId' => $amenityId, 'quantity' => 1]],
        ]));

        self::assertSame('pending_payment', $booking['status']);
        // Held for 48 hours from now
        self::assertSame('2026-09-06T12:00', $booking['holdExpires']);
        self::assertSame(6450.0, $booking['subtotal']);
        self::assertSame(645.0, $booking['discount']);
        self::assertSame(5805.0, $booking['total']);

        $saved = $this->row('SELECT b_status, b_u_id, b_total_price, b_notes, b_hold_expires_at FROM booking WHERE b_reference = ?', [$booking['reference']]);
        self::assertSame(['b_status' => 'pending_payment', 'b_u_id' => $this->guestId, 'b_total_price' => '5805.00',
            'b_notes' => 'Late arrival', 'b_hold_expires_at' => '2026-09-06 12:00:00'], $saved);
        $bookingId = $this->bookingId($booking['reference']);
        self::assertSame(3, $this->rowsCounted('SELECT COUNT(*) FROM booking_line WHERE bl_b_id = ?', [$bookingId]));
        self::assertSame(1, $this->rowsCounted('SELECT COUNT(*) FROM amenity_booking WHERE ab_b_id = ?', [$bookingId]));
        self::assertSame('6300.00', $this->row(
            'SELECT ss.ss_line_total FROM stay_space ss JOIN stay_booking sb ON sb.sb_id = ss.ss_sb_id WHERE sb.sb_b_id = ?', [$bookingId]
        )['ss_line_total']);
        self::assertSame('reserved', $this->row('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', [$bookingId])['pu_status']);
    }

    public function testANewStayWritesItsFirstHistoryRowInTheGuestsName(): void
    {
        $booking = $this->book($this->input());

        $history = $this->row(
            'SELECT bsh_from_status, bsh_to_status, bsh_changed_by FROM booking_status_history WHERE bsh_b_id = ?',
            [$this->bookingId($booking['reference'])]
        );
        self::assertSame(['bsh_from_status' => null, 'bsh_to_status' => 'pending_payment', 'bsh_changed_by' => $this->guestId], $history);
        self::assertSame(1, $this->rowsCounted('SELECT COUNT(*) FROM booking_status_history WHERE bsh_b_id = ?', [$this->bookingId($booking['reference'])]));
    }

    public function testTheHoldLengthComesFromStayHoldHours(): void
    {
        $_ENV['STAY_HOLD_HOURS'] = '2';

        $booking = $this->book($this->input());

        self::assertSame('2026-09-04T14:00', $booking['holdExpires']);
    }

    public function testTakenDatesAreRefusedAndNothingIsSaved(): void
    {
        $this->book($this->input(['checkIn' => '2026-12-12', 'checkOut' => '2026-12-14']));
        $this->insert('space_blackout', 'sbk_id', [
            'sbk_sp_id' => $this->villaId, 'sbk_start_date' => '2026-12-20', 'sbk_end_date' => '2026-12-20', 'sbk_reason' => '[test] repairs',
        ]);
        $before = $this->rowsCounted("SELECT COUNT(*) FROM stay_space WHERE ss_sp_id = ?", [$this->villaId]);

        $overlap = $this->errorFrom(function () {
            StayBookings::create($this->input(['checkIn' => '2026-12-13', 'checkOut' => '2026-12-15']), $this->guestId);
        });
        $blackedOut = $this->errorFrom(function () {
            StayBookings::create($this->input(['checkIn' => '2026-12-19', 'checkOut' => '2026-12-21']), $this->guestId);
        });

        self::assertSame(409, $overlap->status());
        self::assertSame(409, $blackedOut->status());
        self::assertSame($before, $this->rowsCounted("SELECT COUNT(*) FROM stay_space WHERE ss_sp_id = ?", [$this->villaId]));
        // Check-out day is free for the next guest
        $this->book($this->input(['checkIn' => '2026-12-14', 'checkOut' => '2026-12-15']));
    }

    public function testACodeWhoseLastUseWasTakenStopsTheBooking(): void
    {
        $code = $this->createPromo(['promo_usage_limit' => 1]);
        $this->book($this->input(['promoCode' => $code]));

        $error = $this->errorFrom(function () use ($code) {
            StayBookings::create($this->input(['checkIn' => '2026-12-20', 'checkOut' => '2026-12-21', 'promoCode' => $code]), $this->guestId);
        });

        self::assertSame(422, $error->status());
        self::assertSame('Promotion code has reached its usage limit', $error->getMessage());
    }

    // tests/Support/second_stay.php plays the second guest, in its own process
    public function testOnlyOneOfTwoGuestsBookingTheSameNightsAtOnceGetsThem(): void
    {
        $secondGuestId = $this->createGuest();

        $second = null;
        // The & fills in $second. The outer transaction keeps the venue locked until it ends.
        Db::transaction(function () use ($secondGuestId, &$second) {
            $this->book($this->input(['checkIn' => '2026-12-12', 'checkOut' => '2026-12-14']));
            $second = $this->startScript('second_stay.php', [$this->villaId, '2026-12-13', '2026-12-15', $secondGuestId]);
            sleep(2);
        });

        $answer = $this->waitForScript($second);
        if (str_starts_with($answer, '201')) {
            $this->track('booking', 'b_id', $this->bookingId(substr($answer, 4)));
        }
        self::assertSame('409', $answer);
    }

    // One night, 12-13 December, for 2 guests, unless $changes says otherwise
    private function input(array $changes = []): array
    {
        $input = ['spaceId' => $this->villaId, 'checkIn' => '2026-12-12', 'checkOut' => '2026-12-13', 'guests' => 2];

        return array_merge($input, $changes);
    }

    // Books through StayBookings::create() and tracks the booking
    private function book(array $input): array
    {
        $booking = StayBookings::create($input, $this->guestId);
        $this->track('booking', 'b_id', $this->bookingId($booking['reference']));

        return $booking;
    }

    private function priceError(array $changes): string
    {
        $error = $this->errorFrom(function () use ($changes) {
            Pricing::stay($this->input($changes), null);
        });
        self::assertSame(422, $error->status());

        return $error->getMessage();
    }

    private function createAmenity(float $price): int
    {
        $amenityId = $this->insert('amenity', 'a_id', ['a_name' => '[test] Picnic basket']);
        $this->insert('amenity_rate', 'ar_id', ['ar_a_id' => $amenityId, 'ar_price' => $price, 'ar_valid_from' => '2026-01-01']);

        return $amenityId;
    }

    private function createPromo(array $changes): string
    {
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $this->insert('promo', 'promo_id', array_merge([
            'promo_code' => $code, 'promo_type' => 'percentage', 'promo_value' => 10, 'promo_applies_to' => '["stay"]',
            'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ], $changes));

        return $code;
    }

    private function bookingId(string $reference): int
    {
        return (int) $this->row('SELECT b_id FROM booking WHERE b_reference = ?', [$reference])['b_id'];
    }

    private function row(string $sql, array $params): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetch();
    }

    private function rowsCounted(string $sql, array $params): int
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
