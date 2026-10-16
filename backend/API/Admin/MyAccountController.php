<?php

declare(strict_types=1);

namespace GrandGala\API\Admin;

use GrandGala\Middleware\Authentication\Auth;
use GrandGala\Services\Accounts;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Email stays fixed so Team can address them.
class MyAccountController
{
    // PATCH /api/staff/me { name, surname, phone }
    public function updateDetails(Request $request): void
    {
        Response::json(Accounts::updateStaffDetails(
            Request::user(),
            $request->inputText('name'),
            $request->inputText('surname'),
            $request->inputText('phone')
        ));
    }

    // POST /api/staff/me/password { current, next, again }
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
}
