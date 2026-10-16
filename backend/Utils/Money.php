<?php

declare(strict_types=1);

namespace GrandGala\Utils;

// Prices in rand, 0 or more, at most 2 decimals.
class Money
{
    // 422 unless $price is an int or float, R0 or more, with at most 2 decimals.
    public static function check(mixed $price): void
    {
        if (!is_int($price) && !is_float($price)) {
            throw new HttpError(422, 'The price must be a number');
        }
        if ($price < 0) {
            throw new HttpError(422, 'The price must be R0 or more');
        }
        // round() returns a float, so != lets 100 match 100.0. 10.555 fails.
        if (round($price, 2) != $price) {
            throw new HttpError(422, 'The price can have at most 2 decimals');
        }
    }
}
