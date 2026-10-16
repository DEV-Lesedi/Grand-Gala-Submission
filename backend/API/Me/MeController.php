<?php

declare(strict_types=1);

namespace GrandGala\API\Me;

use GrandGala\Middleware\Authentication\Auth;
use GrandGala\Models\Favourite;
use GrandGala\Models\Space;
use GrandGala\Services\Accounts;
use GrandGala\Services\Clock;
use GrandGala\Services\GuestCancel;
use GrandGala\Services\MyBookingsFeed;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Every route needs 'user' middleware, so Request::user() is never null.
class MeController
{
    // PATCH /api/me { name, email, phone }
    public function updateProfile(Request $request): void
    {
        Response::json(Accounts::updateGuestProfile(
            Request::user(),
            $request->inputText('name'),
            $request->inputText('email'),
            $request->inputText('phone')
        ));
    }

    // POST /api/me/password { current, next, again }
    public function changePassword(Request $request): void
    {
        Response::json(Accounts::changePassword(
            Request::user(),
            $request->inputText('current'),
            $request->inputText('next'),
            $request->inputText('again'),
            Auth::currentToken()
        ));
    }

    // GET /api/me/favourites
    public function listFavourites(Request $request): void
    {
        // Saved spaces should still open offline (the app's offline cache).
        Response::cacheable(Favourite::spacesOf(Request::user()['id']), 60);
    }

    // POST /api/me/favourites/:spaceId
    public function addFavourite(Request $request): void
    {
        $spaceId = self::spaceIdParam($request);
        Favourite::add(Request::user()['id'], $spaceId);
        Response::json(['ok' => true], 201);
    }

    // DELETE /api/me/favourites/:spaceId
    public function removeFavourite(Request $request): void
    {
        $spaceId = self::spaceIdParam($request);
        Favourite::remove(Request::user()['id'], $spaceId);
        Response::json(['ok' => true]);
    }

    // GET /api/me/bookings?tab=upcoming|past
    public function bookings(Request $request): void
    {
        $tab = $request->query('tab', 'upcoming');
        if ($tab !== 'upcoming' && $tab !== 'past') {
            throw new HttpError(400, "tab must be 'upcoming' or 'past'");
        }

        Response::json(MyBookingsFeed::forUser(Request::user()['id'], $tab, Clock::today()));
    }

    // GET /api/me/bookings/:reference - owner only, "not yours" looks the same as "doesn't exist"
    public function bookingDetail(Request $request): void
    {
        $booking = MyBookingsFeed::findForUser(Request::user()['id'], (string) $request->param('reference'));
        if ($booking === null) {
            throw new HttpError(404, 'Booking not found');
        }

        Response::json($booking);
    }

    // POST /api/me/bookings/:reference/cancel: unpaid ok; paid stay refund review; paid event 409.
    public function cancelBooking(Request $request): void
    {
        Response::json(GuestCancel::cancel((string) $request->param('reference'), Request::user()['id']));
    }

    private static function spaceIdParam(Request $request): int
    {
        $id = (string) $request->param('spaceId');
        if (!ctype_digit($id)) {
            throw new HttpError(404, 'Space not found');
        }

        $spaceId = (int) $id;
        if (Space::findActive($spaceId) === null) {
            throw new HttpError(404, 'Space not found');
        }

        return $spaceId;
    }

}
