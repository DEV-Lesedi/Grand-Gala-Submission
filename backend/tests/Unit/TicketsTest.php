<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Mailer;
use GrandGala\Services\Tickets;
use GrandGala\Tests\Support\TicketsTestCase;

// Ticket orders, payment, e-tickets, refunds, and the gate scan.
// Now is 2026-09-04 12:00. 3 seats on 2026-11-14 18:30: Adult R120 (max 2) and Child R60.
class TicketsTest extends TicketsTestCase
{
    private array $event;
    private int $adultId;
    private int $childId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = $this->createPublicEvent();
        $this->adultId = $this->event['ticketTiers'][0]['id'];
        $this->childId = $this->event['ticketTiers'][1]['id'];
    }

    public function testAGuestOrderHoldsSeatsAtTheServersPrice(): void
    {
        // The browser's total, price, and user id are ignored.
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2, ['total' => 1, 'unitPrice' => 1, 'userId' => $this->staffId]));

        self::assertStringStartsWith('GGG-TKT-2026-', $order['reference']);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame(240.0, $order['subtotal']);
        self::assertSame(0.0, $order['discount']);
        self::assertSame(240.0, $order['total']);
        self::assertSame('2026-09-04T12:15', $order['holdExpires']);
        self::assertTrue($order['paymentRequired']);
        self::assertNotSame('', $order['accessToken']);
        self::assertSame([['tierId' => $this->adultId, 'name' => 'Adult', 'quantity' => 2, 'unitPrice' => 120.0]], $order['items']);

        $orderId = $this->orderId($order['reference']);
        self::assertSame('', $this->text('SELECT to_u_id FROM ticket_order WHERE to_id = ?', [$orderId]));
        self::assertSame('120.00', $this->text('SELECT toi_unit_price FROM ticket_order_item WHERE toi_to_id = ?', [$orderId]));
        self::assertSame([], $this->ticketsFor($order['reference'])); // only after payment
    }

    public function testHeldSeatsCantBeSoldTwice(): void
    {
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));

        $error = $this->errorFrom(function () {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1));
        });

        self::assertSame(409, $error->status());
        self::assertSame('Not enough Adult tickets left', $error->getMessage());
        self::assertSame(1, $this->ordersFor($this->event['id']));
    }

    public function testAGuestReceiptNeedsItsOwnUntamperedToken(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $otherOrder = $this->placeOrder($this->orderInput($this->event['id'], $this->childId));

        self::assertSame(120.0, Tickets::detail($order['reference'], null, $order['accessToken'])['total']);
        foreach ([null, '', $order['accessToken'] . 'x', $otherOrder['accessToken'], 'not.a-token'] as $token) {
            $error = $this->errorFrom(function () use ($order, $token) {
                Tickets::detail($order['reference'], null, $token);
            });
            self::assertSame(404, $error->status());
            self::assertSame('Order not found', $error->getMessage());
        }
    }

    public function testAnExpiredHoldFreesTheSeatsAndCantBePaid(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));
        Clock::freezeAt('2026-09-04 12:15');

        self::assertSame('expired', Tickets::detail($order['reference'], null, $order['accessToken'])['status']);
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));
        $late = $this->errorFrom(function () use ($order) {
            Tickets::onPaid($order['reference'], $this->addPayment($order['reference']));
        });
        $due = $this->errorFrom(function () use ($order) {
            Tickets::amountDue($order['reference']);
        });

        self::assertSame(409, $late->status());
        self::assertSame('This ticket order can no longer be paid', $late->getMessage());
        self::assertSame(409, $due->status());
        self::assertSame([], $this->ticketsFor($order['reference']));
    }

    public function testPaymentIssuesOneTicketPerSeatAndEmailsThemOnce(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2, ['items' => [
            ['tierId' => $this->adultId, 'quantity' => 2], ['tierId' => $this->childId, 'quantity' => 1],
        ]]));

        $paymentId = $this->pay($order['reference']);
        Tickets::onPaid($order['reference'], $paymentId); // same notice again
        Tickets::issue($this->orderId($order['reference'])); // and a retry asks again

        $tickets = $this->ticketsFor($order['reference']);
        self::assertCount(3, $tickets);
        self::assertSame('paid', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
        self::assertSame([$this->adultId, $this->adultId, $this->childId], array_map('intval', array_column($tickets, 'tk_tt_id')));
        self::assertSame(['120.00', '120.00', '60.00'], array_column($tickets, 'tk_price_snapshot'));
        self::assertCount(3, array_unique(array_column($tickets, 'tk_code')));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $tickets[0]['tk_code']);

        $emails = Mailer::sent();
        self::assertCount(1, $emails);
        self::assertSame('e_tickets', $emails[0]['template']);
        self::assertSame('thandi@example.com', $emails[0]['to']);
        self::assertSame($order['reference'], $emails[0]['data']['orderReference']);
        self::assertSame('paid', $emails[0]['data']['order']['status']);
        self::assertCount(3, $emails[0]['data']['order']['tickets']);
    }

    public function testTheSaleWindowIsEnforced(): void
    {
        $event = $this->createPublicEvent(['date' => '2026-11-28', 'tiers' => [
            ['name' => 'Adult', 'price' => 120, 'allocation' => 2, 'salesOpen' => '2026-10-01T10:00', 'salesClose' => '2026-10-02T10:00'],
        ]]);
        $tierId = $event['ticketTiers'][0]['id'];

        $early = $this->errorFrom(function () use ($event, $tierId) {
            $this->placeOrder($this->orderInput($event['id'], $tierId));
        });
        Clock::freezeAt('2026-10-01 10:00');
        $this->placeOrder($this->orderInput($event['id'], $tierId));
        Clock::freezeAt('2026-10-02 10:00');
        $late = $this->errorFrom(function () use ($event, $tierId) {
            $this->placeOrder($this->orderInput($event['id'], $tierId));
        });

        self::assertSame(409, $early->status());
        self::assertSame("Adult tickets aren't on sale yet", $early->getMessage());
        self::assertSame(409, $late->status());
        self::assertSame('Adult ticket sales have closed', $late->getMessage());
        self::assertSame(1, $this->ordersFor($event['id']));
    }

    public function testStaffRefundAPaidOrderThroughPayments(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));
        $paymentId = $this->pay($order['reference']);

        $result = Tickets::refund($order['reference'], ['reason' => 'Guest is ill']);

        self::assertSame(['reference' => $order['reference'], 'status' => 'cancelled', 'refundAskedFor' => true], $result);
        self::assertSame([['paymentId' => $paymentId, 'amount' => 240.0, 'reason' => 'Guest is ill']], $this->refundsAskedFor());
        self::assertSame('cancelled', $this->ticketsFor($order['reference'])[1]['tk_status']);
        // Seats from a refunded order can be sold again
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));
    }

    public function testTheGateScansAPaidTicketOnceOnTheEventDay(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $this->pay($order['reference']);
        $code = $this->ticketsFor($order['reference'])[0]['tk_code'];

        $tooEarly = $this->errorFrom(function () use ($code) {
            Tickets::scan($this->event['id'], ['code' => $code]);
        });
        Clock::freezeAt('2026-11-14 17:30');
        $scanned = Tickets::scan($this->event['id'], ['code' => $code]);
        $again = $this->errorFrom(function () use ($code) {
            Tickets::scan($this->event['id'], ['code' => $code]);
        });

        self::assertSame(422, $tooEarly->status());
        self::assertSame('Tickets can only be scanned on the event day', $tooEarly->getMessage());
        self::assertSame(['code' => $code, 'status' => 'used', 'tier' => 'Adult', 'usedAt' => '2026-11-14T17:30'], $scanned);
        self::assertSame(409, $again->status());
        self::assertSame('This ticket has already been used', $again->getMessage());
        self::assertSame('used', $this->ticketsFor($order['reference'])[0]['tk_status']);
    }

    public function testTheGateRefusesOtherEventsUnknownCodesAndRefundedTickets(): void
    {
        $other = $this->createPublicEvent(['date' => '2026-11-14', 'spaceIds' => [$this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'pool', 'sp_capacity' => 50])]]);
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $this->pay($order['reference']);
        $code = $this->ticketsFor($order['reference'])[0]['tk_code'];
        Clock::freezeAt('2026-11-14 17:30');

        $wrongEvent = $this->errorFrom(function () use ($other, $code) {
            Tickets::scan($other['id'], ['code' => $code]);
        });
        $unknown = $this->errorFrom(function () {
            Tickets::scan($this->event['id'], ['code' => str_repeat('f', 32)]);
        });
        $missing = $this->errorFrom(function () {
            Tickets::scan($this->event['id'], []);
        });
        Tickets::refund($order['reference'], ['reason' => 'Ill']);
        $refunded = $this->errorFrom(function () use ($code) {
            Tickets::scan($this->event['id'], ['code' => $code]);
        });

        self::assertSame(404, $wrongEvent->status());
        self::assertSame('Ticket not found for this event', $wrongEvent->getMessage());
        self::assertSame(404, $unknown->status());
        self::assertSame(422, $missing->status());
        self::assertSame(409, $refunded->status());
        self::assertSame('This ticket is no longer valid', $refunded->getMessage());
    }

}
