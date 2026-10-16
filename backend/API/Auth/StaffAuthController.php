<?php

declare(strict_types=1);

namespace GrandGala\API\Auth;

use GrandGala\Services\Accounts;
use GrandGala\Services\RateLimiter;
use GrandGala\Utils\ClientIp;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Refuses customer and switched-off accounts.
class StaffAuthController
{
    // POST /api/staff/auth/login (at most 10 tries an address a minute)
    public function login(Request $request): void
    {
        RateLimiter::check('staff_login', ClientIp::remote());
        Response::json(Accounts::signInStaff(
            $request->inputText('email'),
            $request->inputText('password')
        ));
    }
}
