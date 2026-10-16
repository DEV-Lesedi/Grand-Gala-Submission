<?php

declare(strict_types=1);

namespace GrandGala\API\Spaces;

use GrandGala\Services\Blackouts;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class BlackoutsController
{
    // POST /api/staff/blackouts { start, end, reason, spaceIds }: one new id per space.
    public function add(Request $request): void
    {
        $spaceIds = $request->input('spaceIds');
        if (!is_array($spaceIds)) {
            $spaceIds = [];
        }
        // Only whole numbers. PHP would quietly turn "abc" into 0 and a list like [5] into 1.
        foreach ($spaceIds as $spaceId) {
            if (!is_int($spaceId)) {
                throw new HttpError(422, 'Space ids must be whole numbers');
            }
        }

        $ids = Blackouts::add(
            $request->inputText('start'),
            $request->inputText('end'),
            $request->inputText('reason'),
            $spaceIds,
        );

        Response::json(['ids' => $ids], 201);
    }

    // DELETE /api/staff/blackouts/:id
    public function delete(Request $request): void
    {
        $blackoutId = (int) $request->param('id');
        Blackouts::delete($blackoutId);

        Response::json(['deleted' => $blackoutId]);
    }
}
