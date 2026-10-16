<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* A payment belongs to a booking or a ticket order, never both.
 * This class does not read booking, quotation, or ticket-order tables.
 */
class Payment
{
    private const COLUMNS = 'p_id, p_b_id, p_to_id, p_u_id, p_amount, p_merchant_reference, p_gateway_reference,
                             p_status, p_failure_reason';

    // Newest payment still waiting at PayFast, or null.
    public static function pendingFor(?int $bookingId, ?int $orderId): ?array
    {
        if ($bookingId !== null) {
            $sql = "SELECT " . self::COLUMNS . " FROM payment WHERE p_b_id = ? AND p_status = 'pending' ORDER BY p_id DESC LIMIT 1";
            $id = $bookingId;
        } else {
            $sql = "SELECT " . self::COLUMNS . " FROM payment WHERE p_to_id = ? AND p_status = 'pending' ORDER BY p_id DESC LIMIT 1";
            $id = $orderId;
        }

        return self::one($sql, [$id]);
    }

    // $amount is rand, including cents.
    public static function insertPending(?int $bookingId, ?int $orderId, ?int $userId, float $amount, string $merchantReference): int
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO payment (p_b_id, p_to_id, p_u_id, p_amount, p_currency, p_method, p_merchant_reference, p_status)
             VALUES (?, ?, ?, ?, 'ZAR', 'payfast', ?, 'pending')"
        );
        $statement->execute([$bookingId, $orderId, $userId, $amount, $merchantReference]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Our merchant reference, locked until the transaction ends.
    public static function lockByMerchantReference(string $merchantReference): ?array
    {
        return self::one('SELECT ' . self::COLUMNS . ' FROM payment WHERE p_merchant_reference = ? FOR UPDATE', [$merchantReference]);
    }

    // Locked until the transaction ends.
    public static function lock(int $paymentId): ?array
    {
        return self::one('SELECT ' . self::COLUMNS . ' FROM payment WHERE p_id = ? FOR UPDATE', [$paymentId]);
    }

    // True if PayFast's payment id was already used for one of our payments
    public static function gatewayReferenceInUse(string $gatewayReference): bool
    {
        $statement = Db::pdo()->prepare('SELECT 1 FROM payment WHERE p_gateway_reference = ? LIMIT 1 FOR UPDATE');
        $statement->execute([$gatewayReference]);

        return $statement->fetchColumn() !== false;
    }

    // True if the same booking or order already has another paid payment
    public static function otherPaidFor(array $payment): bool
    {
        if ($payment['bookingId'] !== null) {
            $sql = "SELECT 1 FROM payment WHERE p_b_id = ? AND p_id <> ? AND p_status = 'paid' LIMIT 1";
            $id = $payment['bookingId'];
        } else {
            $sql = "SELECT 1 FROM payment WHERE p_to_id = ? AND p_id <> ? AND p_status = 'paid' LIMIT 1";
            $id = $payment['orderId'];
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$id, $payment['id']]);

        return $statement->fetchColumn() !== false;
    }

    // $payload is the notification, stored as it arrived.
    public static function markPaid(int $paymentId, string $gatewayReference, array $payload, string $now): void
    {
        self::setPaid($paymentId, $gatewayReference, $payload, $now, null);
    }

    // Money arrived, but the booking or order could no longer be paid.
    // $problem is stored so staff can see why a refund is needed.
    public static function markPaidLate(int $paymentId, string $gatewayReference, array $payload, string $now, string $problem): void
    {
        self::setPaid($paymentId, $gatewayReference, $payload, $now, $problem);
    }

    // The guest cancelled at PayFast checkout.
    public static function markCancelled(int $paymentId, array $payload): void
    {
        $statement = Db::pdo()->prepare(
            "UPDATE payment SET p_status = 'cancelled', p_signature_valid = 1, p_itn_payload = ? WHERE p_id = ? AND p_status = 'pending'"
        );
        $statement->execute([json_encode($payload), $paymentId]);
    }

    // Refunded, or still waiting to be refunded.
    public static function refundedOrPendingAmount(int $paymentId): float
    {
        $statement = Db::pdo()->prepare(
            "SELECT COALESCE(SUM(rf_amount), 0) FROM refund WHERE rf_p_id = ? AND rf_status IN ('pending', 'processed')"
        );
        $statement->execute([$paymentId]);

        return (float) $statement->fetchColumn();
    }

    // $requestedBy is null when the system asks, not a person.
    public static function insertRefund(int $paymentId, float $amount, string $reason, ?int $requestedBy): int
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO refund (rf_p_id, rf_amount, rf_reason, rf_requested_by, rf_status) VALUES (?, ?, ?, ?, 'pending')"
        );
        $statement->execute([$paymentId, $amount, $reason, $requestedBy]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Locked until the transaction ends.
    public static function lockRefund(int $refundId): ?array
    {
        $statement = Db::pdo()->prepare('SELECT rf_id, rf_p_id, rf_amount, rf_status FROM refund WHERE rf_id = ? FOR UPDATE');
        $statement->execute([$refundId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['rf_id'],
            'paymentId' => (int) $row['rf_p_id'],
            'amount' => (float) $row['rf_amount'],
            'status' => $row['rf_status'],
        ];
    }

    // Records who refunded it, and PayFast's reference.
    public static function markRefundProcessed(int $refundId, int $actorId, string $gatewayReference): void
    {
        $statement = Db::pdo()->prepare(
            "UPDATE refund SET rf_status = 'processed', rf_processed_by = ?, rf_gateway_reference = ? WHERE rf_id = ? AND rf_status = 'pending'"
        );
        $statement->execute([$actorId, $gatewayReference, $refundId]);
    }

    // True only once processed refunds cover the whole payment.
    public static function markRefundedIfFullyRefunded(int $paymentId): bool
    {
        $statement = Db::pdo()->prepare(
            "UPDATE payment p
             SET p.p_status = 'refunded'
             WHERE p.p_id = ? AND p.p_status = 'paid'
               AND (SELECT COALESCE(SUM(r.rf_amount), 0) FROM refund r
                    WHERE r.rf_p_id = p.p_id AND r.rf_status = 'processed') >= p.p_amount"
        );
        $statement->execute([$paymentId]);

        return $statement->rowCount() === 1;
    }

    private static function setPaid(int $paymentId, string $gatewayReference, array $payload, string $now, ?string $problem): void
    {
        $statement = Db::pdo()->prepare(
            "UPDATE payment
             SET p_gateway_reference = ?, p_signature_valid = 1, p_itn_payload = ?, p_status = 'paid', p_paid_at = ?, p_failure_reason = ?
             WHERE p_id = ? AND p_status IN ('pending', 'processing')"
        );
        $statement->execute([$gatewayReference, json_encode($payload), $now, $problem, $paymentId]);
    }

    private static function one(string $sql, array $params): ?array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['p_id'],
            'bookingId' => self::intOrNull($row['p_b_id']),
            'orderId' => self::intOrNull($row['p_to_id']),
            'userId' => self::intOrNull($row['p_u_id']),
            'amount' => (float) $row['p_amount'],
            'merchantReference' => $row['p_merchant_reference'],
            'gatewayReference' => $row['p_gateway_reference'],
            'status' => $row['p_status'],
            // Set when the money came in after the booking or order could no longer be paid
            'problem' => $row['p_failure_reason'],
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
