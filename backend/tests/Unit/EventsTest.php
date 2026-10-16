<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\Clock;
use GrandGala\Services\Events;
use GrandGala\Services\Tickets;
use GrandGala\Tests\Support\TicketsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testTheReferenceComesFromTheBookingCounter(): void
    {
        $next = $this->number("SELECT rs_next FROM reference_sequence WHERE rs_kind = 'booking'");

        $event = $this->createPublicEvent();

        self::assertSame('GGG-2026-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT), $event['reference']);
    }

    public function testANewEventShowsInThePublicList(): void
    {
        $event = $this->createPublicEvent();

        self::assertContains($event['id'], array_column(Events::publicList(), 'id'));
        self::assertSame('[test] Movie Night', Events::publicDetail($event['id'])['title']);
    }

    // Availability::reserve (mode public_event) is the one place that says a space is free.
    public function testABlackedOutSpaceIsRefusedAndNothingIsSaved(): void
    {
        $this->insert('space_blackout', 'sbk_id', [
            'sbk_sp_id' => $this->gardenId, 'sbk_start_date' => '2026-11-14', 'sbk_end_date' => '2026-11-14', 'sbk_reason' => '[test] Repairs',
        ]);
        $before = $this->bookingsAtVenue();

        $error = $this->errorFrom(function () {
            $this->createPublicEvent();
        });

        self::assertSame(409, $error->status());
        self::assertSame($before, $this->bookingsAtVenue());
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

    public function testAPublicEventCanShareADayWithGuestsStayingOver(): void
    {
        // Public events leave rooms open, so a stay at the venue that night does not block it.
        $villaId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_is_accommodation' => 1]);
        $this->createStay($villaId, '2026-11-13', '2026-11-15', 'confirmed');

        $event = $this->createPublicEvent();

        self::assertSame('2026-11-14', $event['date']);
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

    // [changes to the valid event, the error message]
    public static function badEvents(): array
    {
        return [
            'tier names repeat ignoring case' => [['tiers' => [['name' => 'Adult', 'price' => 1, 'allocation' => 1], ['name' => 'adult', 'price' => 1, 'allocation' => 1]]], 'Tier names must be different (ignoring case and spaces)'],
            'tier names repeat ignoring spaces' => [['tiers' => [['name' => 'Ad ult', 'price' => 1, 'allocation' => 1], ['name' => 'adult', 'price' => 1, 'allocation' => 1]]], 'Tier names must be different (ignoring case and spaces)'],
            'more tickets than seats' => [['capacity' => 2], 'The tiers have more tickets than the event has seats'],
            'no tiers' => [['tiers' => []], 'Add between 1 and 20 ticket tiers'],
            'not a real date' => [['date' => '2026-02-30'], 'Event date must be YYYY-MM-DD'],
            'a past date' => [['date' => '2026-09-03'], 'The event must start in the future'],
            'earlier today' => [['date' => '2026-09-04', 'startTime' => '11:00'], 'The event must start in the future'],
            // Same lead time as the enquiry form (Enquiries::LEAD_DAYS). Now is 2026-09-04.
            'later today' => [['date' => '2026-09-04', 'startTime' => '18:00'], 'Event dates must be at least 12 days from today'],
            'only 11 days ahead' => [['date' => '2026-09-15'], 'Event dates must be at least 12 days from today'],
            'ends before it starts' => [['endTime' => '17:00'], 'The end time must be after the start time'],
            'a bad time' => [['startTime' => '25:00'], 'Start time must be HH:MM'],
            'no title' => [['title' => '  '], 'Give the event a title'],
            'the same space twice' => [['spaceIds' => [1, 1]], 'Choose each space only once'],
            'no spaces' => [['spaceIds' => []], 'Choose at least one space'],
            'an unsafe image' => [['image' => 'javascript:alert(1)'], 'The image must be a path like /events/photo.jpg or an https:// link'],
            'a price with 3 decimals' => [['tiers' => [['name' => 'Adult', 'price' => 10.555, 'allocation' => 1]]], 'The price can have at most 2 decimals'],
            'a price as text' => [['tiers' => [['name' => 'Adult', 'price' => '10', 'allocation' => 1]]], 'The price must be a number'],
            'a negative allocation' => [['tiers' => [['name' => 'Adult', 'price' => 10, 'allocation' => -1]]], 'A tier needs a ticket allocation of 0 or more'],
            'a per-order limit of 0' => [['tiers' => [['name' => 'Adult', 'price' => 10, 'allocation' => 1, 'maxPerOrder' => 0]]], 'The per-order limit must be from 1 to 255'],
            'sales close after the start' => [['tiers' => [['name' => 'Adult', 'price' => 10, 'allocation' => 1, 'salesClose' => '2026-11-14T19:00']]], 'Ticket sales must close by the time the event starts'],
            'sales close before they open' => [['tiers' => [['name' => 'Adult', 'price' => 10, 'allocation' => 1, 'salesOpen' => '2026-10-02T10:00', 'salesClose' => '2026-10-01T10:00']]], 'Ticket sales must close after they open'],
            'a bad sales date' => [['tiers' => [['name' => 'Adult', 'price' => 10, 'allocation' => 1, 'salesOpen' => '2026-10-02 10:00']]], 'Sales open must be YYYY-MM-DDTHH:MM'],
        ];
    }

    // Exactly 12 days' notice is enough (now is 2026-09-04)
    public function testAnEventTwelveDaysAheadCanBeCreated(): void
    {
        $event = $this->createPublicEvent(['date' => '2026-09-16']);

        self::assertSame('2026-09-16', $event['date']);
    }

    #[DataProvider('badEvents')]
    public function testABadEventIsRefusedAndNothingIsSaved(array $changes, string $message): void
    {
        $error = $this->errorFrom(function () use ($changes) {
            $this->createPublicEvent($changes);
        });

        self::assertSame(422, $error->status());
        self::assertSame($message, $error->getMessage());
        self::assertSame(0, $this->bookingsAtVenue());
    }

    public function testMoreThanTwentyTiersIsRefused(): void
    {
        $tiers = [];
        for ($i = 1; $i <= 21; $i++) {
            $tiers[] = ['name' => 'Tier ' . $i, 'price' => 10, 'allocation' => 0];
        }

        $error = $this->errorFrom(function () use ($tiers) {
            $this->createPublicEvent(['tiers' => $tiers]);
        });

        self::assertSame('Add between 1 and 20 ticket tiers', $error->getMessage());
    }

    public function testTheCapacityMustFitTheVenue(): void
    {
        $this->update('venue', 'v_id', $this->venueId, ['v_capacity' => 2]);

        $error = $this->errorFrom(function () {
            $this->createPublicEvent();
        });

        self::assertSame(422, $error->status());
        self::assertSame("The capacity can't be more than the venue holds (2)", $error->getMessage());
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

    public function testSpacesTogetherCanHoldMoreThanOne(): void
    {
        $poolId = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'pool', 'sp_capacity' => 20]);

        $event = $this->createPublicEvent(['spaceIds' => [$this->gardenId, $poolId], 'capacity' => 320]);

        self::assertSame(320, $event['capacity']);
        self::assertCount(2, $event['spaceIds']);
    }

    public function testSpacesMustBeHireableAndAtTheEventsVenue(): void
    {
        $otherVenueSpace = $this->createSpace(['sp_type' => 'garden', 'sp_capacity' => 300]);
        $notHireable = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'garden', 'sp_is_hireable' => 0, 'sp_capacity' => 300]);
        $switchedOff = $this->createSpace(['sp_v_id' => $this->venueId, 'sp_type' => 'garden', 'sp_is_active' => 0, 'sp_capacity' => 300]);

        $wrongVenue = $this->errorFrom(function () use ($otherVenueSpace) {
            $this->createPublicEvent(['spaceIds' => [$otherVenueSpace]]);
        });
        $cantHire = $this->errorFrom(function () use ($notHireable) {
            $this->createPublicEvent(['spaceIds' => [$notHireable]]);
        });
        $off = $this->errorFrom(function () use ($switchedOff) {
            $this->createPublicEvent(['spaceIds' => [$switchedOff]]);
        });

        self::assertSame("Choose spaces at the event's venue", $wrongVenue->getMessage());
        self::assertSame("This space can't be hired for events", $cantHire->getMessage());
        self::assertSame(404, $off->status());
        self::assertSame(0, $this->bookingsAtVenue());
    }

    public function testASwitchedOffVenueIsRefused(): void
    {
        $this->update('venue', 'v_id', $this->venueId, ['v_is_active' => 0]);

        $error = $this->errorFrom(function () {
            $this->createPublicEvent();
        });

        self::assertSame(404, $error->status());
        self::assertSame('Venue not found', $error->getMessage());
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

    public function testPrivateAndCancelledEventsAreNotFoundForGuests(): void
    {
        $private = $this->createEvent($this->gardenId, '2026-11-21', 'confirmed');
        $this->update('event_booking', 'eb_id', $private['eventId'], ['eb_is_venue_hosted' => 1]);
        $cancelled = $this->createPublicEvent(['date' => '2026-11-28']);
        Events::cancel($cancelled['id'], ['reason' => 'Rain'], $this->staffId);

        foreach ([$private['eventId'], $cancelled['id'], 999999999] as $eventId) {
            $error = $this->errorFrom(function () use ($eventId) {
                Events::publicDetail($eventId);
            });
            self::assertSame(404, $error->status());
            self::assertSame('Event not found', $error->getMessage());
        }
    }

    public function testStaffCanSeeACancelledEventButNotAPrivateOne(): void
    {
        $private = $this->createEvent($this->gardenId, '2026-11-21', 'confirmed');
        $cancelled = $this->createPublicEvent(['date' => '2026-11-28']);
        Events::cancel($cancelled['id'], ['reason' => 'Rain'], $this->staffId);

        self::assertSame('cancelled', Events::staffDetail($cancelled['id'])['status']);
        self::assertSame(404, $this->errorFrom(function () use ($private) {
            Events::staffDetail($private['eventId']);
        })->status());
    }

    public function testTheStaffListShowsUpcomingEventsWithRevenueButNotCancelledOnes(): void
    {
        $event = $this->createPublicEvent();
        $cancelled = $this->createPublicEvent(['date' => '2026-11-28']);
        Events::cancel($cancelled['id'], ['reason' => 'Rain'], $this->staffId);
        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id'], 2));
        $this->pay($order['reference']);
        // Unpaid: not revenue, not sold
        $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][1]['id']));

        $mine = null;
        $ids = [];
        foreach (Events::staffList() as $listed) {
            $ids[] = $listed['id'];
            if ($listed['id'] === $event['id']) {
                $mine = $listed;
            }
        }

        self::assertNotContains($cancelled['id'], $ids);
        self::assertSame(240.0, $mine['revenue']);
        self::assertSame(2, $mine['ticketsSold']);
        self::assertSame(2, $mine['ticketTiers'][0]['sold']);
    }

    public function testInactiveTiersAreHiddenFromGuestsAndTheEventShowsSalesClosed(): void
    {
        $event = $this->createPublicEvent();
        foreach ($event['ticketTiers'] as $tier) {
            $this->update('ticket_type', 'tt_id', $tier['id'], ['tt_is_active' => 0]);
        }

        $public = Events::publicDetail($event['id']);
        $staff = Events::staffDetail($event['id']);

        self::assertSame('sales_closed', $public['saleStatus']);
        self::assertSame([], $public['ticketTiers']);
        self::assertNull($public['fromPrice']);
        self::assertCount(2, $staff['ticketTiers']);
    }

    public function testSaleStatusIsWorkedOutFromTheClock(): void
    {
        $event = $this->createPublicEvent(['tiers' => [
            ['name' => 'Adult', 'price' => 120, 'allocation' => 2, 'salesOpen' => '2026-10-01T09:00', 'salesClose' => '2026-11-01T09:00'],
        ]]);

        self::assertSame('not_on_sale_yet', Events::publicDetail($event['id'])['saleStatus']);
        Clock::freezeAt('2026-10-01 09:00');
        self::assertSame('on_sale', Events::publicDetail($event['id'])['saleStatus']);
        Clock::freezeAt('2026-11-01 09:00');
        self::assertSame('sales_closed', Events::publicDetail($event['id'])['saleStatus']);
    }

    public function testASoldOutTierAndEventSaySoldOut(): void
    {
        $event = $this->createPublicEvent(['capacity' => 1, 'tiers' => [['name' => 'Adult', 'price' => 120, 'allocation' => 1]]]);
        $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id']));

        $detail = Events::publicDetail($event['id']);

        self::assertSame('sold_out', $detail['saleStatus']);
        self::assertSame('sold_out', $detail['ticketTiers'][0]['saleStatus']);
        self::assertSame(0, $detail['ticketTiers'][0]['available']);
    }

    public function testStaffCanChangeTheTitleDescriptionImageAndCapacity(): void
    {
        $event = $this->createPublicEvent();

        $edited = Events::update($event['id'], [
            'title' => '[test] New title', 'description' => 'New words', 'image' => 'https://example.com/a.jpg', 'capacity' => 10,
        ]);

        self::assertSame('[test] New title', $edited['title']);
        self::assertSame('New words', $edited['description']);
        self::assertSame('https://example.com/a.jpg', $edited['image']);
        self::assertSame(10, $edited['capacity']);
        self::assertSame('New words', $this->text('SELECT eb_description FROM event_booking WHERE eb_id = ?', [$event['id']]));
    }

    public function testTheDateVenueAndSpacesAreFixedAfterCreation(): void
    {
        $event = $this->createPublicEvent();

        foreach (['date' => '2026-11-15', 'venueId' => 1, 'spaceIds' => [1], 'startTime' => '10:00'] as $field => $value) {
            $error = $this->errorFrom(function () use ($event, $field, $value) {
                Events::update($event['id'], [$field => $value]);
            });
            self::assertSame(422, $error->status());
            self::assertSame('The date, times, venue and spaces are fixed. Cancel the event and create a new one', $error->getMessage());
        }
        self::assertSame('2026-11-14', $this->text('SELECT eb_event_date FROM event_booking WHERE eb_id = ?', [$event['id']]));
    }

    public function testTheCapacityCantDropBelowTheTiersOrRiseAboveTheSpaces(): void
    {
        $event = $this->createPublicEvent();

        $belowTiers = $this->errorFrom(function () use ($event) {
            Events::update($event['id'], ['capacity' => 2]);
        });
        $aboveSpaces = $this->errorFrom(function () use ($event) {
            Events::update($event['id'], ['capacity' => 301]);
        });

        self::assertSame('The tiers have more tickets than the event has seats', $belowTiers->getMessage());
        self::assertSame("The capacity can't be more than the chosen spaces hold (300)", $aboveSpaces->getMessage());
    }

    public function testACancelledEventCantBeEdited(): void
    {
        $event = $this->createPublicEvent();
        Events::cancel($event['id'], ['reason' => 'Rain'], $this->staffId);

        $error = $this->errorFrom(function () use ($event) {
            Events::update($event['id'], ['title' => '[test] Again']);
        });

        self::assertSame(409, $error->status());
    }

    public function testStaffCanAddATierAndChangeOne(): void
    {
        $event = $this->createPublicEvent(['capacity' => 10]);
        $adult = $event['ticketTiers'][0];

        $saved = Events::saveTiers($event['id'], ['tiers' => [
            ['id' => $adult['id'], 'name' => 'Adult', 'price' => 150.25, 'allocation' => 5, 'maxPerOrder' => 4],
            ['name' => 'VIP', 'price' => 500, 'allocation' => 2],
        ]]);
        $this->trackTiers($event['id']);

        self::assertCount(3, $saved['ticketTiers']);
        self::assertSame(150.25, $saved['ticketTiers'][0]['price']);
        self::assertSame(5, $saved['ticketTiers'][0]['allocation']);
        self::assertSame('Child', $saved['ticketTiers'][1]['name']); // unsent tiers stay
        self::assertSame('VIP', $saved['ticketTiers'][2]['name']);
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

    public function testAnAllocationCantGoBelowTicketsSoldOrHeld(): void
    {
        $event = $this->createPublicEvent();
        $adult = $event['ticketTiers'][0];
        $this->placeOrder($this->orderInput($event['id'], $adult['id'], 2)); // held, not paid yet

        $error = $this->errorFrom(function () use ($event, $adult) {
            Events::saveTiers($event['id'], ['tiers' => [['id' => $adult['id'], 'name' => 'Adult', 'price' => 120, 'allocation' => 1]]]);
        });

        self::assertSame(409, $error->status());
        self::assertSame("Adult can't have fewer than the 2 tickets already sold or held", $error->getMessage());
    }

    public function testAnExpiredHoldNoLongerProtectsTheAllocation(): void
    {
        $event = $this->createPublicEvent();
        $adult = $event['ticketTiers'][0];
        $this->placeOrder($this->orderInput($event['id'], $adult['id'], 2));
        Clock::freezeAt('2026-09-04 12:16');

        $saved = Events::saveTiers($event['id'], ['tiers' => [['id' => $adult['id'], 'name' => 'Adult', 'price' => 120, 'allocation' => 0]]]);

        self::assertSame(0, $saved['ticketTiers'][0]['allocation']);
    }

    public function testTierChangesStillCheckNamesCapacityAndOwnership(): void
    {
        $event = $this->createPublicEvent();
        $other = $this->createPublicEvent(['date' => '2026-11-28']);
        $adult = $event['ticketTiers'][0];

        $clash = $this->errorFrom(function () use ($event) {
            Events::saveTiers($event['id'], ['tiers' => [['name' => ' CHILD', 'price' => 1, 'allocation' => 0]]]);
        });
        $tooMany = $this->errorFrom(function () use ($event, $adult) {
            Events::saveTiers($event['id'], ['tiers' => [['id' => $adult['id'], 'name' => 'Adult', 'price' => 1, 'allocation' => 3]]]);
        });
        $notThisEvent = $this->errorFrom(function () use ($event, $other) {
            Events::saveTiers($event['id'], ['tiers' => [['id' => $other['ticketTiers'][0]['id'], 'name' => 'Adult', 'price' => 1, 'allocation' => 1]]]);
        });
        $repeated = $this->errorFrom(function () use ($event, $adult) {
            Events::saveTiers($event['id'], ['tiers' => [
                ['id' => $adult['id'], 'name' => 'Adult', 'price' => 1, 'allocation' => 1],
                ['id' => $adult['id'], 'name' => 'Adult 2', 'price' => 1, 'allocation' => 1],
            ]]);
        });

        self::assertSame('Tier names must be different (ignoring case and spaces)', $clash->getMessage());
        self::assertSame('The tiers have more tickets than the event has seats', $tooMany->getMessage());
        self::assertSame("A tier in the list doesn't belong to this event, or is listed twice", $notThisEvent->getMessage());
        self::assertSame("A tier in the list doesn't belong to this event, or is listed twice", $repeated->getMessage());
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM ticket_type WHERE tt_eb_id = ?', [$event['id']]));
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

    public function testAnEventCanOnlyBeCancelledOnceAndNeedsAReason(): void
    {
        $event = $this->createPublicEvent();
        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id']));
        $this->pay($order['reference']);

        $noReason = $this->errorFrom(function () use ($event) {
            Events::cancel($event['id'], ['reason' => ' '], $this->staffId);
        });
        Events::cancel($event['id'], ['reason' => 'Rain'], $this->staffId);
        $again = $this->errorFrom(function () use ($event) {
            Events::cancel($event['id'], ['reason' => 'Rain'], $this->staffId);
        });

        self::assertSame(422, $noReason->status());
        self::assertSame('Give a reason for cancelling', $noReason->getMessage());
        self::assertSame(409, $again->status());
        self::assertCount(1, $this->refundsAskedFor());
    }

    public function testAPaidOrderWithNoPaymentStopsTheWholeCancellation(): void
    {
        $event = $this->createPublicEvent();
        $order = $this->placeOrder($this->orderInput($event['id'], $event['ticketTiers'][0]['id']));
        $this->pay($order['reference']);
        $this->update('payment', 'p_id', $this->number('SELECT p_id FROM payment WHERE p_to_id = ?', [$this->orderId($order['reference'])]), ['p_status' => 'failed']);

        $error = $this->errorFrom(function () use ($event) {
            Events::cancel($event['id'], ['reason' => 'Rain'], $this->staffId);
        });

        self::assertSame(409, $error->status());
        self::assertSame('paid', $this->text('SELECT to_status FROM ticket_order WHERE to_reference = ?', [$order['reference']]));
        self::assertSame('confirmed', $this->text('SELECT b.b_status FROM booking b JOIN event_booking eb ON eb.eb_b_id = b.b_id WHERE eb.eb_id = ?', [$event['id']]));
        self::assertSame([], $this->refundsAskedFor());
    }

    private function row(string $sql, array $params): array
    {
        $statement = \GrandGala\Utils\Db::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetch();
    }
}
