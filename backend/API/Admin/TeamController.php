<?php

declare(strict_types=1);

namespace GrandGala\API\Admin;

use GrandGala\Services\Accounts;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Routes are role:admin; TeamRules still decides which role you may give, and to whom.
class TeamController
{
    // GET /api/staff/team
    public function list(Request $request): void
    {
        Response::json(Accounts::team(Request::user()));
    }

    // POST /api/staff/team { name, surname, email, phone, role }: invite email, no password.
    public function add(Request $request): void
    {
        $form = [
            'name' => $request->inputText('name'),
            'surname' => $request->inputText('surname'),
            'email' => $request->inputText('email'),
            'phone' => $request->inputText('phone'),
            'role' => $request->inputText('role'),
        ];

        Response::json(Accounts::invite(Request::user(), $form), 201);
    }

    // PATCH /api/staff/team/:id { role?, isActive? }
    public function update(Request $request): void
    {
        $role = $request->input('role');
        $isActive = $request->input('isActive');
        if ($isActive !== null) {
            $isActive = (bool) $isActive;
        }

        Response::json(Accounts::updateTeamMember(
            Request::user(),
            (string) $request->param('id'),
            $role,
            $isActive
        ));
    }

    // POST /api/staff/team/:id/reset-password
    public function resetPassword(Request $request): void
    {
        Response::json(Accounts::sendTeamReset(
            Request::user(),
            (string) $request->param('id')
        ));
    }
}
