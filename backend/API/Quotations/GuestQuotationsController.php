<?php

declare(strict_types=1);

namespace GrandGala\API\Quotations;

use GrandGala\Services\Quotations;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Signed-in guests only ever see their own quotations.
class GuestQuotationsController
{
    // GET /api/me/quotations
    public function list(Request $request): void
    {
        Response::json(Quotations::guestList($this->signedInId()));
    }

    // GET /api/me/quotations/:reference
    public function detail(Request $request): void
    {
        Response::json(Quotations::guestDetail((string) $request->param('reference'), $this->signedInId()));
    }

    // POST /api/me/quotations/:reference/accept
    public function accept(Request $request): void
    {
        Response::json(Quotations::accept((string) $request->param('reference'), $this->signedInId()));
    }

    // POST /api/me/quotations/:reference/decline
    public function decline(Request $request): void
    {
        Response::json(Quotations::decline((string) $request->param('reference'), $this->signedInId()));
    }

    // POST /api/me/quotations/:reference/request-changes { note }
    public function requestChanges(Request $request): void
    {
        Response::json(Quotations::requestChanges((string) $request->param('reference'), $this->signedInId(), $request->all()));
    }

    // 'user' middleware already refuses anyone not signed in.
    private function signedInId(): int
    {
        $user = Request::user();
        if ($user === null) {
            throw new HttpError(401, 'Sign in to continue');
        }

        return (int) $user['id'];
    }
}
