<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Events;
use GrandGala\Services\Mailer;
use GrandGala\Services\PaymentGateway\Payable;
use GrandGala\Services\TicketAccess;
use GrandGala\Services\TicketOrderPayable;
use GrandGala\Services\Tickets;
use GrandGala\Tests\Support\TicketsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testTheEventPageCountsHeldSeatsUntilTheirHoldRunsOut(): void
    {
        $before = Events::publicDetail($this->event['id'])['ticketTiers'][0]['available'];
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));

        $whileHeld = Events::publicDetail($this->event['id'])['ticketTiers'][0]['available'];
        Clock::freezeAt('2026-09-04 12:15'); // the hold ends now
        $afterHold = Events::publicDetail($this->event['id'])['ticketTiers'][0]['available'];

        self::assertSame($before - 2, $whileHeld);
        self::assertSame($before, $afterHold);
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

    public function testPricesWithCentsAddUpToTheCent(): void
    {
        $event = $this->createPublicEvent(['date' => '2026-11-28', 'capacity' => 3, 'tiers' => [
            ['name' => 'Adult', 'price' => 33.33, 'allocation' => 3, 'maxPerOrder' => 3],
        ]]);

        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id'], 3));

        self::assertSame(99.99, $order['total']);
        self::assertSame(99.99, (new TicketOrderPayable())->amountDue($order['reference']));
    }

    public function testASignedInOrderBelongsToItsOwnerOnly(): void
    {
        $owner = $this->createGuest();
        $other = $this->createGuest();
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId), $owner);

        self::assertArrayNotHasKey('accessToken', $order);
        self::assertSame($owner, $this->number('SELECT to_u_id FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
        self::assertSame($order['reference'], Tickets::detail($order['reference'], $owner, null)['reference']);
        foreach ([[$other, null], [null, null]] as $who) {
            $error = $this->errorFrom(function () use ($order, $who) {
                Tickets::detail($order['reference'], $who[0], $who[1]);
            });
            self::assertSame(404, $error->status());
        }
        self::assertSame([$order['reference']], array_column(Tickets::myOrders($owner), 'reference'));
        self::assertSame([], Tickets::myOrders($other));
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

    public function testAReceiptTokenStopsWorkingAfterItExpires(): void
    {
        $token = TicketAccess::issue('GGG-TKT-2026-0001', '2026-09-05 12:00:00');

        self::assertTrue(TicketAccess::verify($token, 'GGG-TKT-2026-0001'));
        self::assertFalse(TicketAccess::verify($token, 'GGG-TKT-2026-0002'));
        Clock::freezeAt('2026-09-05 12:00');
        self::assertFalse(TicketAccess::verify($token, 'GGG-TKT-2026-0001'));
    }

    public function testTheHoldLengthComesFromTheSettings(): void
    {
        $_ENV['TICKET_HOLD_MINUTES'] = '30';

        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));

        self::assertSame('2026-09-04T12:30', $order['holdExpires']);
    }

    public function testTheHoldEndsWhenTierSalesClose(): void
    {
        $event = $this->createPublicEvent(['date' => '2026-11-28', 'tiers' => [
            ['name' => 'Adult', 'price' => 120, 'allocation' => 2, 'salesClose' => '2026-09-04T12:05'],
        ]]);

        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id']));

        self::assertSame('2026-09-04T12:05', $order['holdExpires']);
    }

    public function testTheHoldEndsWhenTheEventStarts(): void
    {
        Clock::freezeAt('2026-11-14 18:20');

        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));

        self::assertSame('2026-11-14T18:30', $order['holdExpires']);
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

    public function testTicketsAreOnlyIssuedForPaidOrders(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));

        $error = $this->errorFrom(function () use ($order) {
            Tickets::issue($this->orderId($order['reference']));
        });

        self::assertSame(409, $error->status());
        self::assertSame([], $this->ticketsFor($order['reference']));
        self::assertSame([], Mailer::sent());
    }

    public function testACancelledOrderCantBePaid(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        Tickets::cancelUnpaid($order['reference'], null, $order['accessToken']);

        $error = $this->errorFrom(function () use ($order) {
            $this->pay($order['reference']);
        });

        self::assertSame(409, $error->status());
        self::assertSame([], $this->ticketsFor($order['reference']));
    }

    public function testTheOrderDetailShowsItemsTicketsAndQrCodesOncePaid(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));
        $unpaid = Tickets::detail($order['reference'], null, $order['accessToken']);
        $this->pay($order['reference']);

        $paid = Tickets::detail($order['reference'], null, $order['accessToken']);

        self::assertSame([], $unpaid['tickets']);
        self::assertSame('paid', $paid['status']);
        self::assertSame('[test] Movie Night', $paid['eventTitle']);
        self::assertSame('2026-11-14', $paid['eventDate']);
        self::assertCount(2, $paid['tickets']);
        self::assertSame('Adult', $paid['tickets'][0]['tier']);
        self::assertSame(120.0, $paid['tickets'][0]['price']);
        self::assertSame('valid', $paid['tickets'][0]['status']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $paid['tickets'][0]['qrDataUri']);
    }

    public function testTheQrCodeIsAnSvgForAPaidTicketOnly(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $unpaid = $this->errorFrom(function () use ($order) {
            Tickets::qrSvg($order['reference'], 'abc', null, $order['accessToken']);
        });
        $this->pay($order['reference']);
        $code = $this->ticketsFor($order['reference'])[0]['tk_code'];

        $svg = Tickets::qrSvg($order['reference'], $code, null, $order['accessToken']);
        $wrongCode = $this->errorFrom(function () use ($order) {
            Tickets::qrSvg($order['reference'], str_repeat('0', 32), null, $order['accessToken']);
        });
        $noToken = $this->errorFrom(function () use ($order, $code) {
            Tickets::qrSvg($order['reference'], $code, null, null);
        });

        self::assertSame(409, $unpaid->status());
        self::assertStringContainsString('<svg', $svg);
        self::assertSame(404, $wrongCode->status());
        self::assertSame(404, $noToken->status());
    }

    public function testAPromoCodeGoesThroughPromotions(): void
    {
        // A code that doesn't exist is refused and the order isn't saved
        $error = $this->errorFrom(function () {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => 'NO-SUCH-CODE']));
        });
        self::assertSame(422, $error->status());
        self::assertSame('Promotion code is not available', $error->getMessage());
        self::assertSame(0, $this->ordersFor($this->event['id']));

        // A code of kind 'tickets' gives its discount, and the order remembers which code it used.
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => $code, 'promo_type' => 'fixed', 'promo_value' => 20, 'promo_applies_to' => '["tickets"]',
            'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ]);
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2, ['promoCode' => $code]));

        self::assertSame(240.0, $order['subtotal']);
        self::assertSame(20.0, $order['discount']);
        self::assertSame(220.0, $order['total']);
        self::assertSame($promoId, $this->number('SELECT to_promo_id FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
        // A guest with no account still holds the code, so it counts towards the limit
        $orderId = $this->orderId($order['reference']);
        self::assertSame('reserved', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$orderId]));
        self::assertSame('', $this->text('SELECT pu_user_id FROM promo_use WHERE pu_to_id = ?', [$orderId]));
    }

    // A code with one use left is held by the first order, so a second order can't take it.
    // Paying turns that hold into a use, which still counts.
    public function testATicketCodeLimitedToOneUseCantBeTakenTwice(): void
    {
        $buyerId = $this->createGuest();
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $promoId = $this->insert('promo', 'promo_id', [
            'promo_code' => $code, 'promo_type' => 'fixed', 'promo_value' => 20, 'promo_applies_to' => '["tickets"]',
            'promo_usage_limit' => 1, 'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ]);
        $first = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]), $buyerId);
        $orderId = $this->orderId($first['reference']);

        $second = $this->errorFrom(function () use ($code, $buyerId) {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]), $buyerId);
        });
        $this->pay($first['reference']);
        $afterPaying = $this->errorFrom(function () use ($code, $buyerId) {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]), $buyerId);
        });

        self::assertSame(422, $second->status());
        self::assertSame('Promotion code has reached its usage limit', $second->getMessage());
        self::assertSame('used', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$orderId]));
        self::assertSame(1, $this->number('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promoId]));
        self::assertSame(422, $afterPaying->status());
        self::assertSame('Promotion code has reached its usage limit', $afterPaying->getMessage());
    }

    // Cancelling the unpaid order gives the one use back, so another order can take it
    public function testCancellingATicketOrderGivesTheCodeBack(): void
    {
        $code = $this->ticketCodeLimitedToOne();
        $first = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));

        Tickets::cancelUnpaid($first['reference'], null, $first['accessToken']);
        $second = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));

        self::assertSame('released', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($first['reference'])]));
        self::assertSame('reserved', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($second['reference'])]));
    }

    // The same when the hold runs out and the job expires the order
    public function testAnExpiredTicketOrderGivesTheCodeBack(): void
    {
        $code = $this->ticketCodeLimitedToOne();
        $first = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));
        Clock::freezeAt('2026-09-04 12:16');

        Tickets::expireLapsedHolds();
        $second = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));

        self::assertSame('expired', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$first['reference']]));
        self::assertSame('released', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($first['reference'])]));
        self::assertSame('reserved', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($second['reference'])]));
    }

    // Staff cancelling the event cancels its unpaid orders, and their codes are given back too
    public function testCancellingTheEventGivesATicketCodeBack(): void
    {
        $code = $this->ticketCodeLimitedToOne();
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));

        Events::cancel($this->event['id'], ['reason' => 'Storm warning'], $this->staffId);

        self::assertSame('cancelled', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
        self::assertSame('released', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($order['reference'])]));
    }

    // A paid order gives its code back once the refund goes through, so another order can use it.
    public function testARefundedTicketOrderGivesTheCodeBack(): void
    {
        $code = $this->ticketCodeLimitedToOne();
        $first = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));
        $this->pay($first['reference']);
        $promoId = $this->number('SELECT to_promo_id FROM ticket_order WHERE to_reference = ?', [$first['reference']]);

        Tickets::refund($first['reference'], ['reason' => 'Guest is ill']);
        $stillUsed = $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($first['reference'])]);
        Tickets::onRefunded($first['reference'], 1);
        $second = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));

        self::assertSame('used', $stillUsed);
        self::assertSame('released', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($first['reference'])]));
        self::assertSame(0, $this->number('SELECT promo_usage_count FROM promo WHERE promo_id = ?', [$promoId]));
        self::assertSame('reserved', $this->text('SELECT pu_status FROM promo_use WHERE pu_to_id = ?', [$this->orderId($second['reference'])]));
    }

    // A guest with no account is limited by email, and signing in does not grant another use.
    public function testThePerGuestLimitCountsAGuestByTheirEmail(): void
    {
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $this->insert('promo', 'promo_id', [
            'promo_code' => $code, 'promo_type' => 'fixed', 'promo_value' => 20, 'promo_applies_to' => '["tickets"]',
            'promo_limit_per_user' => 1, 'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ]);
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]));

        $sameGuest = $this->errorFrom(function () use ($code) {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code, 'email' => 'THANDI@example.com']));
        });
        $signedIn = $this->errorFrom(function () use ($code) {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, ['promoCode' => $code]), $this->createGuest());
        });
        $someoneElse = $this->placeOrder($this->orderInput($this->event['id'], $this->childId, 1, ['promoCode' => $code, 'email' => 'sipho@example.com']));

        self::assertSame('You have reached the usage limit for this promotion', $sameGuest->getMessage());
        self::assertSame('You have reached the usage limit for this promotion', $signedIn->getMessage());
        self::assertSame(20.0, $someoneElse['discount']);
    }

    // A ticket code with a single use, valid for this test's dates
    private function ticketCodeLimitedToOne(): string
    {
        $code = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $this->insert('promo', 'promo_id', [
            'promo_code' => $code, 'promo_type' => 'fixed', 'promo_value' => 20, 'promo_applies_to' => '["tickets"]',
            'promo_usage_limit' => 1, 'promo_valid_from' => '2026-09-01', 'promo_valid_to' => '2026-12-31',
        ]);

        return $code;
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

    public function testNoTicketsAreSoldOnceTheEventHasStarted(): void
    {
        Clock::freezeAt('2026-11-14 18:30');

        $error = $this->errorFrom(function () {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        });

        self::assertSame(409, $error->status());
        self::assertSame('Ticket sales for this event have closed', $error->getMessage());
    }

    public function testACancelledEventSellsNothing(): void
    {
        \GrandGala\Services\Events::cancel($this->event['id'], ['reason' => 'Rain'], $this->staffId);

        $error = $this->errorFrom(function () {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        });

        self::assertSame(409, $error->status());
        self::assertSame('This event is not on sale', $error->getMessage());
    }

    // [changes, status, message]. 'ADULT' and 'CHILD' are replaced by the real tier ids.
    public static function badOrders(): array
    {
        return [
            'bad email' => [['email' => 'not-an-email'], 422, 'Enter a valid email address'],
            'no name' => [['name' => ' '], 422, 'Enter your name'],
            'no items' => [['items' => []], 422, 'Choose between 1 and 20 ticket tiers'],
            'zero quantity' => [['items' => [['tierId' => 'ADULT', 'quantity' => 0]]], 422, 'Each tier needs a tierId and a quantity of 1 or more'],
            'quantity as text' => [['items' => [['tierId' => 'ADULT', 'quantity' => '1']]], 422, 'Each tier needs a tierId and a quantity of 1 or more'],
            'unknown tier' => [['items' => [['tierId' => 999999999, 'quantity' => 1]]], 422, 'Choose a ticket tier that is on sale for this event'],
            'over the per-order limit' => [['items' => [['tierId' => 'ADULT', 'quantity' => 3]]], 422, 'You can buy at most 2 Adult tickets per order'],
            'the same tier twice' => [['items' => [['tierId' => 'ADULT', 'quantity' => 1], ['tierId' => 'ADULT', 'quantity' => 1]]], 422, 'Choose each tier only once'],
            'no event' => [['eventId' => 'x'], 422, 'Choose an event'],
            'unknown event' => [['eventId' => 999999999], 404, 'Event not found'],
        ];
    }

    #[DataProvider('badOrders')]
    public function testABadOrderIsRefusedAndNothingIsSaved(array $changes, int $status, string $message): void
    {
        if (isset($changes['items'])) {
            foreach ($changes['items'] as $index => $item) {
                if ($item['tierId'] === 'ADULT') {
                    $changes['items'][$index]['tierId'] = $this->adultId;
                }
            }
        }

        $error = $this->errorFrom(function () use ($changes) {
            $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 1, $changes));
        });

        self::assertSame($status, $error->status());
        self::assertSame($message, $error->getMessage());
        self::assertSame(0, $this->ordersFor($this->event['id']));
    }

    public function testAnInactiveTierCantBeBought(): void
    {
        $this->update('ticket_type', 'tt_id', $this->childId, ['tt_is_active' => 0]);

        $error = $this->errorFrom(function () {
            $this->placeOrder($this->orderInput($this->event['id'], $this->childId));
        });

        self::assertSame('Choose a ticket tier that is on sale for this event', $error->getMessage());
    }

    public function testATierFromAnotherEventCantBeBought(): void
    {
        $other = $this->createPublicEvent(['date' => '2026-11-28']);

        $error = $this->errorFrom(function () use ($other) {
            $this->placeOrder($this->orderInput($this->event['id'], $other['ticketTiers'][0]['id']));
        });

        self::assertSame('Choose a ticket tier that is on sale for this event', $error->getMessage());
    }

    public function testAGuestCanCancelAnUnpaidOrderWithTheirToken(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));

        $noToken = $this->errorFrom(function () use ($order) {
            Tickets::cancelUnpaid($order['reference'], null, null);
        });
        $result = Tickets::cancelUnpaid($order['reference'], null, $order['accessToken']);
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2)); // seats free

        self::assertSame(404, $noToken->status());
        self::assertSame(['reference' => $order['reference'], 'status' => 'cancelled'], $result);
    }

    public function testAPaidOrderCantBeCancelledByTheGuest(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $this->pay($order['reference']);

        $error = $this->errorFrom(function () use ($order) {
            Tickets::cancelUnpaid($order['reference'], null, $order['accessToken']);
        });

        self::assertSame(409, $error->status());
        self::assertSame('Paid orders are refunded by staff', $error->getMessage());
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

    public function testOnlyPaidOrdersCanBeRefundedAndAReasonIsNeeded(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));

        $unpaid = $this->errorFrom(function () use ($order) {
            Tickets::refund($order['reference'], ['reason' => 'Why not']);
        });
        $this->pay($order['reference']);
        $noReason = $this->errorFrom(function () use ($order) {
            Tickets::refund($order['reference'], []);
        });
        $unknown = $this->errorFrom(function () {
            Tickets::refund('GGG-TKT-1999-0001', ['reason' => 'x']);
        });

        self::assertSame(409, $unpaid->status());
        self::assertSame('Only paid orders can be refunded', $unpaid->getMessage());
        self::assertSame(422, $noReason->status());
        self::assertSame(404, $unknown->status());
        self::assertSame([], $this->refundsAskedFor());
    }

    public function testOnRefundedOnlyFinishesARefundThatWasAskedFor(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $this->pay($order['reference']);

        $error = $this->errorFrom(function () use ($order) {
            Tickets::onRefunded($order['reference'], 1);
        });

        self::assertSame(409, $error->status());
        self::assertSame('paid', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
    }

    public function testResendingNeedsAPaidOrder(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $unpaid = $this->errorFrom(function () use ($order) {
            Tickets::resend($order['reference']);
        });
        $this->pay($order['reference']);
        Mailer::clear();

        $result = Tickets::resend($order['reference']);

        self::assertSame(409, $unpaid->status());
        self::assertSame(['reference' => $order['reference'], 'emailSent' => true], $result);
        self::assertSame('e_tickets', Mailer::sent()[0]['template']);
        self::assertSame('thandi@example.com', Mailer::sent()[0]['to']);
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

    public function testTheAttendeeListOnlyHasPaidSeatsAndPages(): void
    {
        $paid = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId, 2));
        $this->pay($paid['reference']);
        $refunded = $this->placeOrder($this->orderInput($this->event['id'], $this->childId));
        $this->pay($refunded['reference']);
        Tickets::refund($refunded['reference'], ['reason' => 'Ill']); // no longer counts

        $page = Tickets::attendees($this->event['id'], ['pageSize' => '1', 'page' => '2']);

        self::assertSame(2, $page['total']);
        self::assertSame(2, $page['page']);
        self::assertSame(1, $page['pageSize']);
        self::assertCount(1, $page['items']);
        self::assertSame($paid['reference'], $page['items'][0]['orderReference']);
        self::assertSame('[test] Thandi', $page['items'][0]['buyerName']);
        self::assertSame('Adult', $page['items'][0]['tier']);
        // Page 2 is the second paid seat
        self::assertSame($this->ticketsFor($paid['reference'])[1]['tk_code'], $page['items'][0]['code']);
    }

    public function testTheEventsPaidOrdersAreNewestFirstWithBuyerItemsAndCodes(): void
    {
        $first = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $this->pay($first['reference']);
        $second = $this->placeOrder($this->orderInput($this->event['id'], $this->childId, 1, ['email' => 'sipho@example.com']));
        $this->pay($second['reference']);
        $this->placeOrder($this->orderInput($this->event['id'], $this->adultId)); // unpaid

        $orders = Tickets::eventOrders($this->event['id'], []);

        self::assertSame(2, $orders['total']);
        self::assertSame([$second['reference'], $first['reference']], array_column($orders['items'], 'reference'));
        self::assertSame('sipho@example.com', $orders['items'][0]['buyerEmail']);
        self::assertSame('Child', $orders['items'][0]['items'][0]['name']);
        self::assertCount(1, $orders['items'][0]['tickets']);
    }

    public function testListsForAnUnknownEventAreNotFound(): void
    {
        self::assertSame(404, $this->errorFrom(function () {
            Tickets::attendees(999999999, []);
        })->status());
        self::assertSame(404, $this->errorFrom(function () {
            Tickets::eventOrders(999999999, []);
        })->status());
    }

    public function testMyTicketsListsOnlyMyPaidOrdersWithTheirTickets(): void
    {
        $me = $this->createGuest();
        $paid = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId), $me);
        $this->pay($paid['reference']);
        $this->placeOrder($this->orderInput($this->event['id'], $this->childId), $me); // unpaid

        $tickets = Tickets::myTickets($me);

        self::assertSame([$paid['reference']], array_column($tickets, 'reference'));
        self::assertCount(1, $tickets[0]['tickets']);
        self::assertCount(2, Tickets::myOrders($me));
        self::assertSame($paid['reference'], Tickets::myOrder($paid['reference'], $me)['reference']);
        self::assertSame(404, $this->errorFrom(function () use ($paid) {
            Tickets::myOrder($paid['reference'], $this->createGuest());
        })->status());
    }

    public function testTicketOrdersArePayable(): void
    {
        $order = $this->placeOrder($this->orderInput($this->event['id'], $this->adultId));
        $payable = new TicketOrderPayable();
        $paymentId = $this->addPayment($order['reference']);

        self::assertInstanceOf(Payable::class, $payable);
        self::assertSame(120.0, $payable->amountDue($order['reference']));
        $payable->onPaid($order['reference'], $paymentId);
        $this->trackTickets($order['reference']);
        self::assertCount(1, $this->ticketsFor($order['reference']));
        Tickets::refund($order['reference'], ['reason' => 'Ill']);
        $payable->onRefunded($order['reference'], 1);
        self::assertSame('refunded', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
    }
}
