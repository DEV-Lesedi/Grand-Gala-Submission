<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Enquiries;
use GrandGala\Services\Mailer;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

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
