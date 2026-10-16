<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Amenity;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Money;

/*
 * Extras a guest can add to a stay. A price is never edited: a new price is a new row from today.
 */
class Amenities
{
    // Every amenity, switched off ones too, with today's price
    public static function all(): array
    {
        return Amenity::allWithPriceOn(Clock::today());
    }

    // Adds an amenity with its price from today.
    public static function add(array $input): array
    {
        $name = self::checkName($input['name'] ?? '');
        $price = $input['price'] ?? null;
        Money::check($price);

        $amenityId = Db::transaction(function () use ($name, $price) {
            $amenityId = Amenity::insert($name);
            Amenity::addPrice($amenityId, (float) $price, Clock::today());

            return $amenityId;
        });

        return Amenity::findWithPriceOn($amenityId, Clock::today());
    }

    // Changes some of name, price and isActive, and returns the amenity.
    // Everything is checked before anything is saved.
    public static function update(int $amenityId, array $changes): array
    {
        $amenity = Amenity::findWithPriceOn($amenityId, Clock::today());
        if ($amenity === null) {
            throw new HttpError(404, 'Amenity not found');
        }

        $name = $amenity['name'];
        if (array_key_exists('name', $changes)) {
            $name = self::checkName($changes['name']);
        }
        $isActive = $amenity['isActive'];
        if (array_key_exists('isActive', $changes)) {
            $isActive = $changes['isActive'];
            if (!is_bool($isActive)) {
                throw new HttpError(422, 'isActive must be true or false');
            }
        }
        $newPrice = null;
        if (array_key_exists('price', $changes)) {
            $newPrice = $changes['price'];
            Money::check($newPrice);
        }

        Db::transaction(function () use ($amenityId, $name, $isActive, $newPrice) {
            Amenity::update($amenityId, $name, $isActive);
            if ($newPrice !== null) {
                Amenity::addPrice($amenityId, (float) $newPrice, Clock::today());
            }
        });

        return Amenity::findWithPriceOn($amenityId, Clock::today());
    }

    // Returns the name trimmed, or throws a 422
    private static function checkName(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '') {
            throw new HttpError(422, 'Give the amenity a name');
        }
        $name = trim($name);
        if (mb_strlen($name) > 100) {
            throw new HttpError(422, 'The name must be 100 characters or fewer');
        }

        return $name;
    }
}
