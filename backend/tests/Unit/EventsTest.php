<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Events;
use GrandGala\Services\Tickets;
use GrandGala\Tests\Support\TicketsTestCase;

// Public events: staff create, edit, and cancel them; guests browse them.
// Now is 2026-09-04 12:00. The test event is 2026-11-14 from 18:30 in a garden.
class EventsTest extends TicketsTestCase
{
    public function testCreatingAPublicEventSavesTheBookingEventSpacesAndTiers(): void
    {
        $event = $this->createPublicEvent(['tiers' => [
            ['name' => 'Adult', 'price' => 120.5, 'allocation' => 2, 'maxPerOrder' => 2],
            ['name' => 'Child', 'price' => 60, 'allocation' => 1, 'maxPerOrder' => 1],
        ]]);

        $row = $this->row('SELECT b.b_status, b.b_notes, b.b_u_id, bt.bt_name, eb.eb_visibility, eb.eb_is_venue_hosted, eb.eb_description,
                                  eb.eb_image_url, eb.eb_capacity, eb.eb_event_date, eb.eb_start_time
                           FROM event_booking eb JOIN booking b ON b.b_id = eb.eb_b_id JOIN booking_type bt ON bt.bt_id = b.b_bt_id
                           WHERE eb.eb_id = ?', [$event['id']]);
        self::assertSame('confirmed', $row['b_status']);
        self::assertSame('event', $row['bt_name']);
        self::assertSame($this->staffId, (int) $row['b_u_id']);
        self::assertNull($row['b_notes']);
        self::assertSame('public', $row['eb_visibility']);
        self::assertSame(1, (int) $row['eb_is_venue_hosted']);
        self::assertSame('Outdoor screening', $row['eb_description']);
        self::assertSame('/events/movie.jpg', $row['eb_image_url']);
        self::assertSame(3, (int) $row['eb_capacity']);
        self::assertSame('2026-11-14', $row['eb_event_date']);
        self::assertSame([$this->gardenId], $this->ids('SELECT es_sp_id FROM event_space WHERE es_eb_id = ?', [$event['id']]));

        self::assertSame('[test] Movie Night', $event['title']);
        self::assertSame('18:30', $event['startTime']);
        self::assertSame('on_sale', $event['saleStatus']);
        self::assertSame(60.0, $event['fromPrice']);
        self::assertCount(2, $event['ticketTiers']);
        self::assertSame(120.5, $event['ticketTiers'][0]['price']);
        self::assertSame(2, $event['ticketTiers'][0]['available']);
        self::assertSame([$this->gardenId], $event['spaceIds']);
        self::assertSame(0.0, $event['revenue']);
    }

    public function testTheSameSpaceCantHostTwoEventsOnOneDay(): void
    {
        $this->createPublicEvent();

        $error = $this->errorFrom(function () {
            $this->createPublicEvent(['title' => '[test] Second']);
        });

        self::assertSame(409, $error->status());
        self::assertSame(1, $this->bookingsAtVenue());
    }

    public function testWhenSavingFailsHalfwayEverythingIsUndone(): void
    {
        // "Cafe" and "Café" are the same name, so the later tier insert fails after the booking.
        $before = $this->bookingsAtVenue();
        $next = $this->number("SELECT rs_next FROM reference_sequence WHERE rs_kind = 'booking'");

        try {
            $this->createPublicEvent(['tiers' => [
                ['name' => 'Cafe', 'price' => 10, 'allocation' => 1],
                ['name' => 'Café', 'price' => 10, 'allocation' => 1],
            ]]);
            self::fail('Expected the second tier to fail');
        } catch (\PDOException $error) {
            self::assertStringContainsString('Duplicate', $error->getMessage());
        }

        self::assertSame($before, $this->bookingsAtVenue());
        self::assertSame($next, $this->number("SELECT rs_next FROM reference_sequence WHERE rs_kind = 'booking'"));
    }

    // Exactly 12 days' notice is enough (now is 2026-09-04)
    public function testAnEventTwelveDaysAheadCanBeCreated(): void
    {
        $event = $this->createPublicEvent(['date' => '2026-09-16']);

        self::assertSame('2026-09-16', $event['date']);
    }

    public function testTheCapacityMustFitTheChosenSpaces(): void
    {
        $poolId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'pool', 'sp_capacity' => 20]);

