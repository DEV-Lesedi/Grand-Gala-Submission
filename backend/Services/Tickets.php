<?php

declare(strict_types=1);

namespace GrandGala\Services;

use DateTimeImmutable;
use GrandGala\Models\PublicEvent;
use GrandGala\Models\Space;
use GrandGala\Models\Ticket;
use GrandGala\Models\TicketOrder;
use GrandGala\Models\TicketType;
use GrandGala\Services\PaymentGateway\Payments;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Sales lock venue, event, then tiers, so the last ticket sells once.
 * Prices are frozen on the order. Tickets are issued only after payment.
 * TicketForm checks the buyer. TicketView is the JSON. TicketGate scans. TicketLists is the staff list.
 */
class Tickets
{
    private const DEFAULT_HOLD_MINUTES = 15;
    // The biggest amount a DECIMAL(12,2) column holds
    private const MAX_TOTAL = 9999999999.99;

    // Holds seats until paid. $userId null is guest checkout and returns an accessToken.
    public static function createOrder(array $input, ?int $userId): array
    {
        $eventId = $input['eventId'] ?? null;
        if (!is_int($eventId) || $eventId < 1) {
            throw new HttpError(422, 'Choose an event');
        }
        $buyer = TicketForm::checkBuyer($input);
        $requested = TicketForm::checkItems($input['items'] ?? null);
        $promoCode = TicketForm::checkPromoCode($input['promoCode'] ?? null);

        $event = PublicEvent::find($eventId);
        if ($event === null || !$event['venueIsActive']) {
            throw new HttpError(404, 'Event not found');
        }

        $saved = Db::transaction(function () use ($event, $buyer, $requested, $promoCode, $userId) {
            // Lock order: the venue (like the availability engine), then the event, then its tiers
            Space::lockVenue($event['venueId']);
            $event = PublicEvent::find($event['id'], true);
            if ($event['status'] !== 'confirmed') {
                throw new HttpError(409, 'This event is not on sale');
            }
            $now = Clock::now();
            $eventStart = Events::startOf($event);
            if ($now >= $eventStart) {
                throw new HttpError(409, 'Ticket sales for this event have closed');
            }

            $tiers = [];
            foreach (TicketType::forEvent($event['id'], true) as $tier) {
                $tiers[$tier['id']] = $tier;
            }
            // Locking read: includes orders saved while this request waited for the locks.
            $seats = TicketOrder::seatsTaken($event['id'], Holds::cutOff(), true);

            $holdExpires = self::holdEnd($now, $eventStart);
            $lines = [];
            $subtotal = 0.0;
            foreach ($requested as $tierId => $quantity) {
                if (!isset($tiers[$tierId]) || !$tiers[$tierId]['isActive']) {
                    throw new HttpError(422, 'Choose a ticket tier that is on sale for this event');
                }
                $tier = $tiers[$tierId];
                if ($quantity > $tier['maxPerOrder']) {
                    throw new HttpError(422, 'You can buy at most ' . $tier['maxPerOrder'] . ' ' . $tier['name'] . ' tickets per order');
                }
                if ($tier['salesOpen'] !== null && $now < $tier['salesOpen']) {
                    throw new HttpError(409, $tier['name'] . " tickets aren't on sale yet");
                }
                if ($tier['salesClose'] !== null && $now >= $tier['salesClose']) {
                    throw new HttpError(409, $tier['name'] . ' ticket sales have closed');
                }

                $taken = 0;
                if (isset($seats[$tierId])) {
                    $taken = $seats[$tierId]['taken'];
                }
                if ($taken + $quantity > $tier['allocation']) {
                    throw new HttpError(409, 'Not enough ' . $tier['name'] . ' tickets left');
                }

                $subtotal = $subtotal + $tier['price'] * $quantity;
                $lines[] = ['tierId' => $tierId, 'quantity' => $quantity, 'unitPrice' => $tier['price']];

                // The hold can't outlast this tier's sales
                if ($tier['salesClose'] !== null && $tier['salesClose'] < $holdExpires) {
                    $holdExpires = $tier['salesClose'];
                }
            }

            // Round to the cent once; summing cents leaves float leftovers.
            $subtotal = round($subtotal, 2);
            if ($subtotal > self::MAX_TOTAL) {
                throw new HttpError(422, 'This order is too large');
            }

            // Checked before the order exists, so a bad code is a 422 and nothing is saved.
            // The use itself is held once the order row is there (reserveOrder).
            $promo = Promotions::quote($promoCode, 'tickets', $subtotal, $userId, $buyer['email']);
            $discount = round((float) $promo['discount'], 2);
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }

            $reference = References::next('ticket_order');
            $orderId = TicketOrder::insert([
                'reference' => $reference,
                'eventId' => $event['id'],
                'userId' => $userId,
                'buyerName' => $buyer['name'],
                'buyerSurname' => $buyer['surname'],
                'buyerEmail' => $buyer['email'],
                'buyerPhone' => $buyer['phone'],
                'subtotal' => $subtotal,
                'promoId' => $promo['promoId'],
                'discount' => $discount,
                'total' => round($subtotal - $discount, 2),
                'holdExpires' => $holdExpires,
            ]);
            foreach ($lines as $line) {
                TicketOrder::insertItem($orderId, $line['tierId'], $line['quantity'], $line['unitPrice']);
            }
            // Holds the code for this order, so a second order can't take the last use.
            Promotions::reserveOrder($promoCode, 'tickets', $subtotal, $userId, $buyer['email'], $orderId);

            // Receipt token lasts 30 days after the event. A missing secret rolls the order back.
            $token = null;
            if ($userId === null) {
                $token = TicketAccess::issue($reference, Dates::addDays($event['date'], 30) . ' ' . $event['startTime'] . ':00');
            }

            return ['orderId' => $orderId, 'token' => $token];
        });

