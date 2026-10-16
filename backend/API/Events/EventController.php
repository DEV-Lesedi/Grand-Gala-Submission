<?php

declare(strict_types=1);

namespace GrandGala\API\Events;

use GrandGala\Services\Events;
use GrandGala\Services\Tickets;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class EventController
{
    // GET /api/events
    public function publicList(Request $request): void
    {
        Response::json(['items' => Events::publicList()]);
    }

    // GET /api/events/:id
    public function publicDetail(Request $request): void
    {
        Response::json(Events::publicDetail(self::eventId($request)));
    }

    // GET /api/staff/events
    public function staffList(Request $request): void
    {
        Response::json(['items' => Events::staffList()]);
    }

    // GET /api/staff/events/:id
    public function staffDetail(Request $request): void
    {
        Response::json(Events::staffDetail(self::eventId($request)));
    }

    // POST /api/staff/events { title, venueId, spaceIds, date, startTime, endTime, capacity, ... }
    public function create(Request $request): void
    {
        Response::json(Events::create($request->all(), self::staffId()), 201);
    }

    // PATCH /api/staff/events/:id { title?, description?, image?, capacity? }
    public function update(Request $request): void
    {
        Response::json(Events::update(self::eventId($request), $request->all()));
    }

    // PUT /api/staff/events/:id/tiers { tiers: [{ id?, name, price, allocation, ... }] }
    public function saveTiers(Request $request): void
    {
        Response::json(Events::saveTiers(self::eventId($request), $request->all()));
    }

    // POST /api/staff/events/:id/cancel { reason }
    public function cancel(Request $request): void
    {
        Response::json(Events::cancel(self::eventId($request), $request->all(), Request::user()['id']));
    }

    // GET /api/staff/events/:id/attendees?page=1&pageSize=20
    public function attendees(Request $request): void
    {
        Response::json(Tickets::attendees(self::eventId($request), $request->allQuery()));
    }

    // GET /api/staff/events/:id/orders?page=1&pageSize=20
    public function orders(Request $request): void
    {
        Response::json(Tickets::eventOrders(self::eventId($request), $request->allQuery()));
    }

    // POST /api/staff/events/:id/scan { code }
    public function scan(Request $request): void
    {
        Response::json(Tickets::scan(self::eventId($request), $request->all()));
    }

    // Only digits are an event id; "abc" or "-1" is not found.
    private static function eventId(Request $request): int
    {
        $id = (string) $request->param('id');
        if (!ctype_digit($id)) {
            throw new HttpError(404, 'Event not found');
        }

        return (int) $id;
    }

    // 'staff' middleware already requires a signed-in staff member.
    private static function staffId(): int
    {
        $user = Request::user();
        if ($user === null || !isset($user['id'])) {
            throw new HttpError(401, 'Please sign in');
        }

        return (int) $user['id'];
    }
}
