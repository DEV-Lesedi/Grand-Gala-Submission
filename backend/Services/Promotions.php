<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Promotion;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use LogicException;
use PDOException;

/*
 * quote() only checks. reserve() holds the code so two bookings cannot take the last use.
 * A ticket guest is the account or buyer email, so guest checkout cannot dodge the limit.
 */
class Promotions
{
    private const KINDS = ['stay', 'event', 'tickets'];

    // Discount for $kind. No code means 0. A bad code is 422.
    // $buyerEmail applies the per-guest limit when the buyer has no account.
    public static function quote(?string $code, string $kind, float $amount, ?int $userId, ?string $buyerEmail = null): array
    {
        if ($code === null || trim($code) === '') {
            return ['discount' => 0.0, 'promoId' => null];
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new HttpError(422, 'Unknown promotion category');
        }
        if (!is_finite($amount) || $amount < 0) {
            throw new HttpError(422, 'Amount must be a non-negative number');
        }

        $promo = Promotion::findByCode(strtoupper(trim($code)));
        if ($promo === null || !$promo['isActive']) {
            throw new HttpError(422, 'Promotion code is not available');
        }
        if ($promo['validFrom'] > Clock::today() || $promo['validTo'] < Clock::today()) {
            throw new HttpError(422, 'Promotion code is outside its valid dates');
        }
        if (!in_array($kind, $promo['appliesTo'], true)) {
            throw new HttpError(422, 'Promotion code does not apply to this booking');
        }
        if ($amount < $promo['minSpend']) {
            return ['discount' => 0.0, 'promoId' => null];
        }

        $counts = Promotion::usageCounts($promo['id'], $userId, $buyerEmail);
        $uses = $counts['reserved'] + $promo['usageCount'];
        if ($promo['usageLimit'] !== null && $uses >= $promo['usageLimit']) {
            throw new HttpError(422, 'Promotion code has reached its usage limit');
        }
        $knowsTheGuest = $userId !== null || $buyerEmail !== null;
        if ($knowsTheGuest && $promo['limitPerUser'] !== null && $counts['perUser'] >= $promo['limitPerUser']) {
            throw new HttpError(422, 'You have reached the usage limit for this promotion');
        }

        $discount = PromotionMath::discount($amount, $promo['type'], $promo['value'], $promo['maxDiscount']);

        return ['discount' => $discount, 'promoId' => $promo['id']];
    }

    // Like quote(), and holds the code until paid, cancelled or expired. The row is locked first.
    public static function reserve(?string $code, string $kind, float $amount, int $userId, int $bookingId): array
    {
        if ($code === null || trim($code) === '') {
            return ['discount' => 0.0, 'promoId' => null];
        }

        return Db::transaction(function () use ($code, $kind, $amount, $userId, $bookingId) {
            $promo = Promotion::findByCode(strtoupper(trim($code)), true);
            if ($promo === null || !$promo['isActive']) {
                throw new HttpError(422, 'Promotion code is not available');
            }

            $quote = self::quote($promo['code'], $kind, $amount, $userId);
            if ($quote['promoId'] === null || $quote['discount'] <= 0) {
                return $quote;
            }

            try {
                Promotion::reserveUse($promo['id'], $userId, $bookingId);
            } catch (PDOException $error) {
                // 23000: the database refused a second code for the same booking
                if ($error->getCode() === '23000') {
                    throw new HttpError(409, 'A promotion has already been reserved for this booking');
                }
                throw $error;
            }

            return $quote;
        });
    }

    // Holds the code only if the discount still matches. Otherwise 409, and nothing is held.
    public static function reserveQuoted(?string $code, string $kind, float $amount, int $userId, int $bookingId, ?int $quotedPromoId, float $quotedDiscount): array
    {
        // Must share reserve()'s transaction, or a mismatch would stay held.
        return Db::transaction(function () use ($code, $kind, $amount, $userId, $bookingId, $quotedPromoId, $quotedDiscount) {
            $promotion = self::reserve($code, $kind, $amount, $userId, $bookingId);
            if ($promotion['promoId'] !== $quotedPromoId || $promotion['discount'] !== $quotedDiscount) {
                throw new HttpError(409, 'Promotion changed; please price the stay again');
            }

            return $promotion;
        });
    }

    // Like reserve(), for a ticket order. The order row must already exist.
    public static function reserveOrder(?string $code, string $kind, float $amount, ?int $userId, string $buyerEmail, int $orderId): array
    {
        if ($code === null || trim($code) === '') {
            return ['discount' => 0.0, 'promoId' => null];
        }

        return Db::transaction(function () use ($code, $kind, $amount, $userId, $buyerEmail, $orderId) {
            $promo = Promotion::findByCode(strtoupper(trim($code)), true);
            if ($promo === null || !$promo['isActive']) {
                throw new HttpError(422, 'Promotion code is not available');
            }

            $quote = self::quote($promo['code'], $kind, $amount, $userId, $buyerEmail);
            if ($quote['promoId'] === null || $quote['discount'] <= 0) {
                return $quote;
            }

            try {
                Promotion::reserveOrderUse($promo['id'], $userId, $orderId);
            } catch (PDOException $error) {
                // 23000: the database refused a second code for the same order
                if ($error->getCode() === '23000') {
                    throw new HttpError(409, 'A promotion has already been reserved for this order');
                }
                throw $error;
            }

            return $quote;
        });
    }

