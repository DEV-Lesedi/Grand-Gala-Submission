<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Orders and their lines: quantity of each tier, at the price paid. */
class TicketOrder
{
    private const SELECT = 'SELECT o.to_id, o.to_reference, o.to_eb_id, o.to_u_id, o.to_buyer_name, o.to_buyer_surname,
                                   o.to_buyer_email, o.to_buyer_phone, o.to_status, o.to_subtotal, o.to_discount_amount,
                                   o.to_total_price, o.to_hold_expires_at, eb.eb_title, eb.eb_event_date, eb.eb_start_time
                            FROM ticket_order o
                            JOIN event_booking eb ON eb.eb_id = o.to_eb_id';

    // Status is the table default: waiting for payment.
    public static function insert(array $order): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO ticket_order (to_reference, to_eb_id, to_u_id, to_buyer_name, to_buyer_surname, to_buyer_email,
                                       to_buyer_phone, to_subtotal, to_promo_id, to_discount_amount, to_total_price, to_hold_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $order['reference'],
            $order['eventId'],
            $order['userId'],
            $order['buyerName'],
            $order['buyerSurname'],
            $order['buyerEmail'],
            $order['buyerPhone'],
            $order['subtotal'],
            $order['promoId'],
            $order['discount'],
            $order['total'],
            $order['holdExpires'],
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // $unitPrice is frozen at sale.
    public static function insertItem(int $orderId, int $tierId, int $quantity, float $unitPrice): void
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO ticket_order_item (toi_to_id, toi_tt_id, toi_quantity, toi_unit_price) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$orderId, $tierId, $quantity, $unitPrice]);
    }

    // $lock holds the row. Lock the venue, the event, then its tiers first.
    public static function findByReference(string $reference, bool $lock = false): ?array
    {
        $sql = self::SELECT . ' WHERE o.to_reference = ?';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }

        return self::one($sql, [$reference]);
    }

    public static function findById(int $orderId): ?array
    {
        return self::one(self::SELECT . ' WHERE o.to_id = ?', [$orderId]);
    }

    // Taken is paid plus live holds. Sold is paid only. An expired hold frees seats immediately.
    // $lock: a plain read can be a stale snapshot, and the last seat would sell twice.
    public static function seatsTaken(int $eventId, string $now, bool $lock = false): array
    {
        $sql = "SELECT o.to_status, i.toi_tt_id, i.toi_quantity
                FROM ticket_order o
                JOIN ticket_order_item i ON i.toi_to_id = o.to_id
                WHERE o.to_eb_id = ?
                  AND (o.to_status = 'paid' OR (o.to_status = 'pending_payment' AND o.to_hold_expires_at > ?))";
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }
        $statement = Db::pdo()->prepare($sql);
        $statement->execute([$eventId, $now]);

        $seats = [];
        foreach ($statement->fetchAll() as $row) {
            $tierId = (int) $row['toi_tt_id'];
            if (!isset($seats[$tierId])) {
                $seats[$tierId] = ['taken' => 0, 'sold' => 0];
            }
            $seats[$tierId]['taken'] = $seats[$tierId]['taken'] + (int) $row['toi_quantity'];
            if ($row['to_status'] === 'paid') {
                $seats[$tierId]['sold'] = $seats[$tierId]['sold'] + (int) $row['toi_quantity'];
            }
        }

        return $seats;
    }

    public static function items(int $orderId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT i.toi_tt_id, i.toi_quantity, i.toi_unit_price, t.tt_name
             FROM ticket_order_item i JOIN ticket_type t ON t.tt_id = i.toi_tt_id
             WHERE i.toi_to_id = ? ORDER BY i.toi_tt_id'
        );
        $statement->execute([$orderId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = [
                'tierId' => (int) $row['toi_tt_id'],
                'name' => $row['tt_name'],
                'quantity' => (int) $row['toi_quantity'],
                'unitPrice' => (float) $row['toi_unit_price'],
            ];
        }

        return $items;
    }

    public static function setStatus(int $orderId, string $status): void
    {
        $statement = Db::pdo()->prepare('UPDATE ticket_order SET to_status = ? WHERE to_id = ?');
        $statement->execute([$status, $orderId]);
    }

    // Locked until the transaction ends. Used when cancelling the event.
    public static function forEventLocked(int $eventId): array
    {
        return self::many(self::SELECT . ' WHERE o.to_eb_id = ? ORDER BY o.to_id FOR UPDATE', [$eventId]);
    }

    // $status limits the list to one status.
    public static function forUser(int $userId, ?string $status = null): array
    {
        $sql = self::SELECT . ' WHERE o.to_u_id = ?';
        $params = [$userId];
        if ($status !== null) {
            $sql = $sql . ' AND o.to_status = ?';
            $params[] = $status;
        }

        return self::many($sql . ' ORDER BY o.to_id DESC', $params);
    }

    public static function paidPage(int $eventId, int $perPage, int $page): array
    {
        $statement = Db::pdo()->prepare("SELECT COUNT(*) FROM ticket_order WHERE to_eb_id = ? AND to_status = 'paid'");
        $statement->execute([$eventId]);
        $total = (int) $statement->fetchColumn();

        // LIMIT and OFFSET are ints we cast ourselves, so they are safe in the SQL.
        $offset = ($page - 1) * $perPage;
        $orders = self::many(
            self::SELECT . " WHERE o.to_eb_id = ? AND o.to_status = 'paid' ORDER BY o.to_id DESC
                             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            [$eventId]
        );

        return ['orders' => $orders, 'total' => $total];
    }

    // Paid orders only.
    public static function revenue(int $eventId): float
    {
        $statement = Db::pdo()->prepare("SELECT COALESCE(SUM(to_total_price), 0) FROM ticket_order WHERE to_eb_id = ? AND to_status = 'paid'");
        $statement->execute([$eventId]);

        return (float) $statement->fetchColumn();
    }

    // Unpaid holds that ran out become expired. Seats were already free.
    // Returns the ids so the caller can release each promo code.
    public static function expireLapsed(string $now): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT to_id FROM ticket_order WHERE to_status = 'pending_payment' AND to_hold_expires_at <= ?"
        );
        $statement->execute([$now]);

        $update = Db::pdo()->prepare(
            "UPDATE ticket_order SET to_status = 'expired' WHERE to_id = ? AND to_status = 'pending_payment'"
        );
        $expired = [];
        foreach ($statement->fetchAll() as $row) {
            $orderId = (int) $row['to_id'];
            $update->execute([$orderId]);
            // Paid between the read and this update: leave it, and leave its code used.
            if ($update->rowCount() === 1) {
                $expired[] = $orderId;
            }
        }

        return $expired;
    }

    // The paid payment a refund is raised against, or null. Read only.
    public static function paidPaymentId(int $orderId): ?int
    {
        $statement = Db::pdo()->prepare("SELECT p_id FROM payment WHERE p_to_id = ? AND p_status = 'paid' ORDER BY p_id DESC LIMIT 1");
        $statement->execute([$orderId]);
        $paymentId = $statement->fetchColumn();
        if ($paymentId === false) {
            return null;
        }

        return (int) $paymentId;
    }

    private static function one(string $sql, array $params): ?array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toOrder($row);
    }

    private static function many(string $sql, array $params): array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);

        $orders = [];
        foreach ($statement->fetchAll() as $row) {
            $orders[] = self::toOrder($row);
        }

        return $orders;
    }

    // Hold stays 'YYYY-MM-DD HH:MM:SS' so it compares with Clock::now().
    private static function toOrder(array $row): array
    {
        $userId = null;
        if ($row['to_u_id'] !== null) {
            $userId = (int) $row['to_u_id'];
        }

        return [
            'id' => (int) $row['to_id'],
            'reference' => $row['to_reference'],
            'eventId' => (int) $row['to_eb_id'],
            'eventTitle' => (string) $row['eb_title'],
            'eventDate' => $row['eb_event_date'],
            'eventStartTime' => substr($row['eb_start_time'], 0, 5),
            'userId' => $userId,
            'buyerName' => $row['to_buyer_name'],
            'buyerSurname' => $row['to_buyer_surname'],
            'buyerEmail' => $row['to_buyer_email'],
            'buyerPhone' => $row['to_buyer_phone'],
            'status' => $row['to_status'],
            'subtotal' => (float) $row['to_subtotal'],
            'discount' => (float) $row['to_discount_amount'],
            'total' => (float) $row['to_total_price'],
            'holdExpires' => $row['to_hold_expires_at'],
        ];
    }
}
