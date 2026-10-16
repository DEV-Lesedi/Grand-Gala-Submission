<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Tickets;
use GrandGala\Tests\Support\TicketsTestCase;
use GrandGala\Utils\Db;

// Two actors at the same moment. The second is tests/Support/second_*.php, its own connection.
// The first holds a transaction open so the second waits, then must see what the first saved.
class TicketsTwoAtOnceTest extends TicketsTestCase
{
    public function testTheLastTicketBoughtTwiceAtOnceSellsOnce(): void
    {
        $event = $this->createPublicEvent(['capacity' => 1, 'tiers' => [['name' => 'Adult', 'price' => 120, 'allocation' => 1]]]);
        $tierId = $event['ticketTiers'][0]['id'];
        $secondBuyerId = $this->createGuest();

        $secondBuyer = null;
        // The & lets the work below fill in $secondBuyer, so we can wait for it afterwards
        Db::transaction(function () use ($event, $tierId, $secondBuyerId, &$secondBuyer) {
            $order = Tickets::createOrder($this->orderInput($event['id'], $tierId), null);
            $this->trackOrder($order['reference']);
            // The second buyer starts before the first commit, so it waits for the locks.
            $secondBuyer = $this->startScript('second_buyer.php', [$event['id'], $tierId, 1, $secondBuyerId]);
            sleep(2);
        });
        $status = $this->waitForScript($secondBuyer);
        foreach ($this->references('SELECT to_reference FROM ticket_order WHERE to_eb_id = ? AND to_u_id = ?', [$event['id'], $secondBuyerId]) as $reference) {
            $this->trackOrder($reference);
        }

        self::assertSame('409', $status);
        self::assertSame(1, $this->ordersFor($event['id']));
    }

    public function testTwoPaymentNotificationsAtOnceIssueTheTicketsOnce(): void
    {
        $event = $this->createPublicEvent();
        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id'], 2));
        $paymentId = $this->addPayment($order['reference']);

        $second = null;
        Db::transaction(function () use ($order, $paymentId, &$second) {
            Tickets::onPaid($order['reference'], $paymentId);
            $second = $this->startScript('second_payment.php', [$order['reference'], $paymentId]);
            sleep(2);
        });
        $status = $this->waitForScript($second);
        $this->trackTickets($order['reference']);

        self::assertSame('200', $status);
        self::assertCount(2, $this->ticketsFor($order['reference']));
    }

    public function testADoubleScanAtTwoGatesLetsThePersonInOnce(): void
    {
        $event = $this->createPublicEvent();
        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id']));
        $this->pay($order['reference']);
        $code = $this->ticketsFor($order['reference'])[0]['tk_code'];
        Clock::freezeAt('2026-11-14 18:00');

        $secondGate = null;
        Db::transaction(function () use ($event, $code, &$secondGate) {
            Tickets::scan($event['id'], ['code' => $code]);
            // Scans a minute later. The same minute would make a broken UPDATE look unchanged.
            $secondGate = $this->startScript('second_scan.php', [$event['id'], $code, '2026-11-14 18:01']);
            sleep(2);
        });

        self::assertSame('409', $this->waitForScript($secondGate));
        self::assertSame('used', $this->ticketsFor($order['reference'])[0]['tk_status']);
    }

    private function references(string $sql, array $params): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $references = [];
        foreach ($statement->fetchAll() as $row) {
            $references[] = $row['to_reference'];
        }

        return $references;
    }
}
