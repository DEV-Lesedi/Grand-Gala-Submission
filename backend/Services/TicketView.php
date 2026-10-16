<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Ticket;
use GrandGala\Models\TicketOrder;

/*
 * A ticket order as the API returns it.
 * An unpaid order past its hold shows as expired, even before the nightly job marks it.
 */
class TicketView
{
    // API shape. $withDetails adds lines, tickets, and QR codes once paid.
    public static function present(array $order, bool $withDetails): array
    {
        // An unpaid order whose hold ran out shows as expired, even before a cron job marks it
        $status = $order['status'];
        if ($status === 'pending_payment' && !Holds::isRunning($order['holdExpires'])) {
            $status = 'expired';
        }

        $result = [
            'reference' => $order['reference'],
            'eventId' => $order['eventId'],
            'eventTitle' => $order['eventTitle'],
            'eventDate' => $order['eventDate'],
            'eventStartTime' => $order['eventStartTime'],
            'status' => $status,
            'subtotal' => $order['subtotal'],
            'discount' => $order['discount'],
            'total' => $order['total'],
            'holdExpires' => Events::apiDateTime($order['holdExpires']),
        ];

        if ($withDetails) {
            $result['items'] = TicketOrder::items($order['id']);
            $tickets = [];
            foreach (Ticket::forOrder($order['id']) as $ticket) {
                $qrDataUri = null;
                $works = $ticket['status'] === 'valid' || $ticket['status'] === 'used';
                if ($order['status'] === 'paid' && $works) {
                    $qrDataUri = TicketQr::dataUri($ticket['code']);
                }
                $tickets[] = [
                    'code' => $ticket['code'],
                    'qrPayload' => TicketQr::payload($ticket['code']),
                    'tier' => $ticket['tier'],
                    'price' => $ticket['price'],
                    'status' => $ticket['status'],
                    'qrDataUri' => $qrDataUri,
                ];
            }
            $result['tickets'] = $tickets;
        }

        return $result;
    }
}
