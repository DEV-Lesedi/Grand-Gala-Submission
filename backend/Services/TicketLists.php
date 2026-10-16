<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\PublicEvent;
use GrandGala\Models\Ticket;
use GrandGala\Models\TicketOrder;
use GrandGala\Utils\HttpError;

/*
 * Staff lists for one public event: one row per seat, and one row per paid order.
 */
class TicketLists
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    // One row per seat of a paid order. Optional page and pageSize.
    public static function attendees(int $eventId, array $query): array
    {
        self::findEvent($eventId);
        $page = self::pageNumber($query);
        $pageSize = self::pageSize($query);

        $result = Ticket::attendeesPage($eventId, $pageSize, $page);

        return ['items' => $result['attendees'], 'total' => $result['total'], 'page' => $page, 'pageSize' => $pageSize];
    }

    // One page of the event's paid orders, newest first, with the buyer, lines and ticket codes
    public static function eventOrders(int $eventId, array $query): array
    {
        self::findEvent($eventId);
        $page = self::pageNumber($query);
        $pageSize = self::pageSize($query);

        $result = TicketOrder::paidPage($eventId, $pageSize, $page);
        $orders = [];
        foreach ($result['orders'] as $order) {
            $row = TicketView::present($order, true);
            $row['buyerName'] = $order['buyerName'];
            $row['buyerSurname'] = $order['buyerSurname'];
            $row['buyerEmail'] = $order['buyerEmail'];
            $row['buyerPhone'] = $order['buyerPhone'];
            $orders[] = $row;
        }

        return ['items' => $orders, 'total' => $result['total'], 'page' => $page, 'pageSize' => $pageSize];
    }

    // ?page=2 gives 2. Anything that isn't a whole number from 1 up means page 1.
    private static function pageNumber(array $query): int
    {
        $page = $query['page'] ?? '1';
        if (is_string($page) && ctype_digit($page) && (int) $page >= 1) {
            return (int) $page;
        }

        return 1;
    }

    // ?pageSize=50 gives 50, at most 100. Anything else means 20.
    private static function pageSize(array $query): int
    {
        $size = $query['pageSize'] ?? '';
        if (is_string($size) && ctype_digit($size) && (int) $size >= 1) {
            return min((int) $size, self::MAX_PAGE_SIZE);
        }

        return self::DEFAULT_PAGE_SIZE;
    }

    // A public event for staff lists and the gate, or 404
    private static function findEvent(int $eventId): array
    {
        $event = PublicEvent::find($eventId);
        if ($event === null) {
            throw new HttpError(404, 'Event not found');
        }

        return $event;
    }

}