        $order = TicketOrder::findById($saved['orderId']);
        $result = TicketView::present($order, false);
        $result['items'] = TicketOrder::items($order['id']);
        $result['paymentRequired'] = true;
        if ($saved['token'] !== null) {
            $result['accessToken'] = $saved['token'];
        }

        return $result;
    }

    // Amount from the database. 409 once the order can no longer be paid.
    public static function amountDue(string $reference): float
    {
        if (!self::canBePaid($reference)) {
            throw new HttpError(409, 'This ticket order can no longer be paid');
        }

        return TicketOrder::findByReference($reference)['total'];
    }

    // Unpaid, hold still running, event still on. 404 if the order does not exist.
    public static function canBePaid(string $reference): bool
    {
        $order = TicketOrder::findByReference($reference);
        if ($order === null) {
            throw new HttpError(404, 'Order not found');
        }
        $event = PublicEvent::find($order['eventId']);

        return self::canStillBePaid($order) && $event !== null && $event['status'] === 'confirmed';
    }

    // Called inside the payment transaction. A second notice for a paid order changes nothing.
    public static function onPaid(string $reference, int $paymentId): void
    {
        Db::transaction(function () use ($reference) {
            $locked = self::lockOrder($reference);
            $order = $locked['order'];

            if ($order['status'] === 'paid') {
                self::issue($order['id']);
                return;
            }
            if (!self::canStillBePaid($order)) {
                throw new HttpError(409, 'This ticket order can no longer be paid');
            }
            if ($locked['event']['status'] !== 'confirmed') {
                throw new HttpError(409, 'This event is not on sale');
            }

            TicketOrder::setStatus($order['id'], 'paid');
            Promotions::markUsed(null, $order['id']);
            self::issue($order['id']);
        });
    }

    // Marks unpaid orders past their hold as expired. A later payment finds them unpayable.
    public static function expireLapsedHolds(): int
    {
        return Db::transaction(function () {
            $orderIds = TicketOrder::expireLapsed(Holds::cutOff());
            foreach ($orderIds as $orderId) {
                Promotions::release(null, $orderId);
            }

            return count($orderIds);
        });
    }

    // One ticket per seat, then email. A second call issues nothing and sends no second email.
    public static function issue(int $orderId): void
    {
        $found = TicketOrder::findById($orderId);
        if ($found === null) {
            throw new HttpError(404, 'Order not found');
        }

        Db::transaction(function () use ($found) {
            $order = self::lockOrder($found['reference'])['order'];
            if ($order['status'] !== 'paid') {
                throw new HttpError(409, 'Tickets are only issued for paid orders');
            }

            $items = TicketOrder::items($order['id']);
            $seats = 0;
            foreach ($items as $item) {
                $seats = $seats + $item['quantity'];
            }
            // Locking read, so a second payment notice sees the tickets the first one made.
            $existing = Ticket::forOrder($order['id'], true);
            if (count($existing) === $seats) {
                return;
            }
            if (count($existing) > 0) {
                throw new HttpError(409, 'Some tickets were already issued for this order; staff need to check it');
            }

            foreach ($items as $item) {
                for ($seat = 1; $seat <= $item['quantity']; $seat++) {
                    // 32 random hex characters. The database refuses a repeat.
                    Ticket::insert($order['id'], $item['tierId'], bin2hex(random_bytes(16)), $item['unitPrice']);
                }
            }

            Mailer::send('e_tickets', $order['buyerEmail'], self::mailData($order));
        });
    }

    // Refund completed: the cancelled order and its tickets become refunded.
    public static function onRefunded(string $reference, int $refundId): void
    {
        Db::transaction(function () use ($reference) {
            $order = self::lockOrder($reference)['order'];
            if ($order['status'] === 'refunded') {
                return;
            }
            if ($order['status'] !== 'cancelled') {
                throw new HttpError(409, 'Only an order whose refund was asked for can be marked refunded');
            }

            TicketOrder::setStatus($order['id'], 'refunded');
            Ticket::setStatusForOrder($order['id'], 'refunded');
            Promotions::giveBack(null, $order['id']);
        });
    }

    // An order with its lines and tickets, for its owner or a guest with the order's receipt token
    public static function detail(string $reference, ?int $userId, ?string $token): array
    {
        return TicketView::present(self::findForBuyer($reference, $userId, $token), true);
    }

    // The buyer cancels an order they haven't paid yet, so its seats are free again
    public static function cancelUnpaid(string $reference, ?int $userId, ?string $token): array
    {
        self::findForBuyer($reference, $userId, $token);

        return Db::transaction(function () use ($reference) {
            $order = self::lockOrder($reference)['order'];
            if ($order['status'] === 'paid') {
                throw new HttpError(409, 'Paid orders are refunded by staff');
            }

            $status = $order['status'];
            if ($status === 'pending_payment') {
                TicketOrder::setStatus($order['id'], 'cancelled');
                Promotions::release(null, $order['id']);
                $status = 'cancelled';
            }

            return ['reference' => $reference, 'status' => $status];
        });
    }

    // The QR code of one ticket in a paid order, as an SVG image
    public static function qrSvg(string $reference, string $code, ?int $userId, ?string $token): string
    {
        $order = self::findForBuyer($reference, $userId, $token);
        if ($order['status'] !== 'paid') {
            throw new HttpError(409, 'Only paid orders have QR codes');
        }

        foreach (Ticket::forOrder($order['id']) as $ticket) {
            $works = $ticket['status'] === 'valid' || $ticket['status'] === 'used';
            if ($ticket['code'] === $code && $works) {
                return TicketQr::svg($code);
            }
        }

        throw new HttpError(404, 'Ticket not found');
    }

    // A signed-in guest's orders, newest first
    public static function myOrders(int $userId): array
    {
        $orders = [];
        foreach (TicketOrder::forUser($userId) as $order) {
            $orders[] = TicketView::present($order, false);
        }

        return $orders;
    }

    // A signed-in guest's paid orders with their tickets (the Tickets tab)
    public static function myTickets(int $userId): array
    {
        $orders = [];
        foreach (TicketOrder::forUser($userId, 'paid') as $order) {
            $orders[] = TicketView::present($order, true);
        }

        return $orders;
    }

    // One of this guest's orders. Someone else's order is "not found".
    public static function myOrder(string $reference, int $userId): array
    {
        $order = TicketOrder::findByReference($reference);
        if ($order === null || $order['userId'] !== $userId) {
            throw new HttpError(404, 'Order not found');
        }

        return TicketView::present($order, true);
    }

    // Cancel now and ask Payments to refund. It becomes refunded when that completes.
    public static function refund(string $reference, array $input): array
    {
        $reason = TicketForm::checkReason($input['reason'] ?? null);

        Db::transaction(function () use ($reference, $reason) {
            $order = self::lockOrder($reference)['order'];
            if ($order['status'] !== 'paid') {
                throw new HttpError(409, 'Only paid orders can be refunded');
            }
            self::refundPaidOrder($order, $reason);
        });

        return ['reference' => $reference, 'status' => 'cancelled', 'refundAskedFor' => true];
    }

    // Cancel every order. Call after the event locked venue, event and tiers.
    public static function cancelOrdersForEvent(int $eventId, string $reason): int
    {
        $refunds = 0;
        foreach (TicketOrder::forEventLocked($eventId) as $order) {
            if ($order['status'] === 'paid') {
                if (self::refundPaidOrder($order, $reason)) {
                    $refunds = $refunds + 1;
                }
            } elseif ($order['status'] === 'pending_payment') {
                TicketOrder::setStatus($order['id'], 'cancelled');
                Promotions::release(null, $order['id']);
            }
        }

        return $refunds;
    }

    // Staff send a paid order's e-tickets to the buyer again
    public static function resend(string $reference): array
    {
        $order = TicketOrder::findByReference($reference);
        if ($order === null) {
            throw new HttpError(404, 'Order not found');
        }
        if ($order['status'] !== 'paid') {
            throw new HttpError(409, 'Only paid orders can have their tickets sent again');
        }

        Mailer::send('e_tickets', $order['buyerEmail'], self::mailData($order));

        return ['reference' => $reference, 'emailSent' => true];
    }

    // One row per seat. The list itself is in TicketLists.
    public static function attendees(int $eventId, array $query): array
    {
        return TicketLists::attendees($eventId, $query);
    }

    // Paid orders for one event. The list itself is in TicketLists.
    public static function eventOrders(int $eventId, array $query): array
    {
        return TicketLists::eventOrders($eventId, $query);
    }

    // What a QR code opens. The page itself is in TicketGate.
    public static function confirmation(string $code): array
    {
        return TicketGate::confirmation($code);
    }

    // Mark a ticket used. The rules are in TicketGate.
    public static function scan(int $eventId, array $input): array
    {
        return TicketGate::scan($eventId, $input);
    }


    // Cancel first, then ask for the money. A free order asks for nothing.
    // Status changes first so a refund that finishes inside this transaction sees it cancelled.
    private static function refundPaidOrder(array $order, string $reason): bool
    {
        $paymentId = TicketOrder::paidPaymentId($order['id']);
        if ($paymentId === null) {
            throw new HttpError(409, 'No payment was found for order ' . $order['reference'] . ", so it can't be refunded");
        }

        TicketOrder::setStatus($order['id'], 'cancelled');
        Ticket::setStatusForOrder($order['id'], 'cancelled');
        if ($order['total'] <= 0) {
            return false;
        }
        Payments::refund($paymentId, $order['total'], $reason);

        return true;
    }

    // Lock order: venue, event, tiers, then the order. Must run inside a transaction.
    private static function lockOrder(string $reference): array
    {
        // A plain read first, only to find the event and venue
        $order = TicketOrder::findByReference($reference);
        if ($order === null) {
            throw new HttpError(404, 'Order not found');
        }
        $event = PublicEvent::find($order['eventId']);
        if ($event === null) {
            throw new HttpError(404, 'Order not found');
        }

        Space::lockVenue($event['venueId']);
        $event = PublicEvent::find($order['eventId'], true);
        TicketType::forEvent($order['eventId'], true);
        $order = TicketOrder::findByReference($reference, true);

        return ['order' => $order, 'event' => $event];
    }

    // Owner, or a guest with a valid receipt token. Anyone else gets "not found".
    private static function findForBuyer(string $reference, ?int $userId, ?string $token): array
    {
        $order = TicketOrder::findByReference($reference);
        if ($order === null) {
            throw new HttpError(404, 'Order not found');
        }

        $allowed = false;
        if ($order['userId'] !== null) {
            $allowed = $userId !== null && $userId === $order['userId'];
        } else {
            $allowed = TicketAccess::verify($token, $reference);
        }
        if (!$allowed) {
            throw new HttpError(404, 'Order not found');
        }

        return $order;
    }

    // True if the order is unpaid and its hold hasn't run out
    private static function canStillBePaid(array $order): bool
    {
        return $order['status'] === 'pending_payment' && Holds::isRunning($order['holdExpires']);
    }

    // When a new order's hold ends: TICKET_HOLD_MINUTES from now, but never after the event starts
    private static function holdEnd(string $now, string $eventStart): string
    {
        $minutes = self::DEFAULT_HOLD_MINUTES;
        $setting = (string) ($_ENV['TICKET_HOLD_MINUTES'] ?? '');
        if (ctype_digit($setting) && (int) $setting >= 1) {
            $minutes = (int) $setting;
        }

        $end = (new DateTimeImmutable($now))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
        if ($end > $eventStart) {
            $end = $eventStart;
        }

        return $end;
    }

    // Reference and paid order with its tickets, for the e_tickets email.
    private static function mailData(array $order): array
    {
        return ['orderReference' => $order['reference'], 'order' => TicketView::present($order, true)];
    }

}
