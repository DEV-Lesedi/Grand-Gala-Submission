<?php

declare(strict_types=1);

namespace GrandGala\Tests\Support;

use GrandGala\Services\Clock;
use GrandGala\Services\Events;
use GrandGala\Services\Mailer;
use GrandGala\Services\Tickets;
use GrandGala\Utils\Db;

// Public events and tickets setup. Now is 2026-09-04 12:00.
// Own venue (500) and garden (300). Shared booking and ticket counters are restored.
abstract class TicketsTestCase extends DatabaseTestCase
{
    protected int $venueId;
    protected int $gardenId;
    protected int $staffId;

    private array $savedCounters = [];
    private bool $setSecret = false;

    // The payments addPayment() made, so their refunds can be read and removed
    private array $paymentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['booking', 'ticket_order'] as $kind) {
            $statement = Db::pdo()->prepare('SELECT rs_year, rs_next FROM reference_sequence WHERE rs_kind = ?');
            $statement->execute([$kind]);
            $this->savedCounters[$kind] = $statement->fetch();
        }

        // Guest receipts need TICKET_ACCESS_SECRET. Use a test secret if .env.testing has none.
        if (($_ENV['TICKET_ACCESS_SECRET'] ?? '') === '') {
            $_ENV['TICKET_ACCESS_SECRET'] = str_repeat('test-secret-', 4);
            $this->setSecret = true;
        }

        Mailer::clear();
        Clock::freezeAt('2026-09-04 12:00');

        $this->venueId = $this->insert('venue', 'v_id', ['v_name' => '[test] Events venue', 'v_capacity' => 500]);
        $this->gardenId = $this->createSpace([
            'sp_v_id' => $this->venueId, 'sp_type' => 'garden', 'sp_name' => '[test] Garden', 'sp_capacity' => 300,
        ]);
        $this->staffId = $this->createGuest();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedCounters as $kind => $counter) {
            $statement = Db::pdo()->prepare('UPDATE reference_sequence SET rs_year = ?, rs_next = ? WHERE rs_kind = ?');
            $statement->execute([$counter['rs_year'], $counter['rs_next'], $kind]);
        }
        if ($this->setSecret) {
            unset($_ENV['TICKET_ACCESS_SECRET']);
        }
        unset($_ENV['TICKET_HOLD_MINUTES']);
        Clock::freeze($_ENV['APP_TODAY']);
        Mailer::clear();
        // Refunds from Payments::refund point at this test's payments, so delete them first.
        foreach ($this->paymentIds as $paymentId) {
            Db::pdo()->prepare('DELETE FROM refund WHERE rf_p_id = ?')->execute([$paymentId]);
        }

        parent::tearDown();
    }

    // A valid event: 3 seats. Adult R120 (2 seats, max 2 per order) and Child R60 (1 seat).
    // $changes replaces any of the fields.
    protected function eventInput(array $changes = []): array
    {
        $input = [
            'title' => '[test] Movie Night',
            'venueId' => $this->venueId,
            'spaceIds' => [$this->gardenId],
            'date' => '2026-11-14',
            'startTime' => '18:30',
            'endTime' => '21:00',
            'capacity' => 3,
            'description' => 'Outdoor screening',
            'image' => '/events/movie.jpg',
            'tiers' => [
                ['name' => 'Adult', 'price' => 120, 'allocation' => 2, 'maxPerOrder' => 2],
                ['name' => 'Child', 'price' => 60, 'allocation' => 1, 'maxPerOrder' => 1],
            ],
        ];

        return array_merge($input, $changes);
    }

    protected function createPublicEvent(array $changes = []): array
    {
        $event = Events::create($this->eventInput($changes), $this->staffId);
        $this->trackEvent($event['id']);

        return $event;
    }

    // Tracks the booking, event, event spaces and tiers of an event the code created
    protected function trackEvent(int $eventId): void
    {
        $bookingId = $this->number('SELECT eb_b_id FROM event_booking WHERE eb_id = ?', [$eventId]);
        $this->track('booking', 'b_id', $bookingId);
        $this->track('event_booking', 'eb_id', $eventId);
        foreach ($this->ids('SELECT es_id FROM event_space WHERE es_eb_id = ?', [$eventId]) as $id) {
            $this->track('event_space', 'es_id', $id);
        }
        $this->trackTiers($eventId);
    }

    // Tracks the tiers of an event (call again after adding tiers)
    protected function trackTiers(int $eventId): void
    {
        foreach ($this->ids('SELECT tt_id FROM ticket_type WHERE tt_eb_id = ?', [$eventId]) as $id) {
            $this->track('ticket_type', 'tt_id', $id);
        }
    }

    protected function orderInput(int $eventId, int $tierId, int $quantity = 1, array $changes = []): array
    {
        $input = [
            'eventId' => $eventId,
            'name' => '[test] Thandi',
            'surname' => 'Buyer',
            'email' => 'thandi@example.com',
            'items' => [['tierId' => $tierId, 'quantity' => $quantity]],
        ];

        return array_merge($input, $changes);
    }

    // Places an order through Tickets::createOrder() and tracks its rows
    protected function placeOrder(array $input, ?int $userId = null): array
    {
        $order = Tickets::createOrder($input, $userId);
        $this->trackOrder($order['reference']);

        return $order;
    }

    protected function trackOrder(string $reference): void
    {
        $orderId = $this->orderId($reference);
        $this->track('ticket_order', 'to_id', $orderId);
        foreach ($this->ids('SELECT toi_id FROM ticket_order_item WHERE toi_to_id = ?', [$orderId]) as $id) {
            $this->track('ticket_order_item', 'toi_id', $id);
        }
    }

    // Records a successful payment and calls the payment hook, as a PayFast notice would.
    protected function pay(string $reference): int
    {
        $paymentId = $this->addPayment($reference);
        Tickets::onPaid($reference, $paymentId);
        $this->trackTickets($reference);

        return $paymentId;
    }

    protected function addPayment(string $reference): int
    {
        $orderId = $this->orderId($reference);
        $total = (float) $this->text('SELECT to_total_price FROM ticket_order WHERE to_id = ?', [$orderId]);

        $paymentId = $this->insert('payment', 'p_id', [
            'p_to_id' => $orderId,
            'p_amount' => $total,
            'p_merchant_reference' => 'TEST-' . strtoupper(bin2hex(random_bytes(6))),
            'p_status' => 'paid',
        ]);
        $this->paymentIds[] = $paymentId;

        return $paymentId;
    }

    // The refunds Payments::refund queued for this test's payments, oldest first,
    // e.g. [['paymentId' => 3, 'amount' => 240.0, 'reason' => 'Guest is ill']]
    protected function refundsAskedFor(): array
    {
        $refunds = [];
        foreach ($this->paymentIds as $paymentId) {
            $statement = Db::pdo()->prepare('SELECT rf_id, rf_p_id, rf_amount, rf_reason FROM refund WHERE rf_p_id = ?');
            $statement->execute([$paymentId]);
            foreach ($statement->fetchAll() as $row) {
                $refunds[(int) $row['rf_id']] = ['paymentId' => (int) $row['rf_p_id'], 'amount' => (float) $row['rf_amount'], 'reason' => $row['rf_reason']];
            }
        }
        ksort($refunds);

        return array_values($refunds);
    }

    protected function trackTickets(string $reference): void
    {
        foreach ($this->ids('SELECT tk_id FROM ticket WHERE tk_to_id = ?', [$this->orderId($reference)]) as $id) {
            $this->track('ticket', 'tk_id', $id);
        }
    }

    protected function orderId(string $reference): int
    {
        return $this->number('SELECT to_id FROM ticket_order WHERE to_reference = ?', [$reference]);
    }

    // The first column of the first row, as a whole number (0 when there is no row)
    protected function number(string $sql, array $params = []): int
    {
        return (int) $this->text($sql, $params);
    }

    // The first column of the first row, as text ('' when there is no row or it is NULL)
    protected function text(string $sql, array $params = []): string
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return '';
        }

        return (string) $value;
    }

    // The first column of every row, as whole numbers
    protected function ids(string $sql, array $params = []): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $ids[] = (int) array_values($row)[0];
        }

        return $ids;
    }

    // How many bookings this test's venue has. Used to prove a refused event saved nothing.
    protected function bookingsAtVenue(): int
    {
        return $this->number('SELECT COUNT(*) FROM event_booking WHERE eb_v_id = ?', [$this->venueId]);
    }

    // How many orders this event has
    protected function ordersFor(int $eventId): int
    {
        return $this->number('SELECT COUNT(*) FROM ticket_order WHERE to_eb_id = ?', [$eventId]);
    }

    protected function ticketsFor(string $reference): array
    {
        $statement = Db::pdo()->prepare('SELECT tk_code, tk_status, tk_price_snapshot, tk_tt_id FROM ticket WHERE tk_to_id = ? ORDER BY tk_id');
        $statement->execute([$this->orderId($reference)]);

        return $statement->fetchAll();
    }
}
