<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Utils\HttpError;

// Discount worked out in cents, so it is exact to the cent.
class PromotionMath
{
    // Never more than the amount, and never more than $maxDiscount when that is set.
    public static function discount(float $amount, string $type, float $value, ?float $maxDiscount): float
    {
        if (!is_finite($amount) || !is_finite($value) || $amount < 0 || $value < 0) {
            throw new HttpError(422, 'Promotion amounts must be non-negative numbers');
        }
        if ($type !== 'percentage' && $type !== 'fixed') {
            throw new HttpError(422, 'Promotion type must be percentage or fixed');
        }
        if ($type === 'percentage' && $value > 100) {
            throw new HttpError(422, 'Percentage promotions cannot exceed 100');
        }

        $amountCents = (int) round($amount * 100);
        if ($type === 'percentage') {
            $discountCents = (int) round($amountCents * $value / 100);
        } else {
            $discountCents = (int) round($value * 100);
        }

        if ($maxDiscount !== null) {
            if (!is_finite($maxDiscount) || $maxDiscount < 0) {
                throw new HttpError(422, 'Maximum discount must be non-negative');
            }
            $discountCents = min($discountCents, (int) round($maxDiscount * 100));
        }

        return min($amountCents, $discountCents) / 100;
    }
}
