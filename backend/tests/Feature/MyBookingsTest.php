<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use GrandGala\Tests\Support\CreatesTestAccounts;
use GrandGala\Utils\Db;

// Guest "My bookings": list, detail, and self-cancel while unpaid.
class MyBookingsTest extends FeatureTestCase
{
    use CreatesTestAccounts;

    public function testAGuestSeesOnlyTheirOwnBookings(): void
    {
        $me = $this->createAccount('customer');
        $someoneElse = $this->createAccount('customer');

        $mine = $this->createOwnedStay($me['id'], 'confirmed', '2026-12-12', '2026-12-14');
        $this->createOwnedStay($someoneElse['id'], 'confirmed', '2026-12-20', '2026-12-22');

        $response = $this->sendAuthed('GET', '/api/me/bookings?tab=upcoming', $this->tokenFor($me['id']));

        self::assertSame(200, $response['status']);
        $references = array_column($response['body'], 'reference');
        self::assertContains($mine, $references);
        self::assertCount(1, $response['body']);
    }

    public function testTheCardStatusVocabularyMatchesBackendFeatures(): void
    {
        $me = $this->createAccount('customer');
        $this->createOwnedStay($me['id'], 'quoted', '2027-03-14', '2027-03-15', total: 0);

        $response = $this->sendAuthed('GET', '/api/me/bookings?tab=upcoming', $this->tokenFor($me['id']));

        self::assertSame('quote_sent', $response['body'][0]['status']);
        self::assertSame('Review quote', $response['body'][0]['actionLabel']);
    }

    public function testABookingThatIsNotMineIsNotFound(): void
    {
        $me = $this->createAccount('customer');
        $someoneElse = $this->createAccount('customer');
        $theirs = $this->createOwnedStay($someoneElse['id'], 'confirmed', '2026-12-12', '2026-12-14');

        $response = $this->sendAuthed('GET', '/api/me/bookings/' . $theirs, $this->tokenFor($me['id']));

        self::assertSame(404, $response['status']);
    }

    public function testAnUnpaidBookingCanBeCancelledByItsGuest(): void
    {
        $me = $this->createAccount('customer');
        $reference = $this->createOwnedStay($me['id'], 'pending_payment', '2026-12-12', '2026-12-14');
        $token = $this->tokenFor($me['id']);

        $cancel = $this->sendAuthed('POST', '/api/me/bookings/' . $reference . '/cancel', $token);
        self::assertSame(200, $cancel['status']);
        self::assertSame(['reference' => $reference, 'status' => 'cancelled', 'refundReviewRequired' => false], $cancel['body']);

        $detail = $this->sendAuthed('GET', '/api/me/bookings/' . $reference, $token);
        self::assertSame('cancelled', $detail['body']['status']);
    }

    // A paid stay is cancelled and flagged for a refund review; a paid event is refused here.
    public function testAConfirmedEventCannotBeSelfCancelledButAConfirmedStayCan(): void
    {
        $me = $this->createAccount('customer');
        $event = $this->createOwnedStay($me['id'], 'confirmed', '2026-12-12', '2026-12-14', 1000, 'event');
        $stay = $this->createOwnedStay($me['id'], 'confirmed', '2026-12-12', '2026-12-14');
        $token = $this->tokenFor($me['id']);

        $refused = $this->sendAuthed('POST', '/api/me/bookings/' . $event . '/cancel', $token);
        $cancelled = $this->sendAuthed('POST', '/api/me/bookings/' . $stay . '/cancel', $token);
        $again = $this->sendAuthed('POST', '/api/me/bookings/' . $stay . '/cancel', $token);

        self::assertSame(409, $refused['status']);
        self::assertSame(200, $cancelled['status']);
        self::assertTrue($cancelled['body']['refundReviewRequired']);
        self::assertSame(409, $again['status']);
    }

    public function testSomeoneElsesBookingCantBeCancelled(): void
    {
        $owner = $this->createAccount('customer');
        $reference = $this->createOwnedStay($owner['id'], 'pending_payment', '2026-12-12', '2026-12-14');
        $other = $this->createAccount('customer');

        $response = $this->sendAuthed('POST', '/api/me/bookings/' . $reference . '/cancel', $this->tokenFor($other['id']));

        self::assertSame(404, $response['status']);
    }

    // Inserted directly so $userId owns it (createStay() always makes its own guest).
    // $type 'event' skips dates; cancelling does not use them.
    private function createOwnedStay(int $userId, string $status, string $checkIn, string $checkOut, float $total = 1000, string $type = 'stay'): string
    {
        $reference = 'TEST-' . strtoupper(bin2hex(random_bytes(5)));
        $bookingId = $this->insert('booking', 'b_id', [
            'b_reference' => $reference,
            'b_bt_id' => $this->bookingTypeId($type),
            'b_u_id' => $userId,
            'b_status' => $status,
            'b_total_price' => $total,
        ]);

        $spaceId = $this->createSpace();
        $stayId = $this->insert('stay_booking', 'sb_id', [
            'sb_b_id' => $bookingId, 'sb_check_in' => $checkIn, 'sb_check_out' => $checkOut, 'sb_guests' => 2,
        ]);
        $this->insert('stay_space', 'ss_id', [
            'ss_sb_id' => $stayId, 'ss_sp_id' => $spaceId, 'ss_nights' => 1, 'ss_price_snapshot' => $total, 'ss_line_total' => $total,
        ]);

        return $reference;
    }

    private function bookingTypeId(string $name): int
    {
        $statement = Db::pdo()->prepare('SELECT bt_id FROM booking_type WHERE bt_name = ?');
        $statement->execute([$name]);

        return (int) $statement->fetchColumn();
    }
}