    // Paid: the held code counts as used. Pass the booking or the order, not both.
    public static function markUsed(?int $bookingId, ?int $orderId = null): void
    {
        self::requireOneTarget($bookingId, $orderId);
        if ($bookingId !== null) {
            Promotion::markUsePaid($bookingId);
            return;
        }
        Promotion::markOrderUsePaid($orderId);
    }

    // The unpaid Booking or ticket order was cancelled or expired: its held code can be used again
    public static function release(?int $bookingId, ?int $orderId = null): void
    {
        self::requireOneTarget($bookingId, $orderId);
        if ($bookingId !== null) {
            Promotion::releaseUse($bookingId);
            return;
        }
        Promotion::releaseOrderUse($orderId);
    }

    // Refunded: the used code can be used again and stops counting toward the limit.
    public static function giveBack(?int $bookingId, ?int $orderId = null): void
    {
        self::requireOneTarget($bookingId, $orderId);
        if ($bookingId !== null) {
            Promotion::giveBackUse($bookingId);
            return;
        }
        Promotion::giveBackOrderUse($orderId);
    }

    // One booking or one ticket order. Both or neither is a bug in the caller.
    private static function requireOneTarget(?int $bookingId, ?int $orderId): void
    {
        $hasBooking = $bookingId !== null;
        $hasOrder = $orderId !== null;
        if ($hasBooking === $hasOrder) {
            throw new LogicException('A promo happening needs a booking or a ticket order, not both and not neither');
        }
    }

    // ---- Staff ----

    public static function all(): array
    {
        return Promotion::all();
    }

    // Saves a new promotion and returns its id. 409 if the code is taken.
    public static function create(array $input, int $actorId): int
    {
        $data = self::validateInput($input, true);
        try {
            return Promotion::insert($data, $actorId);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpError(409, 'Promotion code already exists');
            }
            throw $error;
        }
    }

    // Changes a promotion (everything but its code), or switches it off with isActive false
    public static function update(int $id, array $input, int $actorId): void
    {
        $data = self::validateInput($input, false);
        Db::transaction(function () use ($id, $data, $actorId) {
            if (Promotion::findById($id, true) === null) {
                throw new HttpError(404, 'Promotion not found');
            }
            Promotion::update($id, $data, $actorId);
        });
    }

    // First problem is a 422. A new code is 2–30 letters, numbers, - or _, saved in upper case.
    public static function validateInput(array $input, bool $creating): array
    {
        $code = '';
        if ($creating) {
            $code = strtoupper(trim((string) ($input['code'] ?? '')));
            if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code) !== 1) {
                throw new HttpError(422, 'Promotion code must be 2-30 letters, numbers, hyphens or underscores');
            }
        }

        $type = $input['type'] ?? null;
        if ($type !== 'percentage' && $type !== 'fixed') {
            throw new HttpError(422, 'Promotion type must be percentage or fixed');
        }
        $value = self::number($input, 'value', null);
        if ($type === 'percentage' && $value > 100) {
            throw new HttpError(422, 'Percentage promotions cannot exceed 100');
        }

        $appliesTo = $input['appliesTo'] ?? null;
        if (!is_array($appliesTo) || count($appliesTo) === 0) {
            throw new HttpError(422, 'At least one promotion category is required');
        }
        // Each kind once, in the order given
        $kinds = [];
        foreach ($appliesTo as $kind) {
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new HttpError(422, 'Promotion category is invalid');
            }
            if (!in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        $validFrom = self::date($input, 'validFrom');
        $validTo = self::date($input, 'validTo');
        if ($validTo < $validFrom) {
            throw new HttpError(422, 'Promotion end date must be on or after its start date');
        }

        $maxDiscount = $input['maxDiscount'] ?? null;
        if ($maxDiscount !== null) {
            if (!is_numeric($maxDiscount) || !is_finite((float) $maxDiscount) || (float) $maxDiscount < 0) {
                throw new HttpError(422, 'Maximum discount must be a non-negative number or null');
            }
            $maxDiscount = (float) $maxDiscount;
        }

        $isActive = $input['isActive'] ?? true;
        if (!in_array($isActive, [true, false, 0, 1, '0', '1'], true)) {
            throw new HttpError(422, 'Active must be a boolean');
        }

        $description = null;
        if (isset($input['description'])) {
            $description = trim((string) $input['description']);
        }

        return [
            'code' => $code,
            'description' => $description,
            'type' => $type,
            'appliesTo' => $kinds,
            'value' => $value,
            'minSpend' => self::number($input, 'minSpend', 0.0),
            'maxDiscount' => $maxDiscount,
            'usageLimit' => self::optionalPositiveInt($input['usageLimit'] ?? null, 'Usage limit'),
            'limitPerUser' => self::optionalPositiveInt($input['limitPerUser'] ?? null, 'Per-user limit'),
            'validFrom' => $validFrom,
            'validTo' => $validTo,
            'isActive' => $isActive === true || $isActive === 1 || $isActive === '1',
        ];
    }

    // A number from 0 up. $default is used when the key is missing (null means it's required).
    private static function number(array $input, string $key, ?float $default): float
    {
        $value = $input[$key] ?? $default;
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new HttpError(422, ucfirst($key) . ' must be a non-negative number');
        }

        return (float) $value;
    }

    private static function date(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !Dates::isValid($value)) {
            throw new HttpError(422, ucfirst($key) . ' must be a valid date (YYYY-MM-DD)');
        }

        return $value;
    }

    // A whole number from 1 up, or null when it's left out
    private static function optionalPositiveInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new HttpError(422, $label . ' must be a positive integer or null');
        }

        return (int) $value;
    }
}
