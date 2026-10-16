<?php

declare(strict_types=1);

namespace GrandGala\API\Bookings;

use GrandGala\Services\EventAccommodation;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class EventAccommodationController
{
    // GET /api/staff/bookings/:reference/accommodation
    public function list(Request $request): void
    {
        Response::json(EventAccommodation::list((string) $request->param('reference')));
    }

    // POST /api/staff/bookings/:reference/accommodation { spaceId, checkIn, checkOut }
    public function add(Request $request): void
    {
        Response::json(EventAccommodation::add((string) $request->param('reference'), $request->all()), 201);
    }

    // DELETE /api/staff/bookings/:reference/accommodation/:id
    public function remove(Request $request): void
    {
        // Only digits count; "abc" or "-1" is not found.
        $id = (string) $request->param('id');
        if (!ctype_digit($id)) {
            throw new HttpError(404, 'Accommodation not found');
        }
        EventAccommodation::remove((string) $request->param('reference'), (int) $id);

        Response::json(['deleted' => (int) $id]);
    }
}
