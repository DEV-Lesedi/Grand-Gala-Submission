<?php

declare(strict_types=1);

namespace GrandGala\API\Amenities;

use GrandGala\Services\Amenities;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class AmenitiesController
{
    // GET /api/staff/amenities: every amenity, switched off ones too, with today's price
    public function list(Request $request): void
    {
        Response::json(Amenities::all());
    }

    // POST /api/staff/amenities { name, price }
    public function add(Request $request): void
    {
        Response::json(Amenities::add($request->all()), 201);
    }

    // PATCH /api/staff/amenities/:id: only fields sent, e.g. price or isActive.
    public function update(Request $request): void
    {
        Response::json(Amenities::update((int) $request->param('id'), $request->all()));
    }
}
