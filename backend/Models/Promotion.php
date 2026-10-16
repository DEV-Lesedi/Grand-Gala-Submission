<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;
use UnexpectedValueException;

/* A code is reserved, then used on payment, or released if unpaid work is cancelled.
 * A use points at a booking or a ticket order, never both.
 */
class Promotion
{
    private const COLUMNS = 'promo_id, promo_code, promo_description, promo_type, promo_value, promo_min_spend,
                             promo_max_discount, promo_usage_limit, promo_usage_count, promo_limit_per_user,
                             promo_valid_from, promo_valid_to, promo_is_active, promo_applies_to,
                             promo_created_by, promo_updated_by';

    // Code is already upper case. $lock holds the row until the transaction ends.
    public static function findByCode(string $code, bool $lock = false): ?array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM promo WHERE promo_code = ?';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }

        return self::one($sql, [$code]);
    }

    // $lock holds the row until the transaction ends.
    public static function findById(int $id, bool $lock = false): ?array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM promo WHERE promo_id = ?';
        if ($lock) {
            $sql = $sql . ' FOR UPDATE';
        }

        return self::one($sql, [$id]);
    }

    public static function all(): array
    {
        $rows = Db::pdo()->query(
            'SELECT ' . self::COLUMNS . ' FROM promo ORDER BY promo_date_created DESC, promo_id DESC'
        )->fetchAll();

        $promotions = [];
        foreach ($rows as $row) {
            $promotions[] = self::toPromotion($row);
        }

        return $promotions;
    }

    // $data comes from Promotions::validateInput().
    public static function insert(array $data, int $actorId): int
    {
        $statement = Db::pdo()->prepare(
            'INSERT INTO promo
                (promo_code, promo_description, promo_type, promo_applies_to, promo_value, promo_min_spend,
                 promo_max_discount, promo_usage_limit, promo_limit_per_user, promo_valid_from, promo_valid_to,
                 promo_is_active, promo_created_by, promo_updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['code'],
            $data['description'],
            $data['type'],
            json_encode($data['appliesTo']),
            $data['value'],
            $data['minSpend'],
            $data['maxDiscount'],
            $data['usageLimit'],
            $data['limitPerUser'],
            $data['validFrom'],
            $data['validTo'],
            self::flag($data['isActive']),
            $actorId,
            $actorId,
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    // The code itself never changes.
    public static function update(int $id, array $data, int $actorId): void
    {
        $statement = Db::pdo()->prepare(
            'UPDATE promo
             SET promo_description = ?, promo_type = ?, promo_applies_to = ?, promo_value = ?,
                 promo_min_spend = ?, promo_max_discount = ?, promo_usage_limit = ?, promo_limit_per_user = ?,
                 promo_valid_from = ?, promo_valid_to = ?, promo_is_active = ?, promo_updated_by = ?
             WHERE promo_id = ?'
        );
        $statement->execute([
            $data['description'],
            $data['type'],
            json_encode($data['appliesTo']),
            $data['value'],
            $data['minSpend'],
            $data['maxDiscount'],
            $data['usageLimit'],
            $data['limitPerUser'],
            $data['validFrom'],
            $data['validTo'],
            self::flag($data['isActive']),
            $actorId,
            $id,
        ]);
    }

    // reserved is live holds. perUser counts holds and uses. Email match ignores case.
    // Guest is $userId, or $buyerEmail with no account. Both null means perUser 0.
    public static function usageCounts(int $promoId, ?int $userId, ?string $buyerEmail = null): array
    {
        $statement = Db::pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN pu.pu_status = 'reserved' THEN 1 ELSE 0 END), 0) AS reserved,
                    COALESCE(SUM(CASE WHEN (? IS NOT NULL AND pu.pu_user_id = ?) OR (? IS NOT NULL AND o.to_buyer_email = ?)
                                      THEN 1 ELSE 0 END), 0) AS per_user
             FROM promo_use pu
             LEFT JOIN ticket_order o ON o.to_id = pu.pu_to_id
             WHERE pu.pu_promo_id = ? AND pu.pu_status IN ('reserved', 'used')"
        );
        $statement->execute([$userId, $userId, $buyerEmail, $buyerEmail, $promoId]);
        $row = $statement->fetch();

        return ['reserved' => (int) $row['reserved'], 'perUser' => (int) $row['per_user']];
    }

    // One code per booking; the database refuses a second.
    public static function reserveUse(int $promoId, int $userId, int $bookingId): void
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO promo_use (pu_promo_id, pu_user_id, pu_booking_id, pu_status) VALUES (?, ?, ?, 'reserved')"
        );
        $statement->execute([$promoId, $userId, $bookingId]);
    }

    // One code per order. $userId is null when the buyer has no account.
    public static function reserveOrderUse(int $promoId, ?int $userId, int $orderId): void
    {
        $statement = Db::pdo()->prepare(
            "INSERT INTO promo_use (pu_promo_id, pu_user_id, pu_to_id, pu_status) VALUES (?, ?, ?, 'reserved')"
        );
        $statement->execute([$promoId, $userId, $orderId]);
    }

    // Held code becomes used and is counted. Safe to call twice.
    public static function markUsePaid(int $bookingId): void
    {
        $statement = Db::pdo()->prepare('SELECT pu_id, pu_promo_id, pu_status FROM promo_use WHERE pu_booking_id = ? FOR UPDATE');
        $statement->execute([$bookingId]);
        $use = $statement->fetch();
        if ($use === false || $use['pu_status'] !== 'reserved') {
            return;
        }

        $update = Db::pdo()->prepare("UPDATE promo_use SET pu_status = 'used' WHERE pu_id = ?");
        $update->execute([(int) $use['pu_id']]);
        $count = Db::pdo()->prepare('UPDATE promo SET promo_usage_count = promo_usage_count + 1 WHERE promo_id = ?');
        $count->execute([(int) $use['pu_promo_id']]);
    }

    // Someone else may use the code again.
    public static function releaseUse(int $bookingId): void
    {
        $statement = Db::pdo()->prepare(
            "UPDATE promo_use SET pu_status = 'released' WHERE pu_booking_id = ? AND pu_status = 'reserved'"
        );
        $statement->execute([$bookingId]);
    }

    // Held code becomes used and is counted. Safe to call twice.
    public static function markOrderUsePaid(int $orderId): void
    {
        $statement = Db::pdo()->prepare('SELECT pu_id, pu_promo_id, pu_status FROM promo_use WHERE pu_to_id = ? FOR UPDATE');
        $statement->execute([$orderId]);
        $use = $statement->fetch();
        if ($use === false || $use['pu_status'] !== 'reserved') {
            return;
        }

        $update = Db::pdo()->prepare("UPDATE promo_use SET pu_status = 'used' WHERE pu_id = ?");
        $update->execute([(int) $use['pu_id']]);
        $count = Db::pdo()->prepare('UPDATE promo SET promo_usage_count = promo_usage_count + 1 WHERE promo_id = ?');
        $count->execute([(int) $use['pu_promo_id']]);
    }

    // Someone else may use the code again.
    public static function releaseOrderUse(int $orderId): void
    {
        $statement = Db::pdo()->prepare(
            "UPDATE promo_use SET pu_status = 'released' WHERE pu_to_id = ? AND pu_status = 'reserved'"
        );
        $statement->execute([$orderId]);
    }

    // A used code is given back and stops counting. Safe to call twice.
    public static function giveBackUse(int $bookingId): void
    {
        $statement = Db::pdo()->prepare('SELECT pu_id, pu_promo_id, pu_status FROM promo_use WHERE pu_booking_id = ? FOR UPDATE');
        $statement->execute([$bookingId]);
        self::giveBackPaid($statement->fetch());
    }

    // Same as giveBackUse().
    public static function giveBackOrderUse(int $orderId): void
    {
        $statement = Db::pdo()->prepare('SELECT pu_id, pu_promo_id, pu_status FROM promo_use WHERE pu_to_id = ? FOR UPDATE');
        $statement->execute([$orderId]);
        self::giveBackPaid($statement->fetch());
    }

    // Locked promo_use row, or false if none. Only a used code is given back.
    private static function giveBackPaid(array|false $use): void
    {
        if ($use === false || $use['pu_status'] !== 'used') {
            return;
        }

        $update = Db::pdo()->prepare("UPDATE promo_use SET pu_status = 'released' WHERE pu_id = ?");
        $update->execute([(int) $use['pu_id']]);
        // Never below 0, even if staff edited the count by hand
        $count = Db::pdo()->prepare(
            'UPDATE promo SET promo_usage_count = promo_usage_count - 1 WHERE promo_id = ? AND promo_usage_count > 0'
        );
        $count->execute([(int) $use['pu_promo_id']]);
    }

    private static function one(string $sql, array $params): ?array
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toPromotion($row);
    }

    private static function flag(bool $value): int
    {
        if ($value) {
            return 1;
        }

        return 0;
    }

    private static function toPromotion(array $row): array
    {
        $appliesTo = json_decode((string) $row['promo_applies_to'], true);
        if (!is_array($appliesTo)) {
            throw new UnexpectedValueException('Stored promotion kinds must be a JSON list');
        }

        $maxDiscount = null;
        if ($row['promo_max_discount'] !== null) {
            $maxDiscount = (float) $row['promo_max_discount'];
        }
        $usageLimit = null;
        if ($row['promo_usage_limit'] !== null) {
            $usageLimit = (int) $row['promo_usage_limit'];
        }
        $limitPerUser = null;
        if ($row['promo_limit_per_user'] !== null) {
            $limitPerUser = (int) $row['promo_limit_per_user'];
        }
        $createdBy = null;
        if ($row['promo_created_by'] !== null) {
            $createdBy = (int) $row['promo_created_by'];
        }
        $updatedBy = null;
        if ($row['promo_updated_by'] !== null) {
            $updatedBy = (int) $row['promo_updated_by'];
        }

        return [
            'id' => (int) $row['promo_id'],
            'code' => $row['promo_code'],
            'description' => $row['promo_description'],
            'type' => $row['promo_type'],
            'appliesTo' => $appliesTo,
            'value' => (float) $row['promo_value'],
            'minSpend' => (float) $row['promo_min_spend'],
            'maxDiscount' => $maxDiscount,
            'usageLimit' => $usageLimit,
            'usageCount' => (int) $row['promo_usage_count'],
            'limitPerUser' => $limitPerUser,
            'validFrom' => $row['promo_valid_from'],
            'validTo' => $row['promo_valid_to'],
            'isActive' => (int) $row['promo_is_active'] === 1,
            'createdBy' => $createdBy,
            'updatedBy' => $updatedBy,
        ];
    }
}
