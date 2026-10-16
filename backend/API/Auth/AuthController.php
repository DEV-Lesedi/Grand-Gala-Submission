<?php

declare(strict_types=1);

namespace GrandGala\API\Auth;

use GrandGala\Middleware\Authentication\Auth;
use GrandGala\Services\Accounts;
use GrandGala\Services\RateLimiter;
use GrandGala\Utils\ClientIp;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Staff login is StaffAuthController.
class AuthController
{
    // POST /api/auth/register (at most 5 an address every 10 minutes)
    public function register(Request $request): void
    {
        RateLimiter::check('register', ClientIp::remote());
        $account = Accounts::register(
            $request->inputText('name'),
            $request->inputText('email'),
            $request->inputText('password')
        );

        Response::json($account, 201);
    }

    // POST /api/auth/login (at most 10 tries an address a minute)
    public function login(Request $request): void
    {
        RateLimiter::check('guest_login', ClientIp::remote());
        Response::json(Accounts::signInGuest(
            $request->inputText('email'),
            $request->inputText('password')
        ));
    }

    // POST /api/auth/logout
    public function logout(Request $request): void
    {
        Response::json(Accounts::signOut(Auth::currentToken()));
    }

    // GET /api/auth/me
    public function me(Request $request): void
    {
        // 'user' middleware already set Request::user().
        Response::json(Request::user());
    }

    // POST /api/auth/forgot-password: same answer either way. At most 5 per address / 10 min.
    public function forgotPassword(Request $request): void
    {
        RateLimiter::check('forgot_password', ClientIp::remote());
        Response::json(Accounts::requestReset($request->inputText('email')));
    }

    // POST /api/auth/reset-password { token, password }
    public function resetPassword(Request $request): void
    {
        Response::json(Accounts::resetWithToken(
            $request->inputText('token'),
            $request->inputText('password')
        ));
    }
}
