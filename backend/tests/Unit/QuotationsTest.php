<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Enquiries;
use GrandGala\Services\Mailer;
use GrandGala\Services\QuotationPayment;
use GrandGala\Services\Quotations;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

// Staff price an enquiry; the guest accepts, declines, or asks for changes.
// Garden on 17 Oct 2026, 14:00–23:00, 80 guests, plus one room. Today is 2026-09-04 12:00.
// Omitted plan fields come from the enquiry. The shared quotation counter is restored.
class QuotationsTest extends DatabaseTestCase
{
    private const EVENT_DATE = '2026-10-17';

    private array $savedCounter = [];
    private int $venueId;
    private int $gardenId;
    private int $villaId;
    private int $roomId;
    private int $poolId;
    private int $guestId;
    private int $staffId;
    private int $bookingId;
    private string $bookingReference;

    protected function setUp(): void
    {
        parent::setUp();
        // High numbers, so our test quotations never clash with the team's demo ones
        $this->savedCounter = Db::pdo()->query("SELECT rs_year, rs_next FROM reference_sequence WHERE rs_kind = 'quotation'")->fetch();
        $this->setQuotationCounter(9000);
        Mailer::clear();

        $this->venueId = $this->createVenue();
        $this->gardenId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'garden', 'sp_name' => '[test] Garden', 'sp_capacity' => 120]);
        $this->villaId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_is_accommodation' => 1, 'sp_name' => '[test] Villa']);
        $this->roomId = $this->createSpace([
            'sp_v_id' => $this->venueId, 'sp_type' => 'room', 'sp_parent_id' => $this->villaId, 'sp_is_accommodation' => 1, 'sp_name' => '[test] Rose Room',
        ]);
        $this->poolId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'pool', 'sp_name' => '[test] Pool', 'sp_capacity' => 90]);
        $this->addRate($this->roomId, 'per_night', 650.5, '2026-01-01', null);
        $this->addRate($this->villaId, 'per_night', 3000, '2026-01-01', null);
        $this->addRate($this->gardenId, 'per_event', 9000, '2026-01-01', null);

        $this->guestId = $this->createGuest();
        $this->staffId = $this->createGuest();
        $this->bookingId = $this->createEnquiry($this->guestId);
        $this->bookingReference = $this->referenceOf($this->bookingId);
    }

    protected function tearDown(): void
    {
        // Delete acceptance event rows first: they point at spaces, even if tracking was skipped.
        Clock::freeze('2026-09-04');
        unset($_ENV['QUOTATION_HOLD_HOURS']);
        // "finally": the counter is put back even if deleting the test's rows fails
        try {
            $this->trackEventRows($this->bookingId);
            parent::tearDown();
        } finally {
            $this->setQuotationCounter((int) $this->savedCounter['rs_next'], (int) $this->savedCounter['rs_year']);
        }
    }

    // ---- Staff: create and revise ----

    public function testCreatingAQuotationWorksOutTheTotalsAndIgnoresTheBrowsers(): void
    {
        $quotation = $this->create($this->input([
            'total' => 1, 'subtotal' => 1, 'version' => 99, 'status' => 'accepted',
        ]));

        self::assertSame('GGG-Q-9000', $quotation['reference']);
        self::assertSame($this->bookingReference, $quotation['bookingReference']);
        self::assertSame(1, $quotation['version']);
        self::assertSame('sent', $quotation['status']);
        self::assertSame(28000.0, $quotation['subtotal']);
        self::assertSame(1000.0, $quotation['discount']);
        self::assertSame(27000.0, $quotation['total']);
        self::assertSame(3521.74, $quotation['vatIncluded']); // 27000 × 15/115, to the cent
        self::assertSame('2026-09-04', $quotation['sentDate']);
        self::assertSame('quoted', $this->bookingStatus());
        self::assertSame(27000.0, (float) $this->column('SELECT q_amount FROM quotation WHERE q_reference = ?', [$quotation['reference']]));
    }

    public function testANewVersionSupersedesTheSentOneAndKeepsItsPrice(): void
    {
        $first = $this->create($this->input());
        $second = $this->create($this->input(['discount' => 0]));

        $versions = Quotations::versions($second['reference']);

        self::assertSame(2, $second['version']);
        self::assertSame(28000.0, $second['total']);
        self::assertSame([$second['reference'], $first['reference']], array_column($versions, 'reference'));
        self::assertSame(['sent', 'superseded'], array_column($versions, 'status'));
        self::assertSame(27000.0, $versions[1]['total']);
        self::assertSame([['reference' => $first['reference'], 'version' => 1, 'status' => 'superseded', 'date' => '2026-09-04']], $second['previousVersions']);
    }

    // The agreed plan: date, times, spaces, and rooms

    public function testAQuotationIsPrefilledFromTheEnquiry(): void
    {
        $quotation = $this->create($this->input());

        self::assertSame(self::EVENT_DATE, $quotation['eventDate']);
        self::assertSame('14:00', $quotation['startTime']);
        self::assertSame('23:00', $quotation['endTime']);
        self::assertSame([['id' => $this->gardenId, 'name' => '[test] Garden']], $quotation['spaces']);
        // The enquiry asked for one room, so the quotation gives that room for the same nights.
        self::assertSame(
            [['spaceId' => $this->roomId, 'space' => '[test] Rose Room', 'checkIn' => self::EVENT_DATE, 'checkOut' => '2026-10-18']],
            $quotation['rooms']
        );
        self::assertSame($this->gardenId, $quotation['items'][0]['spaceId']);
        self::assertNull($quotation['items'][1]['spaceId']);

        $id = $this->quotationId($quotation['reference']);
        self::assertSame([self::EVENT_DATE, '14:00:00', '23:00:00'], array_values($this->rows(
            'SELECT q_event_date, q_start_time, q_end_time FROM quotation WHERE q_id = ?', [$id]
        )[0]));
        self::assertSame([$this->gardenId], $this->idsOf('SELECT qs_sp_id FROM quotation_space WHERE qs_q_id = ? ORDER BY qs_id', $id));
        self::assertSame([$this->roomId], $this->idsOf('SELECT qr_sp_id FROM quotation_room WHERE qr_q_id = ? ORDER BY qr_id', $id));
    }

    // ---- Guest: see their quotations ----

    public function testSomeoneElsesQuotationCantBeAnswered(): void
    {
        $quotation = $this->create($this->input());
        $otherGuestId = $this->createGuest();

        $accept = $this->errorFrom(function () use ($quotation, $otherGuestId) {
            Quotations::accept($quotation['reference'], $otherGuestId);
        });
        $decline = $this->errorFrom(function () use ($quotation, $otherGuestId) {
            Quotations::decline($quotation['reference'], $otherGuestId);
        });
        $changes = $this->errorFrom(function () use ($quotation, $otherGuestId) {
            Quotations::requestChanges($quotation['reference'], $otherGuestId, ['note' => 'Cheaper please']);
        });

        self::assertSame([404, 404, 404], [$accept->status(), $decline->status(), $changes->status()]);
        self::assertSame('sent', $this->quotationStatus($quotation['reference']));
        self::assertSame('quoted', $this->bookingStatus());
    }

    // ---- Guest: accept ----

    public function testAcceptingReservesThePrivateEventGivesTheRoomAndStartsTheHold(): void
    {
        $quotation = $this->create($this->input());

        $result = $this->accept($quotation);

        self::assertSame('accepted', $result['status']);
        self::assertSame('pending_payment', $result['bookingStatus']);
        self::assertSame('2026-09-07T12:00', $result['holdExpires']); // 72 hours from now
        self::assertSame(27000.0, $result['total']);
        self::assertSame('accepted', $this->quotationStatus($quotation['reference']));

        $booking = $this->rows('SELECT b_status, b_subtotal, b_discount_amount, b_total_price, b_hold_expires_at FROM booking WHERE b_id = ?', [$this->bookingId])[0];
        self::assertSame(['pending_payment', '28000.00', '1000.00', '27000.00', '2026-09-07 12:00:00'], array_values($booking));

        $event = $this->rows('SELECT eb_id, eb_v_id, eb_event_date, eb_start_time, eb_end_time, eb_visibility, eb_guest_count FROM event_booking WHERE eb_b_id = ?', [$this->bookingId])[0];
        self::assertSame([$this->venueId, self::EVENT_DATE, '14:00:00', '23:00:00', 'private', 80], array_slice(array_values($event), 1));
        $spaces = $this->rows('SELECT es_sp_id, es_price_snapshot FROM event_space WHERE es_eb_id = ?', [$event['eb_id']]);
        // The garden's price is frozen from its quotation line (10000), not the rate card (9000)
        self::assertSame([[ 'es_sp_id' => $this->gardenId, 'es_price_snapshot' => '10000.00']], $spaces);
        $rooms = $this->rows('SELECT esa_sp_id, esa_check_in, esa_check_out, esa_price_snapshot FROM event_space_allocation WHERE esa_eb_id = ?', [$event['eb_id']]);
        self::assertSame([[ 'esa_sp_id' => $this->roomId, 'esa_check_in' => self::EVENT_DATE, 'esa_check_out' => '2026-10-18', 'esa_price_snapshot' => '650.50']], $rooms);
    }

    public function testATakenSpaceStopsTheAcceptanceAndNothingChanges(): void
    {
        $quotation = $this->create($this->input());
        $this->createEvent($this->gardenId, self::EVENT_DATE, 'confirmed');

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('Already booked on these dates', $error->getMessage());
        $this->assertNothingAccepted($quotation['reference']);
    }

    public function testAcceptingTwiceIsRefused(): void
    {
        $quotation = $this->accept($this->create($this->input()));

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('Only the latest quotation sent to you can be answered', $error->getMessage());
        self::assertSame(1, (int) $this->column('SELECT COUNT(*) FROM event_booking WHERE eb_b_id = ?', [$this->bookingId]));
    }

    // ---- Expiry ----

    // ---- Guest: decline or ask for changes ----

    public function testDecliningCancelsTheBooking(): void
    {
        $quotation = $this->create($this->input());

        $result = Quotations::decline($quotation['reference'], $this->guestId);
        $again = $this->errorFrom(function () {
            $this->create($this->input());
        });

        self::assertSame('declined', $result['status']);
        self::assertSame('cancelled', $result['bookingStatus']);
        self::assertSame('declined', $this->quotationStatus($quotation['reference']));
        self::assertSame('cancelled', $this->bookingStatus());
        self::assertSame(409, $again->status());
    }

    public function testAskingForChangesReopensTheEnquiryWithTheGuestsNote(): void
    {
        $first = $this->create($this->input());

        $result = Quotations::requestChanges($first['reference'], $this->guestId, ['note' => '  Please remove the catering  ']);
        $second = $this->create($this->input());
        $detail = Quotations::staffDetail($second['reference']);

        self::assertSame('enquiry', $result['bookingStatus']);
        self::assertSame('declined', $this->quotationStatus($first['reference']));
        self::assertSame(2, $second['version']);
        self::assertSame('Please remove the catering', $detail['changeRequests'][0]['note']);
        self::assertSame('Please remove the catering', Enquiries::detail($detail['bookingReference'])['changeRequests'][0]['note']);
        self::assertSame([['reference' => $first['reference'], 'version' => 1, 'status' => 'declined', 'date' => '2026-09-04']], $detail['previousVersions']);
    }

    // Paying for an accepted quotation

    public function testPayingConfirmsTheBookingOnce(): void
    {
        $quotation = $this->accept($this->create($this->input()));
        $payment = new QuotationPayment();
        Mailer::clear();

        $payment->onPaid($quotation['reference'], 1);
        $payment->onPaid($quotation['reference'], 1); // PayFast can send the same notice twice

        self::assertSame('confirmed', $this->bookingStatus());
        self::assertCount(1, Mailer::sent());
        self::assertSame('booking_confirmed', Mailer::sent()[0]['template']);
    }

    // ---- Helpers ----

    // Valid quotation: 10000 + 180 × 100 = 28000, minus 1000. $change replaces fields.
    private function input(array $change = []): array
    {
        $input = [
            'bookingReference' => $this->bookingReference,
            'items' => [
                ['label' => 'Garden hire', 'quantity' => 1, 'unitPrice' => 10000, 'spaceId' => $this->gardenId],
                ['label' => 'Buffet dinner', 'quantity' => 180, 'unitPrice' => 100],
            ],
            'discount' => 1000,
            'validUntil' => '2026-09-11',
            'notes' => 'All prices include VAT',
        ];

        return array_merge($input, $change);
    }

    // Replaces the words in capitals in a badPlans() case with this test's space ids
    private function fill(mixed $value): mixed
    {
        if (is_array($value)) {
            $filled = [];
            foreach ($value as $key => $item) {
                $filled[$key] = $this->fill($item);
            }

            return $filled;
        }
        if ($value === 'GARDEN') {
            return $this->gardenId;
        }
        if ($value === 'ROOM') {
            return $this->roomId;
        }
        if ($value === 'POOL') {
            return $this->poolId;
        }
        if ($value === 'CLOSED') {
            return $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'hall', 'sp_is_active' => 0]);
        }
        if ($value === 'ELSEWHERE') {
            return $this->createSpace(['sp_type' => 'garden', 'sp_capacity' => 500]);
        }
        if ($value === 'ELSEWHERE_ROOM') {
            return $this->createSpace(['sp_type' => 'room', 'sp_is_accommodation' => 1]);
        }

        return $value;
    }

    // The ids in the first column of a query with one ? (e.g. a quotation's spaces)
    private function idsOf(string $sql, int $id): array
    {
        $ids = [];
        foreach ($this->rows($sql, [$id]) as $row) {
            $ids[] = (int) array_values($row)[0];
        }

        return $ids;
    }

    // Accepts as the guest and tracks the event rows so tearDown can delete them.
    private function accept(array $quotation): array
    {
        $result = Quotations::accept($quotation['reference'], $this->guestId);
        $this->trackEventRows($this->bookingId);

        return $result;
    }

    // Enquiry shape (migration 006): garden, 14:00–23:00, 80 guests, one room that night.
    private function createEnquiry(int $guestId): int
    {
        $typeId = (int) Db::pdo()->query("SELECT bt_id FROM booking_type WHERE bt_name = 'event'")->fetchColumn();
        $bookingId = $this->insert('booking', 'b_id', [
            'b_reference' => 'TEST-' . strtoupper(bin2hex(random_bytes(5))), 'b_bt_id' => $typeId, 'b_u_id' => $guestId,
            'b_status' => 'enquiry',
        ]);
        $enquiryId = $this->insert('event_enquiry', 'ee_id', [
            'ee_b_id' => $bookingId, 'ee_preferred_date' => self::EVENT_DATE, 'ee_start_time' => '14:00', 'ee_end_time' => '23:00',
            'ee_estimated_guests' => 80, 'ee_occasion' => '[test] Wedding',
            'ee_accommodation_sp_id' => $this->roomId, 'ee_accommodation_check_in' => self::EVENT_DATE,
            'ee_accommodation_check_out' => '2026-10-18', 'ee_accommodation_rooms' => 1, 'ee_accommodation_guests' => 2,
        ]);
        $this->insert('event_enquiry_space', 'ees_id', ['ees_ee_id' => $enquiryId, 'ees_sp_id' => $this->gardenId]);
        // Rows the code under test makes for this booking (deleted before the booking itself)
        $this->track('booking_status_history', 'bsh_b_id', $bookingId);
        $this->trackQuotations($bookingId);

        return $bookingId;
    }

    // Creates as staff, then re-tracks rows so they are deleted before spaces made after setUp.
    private function create(array $input): array
    {
        try {
            return Quotations::create($input, $this->staffId);
        } finally {
            $this->trackQuotations($this->bookingId);
        }
    }

    // Tracks the booking's quotations. Lines, spaces, and rooms cascade on delete.
    private function trackQuotations(int $bookingId): void
    {
        $this->track('quotation', 'q_b_id', $bookingId);
    }

    private function quotationId(string $reference): int
    {
        return (int) $this->column('SELECT q_id FROM quotation WHERE q_reference = ?', [$reference]);
    }

    // Registers the event_booking, event_space and event_space_allocation rows an acceptance wrote
    private function trackEventRows(int $bookingId): void
    {
        $eventId = $this->column('SELECT eb_id FROM event_booking WHERE eb_b_id = ?', [$bookingId]);
        if ($eventId === false) {
            return;
        }
        $this->track('event_booking', 'eb_id', (int) $eventId);
        $this->track('event_space', 'es_eb_id', (int) $eventId);
        $this->track('event_space_allocation', 'esa_eb_id', (int) $eventId);
    }

    // Checks a refused acceptance left everything as it was
    private function assertNothingAccepted(string $reference): void
    {
        $this->trackEventRows($this->bookingId); // if a broken rule left rows behind
        self::assertSame('sent', $this->quotationStatus($reference));
        self::assertSame('quoted', $this->bookingStatus());
        self::assertNull($this->column('SELECT b_hold_expires_at FROM booking WHERE b_id = ?', [$this->bookingId]));
        self::assertSame(0, (int) $this->column('SELECT COUNT(*) FROM event_booking WHERE eb_b_id = ?', [$this->bookingId]));
        self::assertSame(0, (int) $this->column(
            'SELECT COUNT(*) FROM event_space_allocation WHERE esa_sp_id = ? AND esa_check_in = ?', [$this->roomId, self::EVENT_DATE]
        ));
    }

    private function setQuotationCounter(int $next, int $year = 2026): void
    {
        Db::pdo()->prepare("UPDATE reference_sequence SET rs_year = ?, rs_next = ? WHERE rs_kind = 'quotation'")->execute([$year, $next]);
    }

    private function bookingStatus(): string
    {
        return (string) $this->column('SELECT b_status FROM booking WHERE b_id = ?', [$this->bookingId]);
    }

    private function quotationStatus(string $reference): string
    {
        return (string) $this->column('SELECT q_status FROM quotation WHERE q_reference = ?', [$reference]);
    }

    private function referenceOf(int $bookingId): string
    {
        return (string) $this->column('SELECT b_reference FROM booking WHERE b_id = ?', [$bookingId]);
    }

    private function column(string $sql, array $parameters): mixed
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function rows(string $sql, array $parameters): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}
