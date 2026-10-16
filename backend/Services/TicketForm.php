<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Utils\HttpError;

/*
 * Checks a ticket order before it is saved. The first problem is a 422.
 */
class TicketForm
{
    private const MAX_ITEMS = 20;

    // The buyer's details, checked and trimmed
    public static function checkBuyer(array $input): array
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80) {
            throw new HttpError(422, 'Enter your name');
        }
        $surname = $input['surname'] ?? null;
        if (!is_string($surname) || trim($surname) === '' || mb_strlen(trim($surname)) > 80) {
            throw new HttpError(422, 'Enter your surname');
        }
        $email = $input['email'] ?? null;
        if (!is_string($email) || mb_strlen(trim($email)) > 150 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpError(422, 'Enter a valid email address');
        }
        $phone = $input['phone'] ?? null;
        if ($phone !== null && (!is_string($phone) || mb_strlen(trim($phone)) > 30)) {
            throw new HttpError(422, 'The phone number can be up to 30 characters');
        }
        if ($phone !== null) {
            $phone = trim($phone);
        }

        return ['name' => trim($name), 'surname' => trim($surname), 'email' => trim($email), 'phone' => $phone];
    }

    // tierId => quantity. 1 to 20 tiers, each once, 1 to 255 tickets each.
    public static function checkItems(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > self::MAX_ITEMS) {
            throw new HttpError(422, 'Choose between 1 and 20 ticket tiers');
        }

        $requested = [];
        foreach ($items as $item) {
            $isValid = is_array($item)
                && isset($item['tierId']) && is_int($item['tierId']) && $item['tierId'] >= 1
                && isset($item['quantity']) && is_int($item['quantity']) && $item['quantity'] >= 1 && $item['quantity'] <= 255;
            if (!$isValid) {
                throw new HttpError(422, 'Each tier needs a tierId and a quantity of 1 or more');
            }
            if (isset($requested[$item['tierId']])) {
                throw new HttpError(422, 'Choose each tier only once');
            }
            $requested[$item['tierId']] = $item['quantity'];
        }

        return $requested;
    }

    public static function checkPromoCode(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        if (!is_string($code) || mb_strlen($code) > 50) {
            throw new HttpError(422, 'The promo code must be text of up to 50 characters');
        }

        return trim($code);
    }

    public static function checkReason(mixed $reason): string
    {
        if (!is_string($reason) || trim($reason) === '') {
            throw new HttpError(422, 'Give a reason for the refund');
        }
        if (mb_strlen(trim($reason)) > 255) {
            throw new HttpError(422, 'The reason can be up to 255 characters');
        }

        return trim($reason);
    }
}
