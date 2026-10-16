<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Availability;
use GrandGala\Services\Clock;
use GrandGala\Services\Enquiries;
use GrandGala\Services\Mailer;
use GrandGala\Services\QuotationPayment;
use GrandGala\Services\Quotations;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testEachLineIsSavedInItsOwnRowWithCents(): void
    {
        $quotation = $this->create($this->input([
            'items' => [
                ['label' => 'Garden hire', 'quantity' => 1, 'unitPrice' => 8500.5],
                ['label' => 'Bar staff hours', 'quantity' => 1.5, 'unitPrice' => 101.33],
            ],
            'discount' => 0.5,
        ]));

        $rows = $this->rows(
            'SELECT qi.qi_position, qi.qi_label, qi.qi_quantity, qi.qi_unit_price FROM quotation_item qi
             JOIN quotation q ON q.q_id = qi.qi_q_id WHERE q.q_reference = ? ORDER BY qi.qi_position',
            [$quotation['reference']]
        );

        self::assertSame([1, 'Garden hire', '1.00', '8500.50'], array_values($rows[0]));
        self::assertSame([2, 'Bar staff hours', '1.50', '101.33'], array_values($rows[1]));
        self::assertSame(152.0, $quotation['items'][1]['amount']); // 1.5 × 101.33 rounds to 152
        self::assertSame(8652.5, $quotation['subtotal']);
        self::assertSame(8652.0, $quotation['total']);
        self::assertSame(1128.52, $quotation['vatIncluded']);
    }

    public function testTheGuestIsEmailedTheQuotation(): void
    {
        $quotation = $this->create($this->input());

        $sent = Mailer::sent();
        self::assertCount(1, $sent);
        self::assertSame('quotation_sent', $sent[0]['template']);
        self::assertSame($this->guestEmail(), $sent[0]['to']);
        self::assertSame($quotation['reference'], $sent[0]['data']['reference']);
    }

    public function testAQuotationIsValidForSevenDaysUnlessStaffSayOtherwise(): void
    {
        $input = $this->input();
        unset($input['validUntil']);

        $quotation = $this->create($input);

        self::assertSame('2026-09-11', $quotation['validUntil']);
        self::assertSame('All prices include VAT', $quotation['notes']);
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

    public function testTheStaffListShowsOnlyTheLatestVersionOfEachBooking(): void
    {
        $this->create($this->input());
        $second = $this->create($this->input());

        $mine = $this->onlyThisBooking(Quotations::staffList([])['quotations']);

        self::assertSame([$second['reference']], array_column($mine, 'reference'));
    }

    // It also drops out of the status counts.
    public function testAQuotationTheGuestDeclinedLeavesTheStaffList(): void
    {
        $quotation = $this->create($this->input());
        $before = Quotations::staffList([])['counts'];

        Quotations::decline($quotation['reference'], $this->guestId);

        $after = Quotations::staffList([]);
        self::assertSame([], $this->onlyThisBooking($after['quotations']));
        self::assertSame([], $this->onlyThisBooking(Quotations::staffList(['status' => 'declined'])['quotations']));
        self::assertSame(-1, $after['counts']['all'] - $before['all']);
        self::assertSame(0, $after['counts']['declined'] - $before['declined']);
    }

    // Asking for changes declines it, but staff still owe a new version, so it stays listed.
    public function testAQuotationWithAChangeRequestStaysOnTheStaffList(): void
    {
        $quotation = $this->create($this->input());

        Quotations::requestChanges($quotation['reference'], $this->guestId, ['note' => 'Fewer guests']);

        $mine = $this->onlyThisBooking(Quotations::staffList([])['quotations']);
        self::assertSame([$quotation['reference']], array_column($mine, 'reference'));
        self::assertSame('declined', $mine[0]['status']);
    }

    // Each case changes one field of a good quotation, and the message the staff member sees
    public static function badQuotations(): array
    {
        return [
            'no lines' => [['items' => []], 'Add at least one line'],
            'price as text' => [['items' => [['label' => 'Hire', 'quantity' => 1, 'unitPrice' => '100']]], 'The price must be a number'],
            'negative price' => [['items' => [['label' => 'Hire', 'quantity' => 1, 'unitPrice' => -1]]], 'The price must be R0 or more'],
            'part of a cent' => [['items' => [['label' => 'Hire', 'quantity' => 1, 'unitPrice' => 10.555]]], 'The price can have at most 2 decimals'],
            'negative quantity' => [['items' => [['label' => 'Hire', 'quantity' => -1, 'unitPrice' => 1]]], 'Quantities must be numbers from 0 up, with at most 2 decimals'],
            'quantity with 3 decimals' => [['items' => [['label' => 'Hire', 'quantity' => 1.001, 'unitPrice' => 1]]], 'Quantities must be numbers from 0 up, with at most 2 decimals'],
            'empty label' => [['items' => [['label' => ' ', 'quantity' => 1, 'unitPrice' => 1]]], 'Every line needs a label'],
            'discount above the lines' => [['discount' => 28000.01], 'The discount can\'t be more than the lines add up to'],
            'negative discount' => [['discount' => -1], 'The price must be R0 or more'],
            'validity in the past' => [['validUntil' => '2026-09-03'], 'Valid until can\'t be in the past'],
            'no such date' => [['validUntil' => '2026-02-30'], 'Valid until date must be YYYY-MM-DD'],
        ];
    }

    #[DataProvider('badQuotations')]
    public function testABadQuotationIsRefusedAndNothingIsSaved(array $change, string $message): void
    {
        $error = $this->errorFrom(function () use ($change) {
            $this->create($this->input($change));
        });

        self::assertSame(422, $error->status());
        self::assertSame($message, $error->getMessage());
        self::assertSame(0, $this->quotationCount());
        self::assertSame('enquiry', $this->bookingStatus());
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

    // When the guest asked for the whole villa, the quotation gives the whole villa
    public function testAVillaRequestIsPrefilledAsTheVilla(): void
    {
        Db::pdo()->prepare('UPDATE event_enquiry SET ee_accommodation_sp_id = ?, ee_accommodation_rooms = 4 WHERE ee_b_id = ?')
            ->execute([$this->villaId, $this->bookingId]);

        $quotation = $this->create($this->input());

        self::assertSame(
            [['spaceId' => $this->villaId, 'space' => '[test] Villa', 'checkIn' => self::EVENT_DATE, 'checkOut' => '2026-10-18']],
            $quotation['rooms']
        );
    }

    // When the guest picked rooms, the quotation gives each of those rooms for the asked nights
    public function testPickedRoomsArePrefilledOneByOne(): void
    {
        $poolRoom = $this->createSpace([
            'sp_v_id' => $this->venueId, 'sp_type' => 'room', 'sp_parent_id' => $this->villaId, 'sp_is_accommodation' => 1, 'sp_name' => '[test] Pool Room',
        ]);
        $this->addRate($poolRoom, 'per_night', 700, '2026-01-01', null);
        $enquiryId = (int) $this->rows('SELECT ee_id FROM event_enquiry WHERE ee_b_id = ?', [$this->bookingId])[0]['ee_id'];
        Db::pdo()->prepare('UPDATE event_enquiry SET ee_accommodation_sp_id = ?, ee_accommodation_rooms = 2 WHERE ee_id = ?')
            ->execute([$this->villaId, $enquiryId]);
        // insert() tracks the rows, so they're removed before the rooms they point at
        $this->insert('event_enquiry_room', 'eer_id', ['eer_ee_id' => $enquiryId, 'eer_sp_id' => $this->roomId]);
        $this->insert('event_enquiry_room', 'eer_id', ['eer_ee_id' => $enquiryId, 'eer_sp_id' => $poolRoom]);

        $quotation = $this->create($this->input());

        self::assertSame(
            [
                ['spaceId' => $this->roomId, 'space' => '[test] Rose Room', 'checkIn' => self::EVENT_DATE, 'checkOut' => '2026-10-18'],
                ['spaceId' => $poolRoom, 'space' => '[test] Pool Room', 'checkIn' => self::EVENT_DATE, 'checkOut' => '2026-10-18'],
            ],
            $quotation['rooms']
        );
    }

    public function testWithoutAnAccommodationRequestThereAreNoRooms(): void
    {
        Db::pdo()->prepare('UPDATE event_enquiry SET ee_accommodation_sp_id = NULL, ee_accommodation_check_in = NULL,
                            ee_accommodation_check_out = NULL, ee_accommodation_rooms = NULL, ee_accommodation_guests = NULL
                            WHERE ee_b_id = ?')
            ->execute([$this->bookingId]);

        $quotation = $this->create($this->input());

        self::assertSame([], $quotation['rooms']);
    }

    // Staff may change the date, times, spaces, and rooms. The enquiry keeps the guest's ask.
    public function testStaffCanAgreeADifferentPlan(): void
    {
        $quotation = $this->create($this->input($this->otherPlan()));

        self::assertSame('2026-10-24', $quotation['eventDate']);
        self::assertSame('10:00', $quotation['startTime']);
        self::assertSame('15:30', $quotation['endTime']);
        self::assertSame([['id' => $this->poolId, 'name' => '[test] Pool']], $quotation['spaces']);
        self::assertSame(
            [['spaceId' => $this->villaId, 'space' => '[test] Villa', 'checkIn' => '2026-10-23', 'checkOut' => '2026-10-25']],
            $quotation['rooms']
        );
        $enquiry = $this->rows('SELECT ee_preferred_date, ee_start_time FROM event_enquiry WHERE ee_b_id = ?', [$this->bookingId])[0];
        self::assertSame([self::EVENT_DATE, '14:00:00'], array_values($enquiry));
    }

    // The same space twice counts once, like on the enquiry form
    public function testTheSameSpaceTwiceCountsOnce(): void
    {
        $quotation = $this->create($this->input(['spaces' => [$this->gardenId, $this->poolId, $this->gardenId]]));

        self::assertSame([$this->gardenId, $this->poolId], array_column($quotation['spaces'], 'id'));
    }

    // Each field staff leave out comes from the enquiry, even when they send others
    public function testOnlyWhatStaffLeaveOutIsPrefilled(): void
    {
        $quotation = $this->create($this->input(['startTime' => '12:00', 'rooms' => []]));

        self::assertSame(self::EVENT_DATE, $quotation['eventDate']);
        self::assertSame('12:00', $quotation['startTime']);
        self::assertSame('23:00', $quotation['endTime']);
        self::assertSame([['id' => $this->gardenId, 'name' => '[test] Garden']], $quotation['spaces']);
        self::assertSame([], $quotation['rooms']);
    }

    public function testANewVersionCanChangeThePlan(): void
    {
        $first = $this->create($this->input());
        $second = $this->create($this->input($this->otherPlan()));

        $versions = Quotations::versions($second['reference']);

        self::assertSame(['2026-10-24', self::EVENT_DATE], array_column($versions, 'eventDate'));
        self::assertSame([[['id' => $this->poolId, 'name' => '[test] Pool']], [['id' => $this->gardenId, 'name' => '[test] Garden']]], array_column($versions, 'spaces'));
        self::assertSame($first['rooms'], $versions[1]['rooms']);
        self::assertSame($this->villaId, $versions[0]['rooms'][0]['spaceId']);
    }

    // An enquiry saved before migration 006 has no times or spaces: staff must give them
    public function testAnEnquiryWithoutTimesOrSpacesNeedsThemOnTheQuotation(): void
    {
        Db::pdo()->prepare('UPDATE event_enquiry SET ee_start_time = NULL, ee_end_time = NULL WHERE ee_b_id = ?')->execute([$this->bookingId]);
        Db::pdo()->prepare('DELETE FROM event_enquiry_space WHERE ees_ee_id = (SELECT ee_id FROM event_enquiry WHERE ee_b_id = ?)')->execute([$this->bookingId]);

        $error = $this->errorFrom(function () {
            $this->create($this->input());
        });
        $quotation = $this->create($this->input(['startTime' => '14:00', 'endTime' => '23:00', 'spaces' => [$this->gardenId]]));

        self::assertSame(422, $error->status());
        self::assertSame('The enquiry has no event date, times or spaces, so give them on the quotation', $error->getMessage());
        self::assertSame('sent', $quotation['status']);
    }

    // Each case changes the plan. Capital words are this test's spaces (see fill()).
    public static function badPlans(): array
    {
        $stay = ['checkIn' => '2026-10-17', 'checkOut' => '2026-10-18'];

        return [
            'past event date' => [['eventDate' => '2026-09-03'], 'The event date can\'t be in the past'],
            'no such event date' => [['eventDate' => '2026-02-30'], 'Event date must be YYYY-MM-DD'],
            'event date not text' => [['eventDate' => 20261017], 'Event date must be YYYY-MM-DD'],
            'short start time' => [['startTime' => '9:00'], 'Times must be HH:MM'],
            'hour 24' => [['endTime' => '24:00'], 'Times must be HH:MM'],
            'end before start' => [['startTime' => '15:00', 'endTime' => '14:59'], 'The end time must be after the start time'],
            'end at start' => [['endTime' => '14:00'], 'The end time must be after the start time'],
            'no spaces' => [['spaces' => []], 'Choose between 1 and 20 spaces'],
            'spaces not a list' => [['spaces' => 'GARDEN'], 'Choose between 1 and 20 spaces'],
            '21 spaces' => [['spaces' => array_fill(0, 21, 'GARDEN')], 'Choose between 1 and 20 spaces'],
            'unknown space' => [['spaces' => [999999999]], 'Choose spaces from the list'],
            'space by name' => [['spaces' => ['Open Garden']], 'Choose spaces from the list'],
            'switched-off space' => [['spaces' => ['CLOSED']], 'Choose spaces from the list'],
            'spaces at two venues' => [['spaces' => ['GARDEN', 'ELSEWHERE']], 'The spaces must be at one venue'],
            'rooms not a list' => [['rooms' => 'ROOM'], 'Rooms must be a list of { spaceId, checkIn, checkOut }'],
            '51 rooms' => [['rooms' => array_fill(0, 51, ['spaceId' => 'ROOM', 'checkIn' => '2026-10-17', 'checkOut' => '2026-10-18'])], 'Rooms must be a list of { spaceId, checkIn, checkOut }'],
            'room not an object' => [['rooms' => ['ROOM']], 'Rooms must be a list of { spaceId, checkIn, checkOut }'],
            'room without a space' => [['rooms' => [$stay]], 'Choose rooms or the villa from the list'],
            'unknown room' => [['rooms' => [array_merge($stay, ['spaceId' => 999999999])]], 'Choose rooms or the villa from the list'],
            'a pool as a room' => [['rooms' => [array_merge($stay, ['spaceId' => 'POOL'])]], 'Choose rooms or the villa from the list'],
            'room at another venue' => [['rooms' => [array_merge($stay, ['spaceId' => 'ELSEWHERE_ROOM'])]], 'The rooms must be at the event\'s venue'],
            'bad check-in' => [['rooms' => [['spaceId' => 'ROOM', 'checkIn' => '2026-10-32', 'checkOut' => '2026-10-18']]], 'Check-in date must be YYYY-MM-DD'],
            'check-out on check-in' => [['rooms' => [['spaceId' => 'ROOM', 'checkIn' => '2026-10-17', 'checkOut' => '2026-10-17']]], 'Check-out must be after check-in'],
            'stay after the event' => [['rooms' => [['spaceId' => 'ROOM', 'checkIn' => '2026-10-18', 'checkOut' => '2026-10-19']]], 'Each stay must include the event day or the night before it'],
            'stay before the event' => [['rooms' => [['spaceId' => 'ROOM', 'checkIn' => '2026-10-15', 'checkOut' => '2026-10-16']]], 'Each stay must include the event day or the night before it'],
            'same room twice' => [['rooms' => [array_merge($stay, ['spaceId' => 'ROOM']), array_merge($stay, ['spaceId' => 'ROOM'])]], 'Each room can be on the quotation once'],
            'line for a space not on it' => [['items' => [['label' => 'Pool hire', 'quantity' => 1, 'unitPrice' => 100, 'spaceId' => 'POOL']], 'discount' => 0], 'A line can only price a space or room on the quotation'],
            'line space not a number' => [['items' => [['label' => 'Hire', 'quantity' => 1, 'unitPrice' => 100, 'spaceId' => 'x']], 'discount' => 0], 'A line can only price a space or room on the quotation'],
        ];
    }

    #[DataProvider('badPlans')]
    public function testABadPlanIsRefusedAndNothingIsSaved(array $change, string $message): void
    {
        $change = $this->fill($change);

        $error = $this->errorFrom(function () use ($change) {
            $this->create($this->input($change));
        });

        self::assertSame(422, $error->status());
        self::assertSame($message, $error->getMessage());
        self::assertSame(0, $this->quotationCount());
        self::assertSame('enquiry', $this->bookingStatus());
    }

    // A room can be priced with a line, like any other space.
    public function testALineCanPriceARoomOnTheQuotation(): void
    {
        $input = $this->input();
        $input['items'][] = ['label' => 'Rose Room, 1 night', 'quantity' => 1, 'unitPrice' => 650.5, 'spaceId' => $this->roomId];

        $quotation = $this->create($input);

        self::assertSame($this->roomId, $quotation['items'][2]['spaceId']);
        self::assertSame(28650.5, $quotation['subtotal']);
    }

    public function testTheGuestsMustFitTheSpacesAndTheVenue(): void
    {
        $hallHire = ['items' => [['label' => 'Hall hire', 'quantity' => 1, 'unitPrice' => 5000]], 'discount' => 0];
        $tinyId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'hall', 'sp_capacity' => 79]);
        $tooSmall = $this->errorFrom(function () use ($tinyId, $hallHire) {
            $this->create($this->input(array_merge($hallHire, ['spaces' => [$tinyId]])));
        });
        // 79 + 1 = 80 is just enough
        $smallId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'hall', 'sp_capacity' => 1]);
        $fits = $this->create($this->input(array_merge($hallHire, ['spaces' => [$tinyId, $smallId]])));
        // The venue holds 100: 101 guests don't fit, even though the garden holds 120
        Db::pdo()->prepare('UPDATE event_enquiry SET ee_estimated_guests = 101 WHERE ee_b_id = ?')->execute([$this->bookingId]);
        $venue = $this->errorFrom(function () {
            $this->create($this->input());
        });

        self::assertSame(422, $tooSmall->status());
        self::assertSame('The spaces hold fewer guests than are coming', $tooSmall->getMessage());
        self::assertSame('sent', $fits['status']);
        self::assertSame(422, $venue->status());
        self::assertSame('The venue holds fewer guests than are coming', $venue->getMessage());
        self::assertSame(1, $this->quotationCount());
    }

    // Accept checks again: a space can be made smaller after the quotation was sent
    public function testGuestsWhoNoLongerFitStopTheAcceptance(): void
    {
        $quotation = $this->create($this->input());
        $this->update('space', 'sp_id', $this->gardenId, ['sp_capacity' => 79]);

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(422, $error->status());
        self::assertSame('The spaces hold fewer guests than are coming', $error->getMessage());
        $this->assertNothingAccepted($quotation['reference']);
    }

    public function testOnlyEventEnquiriesCanBeQuoted(): void
    {
        $roomStay = $this->createStay($this->roomId, '2026-11-01', '2026-11-02', 'confirmed');

        $unknown = $this->errorFrom(function () {
            $this->create($this->input(['bookingReference' => 'TEST-NOPE']));
        });
        $stay = $this->errorFrom(function () use ($roomStay) {
            $this->create($this->input(['bookingReference' => $this->referenceOf($roomStay)]));
        });
        $this->update('booking', 'b_id', $this->bookingId, ['b_status' => 'cancelled']);
        $cancelled = $this->errorFrom(function () {
            $this->create($this->input());
        });

        self::assertSame(404, $unknown->status());
        self::assertSame('Booking not found', $unknown->getMessage());
        self::assertSame(422, $stay->status());
        self::assertSame('Quotations are for event bookings', $stay->getMessage());
        self::assertSame(409, $cancelled->status());
        self::assertSame('This booking can\'t be quoted any more', $cancelled->getMessage());
    }

    public function testAnAcceptedQuotationIsFinal(): void
    {
        $quotation = $this->accept($this->create($this->input()));

        $error = $this->errorFrom(function () {
            $this->create($this->input());
        });

        self::assertSame(409, $error->status());
        self::assertSame('An accepted quotation can\'t be revised', $error->getMessage());
        self::assertSame('accepted', $this->quotationStatus($quotation['reference']));
    }

    public function testLatestForGivesTheNewestVersionOrNull(): void
    {
        self::assertNull(Quotations::latestFor($this->bookingReference));
        self::assertNull(Quotations::latestFor('TEST-NOPE'));

        $this->create($this->input());
        $second = $this->create($this->input());

        self::assertSame($second['reference'], Quotations::latestFor($this->bookingReference)['reference']);
    }

    // ---- Guest: see their quotations ----

    public function testAGuestSeesOnlyTheirOwnQuotations(): void
    {
        $quotation = $this->create($this->input());
        $otherGuestId = $this->createGuest();

        $mine = Quotations::guestList($this->guestId)['quotations'];
        $theirs = Quotations::guestList($otherGuestId)['quotations'];
        $error = $this->errorFrom(function () use ($quotation, $otherGuestId) {
            Quotations::guestDetail($quotation['reference'], $otherGuestId);
        });

        self::assertSame([$quotation['reference']], array_column($mine, 'reference'));
        self::assertSame([], $theirs);
        self::assertSame($quotation['reference'], Quotations::guestDetail($quotation['reference'], $this->guestId)['reference']);
        self::assertSame(404, $error->status());
        self::assertSame('Quotation not found', $error->getMessage());
    }

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

    // Accept reserves what the accepted quotation agreed, not what the enquiry asked for
    public function testAcceptingUsesTheQuotationsPlan(): void
    {
        $plan = $this->otherPlan();
        $plan['items'] = [
            ['label' => 'Pool hire', 'quantity' => 1, 'unitPrice' => 4000, 'spaceId' => $this->poolId],
            ['label' => 'Pool lights', 'quantity' => 2, 'unitPrice' => 250.25, 'spaceId' => $this->poolId],
            ['label' => 'Villa, 2 nights', 'quantity' => 2, 'unitPrice' => 3000, 'spaceId' => $this->villaId],
        ];
        $plan['discount'] = 0;
        $quotation = $this->create($this->input($plan));

        $this->accept($quotation);

        $event = $this->rows('SELECT eb_id, eb_event_date, eb_start_time, eb_end_time, eb_guest_count, eb_occasion FROM event_booking WHERE eb_b_id = ?', [$this->bookingId])[0];
        self::assertSame(['2026-10-24', '10:00:00', '15:30:00', 80, '[test] Wedding'], array_slice(array_values($event), 1));
        // The pool's price is its two lines added up: 4000 + 2 × 250.25
        $spaces = $this->rows('SELECT es_sp_id, es_price_snapshot FROM event_space WHERE es_eb_id = ?', [$event['eb_id']]);
        self::assertSame([['es_sp_id' => $this->poolId, 'es_price_snapshot' => '4500.50']], $spaces);
        // The villa, two nights at 3000, frozen by EventAccommodation itself
        $rooms = $this->rows('SELECT esa_sp_id, esa_check_in, esa_check_out, esa_price_snapshot FROM event_space_allocation WHERE esa_eb_id = ?', [$event['eb_id']]);
        self::assertSame([['esa_sp_id' => $this->villaId, 'esa_check_in' => '2026-10-23', 'esa_check_out' => '2026-10-25', 'esa_price_snapshot' => '6000.00']], $rooms);
        self::assertSame(10500.5, (float) $this->column('SELECT b_total_price FROM booking WHERE b_id = ?', [$this->bookingId]));
        // The garden the enquiry asked for is still free on the enquiry's day
        Db::transaction(function () {
            Availability::assertFree($this->gardenId, self::EVENT_DATE, self::EVENT_DATE, 'event');
        });
    }

    // A space with no line of its own shows as R0; the guest pays the quotation total.
    public function testASpaceWithoutALineIsFrozenAtNothing(): void
    {
        $input = $this->input(['spaces' => [$this->gardenId, $this->poolId]]);
        $quotation = $this->create($input);

        $this->accept($quotation);

        $eventId = (int) $this->column('SELECT eb_id FROM event_booking WHERE eb_b_id = ?', [$this->bookingId]);
        self::assertSame(
            [['es_sp_id' => $this->gardenId, 'es_price_snapshot' => '10000.00'], ['es_sp_id' => $this->poolId, 'es_price_snapshot' => '0.00']],
            $this->rows('SELECT es_sp_id, es_price_snapshot FROM event_space WHERE es_eb_id = ? ORDER BY es_sp_id = ?', [$eventId, $this->poolId])
        );
    }

    // The latest version's plan is the one reserved, when versions differ
    public function testTheAcceptedVersionsPlanIsReserved(): void
    {
        $this->create($this->input($this->otherPlan()));
        $second = $this->create($this->input(['rooms' => []]));

        $this->accept($second);

        $event = $this->rows('SELECT eb_id, eb_event_date FROM event_booking WHERE eb_b_id = ?', [$this->bookingId])[0];
        self::assertSame(self::EVENT_DATE, $event['eb_event_date']);
        self::assertSame([$this->gardenId], $this->idsOf('SELECT es_sp_id FROM event_space WHERE es_eb_id = ?', (int) $event['eb_id']));
        self::assertSame(0, (int) $this->column('SELECT COUNT(*) FROM event_space_allocation WHERE esa_eb_id = ?', [$event['eb_id']]));
    }

    // The quotation's own date is checked for clashes: a taken pool on the agreed day stops it
    public function testATakenSpaceOnTheAgreedDayStopsTheAcceptance(): void
    {
        $quotation = $this->create($this->input($this->otherPlan()));
        $this->createEvent($this->poolId, '2026-10-24', 'confirmed');

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        $this->assertNothingAccepted($quotation['reference']);
    }

    // A taken villa on the agreed nights undoes the event rows already written
    public function testATakenVillaUndoesEverything(): void
    {
        $quotation = $this->create($this->input($this->otherPlan()));
        $this->createStay($this->roomId, '2026-10-23', '2026-10-24', 'confirmed');

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        $this->assertNothingAccepted($quotation['reference']);
        self::assertSame(0, (int) $this->column('SELECT COUNT(*) FROM event_space_allocation WHERE esa_sp_id = ?', [$this->villaId]));
    }

    public function testTheHoldLengthComesFromTheEnvironment(): void
    {
        $_ENV['QUOTATION_HOLD_HOURS'] = '24';

        $result = $this->accept($this->create($this->input()));

        self::assertSame('2026-09-05T12:00', $result['holdExpires']);
    }

    public function testTheGuestIsEmailedAPaymentLinkAfterAccepting(): void
    {
        $quotation = $this->create($this->input());
        Mailer::clear();

        $this->accept($quotation);

        $sent = Mailer::sent();
        self::assertCount(1, $sent);
        self::assertSame('payment_link', $sent[0]['template']);
        self::assertSame($this->guestEmail(), $sent[0]['to']);
    }

    public function testAnAcceptedPrivateEventKeepsTheVenueForItsOwnGuests(): void
    {
        $this->accept($this->create($this->input()));
        $poolId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'pool']);

        // Another guest can't stay in the villa that night, or hire the garden that day
        $stay = $this->errorFrom(function () {
            Db::transaction(function () {
                // The night before is still free
                Availability::assertFree($this->villaId, '2026-10-16', '2026-10-17', 'stay');
                Availability::assertFree($this->villaId, self::EVENT_DATE, '2026-10-18', 'stay');
            });
        });
        $garden = $this->errorFrom(function () {
            Db::transaction(function () {
                Availability::assertFree($this->gardenId, self::EVENT_DATE, self::EVENT_DATE, 'event');
            });
        });
        // Another space at the venue can still host an event that day
        Db::transaction(function () use ($poolId) {
            Availability::assertFree($poolId, self::EVENT_DATE, self::EVENT_DATE, 'event');
        });

        self::assertSame(409, $stay->status());
        self::assertSame(409, $garden->status());
        self::assertSame('Already booked on these dates', $garden->getMessage());
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

    public function testATakenRoomUndoesTheEventAlreadyWritten(): void
    {
        $quotation = $this->create($this->input());
        // The room is checked after the event rows are written, so those rows must be undone.
        $this->createStay($this->roomId, '2026-10-16', '2026-10-18', 'confirmed');

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
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

    public function testOnlyTheLatestVersionCanBeAnswered(): void
    {
        $old = $this->create($this->input());
        $this->create($this->input());

        $accept = $this->errorFrom(function () use ($old) {
            Quotations::accept($old['reference'], $this->guestId);
        });
        $decline = $this->errorFrom(function () use ($old) {
            Quotations::decline($old['reference'], $this->guestId);
        });

        self::assertSame(409, $accept->status());
        self::assertSame(409, $decline->status());
        self::assertSame('Only the latest quotation sent to you can be answered', $accept->getMessage());
    }

    public function testAnEventDayThatHasPassedCantBeAccepted(): void
    {
        $quotation = $this->create($this->input(['validUntil' => '2026-12-31']));
        Clock::freeze('2026-10-18');

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('The event day has passed', $error->getMessage());
        $this->assertNothingAccepted($quotation['reference']);
    }

    public function testABookingNoLongerQuotedCantBeAnswered(): void
    {
        $quotation = $this->create($this->input());
        // e.g. staff cancelled the booking while the quotation was out
        $this->update('booking', 'b_id', $this->bookingId, ['b_status' => 'cancelled']);

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('This booking is no longer waiting for an answer', $error->getMessage());
        self::assertSame(0, (int) $this->column('SELECT COUNT(*) FROM event_booking WHERE eb_b_id = ?', [$this->bookingId]));
    }

    public function testTheQuotationsSpacesMustStillBeBookable(): void
    {
        $quotation = $this->create($this->input());
        $elsewhereId = $this->createSpace(['sp_type' => 'garden']); // in another venue

        // Staff switched the garden off after the quotation was sent
        $this->update('space', 'sp_id', $this->gardenId, ['sp_is_active' => 0]);
        $switchedOff = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });
        $this->update('space', 'sp_id', $this->gardenId, ['sp_is_active' => 1]);
        // Accept checks again, in case the saved rows were changed to span two venues.
        $this->insert('quotation_space', 'qs_id', ['qs_q_id' => $this->quotationId($quotation['reference']), 'qs_sp_id' => $elsewhereId]);
        $twoVenues = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $switchedOff->status());
        self::assertSame('A space on the quotation can no longer be booked', $switchedOff->getMessage());
        self::assertSame(422, $twoVenues->status());
        self::assertSame('The quotation\'s spaces must be at one venue', $twoVenues->getMessage());
        $this->assertNothingAccepted($quotation['reference']);
    }

    // Quotations sent before migration 006 have no plan, so there is nothing to reserve
    public function testAQuotationWithoutAPlanCantBeAccepted(): void
    {
        $quotation = $this->create($this->input());
        Db::pdo()->prepare('UPDATE quotation SET q_event_date = NULL, q_start_time = NULL, q_end_time = NULL WHERE q_reference = ?')
            ->execute([$quotation['reference']]);

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('This quotation has no event plan. Ask us for a new version.', $error->getMessage());
        $this->assertNothingAccepted($quotation['reference']);
    }

    public function testABookingThatAlreadyHasAnEventIsRefused(): void
    {
        $quotation = $this->create($this->input());
        $this->insert('event_booking', 'eb_id', [
            'eb_b_id' => $this->bookingId, 'eb_v_id' => $this->venueId, 'eb_event_date' => self::EVENT_DATE,
            'eb_start_time' => '10:00:00', 'eb_end_time' => '16:00:00', 'eb_visibility' => 'private',
        ]);

        $error = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('This booking already has an event', $error->getMessage());
        self::assertSame('sent', $this->quotationStatus($quotation['reference']));
    }

    // ---- Expiry ----

    public function testAQuotationPastItsDateShowsAsExpiredAndCantBeAnswered(): void
    {
        $quotation = $this->create($this->input(['validUntil' => '2026-09-10']));
        Clock::freeze('2026-09-11');

        $detail = Quotations::guestDetail($quotation['reference'], $this->guestId);
        $expired = $this->onlyThisBooking(Quotations::staffList(['status' => 'expired'])['quotations']);
        $sent = $this->onlyThisBooking(Quotations::staffList(['status' => 'sent'])['quotations']);
        $accept = $this->errorFrom(function () use ($quotation) {
            Quotations::accept($quotation['reference'], $this->guestId);
        });
        $decline = $this->errorFrom(function () use ($quotation) {
            Quotations::decline($quotation['reference'], $this->guestId);
        });

        self::assertSame('expired', $detail['status']);
        self::assertCount(1, $expired);
        self::assertCount(0, $sent);
        self::assertSame(409, $accept->status());
        self::assertSame('This quotation has expired', $accept->getMessage());
        self::assertSame(409, $decline->status());
        // Reading never writes: the row is still 'sent' until the nightly job expires it
        self::assertSame('sent', $this->quotationStatus($quotation['reference']));
        $this->assertNothingAccepted($quotation['reference']);
    }

    public function testTheLastValidDayStillCountsUntilMidnight(): void
    {
        $quotation = $this->create($this->input(['validUntil' => '2026-09-10']));
        Clock::freezeAt('2026-09-10 23:59');

        $result = $this->accept($quotation);

        self::assertSame('accepted', $result['status']);
    }

    // PER_PAGE, newest first; a bad page is page 1. Ours fill page 1, so the oldest starts page 2.
    public function testTheStaffListIsPaged(): void
    {
        $references = [];
        for ($i = 1; $i <= Quotations::PER_PAGE + 1; $i++) {
            $bookingId = $this->createEnquiry($this->guestId);
            $reference = 'TEST-Q-' . strtoupper(bin2hex(random_bytes(4)));
            $this->insert('quotation', 'q_id', [
                'q_b_id' => $bookingId, 'q_reference' => $reference, 'q_amount' => 100, 'q_status' => 'sent', 'q_valid_until' => '2026-09-30',
            ]);
            $references[] = $reference;
        }

        $first = Quotations::staffList(['status' => 'sent']);
        $second = Quotations::staffList(['status' => 'sent', 'page' => '2']);
        $notANumber = Quotations::staffList(['status' => 'sent', 'page' => '2 OR 1=1']);
        $all = Quotations::staffList([]);

        self::assertCount(Quotations::PER_PAGE, $first['quotations']);
        self::assertSame(array_reverse(array_slice($references, 1)), array_column($first['quotations'], 'reference'));
        self::assertSame(1, $first['page']);
        self::assertSame(2, $second['page']);
        self::assertSame($references[0], $second['quotations'][0]['reference']);
        self::assertSame((int) ceil($first['total'] / Quotations::PER_PAGE), $first['pageCount']);
        self::assertSame($first['counts']['sent'], $first['total']);
        self::assertSame($all['counts']['all'], $all['total']);
        self::assertSame(1, $notANumber['page']);
    }

    // The status chips are worked out in SQL the same way the quotation shows them
    public function testTheChipsCountExpiredQuotationsAsExpired(): void
    {
        // Counted the same day before and after, so other quotations cannot change chip mid-test.
        Clock::freeze('2026-09-11');
        $before = Quotations::staffList([])['counts'];
        Clock::freeze('2026-09-04');
        $this->create($this->input(['validUntil' => '2026-09-10']));
        Clock::freeze('2026-09-11');

        $after = Quotations::staffList([])['counts'];
        $expired = Quotations::staffList(['status' => 'expired']);

        self::assertSame($this->bookingReference, $expired['quotations'][0]['bookingReference']);
        self::assertSame('expired', $expired['quotations'][0]['status']);
        self::assertSame(1, $after['all'] - $before['all']);
        self::assertSame(1, $after['expired'] - $before['expired']);
        self::assertSame(0, $after['sent'] - $before['sent']);
    }

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $error = $this->errorFrom(function () {
            Quotations::staffList(['status' => 'paid']);
        });

        self::assertSame(422, $error->status());
        self::assertSame('Unknown status', $error->getMessage());
    }

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

    public function testAskingForChangesNeedsANote(): void
    {
        $quotation = $this->create($this->input());

        $empty = $this->errorFrom(function () use ($quotation) {
            Quotations::requestChanges($quotation['reference'], $this->guestId, ['note' => '  ']);
        });
        $tooLong = $this->errorFrom(function () use ($quotation) {
            Quotations::requestChanges($quotation['reference'], $this->guestId, ['note' => str_repeat('a', 256)]);
        });

        self::assertSame(422, $empty->status());
        self::assertSame('Tell us what you would like changed', $empty->getMessage());
        self::assertSame(422, $tooLong->status());
        self::assertSame('Keep the note to 255 characters', $tooLong->getMessage());
        self::assertSame('sent', $this->quotationStatus($quotation['reference']));
    }

    // Paying for an accepted quotation

    public function testTheAmountDueIsTheAcceptedTotalWithCents(): void
    {
        $quotation = $this->accept($this->create($this->input(['discount' => 0.5])));

        $amount = (new QuotationPayment())->amountDue($quotation['reference']);

        self::assertSame(27999.5, $amount);
    }

    public function testOnlyAnAcceptedQuotationWithALiveHoldCanBePaid(): void
    {
        $quotation = $this->create($this->input());
        $payment = new QuotationPayment();

        $notAccepted = $this->errorFrom(function () use ($payment, $quotation) {
            $payment->amountDue($quotation['reference']);
        });
        $this->accept($quotation);
        Clock::freeze('2026-09-08'); // the 72-hour hold ran out on the 7th
        $holdOver = $this->errorFrom(function () use ($payment, $quotation) {
            $payment->onPaid($quotation['reference'], 1);
        });

        self::assertSame(409, $notAccepted->status());
        self::assertSame('Only the latest accepted quotation can be paid', $notAccepted->getMessage());
        self::assertSame(409, $holdOver->status());
        self::assertSame('The hold on this booking has run out or it was cancelled', $holdOver->getMessage());
        self::assertSame('pending_payment', $this->bookingStatus());
    }

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

    public function testARefundIsOnlyFinishedForACancelledBooking(): void
    {
        $quotation = $this->accept($this->create($this->input()));
        $payment = new QuotationPayment();
        $payment->onPaid($quotation['reference'], 1);

        $stillConfirmed = $this->errorFrom(function () use ($payment, $quotation) {
            $payment->onRefunded($quotation['reference'], 1);
        });
        $this->update('booking', 'b_id', $this->bookingId, ['b_status' => 'cancelled']);
        $payment->onRefunded($quotation['reference'], 1);
        $payment->onRefunded($quotation['reference'], 1); // a second notice changes nothing

        self::assertSame(409, $stillConfirmed->status());
        self::assertSame('Cancel the booking before finishing its refund', $stillConfirmed->getMessage());
        self::assertSame('refunded', $this->bookingStatus());
        self::assertSame(1, (int) $this->column(
            "SELECT COUNT(*) FROM booking_status_history WHERE bsh_b_id = ? AND bsh_from_status = 'cancelled' AND bsh_to_status = 'refunded'",
            [$this->bookingId]
        ));
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

    // A different plan: the pool on 24 Oct, 10:00–15:30, and the whole villa for two nights.
    private function otherPlan(): array
    {
        return [
            'eventDate' => '2026-10-24',
            'startTime' => '10:00',
            'endTime' => '15:30',
            'spaces' => [$this->poolId],
            'rooms' => [['spaceId' => $this->villaId, 'checkIn' => '2026-10-23', 'checkOut' => '2026-10-25']],
            'items' => [['label' => 'Pool hire', 'quantity' => 1, 'unitPrice' => 4000, 'spaceId' => $this->poolId]],
            'discount' => 0,
        ];
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

    // The staff list holds everyone's quotations; keep only this test's booking
    private function onlyThisBooking(array $quotations): array
    {
        $mine = [];
        foreach ($quotations as $quotation) {
            if ($quotation['bookingReference'] === $this->bookingReference) {
                $mine[] = $quotation;
            }
        }

        return $mine;
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

    private function quotationCount(): int
    {
        return (int) $this->column('SELECT COUNT(*) FROM quotation WHERE q_b_id = ?', [$this->bookingId]);
    }

    private function guestEmail(): string
    {
        return (string) $this->column('SELECT u_email FROM app_user WHERE u_id = ?', [$this->guestId]);
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
