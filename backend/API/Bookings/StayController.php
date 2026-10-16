<?php

declare(strict_types=1);

namespace GrandGala\API\Bookings;

use GrandGala\Services\Pricing;
use GrandGala\Services\StayBookings;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Self-cancel is on MeController.
class StayController
{
    // POST /api/stays/price { spaceId, checkIn, checkOut, guests, amenities?, promoCode? } public
    public function price(Request $request): void
    {
        $userId = null;
        $user = Request::user();
        if ($user !== null) {
            $userId = $user['id'];
        }

        Response::json(Pricing::stay($request->all(), $userId));
    }

    // POST /api/bookings: same body plus notes?. Requires the 'user' middleware.
    public function create(Request $request): void
    {
        Response::json(StayBookings::create($request->all(), Request::user()['id']), 201);
    }
}
