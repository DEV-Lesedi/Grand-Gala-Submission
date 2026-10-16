<?php

declare(strict_types=1);

namespace GrandGala\API\Promotions;

use GrandGala\Services\Promotions;
use GrandGala\Services\RateLimiter;
use GrandGala\Utils\ClientIp;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Admins manage codes; anyone may check one before paying. Staff routes need role:admin.
class PromotionController
{
    // GET /api/staff/promotions
    public function list(Request $request): void
    {
        Response::json(['promotions' => Promotions::all()]);
    }

    // POST /api/staff/promotions { code, type, value, appliesTo, validFrom, validTo, ... }
    public function create(Request $request): void
    {
        $id = Promotions::create($request->all(), Request::user()['id']);
        Response::json(['id' => $id], 201);
    }

    // PATCH /api/staff/promotions/:id. { isActive: false } switches it off.
    public function update(Request $request): void
    {
        $id = (string) $request->param('id');
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new HttpError(404, 'Promotion not found');
        }
        Promotions::update((int) $id, $request->all(), Request::user()['id']);
        Response::json(['updated' => true]);
    }

    // POST /api/promotions/validate { code, kind, amount }: quote only, 30/min per address.
    public function validate(Request $request): void
    {
        RateLimiter::check('promotion_validate', ClientIp::remote());
        $code = $request->input('code');
        $kind = $request->input('kind');
        $amount = $request->input('amount');
        if (!is_string($code) || !is_string($kind) || !is_numeric($amount)) {
            throw new HttpError(422, 'Code, kind and amount are required');
        }

        $userId = null;
        $user = Request::user();
        if ($user !== null) {
            $userId = $user['id'];
        }

        Response::json(Promotions::quote($code, $kind, (float) $amount, $userId));
    }
}