        $error = $this->errorFrom(function () use ($poolId) {
            $this->createPublicEvent(['spaceIds' => [$poolId], 'capacity' => 21]);
        });

        self::assertSame(422, $error->status());
        self::assertSame("The capacity can't be more than the chosen spaces hold (20)", $error->getMessage());
    }

    public function testThePublicListOnlyShowsUpcomingPublicEventsOnSale(): void
    {
        $event = $this->createPublicEvent();
        $past = $this->createEvent($this->gardenId, '2026-09-01', 'confirmed', null, 'public');
        $this->update('event_booking', 'eb_id', $past['eventId'], ['eb_is_venue_hosted' => 1]);
        $poolId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'pool']);
        $private = $this->createEvent($poolId, '2026-11-21', 'confirmed');
        // A private event stays hidden even if the venue hosts it. Only visibility 'public' shows.
        $this->update('event_booking', 'eb_id', $private['eventId'], ['eb_is_venue_hosted' => 1]);
        $cancelled = $this->createPublicEvent(['date' => '2026-11-28']);
        Events::cancel($cancelled['id'], ['reason' => 'Rain'], $this->staffId);

        $ids = array_column(Events::publicList(), 'id');

        self::assertContains($event['id'], $ids);
        self::assertNotContains($past['eventId'], $ids);
        self::assertNotContains($private['eventId'], $ids);
        self::assertNotContains($cancelled['id'], $ids);
    }

    public function testANewPriceOnlyAppliesToNewOrders(): void
    {
        $event = $this->createPublicEvent();
        $adult = $event['ticketTiers'][0];
        $order = $this->placeOrder($this->orderInput($event['id'], $adult['id']));

        Events::saveTiers($event['id'], ['tiers' => [['id' => $adult['id'], 'name' => 'Adult', 'price' => 999, 'allocation' => 2]]]);
        $this->pay($order['reference']);

        self::assertSame(120.0, (float) $this->ticketsFor($order['reference'])[0]['tk_price_snapshot']);
    }

    public function testCancellingAnEventRefundsEveryPaidOrderAndCancelsUnpaidOnes(): void
    {
        $event = $this->createPublicEvent(['capacity' => 4, 'tiers' => [
            ['name' => 'Adult', 'price' => 120.5, 'allocation' => 4, 'maxPerOrder' => 4],
        ]]);
        $tierId = $event['ticketTiers'][0]['id'];
        $paidA = $this->placeOrder($this->orderInput($event['id'], $tierId, 2));
        $paymentA = $this->pay($paidA['reference']);
        $paidB = $this->placeOrder($this->orderInput($event['id'], $tierId, 1));
        $paymentB = $this->pay($paidB['reference']);
        $unpaid = $this->placeOrder($this->orderInput($event['id'], $tierId, 1));

        $result = Events::cancel($event['id'], ['reason' => 'Storm warning'], $this->staffId);

        self::assertSame(['eventId' => $event['id'], 'status' => 'cancelled', 'refundsAskedFor' => 2], $result);
        self::assertSame([
            ['paymentId' => $paymentA, 'amount' => 241.0, 'reason' => 'Storm warning'],
            ['paymentId' => $paymentB, 'amount' => 120.5, 'reason' => 'Storm warning'],
        ], $this->refundsAskedFor());
        self::assertSame('cancelled', $this->text('SELECT b.b_status FROM booking b JOIN event_booking eb ON eb.eb_b_id = b.b_id WHERE eb.eb_id = ?', [$event['id']]));
        self::assertSame('cancelled', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$unpaid['reference']]));
        self::assertSame('cancelled', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$paidA['reference']]));
        foreach ($this->ticketsFor($paidA['reference']) as $ticket) {
            self::assertSame('cancelled', $ticket['tk_status']);
        }
        // The refund finishing later marks the order refunded (Payable::onRefunded)
        Tickets::onRefunded($paidA['reference'], 1);
        self::assertSame('refunded', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$paidA['reference']]));
        self::assertSame('refunded', $this->ticketsFor($paidA['reference'])[0]['tk_status']);
    }

    private function row(string $sql, array $params): array
    {
        $statement = \GrandGala\Utils\Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetch();
    }
}
