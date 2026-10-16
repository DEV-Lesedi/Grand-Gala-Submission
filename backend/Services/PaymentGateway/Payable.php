<?php

declare(strict_types=1);

namespace GrandGala\Services\PaymentGateway;

/*
 * What a guest can pay for. Payments::payableFor() picks one:
 * a stay (StayPayable), a quotation (QuotationPayment), or tickets (TicketOrderPayable).
 * $reference is that stay, quotation, or ticket order. Money is rand with cents.
 */
interface Payable
{
    // Signed-in guest, or a receipt token for guest checkout. 401 to sign in; 404 if not theirs.
    public function payer(string $reference, ?int $userId, ?string $accessToken): array;

    // Amount from the database, never the request. 409 once it cannot be paid.
    public function amountDue(string $reference): float;

    // If money arrives when this is false, it is recorded and a refund is queued.
    public function canBePaidNow(string $reference): bool;

    // The item name PayFast shows on its checkout page
    public function label(string $reference): string;

    // Reference this payment belongs to. null when it is not this kind of thing.
    public function referenceOf(?int $bookingId, ?int $orderId): ?string;

    // Called inside the payment confirmation's transaction once the payment is verified
    public function onPaid(string $reference, int $paymentId): void;

    // Called inside the refund's transaction
    public function onRefunded(string $reference, int $refundId): void;
}
