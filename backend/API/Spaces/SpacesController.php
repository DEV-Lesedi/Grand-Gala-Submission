<?php

declare(strict_types=1);

namespace GrandGala\API\Spaces;

use GrandGala\Models\Amenity;
use GrandGala\Models\Media;
use GrandGala\Models\Space;
use GrandGala\Services\Clock;
use GrandGala\Services\RateSchedule;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class SpacesController
{
    // GET /api/spaces
    public function list(Request $request): void
    {
        $spaces = Space::allActive();

        $spaceIds = [];
        foreach ($spaces as $space) {
            $spaceIds[] = $space['id'];
        }

        // Get all photos and prices at once. One query per space would be slow.
        $photos = Media::primaryPhotosOfSpaces($spaceIds);
        $rates = RateSchedule::currentRates($spaceIds);

        $result = [];
        foreach ($spaces as $space) {
            $space['photo'] = $photos[$space['id']] ?? null;
            $space['currentRates'] = $rates[$space['id']];
            $result[] = $space;
        }

        // The catalogue should still open offline, cached by the
        // service worker. 5 minutes is long enough to matter, short enough that a changed
        // price shows up on the next real visit.
        Response::cacheable($result, 300);
    }

    // GET /api/spaces/:id
    public function show(Request $request): void
    {
        // Whole-number ids only; a slug is not found.
        $id = (string) $request->param('id');
        if (!ctype_digit($id)) {
            throw new HttpError(404, 'Space not found');
        }

        $spaceId = (int) $id;
        $space = Space::findActive($spaceId);
        if ($space === null) {
            throw new HttpError(404, 'Space not found'); // also hides switched-off spaces
        }

        $photos = Media::primaryPhotosOfSpaces([$spaceId]);
        $rates = RateSchedule::currentRates([$spaceId]);

        $space['photo'] = $photos[$spaceId] ?? null;
        $space['currentRates'] = $rates[$spaceId];
        $space['rooms'] = Space::activeRoomsOf($spaceId);

        // Amenities only for stays.
        $space['amenities'] = [];
        if ($space['isAccommodation']) {
            $space['amenities'] = Amenity::activeWithPriceOn(Clock::today());
        }

        Response::cacheable($space, 300);
    }
}
