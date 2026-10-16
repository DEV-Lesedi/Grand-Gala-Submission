<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\GuestCancel;
use GrandGala\Tests\Support\DatabaseTestCase;
use GrandGala\Utils\Db;

// A guest cancels their own booking. The HTTP side is in Feature/MyBookingsTest.php.
class GuestCancelTest extends DatabaseTestCase
{
    public function testCancellingAnUnpaidStayGivesItsPromoCodeBackAndIsWrittenDown(): void
    {
        $stay = $this->booking('stay', 'pending_payment');
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => 'TEST' . strtoupper(bin2hex(random_bytes(4))), 'promo_type' => 'fixed', 'promo_value' => 10,
            'promo_applies_to' => '["stay"]', 'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ]);
        $this->insert('promo_use', 'pu_id', ['pu_promo_id' => $promoId, 'pu_user_id' => $stay['userId'], 'pu_booking_id' => $stay['id']]);

        $result = GuestCancel::cancel($stay['reference'], $stay['userId']);

        self::assertSame(['reference' => $stay['reference'], 'status' => 'cancelled', 'refundReviewRequired' => false], $result);
        self::assertSame('released', $this->value('SELECT pu_status FROM promo_use WHERE pu_booking_id = ?', [$stay['id']]));
        self::assertSame(null, $this->value('SELECT b_hold_expires_at FROM booking WHERE b_id = ?', [$stay['id']]));
        $history = Db::pdo()->prepare('SELECT bsh_from_status, bsh_to_status, bsh_changed_by, bsh_reason FROM booking_status_history WHERE bsh_b_id = ?');
        $history->execute([$stay['id']]);
        self::assertSame([['bsh_from_status' => 'pending_payment', 'bsh_to_status' => 'cancelled',
            'bsh_changed_by' => $stay['userId'], 'bsh_reason' => 'Cancelled by guest']], $history->fetchAll());
    }

    public function testCancellingAQuotedEventDeclinesTheQuotationWaitingForAnAnswer(): void
    {
        $event = $this->booking('event', 'quoted');
        $quotationId = $this->insert('quotation', 'q_id', [
            'q_b_id' => $event['id'], 'q_reference' => 'TQ-' . strtoupper(bin2hex(random_bytes(5))), 'q_amount' => 900,
            'q_status' => 'sent', 'q_valid_until' => '2026-09-10',
        ]);

        GuestCancel::cancel($event['reference'], $event['userId']);

        self::assertSame('cancelled', $this->value('SELECT b_status FROM booking WHERE b_id = ?', [$event['id']]));
        self::assertSame('declined', $this->value('SELECT q_status FROM quotation WHERE q_id = ?', [$quotationId]));
    }

    public function testAPaidStayIsCancelledForRefundReviewButAPaidEventIsRefused(): void
    {
        $stay = $this->booking('stay', 'confirmed');
        $event = $this->booking('event', 'confirmed');

        $result = GuestCancel::cancel($stay['reference'], $stay['userId']);
        $refused = $this->errorFrom(function () use ($event) {
            GuestCancel::cancel($event['reference'], $event['userId']);
        });

        self::assertTrue($result['refundReviewRequired']);
        self::assertSame('cancelled', $this->value('SELECT b_status FROM booking WHERE b_id = ?', [$stay['id']]));
        self::assertSame(409, $refused->status());
        // The guest is told how to cancel an event instead (staff cancel and refund it)
        self::assertStringStartsWith('A confirmed event can\'t be cancelled here', $refused->getMessage());
        self::assertSame('confirmed', $this->value('SELECT b_status FROM booking WHERE b_id = ?', [$event['id']]));
    }

    public function testFinishedBookingsAndOtherGuestsBookingsCantBeCancelled(): void
    {
        $completed = $this->booking('stay', 'completed');
        $expired = $this->booking('stay', 'expired');
        $someoneElse = $this->createGuest();

        self::assertSame(409, $this->errorFrom(function () use ($completed) {
            GuestCancel::cancel($completed['reference'], $completed['userId']);
        })->status());
        self::assertSame(409, $this->errorFrom(function () use ($expired) {
            GuestCancel::cancel($expired['reference'], $expired['userId']);
        })->status());
        self::assertSame(404, $this->errorFrom(function () use ($completed, $someoneElse) {
            GuestCancel::cancel($completed['reference'], $someoneElse);
        })->status());
    }

    // A $type booking at $status, owned by a new guest. Returns id, reference, and userId.
    private function booking(string $type, string $status): array
    {
        $userId = $this->createGuest();
        $typeId = (int) $this->value('SELECT bt_id FROM booking_type WHERE bt_name = ?', [$type]);
        $reference = 'TEST-' . strtoupper(bin2hex(random_bytes(5)));
        $hold = null;
        if ($status === 'pending_payment') {
            $hold = '2026-09-06 12:00:00';
        }
        $id = $this->insert('booking', 'b_id', [
            'b_reference' => $reference, 'b_bt_id' => $typeId, 'b_u_id' => $userId, 'b_status' => $status, 'b_hold_expires_at' => $hold,
        ]);

        return ['id' => $id, 'reference' => $reference, 'userId' => $userId];
    }

    private function value(string $sql, array $params): mixed
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
