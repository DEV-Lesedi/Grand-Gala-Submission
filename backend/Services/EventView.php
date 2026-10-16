<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\PublicEvent;
use GrandGala\Models\TicketOrder;
use GrandGala\Models\TicketType;

/*
 * The event as the API returns it. Sale status is worked out from the clock.
 * Guests skip inactive tiers. Staff also get the reference, spaces and sales.
 */
class EventView
{
    // API shape. Guests skip inactive tiers; staff also get reference, spaces and sales.
    public static function present(array $event, bool $forStaff): array
    {
        $now = Clock::now();
        $eventStart = Events::startOf($event);
        $seats = TicketOrder::seatsTaken($event['id'], Holds::cutOff());

        $tiers = [];
        $hasActiveTier = false;
        $anyOnSale = false;
        $anyNotYet = false;
        $ticketsLeft = 0;
        $ticketsSold = 0;
        $fromPrice = null;
        foreach (TicketType::forEvent($event['id']) as $tier) {
            if (!$forStaff && !$tier['isActive']) {
                continue;
            }

            $taken = 0;
            $sold = 0;
            if (isset($seats[$tier['id']])) {
                $taken = $seats[$tier['id']]['taken'];
                $sold = $seats[$tier['id']]['sold'];
            }
            $available = max(0, $tier['allocation'] - $taken);
            $saleStatus = self::tierSaleStatus($tier, $available, $now, $eventStart);
            $ticketsSold = $ticketsSold + $sold;

            if ($tier['isActive']) {
                $hasActiveTier = true;
                $ticketsLeft = $ticketsLeft + $available;
                if ($saleStatus === 'on_sale') {
                    $anyOnSale = true;
                }
                if ($saleStatus === 'not_on_sale_yet') {
                    $anyNotYet = true;
                }
                if ($fromPrice === null || $tier['price'] < $fromPrice) {
                    $fromPrice = $tier['price'];
                }
            }

            $tiers[] = [
                'id' => $tier['id'],
                'name' => $tier['name'],
                'description' => $tier['description'],
                'price' => $tier['price'],
                'allocation' => $tier['allocation'],
                'available' => $available,
                'sold' => $sold,
                'maxPerOrder' => $tier['maxPerOrder'],
                'salesOpen' => Events::apiDateTime($tier['salesOpen']),
                'salesClose' => Events::apiDateTime($tier['salesClose']),
                'active' => $tier['isActive'],
                'saleStatus' => $saleStatus,
            ];
        }

        // The event as a whole: on sale if any tier is; otherwise not yet, sold out or closed
        $saleStatus = 'sales_closed';
        if ($event['status'] === 'confirmed' && $hasActiveTier) {
            if ($anyOnSale) {
                $saleStatus = 'on_sale';
            } elseif ($anyNotYet) {
                $saleStatus = 'not_on_sale_yet';
            } elseif ($ticketsLeft === 0) {
                $saleStatus = 'sold_out';
            }
        }

        $result = [
            'id' => $event['id'],
            'slug' => 'event-' . $event['id'],
            'title' => $event['title'],
            'date' => $event['date'],
            'startTime' => $event['startTime'],
            'endTime' => $event['endTime'],
            'venue' => $event['venueName'],
            'venueId' => $event['venueId'],
            'capacity' => $event['capacity'],
            'description' => (string) $event['description'],
            'image' => $event['image'],
            'status' => $event['status'],
            'saleStatus' => $saleStatus,
            'ticketTiers' => $tiers,
            'fromPrice' => $fromPrice,
        ];
        if ($forStaff) {
            $result['reference'] = $event['reference'];
            $result['spaceIds'] = PublicEvent::spaceIds($event['id']);
            $result['ticketsSold'] = $ticketsSold;
            $result['revenue'] = TicketOrder::revenue($event['id']);
        }

        return $result;
    }

    // One tier's sale status right now: sales_closed, sold_out, not_on_sale_yet or on_sale
    private static function tierSaleStatus(array $tier, int $available, string $now, string $eventStart): string
    {
        if (!$tier['isActive']) {
            return 'sales_closed';
        }
        if ($tier['salesClose'] !== null && $now >= $tier['salesClose']) {
            return 'sales_closed';
        }
        if ($now >= $eventStart) {
            return 'sales_closed';
        }
        if ($available === 0) {
            return 'sold_out';
        }
        if ($tier['salesOpen'] !== null && $now < $tier['salesOpen']) {
            return 'not_on_sale_yet';
        }

        return 'on_sale';
    }
}
