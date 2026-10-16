<?php

declare(strict_types=1);

namespace GrandGala\API\Bookings;

use GrandGala\Services\StaffBookings;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class StaffBookingsController
{
    // GET /api/staff/bookings?view=&status=&type=&q=&page= (all optional)
    public function list(Request $request): void
    {
        Response::json(StaffBookings::list($request->allQuery()));
    }

    // GET /api/staff/bookings/:reference
    public function detail(Request $request): void
    {
        Response::json(StaffBookings::detail((string) $request->param('reference')));
    }
}
