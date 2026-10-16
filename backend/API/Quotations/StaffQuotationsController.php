<?php

declare(strict_types=1);

namespace GrandGala\API\Quotations;

use GrandGala\Services\Quotations;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class StaffQuotationsController
{
    // POST /api/staff/quotations { bookingReference, items, discount, validUntil, notes }
    public function create(Request $request): void
    {
        $staffId = $this->signedInId();

        Response::json(Quotations::create($request->all(), $staffId), 201);
    }

    // GET /api/staff/quotations?status=sent|accepted|expired|declined
    public function list(Request $request): void
    {
        $this->signedInId();

        Response::json(Quotations::staffList($request->allQuery()));
    }

    // GET /api/staff/quotations/:reference
    public function detail(Request $request): void
    {
        $this->signedInId();

        Response::json(Quotations::staffDetail((string) $request->param('reference')));
    }

    // GET /api/staff/quotations/:reference/versions
    public function versions(Request $request): void
    {
        $this->signedInId();

        Response::json(['versions' => Quotations::versions((string) $request->param('reference'))]);
    }

    // 'staff' middleware already refuses everyone else.
    private function signedInId(): int
    {
        $user = Request::user();
        if ($user === null) {
            throw new HttpError(401, 'Sign in to continue');
        }

        return (int) $user['id'];
    }
}
