<?php

declare(strict_types=1);

namespace GrandGala\API\Enquiries;

use GrandGala\Services\Enquiries;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class EnquiriesController
{
    // POST /api/enquiries { occasion, preferredDate, ... }
    public function submit(Request $request): void
    {
        // The guest is whoever is signed in, never an id from the body
        $user = Request::user();
        if ($user === null) {
            throw new HttpError(401, 'Please sign in');
        }

        Response::json(Enquiries::submit((int) $user['id'], $request->all()), 201);
    }
}
