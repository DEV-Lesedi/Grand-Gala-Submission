<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\TicketOrder;
use GrandGala\Services\PaymentGateway\Payable;
use GrandGala\Utils\HttpError;

/*
 * Payable for a ticket order. Guest checkout uses the receipt token.
 * Amount, payment and refund follow Tickets, in its lock order.
 */
class TicketOrderPayable implements Payable
{
    public function payer(string $reference, ?int $userId, ?string $accessToken): array
    {
        $order = TicketOrder::findByReference($reference);
        if ($order === null) {
            throw new HttpError(404, 'Nothing to pay was found for ' . $reference);
        }

        if ($order['userId'] === null) {
            if (!TicketAccess::verify($accessToken, $reference)) {
                throw new HttpError(404, 'Nothing to pay was found for ' . $reference);
            }
        } elseif ($userId === null) {
            throw new HttpError(401, 'Please sign in');
        } elseif ($order['userId'] !== $userId) {
            // Someone else's order is "not found", so they cannot tell it exists.
            throw new HttpError(404, 'Nothing to pay was found for ' . $reference);
        }

        return [
            'bookingId' => null,
            'orderId' => $order['id'],
            'userId' => $order['userId'],
            'name' => $order['buyerName'],
            'surname' => $order['buyerSurname'],
            'email' => $order['buyerEmail'],
        ];
    }

    public function amountDue(string $reference): float
    {
        return Tickets::amountDue($reference);
    }

    public function canBePaidNow(string $reference): bool
    {
        return Tickets::canBePaid($reference);
    }

    public function label(string $reference): string
    {
        return 'Grand Gala tickets ' . $reference;
    }

    public function referenceOf(?int $bookingId, ?int $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }
        $order = TicketOrder::findById($orderId);
        if ($order === null) {
            return null;
        }

        return $order['reference'];
    }

    // Marks the order paid and issues its tickets, inside the payment transaction.
    public function onPaid(string $reference, int $paymentId): void
    {
        Tickets::onPaid($reference, $paymentId);
    }

    // Marks a cancelled order and its tickets refunded. Called inside the refund's transaction.
    public function onRefunded(string $reference, int $refundId): void
    {
        Tickets::onRefunded($reference, $refundId);
    }
}
