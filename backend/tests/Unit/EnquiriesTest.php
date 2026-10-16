<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Enquiries;
use GrandGala\Services\Mailer;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;
use PHPUnit\Framework\Attributes\DataProvider;

// A signed-in guest asks for an event quote, and staff read the enquiries.
// An enquiry is a booking plus event_enquiry, and catering or décor when requested.
// Today is 2026-09-04 12:00. List tests use "received" in 2037 so they sort first.
class EnquiriesTest extends DatabaseTestCase
{
    private int $guestId;
    private int $gardenId;
    private string $gardenName;
    private int $villaId;
    private ?string $oldStaffEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestId = $this->createGuest();
        $this->update('app_user', 'u_id', $this->guestId, ['u_name' => 'Thandi', 'u_surname' => '[test] Mabuza', 'u_phone' => '+27 71 900 0000']);

        // A random name, so finding the space by name can't match the demo spaces
        $venueId = $this->createVenue();
        $this->gardenName = '[test] Garden ' . bin2hex(random_bytes(4));
        $this->gardenId = $this->createSpace(['sp_v_id' => $venueId, 'sp_type' => 'garden', 'sp_name' => $this->gardenName]);
        $this->villaId = $this->createSpace(['sp_v_id' => $venueId, 'sp_is_accommodation' => 1, 'sp_name' => '[test] Villa']);

