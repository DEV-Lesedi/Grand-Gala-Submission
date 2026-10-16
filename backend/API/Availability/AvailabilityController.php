<?php

declare(strict_types=1);

namespace GrandGala\API\Availability;

use GrandGala\Models\Space;
use GrandGala\Services\Availability;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class AvailabilityController
{
    // Most spaces asked about at once (Browse stays and Reserve ask for the villa and its rooms)
    private const MAX_SPACES = 20;

    // GET /api/availability?spaceId=1&from=2026-12-01&to=2026-12-31
    // GET /api/availability?spaceIds=1,2,3&from=…&to=… → { "1": [days], "2": [days] }, one request instead of one per space
    public function calendar(Request $request): void
    {
        if ($request->queryText('spaceIds') !== '') {
            $this->severalCalendars($request);
            return;
        }

        $spaceId = (int) $request->queryText('spaceId');
        $from = $request->queryText('from');
        $to = $request->queryText('to');

        if ($spaceId < 1) {
            throw new HttpError(422, 'Space is required');
        }

        // Like the catalogue: a switched-off space has no calendar
        if (Space::findActive($spaceId) === null) {
            throw new HttpError(404, 'Space not found');
        }

        Response::json(Availability::calendar($spaceId, $from, $to));
    }

    private function severalCalendars(Request $request): void
    {
        $spaceIds = [];
        foreach (explode(',', $request->queryText('spaceIds')) as $text) {
            if (preg_match('/^[1-9][0-9]{0,9}$/', trim($text)) !== 1) {
                throw new HttpError(422, 'Space ids must be numbers, like 1,2,3');
            }
            $spaceIds[] = (int) trim($text);
        }
        if (count($spaceIds) > self::MAX_SPACES) {
            throw new HttpError(422, 'Ask for at most ' . self::MAX_SPACES . ' spaces at once');
        }

        $from = $request->queryText('from');
        $to = $request->queryText('to');
        $calendars = [];
        foreach (array_unique($spaceIds) as $spaceId) {
            // A switched-off space has no calendar: left out, like the catalogue leaves it out
            if (Space::findActive($spaceId) !== null) {
                $calendars[(string) $spaceId] = Availability::calendar($spaceId, $from, $to);
            }
        }

        // Keyed by id (ids start at 1, so JSON gets an object). No active space at all gives [].
        Response::json($calendars);
    }
}