        $this->oldStaffEmail = $_ENV['STAFF_ENQUIRY_EMAIL'] ?? null;
        $_ENV['STAFF_ENQUIRY_EMAIL'] = 'staff-test@example.com';
        Mailer::clear();
    }

    protected function tearDown(): void
    {
        if ($this->oldStaffEmail === null) {
            unset($_ENV['STAFF_ENQUIRY_EMAIL']);
        } else {
            $_ENV['STAFF_ENQUIRY_EMAIL'] = $this->oldStaffEmail;
        }
        Mailer::clear();
        parent::tearDown();
    }

    // Sending an enquiry

    public function testAnEnquiryIsSavedAsAnEventBookingWaitingForAQuote(): void
    {
        $result = $this->submit($this->body());

        self::assertMatchesRegularExpression('/^GGG-2026-\d{4}$/', $result['reference']);
        self::assertSame('enquiry', $result['status']);

        $booking = $this->bookingRow($result['reference']);
        self::assertSame('enquiry', $booking['b_status']);
        self::assertSame('event', $booking['bt_name']);
        self::assertSame($this->guestId, (int) $booking['b_u_id']);
        self::assertSame('2026-09-04 12:00:00', $booking['b_date_created']);
        // No JSON in the notes: everything has its own column
        self::assertNull($booking['b_notes']);

        $enquiry = $this->enquiryRow((int) $booking['b_id']);
        self::assertSame('2026-11-14', $enquiry['ee_preferred_date']);
        self::assertSame('2026-11-21', $enquiry['ee_alternate_date']);
        self::assertSame(80, (int) $enquiry['ee_estimated_guests']);
        self::assertSame('Wedding reception', $enquiry['ee_occasion']);
        self::assertSame('Wheelchair access', $enquiry['ee_requirements']);
        self::assertSame(1, (int) $enquiry['ee_wants_catering']);
        self::assertSame(1, (int) $enquiry['ee_wants_decor']);
        self::assertSame(0, (int) $enquiry['ee_self_catering']);
        self::assertSame(0, (int) $enquiry['ee_self_decor']);
        // Migration 006: the times and the accommodation request have their own columns
        self::assertSame('14:00:00', $enquiry['ee_start_time']);
        self::assertSame('23:00:00', $enquiry['ee_end_time']);
        self::assertSame($this->villaId, (int) $enquiry['ee_accommodation_sp_id']);
        self::assertSame('2026-11-14', $enquiry['ee_accommodation_check_in']);
        self::assertSame('2026-11-15', $enquiry['ee_accommodation_check_out']);
        self::assertSame(3, (int) $enquiry['ee_accommodation_rooms']);
        self::assertSame(6, (int) $enquiry['ee_accommodation_guests']);
        self::assertSame([$this->gardenId], $this->requestedSpaceIds((int) $enquiry['ee_id']));
    }

    public function testWithoutAccommodationTheRequestColumnsAreEmpty(): void
    {
        $body = $this->body();
        $body['accommodation'] = null;

        $result = $this->submit($body);

        $enquiry = $this->enquiryRow((int) $this->bookingRow($result['reference'])['b_id']);
        self::assertNull($enquiry['ee_accommodation_sp_id']);
        self::assertNull($enquiry['ee_accommodation_check_in']);
        self::assertNull($enquiry['ee_accommodation_check_out']);
        self::assertNull($enquiry['ee_accommodation_rooms']);
        self::assertNull($enquiry['ee_accommodation_guests']);
        self::assertNull(Enquiries::detail($result['reference'])['accommodation']);
    }

    public function testPricesUsersAndStatusesFromTheBrowserAreIgnored(): void
    {
        $body = $this->body();
        $body['total'] = 999;
        $body['userId'] = 1;
        $body['status'] = 'confirmed';

        $result = $this->submit($body);

        $booking = $this->bookingRow($result['reference']);
        self::assertSame('enquiry', $booking['b_status']);
        self::assertSame($this->guestId, (int) $booking['b_u_id']);
        self::assertSame(0.0, (float) $booking['b_total_price']);
    }

    public function testTheAlternateDateIsOptional(): void
    {
        $body = $this->body();
        unset($body['alternateDate']);

        $result = $this->submit($body);

        $booking = $this->bookingRow($result['reference']);
        self::assertNull($this->enquiryRow((int) $booking['b_id'])['ee_alternate_date']);
    }

    public function testCateringAndDecorRowsAreSavedWhenTheVenueProvidesThem(): void
    {
        $result = $this->submit($this->body());
        $bookingId = (int) $this->bookingRow($result['reference'])['b_id'];

        $catering = $this->rows('SELECT c_meal_type, c_headcount, c_special_requests, c_line_total FROM catering WHERE c_b_id = ?', $bookingId);
        $decor = $this->rows('SELECT d_requests FROM decoration WHERE d_b_id = ?', $bookingId);

        self::assertCount(1, $catering);
        self::assertSame('dinner', $catering[0]['c_meal_type']);
        self::assertSame(80, (int) $catering[0]['c_headcount']);
        self::assertSame('Vegetarian meals', $catering[0]['c_special_requests']);
        // Not priced yet: the quotation sets the price
        self::assertNull($catering[0]['c_line_total']);
        self::assertCount(1, $decor);
        self::assertSame('Long tables and flowers', $decor[0]['d_requests']);
    }

    public function testNoCateringOrDecorRowsWhenTheGuestBringsTheirOwn(): void
    {
        $body = $this->body();
        $body['wantsCatering'] = false;
        $body['selfCatering'] = true;
        $body['wantsDecor'] = false;
        $body['selfDecor'] = true;

        $result = $this->submit($body);
        $bookingId = (int) $this->bookingRow($result['reference'])['b_id'];

        self::assertCount(0, $this->rows('SELECT c_id FROM catering WHERE c_b_id = ?', $bookingId));
        self::assertCount(0, $this->rows('SELECT d_id FROM decoration WHERE d_b_id = ?', $bookingId));
        $enquiry = $this->enquiryRow($bookingId);
        self::assertSame(0, (int) $enquiry['ee_wants_catering']);
        self::assertSame(1, (int) $enquiry['ee_self_catering']);
        self::assertSame(0, (int) $enquiry['ee_wants_decor']);
        self::assertSame(1, (int) $enquiry['ee_self_decor']);
    }

    public function testTheGuestAndTheStaffInboxAreEmailed(): void
    {
        $result = $this->submit($this->body());

        $sent = Mailer::sent();
        self::assertCount(2, $sent);
        self::assertSame('enquiry_received', $sent[0]['template']);
        self::assertSame($this->guestEmail(), $sent[0]['to']);
        self::assertSame('enquiry_staff', $sent[1]['template']);
        self::assertSame('staff-test@example.com', $sent[1]['to']);

        // The emails carry the whole brief: times, spaces (by name) and rooms too
        $data = $sent[1]['data'];
        self::assertSame($result['reference'], $data['reference']);
        self::assertSame('Thandi [test] Mabuza', $data['guestName']);
        self::assertSame('14:00', $data['startTime']);
        self::assertSame('23:00', $data['endTime']);
        self::assertSame([$this->gardenName], $data['spaces']);
        self::assertSame(
            ['space' => '[test] Villa', 'checkIn' => '2026-11-14', 'checkOut' => '2026-11-15', 'rooms' => 3, 'guests' => 6],
            $data['accommodation']
        );
    }

    // Staff see every enquiry on the Enquiries page; the staff email is only an extra heads-up
    public function testWithoutAStaffInboxTheEnquiryIsStillSavedAndOnlyTheGuestIsEmailed(): void
    {
        unset($_ENV['STAFF_ENQUIRY_EMAIL']);
        $before = $this->enquiryCount();

        $result = $this->submit($this->body());

        self::assertSame('enquiry', $result['status']);
        self::assertSame($before + 1, $this->enquiryCount());
        self::assertSame(['enquiry_received'], array_column(Mailer::sent(), 'template'));
    }

    public function testSpacesCanBeIdsOrNamesAndRepeatsCountOnce(): void
    {
        $poolId = $this->createSpace(['sp_type' => 'pool', 'sp_name' => '[test] Pool']);
        $body = $this->body();
        $body['requestedSpaces'] = [$this->gardenId, '  ' . strtoupper($this->gardenName) . ' ', $poolId];

        $result = $this->submit($body);

        self::assertSame([$this->gardenName, '[test] Pool'], Mailer::sent()[1]['data']['spaces']);
        // Saved once each, in the order the guest gave them
        $enquiryId = (int) $this->enquiryRow((int) $this->bookingRow($result['reference'])['b_id'])['ee_id'];
        self::assertSame([$this->gardenId, $poolId], $this->requestedSpaceIds($enquiryId));
        self::assertSame([$this->gardenName, '[test] Pool'], Enquiries::detail($result['reference'])['spaces']);
    }

    public function testTwentySpacesAreAllowed(): void
    {
        $body = $this->body();
        $body['requestedSpaces'] = array_fill(0, 20, $this->gardenId);

        $result = $this->submit($body);

        self::assertSame('enquiry', $result['status']);
    }

    // Edges are allowed: 12 days ahead, 1 guest, 65535 guests, and a one-minute event.
    public function testTheEdgesOfEachRuleAreAllowed(): void
    {
        $body = $this->body();
        $body['preferredDate'] = '2026-09-16';
        $body['alternateDate'] = '2026-09-16';
        $body['startTime'] = '23:58';
        $body['endTime'] = '23:59';
        $body['estimatedGuests'] = 65535;
        $body['accommodation']['checkIn'] = '2026-09-16';
        $body['accommodation']['checkOut'] = '2026-09-17';
        $body['occasion'] = str_repeat('é', 150);
        $body['requirements'] = str_repeat('é', 10000);
        $this->submit($body);

        $body['estimatedGuests'] = 1;
        $body['accommodation'] = null;
        $result = $this->submit($body);

        self::assertSame('enquiry', $result['status']);
    }

    // [what changes in the body, the error message]
    public static function badEnquiries(): array
    {
        $tooManySpaces = array_fill(0, 21, 1);

        return [
            'past preferred date' => [['preferredDate' => '2026-09-03'], 'Event dates must be at least 12 days from today'],
            'tomorrow preferred date' => [['preferredDate' => '2026-09-05'], 'Event dates must be at least 12 days from today'],
            '11 days preferred date' => [['preferredDate' => '2026-09-15'], 'Event dates must be at least 12 days from today'],
            'past alternate date' => [['alternateDate' => '2026-09-03'], 'Event dates must be at least 12 days from today'],
            'tomorrow alternate date' => [['alternateDate' => '2026-09-05'], 'Event dates must be at least 12 days from today'],
            'not a real date' => [['preferredDate' => '2026-02-30'], 'Preferred date must be YYYY-MM-DD'],
            'written date' => [['preferredDate' => 'Sat 14 Nov 2026'], 'Preferred date must be YYYY-MM-DD'],
            'one-digit day' => [['preferredDate' => '2026-11-4'], 'Preferred date must be YYYY-MM-DD'],
            'bad alternate date' => [['alternateDate' => '14/11/2026'], 'Alternate date must be YYYY-MM-DD'],
            'hour 25' => [['startTime' => '25:00'], 'Times must be HH:MM'],
            'short time' => [['endTime' => '9:00'], 'Times must be HH:MM'],
            'end before start' => [['endTime' => '13:00'], 'The end time must be after the start time'],
            'end at start' => [['endTime' => '14:00'], 'The end time must be after the start time'],
            'no guests' => [['estimatedGuests' => 0], 'Estimated guests must be a whole number from 1 to 65535'],
            'too many guests' => [['estimatedGuests' => 65536], 'Estimated guests must be a whole number from 1 to 65535'],
            'guests as text' => [['estimatedGuests' => '80'], 'Estimated guests must be a whole number from 1 to 65535'],
            'half a guest' => [['estimatedGuests' => 1.5], 'Estimated guests must be a whole number from 1 to 65535'],
            'no spaces' => [['requestedSpaces' => []], 'Choose between 1 and 20 spaces'],
            '21 spaces' => [['requestedSpaces' => $tooManySpaces], 'Choose between 1 and 20 spaces'],
            'spaces not a list' => [['requestedSpaces' => 'Open Garden'], 'Choose between 1 and 20 spaces'],
            'empty space' => [['requestedSpaces' => [null]], 'Choose spaces from the list'],
            'unknown space id' => [['requestedSpaces' => [999999999]], 'Choose spaces from the list'],
            'unknown space name' => [['requestedSpaces' => ['[test] No such space']], 'Choose spaces from the list'],
            'no consent' => [['contactConsent' => false], 'Please agree to be contacted about your enquiry'],
            'consent as text' => [['contactConsent' => 'true'], 'contactConsent must be true or false'],
            'catering flag as text' => [['wantsCatering' => 'false'], 'wantsCatering must be true or false'],
            'both caterings' => [['selfCatering' => true], 'Choose venue catering or your own, not both'],
            'both decors' => [['selfDecor' => true], 'Choose venue décor or your own, not both'],
            'unknown meal' => [['mealType' => 'banquet'], 'Choose breakfast, lunch, dinner or all'],
            'no occasion' => [['occasion' => '   '], 'Tell us the occasion'],
            'long occasion' => [['occasion' => str_repeat('x', 151)], 'The occasion can be at most 150 characters'],
            'long requirements' => [['requirements' => str_repeat('x', 10001)], 'Requirements can be at most 10000 characters'],
            'long catering request' => [['cateringRequest' => str_repeat('x', 10001)], 'The catering request can be at most 10000 characters'],
            'long decor request' => [['decorRequest' => str_repeat('x', 10001)], 'The décor request can be at most 10000 characters'],
            'requirements not text' => [['requirements' => ['x']], 'Requirements must be text'],
        ];
    }

    #[DataProvider('badEnquiries')]
    public function testABadEnquiryIsRefusedAndNothingIsSaved(array $changes, string $message): void
    {
        $before = $this->enquiryCount();

        $error = $this->errorFrom(function () use ($changes) {
            $this->submit(array_replace($this->body(), $changes));
        });

        self::assertSame(422, $error->status());
        self::assertSame($message, $error->getMessage());
        self::assertSame($before, $this->enquiryCount());
        self::assertSame([], Mailer::sent());
    }

    // [what changes in the accommodation request, the error message]
    public static function badAccommodation(): array
    {
        return [
            'not an object' => ['rooms please', 'Accommodation must have a space, check-in, check-out, rooms and guests'],
            'no space' => [['spaceId' => null], 'Choose a room or villa for accommodation'],
            'check-in in the past' => [['checkIn' => '2026-09-03', 'checkOut' => '2026-09-05'], 'Check-in must be at least 12 days from today'],
            'check-in tomorrow' => [['checkIn' => '2026-09-05', 'checkOut' => '2026-09-06'], 'Check-in must be at least 12 days from today'],
            'check-in in 11 days' => [['checkIn' => '2026-09-15', 'checkOut' => '2026-09-16'], 'Check-in must be at least 12 days from today'],
            'check-out on check-in' => [['checkOut' => '2026-11-14'], 'Check-out must be after check-in'],
            'bad check-out' => [['checkOut' => '2026-11-31'], 'Check-out date must be YYYY-MM-DD'],
            'no rooms' => [['rooms' => 0], 'Rooms must be a whole number from 1 to 100'],
            'rooms as text' => [['rooms' => '3'], 'Rooms must be a whole number from 1 to 100'],
            'nobody staying' => [['guests' => 0], 'Guests staying must be a whole number from 1 to 65535'],
        ];
    }

    #[DataProvider('badAccommodation')]
    public function testBadAccommodationIsRefused(mixed $changes, string $message): void
    {
        $body = $this->body();
        if (is_array($changes)) {
            $body['accommodation'] = array_replace($body['accommodation'], $changes);
        } else {
            $body['accommodation'] = $changes;
        }

        $error = $this->errorFrom(function () use ($body) {
            $this->submit($body);
        });

        self::assertSame(422, $error->status());
        self::assertSame($message, $error->getMessage());
    }

    // A list of room requests is refused; the form asks for one.
    public function testAListOfAccommodationRequestsIsRefused(): void
    {
        $body = $this->body();
        $body['accommodation'] = [$body['accommodation']];

        $error = $this->errorFrom(function () use ($body) {
            $this->submit($body);
        });

        self::assertSame(422, $error->status());
        self::assertSame('Accommodation must have a space, check-in, check-out, rooms and guests', $error->getMessage());
    }

    public function testAccommodationMustBeARoomOrVilla(): void
    {
        $body = $this->body();
        $body['accommodation']['spaceId'] = $this->gardenId;

        $error = $this->errorFrom(function () use ($body) {
            $this->submit($body);
        });

        self::assertSame(422, $error->status());
        self::assertSame('Choose a room or villa for accommodation', $error->getMessage());
    }

    public function testSpacesGuestsCantSeeCantBeRequested(): void
    {
        $hiddenId = $this->createSpace(['sp_type' => 'pool', 'sp_name' => '[test] Closed pool', 'sp_is_active' => 0]);
        $body = $this->body();
        $body['requestedSpaces'] = [$hiddenId];

        $byId = $this->errorFrom(function () use ($body) {
            $this->submit($body);
        });
        $body['requestedSpaces'] = ['[test] Closed pool'];
        $byName = $this->errorFrom(function () use ($body) {
            $this->submit($body);
        });

        self::assertSame('Choose spaces from the list', $byId->getMessage());
        self::assertSame('Choose spaces from the list', $byName->getMessage());
    }

    public function testASwitchedOffAccountCantSendAnEnquiry(): void
    {
        $this->update('app_user', 'u_id', $this->guestId, ['u_is_active' => 0]);
        $before = $this->enquiryCount();

        $switchedOff = $this->errorFrom(function () {
            $this->submit($this->body());
        });
        $missing = $this->errorFrom(function () {
            Enquiries::submit(999999999, $this->body());
        });

        self::assertSame(401, $switchedOff->status());
        self::assertSame('Sign in with an active account', $switchedOff->getMessage());
        self::assertSame(401, $missing->status());
        self::assertSame($before, $this->enquiryCount());
    }

    // Saved on the shared connection with no early commit, so the caller's rollback removes it.
    public function testEverythingIsSavedInOneTransaction(): void
    {
        $before = $this->enquiryCount();
        $cateringBefore = $this->guestsCatering();

        try {
            Db::transaction(function () {
                Enquiries::submit($this->guestId, $this->body());
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('undo', $error->getMessage());
        }

        self::assertSame($before, $this->enquiryCount());
        self::assertSame($cateringBefore, $this->guestsCatering());
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM event_enquiry ee JOIN booking b ON b.b_id = ee.ee_b_id WHERE b.b_u_id = ' . $this->guestId));
    }

    // If the décor insert fails, the booking, enquiry, and catering already written roll back.
    // A TEMPORARY decoration table on this connection hides the real one; other tests are safe.
    public function testAFailedSaveUndoesEverything(): void
    {
        Db::pdo()->exec('CREATE TEMPORARY TABLE decoration (d_id INT)');
        try {
            $failed = false;
            try {
                Enquiries::submit($this->guestId, $this->body());
            } catch (\PDOException $error) {
                $failed = true;
            }
        } finally {
            // TEMPORARY, so this can only drop this connection's table, never the real one.
            Db::pdo()->exec('DROP TEMPORARY TABLE IF EXISTS decoration');
        }
        // If anything was saved (it shouldn't be), make sure tearDown deletes it
        foreach ($this->guestsBookingIds() as $bookingId) {
            $this->track('booking', 'b_id', $bookingId);
        }

        self::assertTrue($failed);
        self::assertSame([], $this->guestsBookingIds());
        self::assertSame(0, $this->guestsCatering());
        self::assertSame([], Mailer::sent());
    }

    // Staff reading enquiries

    public function testTheDetailHasTheShapeOfTheFrontendMock(): void
    {
        $result = $this->submit($this->body());

        $detail = Enquiries::detail($result['reference']);

        self::assertSame([
            'reference' => $result['reference'],
            'guest' => 'T. [test] Mabuza',
            'fullName' => 'Thandi [test] Mabuza',
            'contact' => $this->guestEmail() . ' · +27 71 900 0000',
            'occasion' => 'Wedding reception',
            'spaces' => [$this->gardenName],
            'preferredDate' => '2026-11-14',
            'alternateDate' => '2026-11-21',
            'startTime' => '14:00',
            'endTime' => '23:00',
            'estimatedGuests' => 80,
            'status' => 'enquiry',
            'received' => '2026-09-04T12:00',
            'accommodation' => ['space' => '[test] Villa', 'checkIn' => '2026-11-14', 'checkOut' => '2026-11-15', 'rooms' => 3, 'guests' => 6, 'roomNames' => []],
            'wantsCatering' => true,
            'selfCatering' => false,
            'mealType' => 'dinner',
            'cateringRequest' => 'Vegetarian meals',
            'wantsDecor' => true,
            'selfDecor' => false,
            'decorRequest' => 'Long tables and flowers',
            'requirements' => 'Wheelchair access',
            'quote' => null,
            'changeRequests' => [],
        ], $detail);
    }

    // The list uses the same shape as the detail, including times, spaces, and rooms.
    public function testTheListShowsTheTimesSpacesAndRooms(): void
    {
        $result = $this->submit($this->body());
        $this->update('booking', 'b_id', (int) $this->bookingRow($result['reference'])['b_id'], ['b_date_created' => '2037-06-01 09:00:00']);

        $first = Enquiries::list(['status' => 'enquiry'])['enquiries'][0];
        self::assertSame(Enquiries::detail($result['reference']), $first);
        self::assertSame([$this->gardenName], $first['spaces']);
        self::assertSame('14:00', $first['startTime']);
    }

    // If the email fails after save, the enquiry stays, the guest is told, and it is logged.
    public function testAnEmailThatFailsDoesntUndoTheEnquiry(): void
    {
        $oldLog = ini_set('error_log', '/dev/null');
        Mailer::failWith('SMTP is down');
        try {
            $result = Enquiries::submit($this->guestId, $this->body());
        } finally {
            Mailer::failWith(null);
            ini_set('error_log', (string) $oldLog);
            // Whatever happened, the guest's bookings are deleted after the test
            foreach ($this->guestsBookingIds() as $bookingId) {
                $this->track('booking', 'b_id', $bookingId);
            }
        }

        self::assertSame('enquiry', $result['status']);
        self::assertSame('enquiry', $this->bookingRow($result['reference'])['b_status']);
        self::assertSame([$this->gardenName], Enquiries::detail($result['reference'])['spaces']);
    }

    public function testWithoutCateringOrDecorTheDetailHasNoMealOrRequests(): void
    {
        $this->update('app_user', 'u_id', $this->guestId, ['u_phone' => null]);
        $body = $this->body();
        $body['wantsCatering'] = false;
        $body['wantsDecor'] = false;
        $result = $this->submit($body);

        $detail = Enquiries::detail($result['reference']);

        self::assertNull($detail['mealType']);
        self::assertSame('', $detail['cateringRequest']);
        self::assertSame('', $detail['decorRequest']);
        // No phone: just the email
        self::assertSame($this->guestEmail(), $detail['contact']);
    }

    public function testTheDetailShowsTheLatestQuote(): void
    {
        $reference = $this->createEnquiry('quoted', '2037-01-01 09:00:00');
        $bookingId = (int) $this->bookingRow($reference)['b_id'];
        $this->addQuote($bookingId, 'superseded');
        $latest = $this->addQuote($bookingId, 'sent');

        $detail = Enquiries::detail($reference);

        self::assertSame(['reference' => $latest, 'version' => 2, 'status' => 'sent'], $detail['quote']);
    }

    public function testAnUnknownReferenceOrAStayIsNotAnEnquiry(): void
    {
        $stayId = $this->createStay($this->villaId, '2026-11-01', '2026-11-02', 'confirmed');
        $stayReference = $this->bookingReference($stayId);

        $unknown = $this->errorFrom(function () {
            Enquiries::detail('GGG-1999-0001');
        });
        $stay = $this->errorFrom(function () use ($stayReference) {
            Enquiries::detail($stayReference);
        });

        self::assertSame(404, $unknown->status());
        self::assertSame('Enquiry not found', $unknown->getMessage());
        self::assertSame(404, $stay->status());
        self::assertSame('Enquiry not found', $stay->getMessage());
    }

    public function testTheListShowsOneChipNewestFirst(): void
    {
        $older = $this->createEnquiry('enquiry', '2037-01-01 09:00:00');
        $newer = $this->createEnquiry('enquiry', '2037-01-02 09:00:00');
        $quoted = $this->createEnquiry('quoted', '2037-01-03 09:00:00');

        $list = Enquiries::list(['status' => 'enquiry']);
        $references = array_column($list['enquiries'], 'reference');

        self::assertSame([$newer, $older], array_slice($references, 0, 2));
        self::assertNotContains($quoted, $references);
        self::assertSame('2037-01-02T09:00', $list['enquiries'][0]['received']);
    }

    public function testTheChipIsEnquiryUnlessOneIsChosen(): void
    {
        $reference = $this->createEnquiry('enquiry', '2037-01-01 09:00:00');

        $list = Enquiries::list([]);

        self::assertSame($reference, $list['enquiries'][0]['reference']);
    }

    public function testQuotedAndClosedChips(): void
    {
        $quoted = $this->createEnquiry('quoted', '2037-01-01 09:00:00');
        $closed = [];
        foreach (['pending_payment', 'confirmed', 'completed', 'cancelled', 'expired', 'refunded'] as $index => $status) {
            $closed[] = $this->createEnquiry($status, '2037-02-0' . ($index + 1) . ' 09:00:00');
        }

        $quotedList = array_column(Enquiries::list(['status' => 'quoted'])['enquiries'], 'reference');
        $closedList = array_column(Enquiries::list(['status' => 'closed'])['enquiries'], 'reference');

        self::assertSame($quoted, $quotedList[0]);
        self::assertSame(array_reverse($closed), array_slice($closedList, 0, 6));
        self::assertNotContains($quoted, $closedList);
    }

    public function testTheCountsForEachChip(): void
    {
        $before = Enquiries::list([])['counts'];
        $this->createEnquiry('enquiry', '2037-01-01 09:00:00');
        $this->createEnquiry('enquiry', '2037-01-01 10:00:00');
        $this->createEnquiry('quoted', '2037-01-01 11:00:00');
        $this->createEnquiry('cancelled', '2037-01-01 12:00:00');

        $after = Enquiries::list([])['counts'];

        self::assertSame(2, $after['enquiry'] - $before['enquiry']);
        self::assertSame(1, $after['quoted'] - $before['quoted']);
        self::assertSame(1, $after['closed'] - $before['closed']);
    }

    public function testTheListIsPaged(): void
    {
        for ($i = 1; $i <= Enquiries::PER_PAGE + 1; $i++) {
            $this->createEnquiry('enquiry', '2037-03-01 09:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00');
        }

        $first = Enquiries::list(['status' => 'enquiry']);
        $second = Enquiries::list(['status' => 'enquiry', 'page' => '2']);
        $notANumber = Enquiries::list(['status' => 'enquiry', 'page' => '1 OR 1=1']);

        self::assertCount(Enquiries::PER_PAGE, $first['enquiries']);
        self::assertSame(1, $first['page']);
        self::assertSame(2, $second['page']);
        self::assertSame((int) ceil($first['total'] / Enquiries::PER_PAGE), $first['pageCount']);
        // The oldest of our enquiries is first on page 2
        self::assertSame('2037-03-01T09:01', $second['enquiries'][0]['received']);
        self::assertSame(1, $notANumber['page']);
        self::assertSame($first['enquiries'], $notANumber['enquiries']);
    }

    public function testAnUnknownChipIsRefused(): void
    {
        $error = $this->errorFrom(function () {
            Enquiries::list(['status' => 'all']);
        });

        self::assertSame(422, $error->status());
        self::assertSame('Unknown status', $error->getMessage());
    }

    // Picking rooms

    public function testPickedRoomsAreSavedAndStaffSeeThemByName(): void
    {
        [$rose, $pool] = $this->villaRooms(['[test] Rose Room', '[test] Pool Room']);
        $body = $this->body();
        $body['accommodation'] = ['roomIds' => [$rose, $pool], 'checkIn' => '2026-11-14', 'checkOut' => '2026-11-16', 'guests' => 3];

        $result = $this->submit($body);

        $enquiry = $this->enquiryRow((int) $this->bookingRow($result['reference'])['b_id']);
        // Same columns a "number of rooms" request fills: which villa, and how many.
        self::assertSame($this->villaId, (int) $enquiry['ee_accommodation_sp_id']);
        self::assertSame(2, (int) $enquiry['ee_accommodation_rooms']);
        self::assertSame(3, (int) $enquiry['ee_accommodation_guests']);
        $picked = [];
        foreach ($this->rows('SELECT eer_sp_id FROM event_enquiry_room WHERE eer_ee_id = ? ORDER BY eer_id', (int) $enquiry['ee_id']) as $row) {
            $picked[] = (int) $row['eer_sp_id'];
        }
        self::assertSame([$rose, $pool], $picked);

        $detail = Enquiries::detail($result['reference']);
        self::assertSame(['[test] Rose Room', '[test] Pool Room'], $detail['accommodation']['roomNames']);
        self::assertSame(2, $detail['accommodation']['rooms']);
    }

    public function testARoomCountRequestHasNoRoomNames(): void
    {
        $result = $this->submit($this->body());

        self::assertSame([], Enquiries::detail($result['reference'])['accommodation']['roomNames']);
    }

    public function testPickedRoomsMustBeRoomsOfOneVilla(): void
    {
        [$rose] = $this->villaRooms(['[test] Rose Room']);
        $otherVilla = $this->createSpace(['sp_is_accommodation' => 1, 'sp_name' => '[test] Other villa']);
        $otherRoom = $this->createSpace(['sp_type' => 'room', 'sp_parent_id' => $otherVilla, 'sp_is_accommodation' => 1, 'sp_name' => '[test] Far Room']);

        foreach ([[$rose, $this->gardenId], [$rose, $otherRoom], [$this->villaId], []] as $roomIds) {
            $body = $this->body();
            $body['accommodation'] = ['roomIds' => $roomIds, 'checkIn' => '2026-11-14', 'checkOut' => '2026-11-16', 'guests' => 2];
            $error = $this->errorFrom(function () use ($body) {
                $this->submit($body);
            });

            self::assertSame(422, $error->status());
            self::assertSame('Choose rooms from one villa', $error->getMessage());
        }
    }

    // Rooms of the test villa, one per name. Returns their ids in the same order.
    private function villaRooms(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[] = $this->createSpace([
                'sp_type' => 'room', 'sp_parent_id' => $this->villaId, 'sp_is_accommodation' => 1, 'sp_name' => $name,
            ]);
        }

        return $ids;
    }

    // ---------- Helpers ----------

    // A good enquiry for the test garden, with a room request for the test villa
    private function body(): array
    {
        return [
            'occasion' => 'Wedding reception',
            'preferredDate' => '2026-11-14',
            'alternateDate' => '2026-11-21',
            'startTime' => '14:00',
            'endTime' => '23:00',
            'estimatedGuests' => 80,
            'requestedSpaces' => [$this->gardenId],
            'accommodation' => ['spaceId' => $this->villaId, 'checkIn' => '2026-11-14', 'checkOut' => '2026-11-15', 'rooms' => 3, 'guests' => 6],
            'wantsCatering' => true,
            'selfCatering' => false,
            'mealType' => 'dinner',
            'cateringRequest' => 'Vegetarian meals',
            'wantsDecor' => true,
            'selfDecor' => false,
            'decorRequest' => 'Long tables and flowers',
            'requirements' => 'Wheelchair access',
            'contactConsent' => true,
        ];
    }

    // Sends an enquiry as the test guest, and tracks the booking so tearDown deletes it
    // (its event_enquiry, catering and decoration rows are deleted with it)
    private function submit(array $body): array
    {
        $result = Enquiries::submit($this->guestId, $body);
        $this->track('booking', 'b_id', (int) $this->bookingRow($result['reference'])['b_id']);

        return $result;
    }

    // Inserts an enquiry directly, with any status and received time. Returns the reference.
    private function createEnquiry(string $status, string $received): string
    {
        $eventType = $this->number("SELECT bt_id FROM booking_type WHERE bt_name = 'event'");
        $reference = 'TEST-' . strtoupper(bin2hex(random_bytes(5)));
        $bookingId = $this->insert('booking', 'b_id', [
            'b_reference' => $reference, 'b_bt_id' => $eventType, 'b_u_id' => $this->guestId,
            'b_status' => $status, 'b_date_created' => $received,
        ]);
        $this->insert('event_enquiry', 'ee_id', [
            'ee_b_id' => $bookingId, 'ee_preferred_date' => '2027-01-01', 'ee_estimated_guests' => 10, 'ee_occasion' => '[test] Party',
        ]);

        return $reference;
    }

    // Adds a quotation to a booking and returns its reference
    private function addQuote(int $bookingId, string $status): string
    {
        $reference = 'TEST-Q-' . strtoupper(bin2hex(random_bytes(4)));
        $this->insert('quotation', 'q_id', [
            'q_b_id' => $bookingId, 'q_reference' => $reference, 'q_amount' => 1000, 'q_status' => $status,
        ]);

        return $reference;
    }

    private function bookingRow(string $reference): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT b.*, bt.bt_name FROM booking b JOIN booking_type bt ON bt.bt_id = b.b_bt_id WHERE b.b_reference = ?'
        );
        $statement->execute([$reference]);

        return $statement->fetch();
    }

    private function enquiryRow(int $bookingId): array
    {
        return $this->rows('SELECT * FROM event_enquiry WHERE ee_b_id = ?', $bookingId)[0];
    }

    private function bookingReference(int $bookingId): string
    {
        return $this->rows('SELECT b_reference FROM booking WHERE b_id = ?', $bookingId)[0]['b_reference'];
    }

    private function guestEmail(): string
    {
        return $this->rows('SELECT u_email FROM app_user WHERE u_id = ?', $this->guestId)[0]['u_email'];
    }

    private function rows(string $sql, int $id): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$id]);

        return $statement->fetchAll();
    }

    private function number(string $sql): int
    {
        return (int) Db::pdo()->query($sql)->fetchColumn();
    }

    // The space ids saved in event_enquiry_space for an enquiry, in the order they were saved
    private function requestedSpaceIds(int $enquiryId): array
    {
        $ids = [];
        foreach ($this->rows('SELECT ees_sp_id FROM event_enquiry_space WHERE ees_ee_id = ? ORDER BY ees_id', $enquiryId) as $row) {
            $ids[] = (int) $row['ees_sp_id'];
        }

        return $ids;
    }

    private function guestsBookingIds(): array
    {
        $ids = [];
        foreach ($this->rows('SELECT b_id FROM booking WHERE b_u_id = ?', $this->guestId) as $row) {
            $ids[] = (int) $row['b_id'];
        }

        return $ids;
    }

    private function guestsCatering(): int
    {
        return $this->number('SELECT COUNT(*) FROM catering c JOIN booking b ON b.b_id = c.c_b_id WHERE b.b_u_id = ' . $this->guestId);
    }

    // The test guest's bookings. Only this test uses this guest, so other people's tests running
    // at the same time can't change the number.
    private function enquiryCount(): int
    {
        return $this->number('SELECT COUNT(*) FROM booking WHERE b_u_id = ' . $this->guestId);
    }
}
