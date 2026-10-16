<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Blackout;
use GrandGala\Models\Media;
use GrandGala\Models\Space;
use GrandGala\Models\SpaceRate;
use GrandGala\Utils\HttpError;

/*
 * A new space starts off, and can be switched on only once it has a price.
 * A room's villa never changes. Prices go through Rates; blackouts through Blackouts.
 */
class SpacesEditor
{
    private const TYPES = ['villa', 'room', 'garden', 'marquee', 'pangola', 'pool', 'hall', 'other'];

    // Every space, including switched-off ones, with prices, blackouts and photo.
    public static function all(): array
    {
        return self::withDetails(Space::allForStaff());
    }

    // A room also needs parentId, its villa. Description is optional.
    public static function add(array $input): array
    {
        $space = [
            'venueId' => $input['venueId'] ?? null,
            'parentId' => $input['parentId'] ?? null,
            'name' => $input['name'] ?? '',
            'type' => $input['type'] ?? '',
            'capacity' => $input['capacity'] ?? 0,
            'isAccommodation' => $input['isAccommodation'] ?? false,
            'isHireable' => $input['isHireable'] ?? false,
            'description' => $input['description'] ?? null,
        ];
        $space = self::checkBasics($space);

        if (!is_int($space['venueId'])) {
            throw new HttpError(422, 'Choose a venue');
        }
        if (!Space::venueExists($space['venueId'])) {
            throw new HttpError(404, 'Venue not found');
        }

        if ($space['type'] === 'room') {
            if (!is_int($space['parentId'])) {
                throw new HttpError(422, 'A villa room needs its villa');
            }
            $villa = Space::findForStaff($space['parentId']);
            if ($villa === null || $villa['type'] !== 'villa' || $villa['venueId'] !== $space['venueId']) {
                throw new HttpError(422, 'A villa room needs a villa at the same venue');
            }
        } elseif ($space['parentId'] !== null) {
            throw new HttpError(422, 'Only a villa room can belong to a villa');
        }

        $spaceId = Space::insert($space);

        return self::find($spaceId);
    }

    // Only sent fields change. The venue and the villa cannot.
    public static function update(int $spaceId, array $changes): array
    {
        $space = Space::findForStaff($spaceId);
        if ($space === null) {
            throw new HttpError(404, 'Space not found');
        }
        $wasRoom = $space['type'] === 'room';
        $wasActive = $space['isActive'];

        $editable = ['name', 'type', 'capacity', 'isAccommodation', 'isHireable', 'description', 'isActive'];
        foreach ($editable as $field) {
            // array_key_exists (not isset) so that "description": null clears the description
            if (array_key_exists($field, $changes)) {
                $space[$field] = $changes[$field];
            }
        }
        $space = self::checkBasics($space);

        // A room stays a room. Anything else cannot become one.
        $isRoom = $space['type'] === 'room';
        if ($isRoom !== $wasRoom) {
            throw new HttpError(422, "A villa room can't change type, and other spaces can't become one");
        }
        if (!is_bool($space['isActive'])) {
            throw new HttpError(422, 'isActive must be true or false');
        }
        // Needs a price, running now or starting later, before guests can see it.
        $switchingOn = $space['isActive'] && !$wasActive;
        if ($switchingOn && !SpaceRate::hasPriceFrom($spaceId, Clock::today())) {
            throw new HttpError(422, 'Add a price before switching this space on');
        }

        Space::update($space);

        return self::find($spaceId);
    }

    // One space with its prices, blackouts and photo
    private static function find(int $spaceId): array
    {
        $spaces = self::withDetails([Space::findForStaff($spaceId)]);

        return $spaces[0];
    }

    // Adds each space's prices, blackouts and photo. One query each for all spaces together.
    private static function withDetails(array $spaces): array
    {
        $spaceIds = [];
        foreach ($spaces as $space) {
            $spaceIds[] = $space['id'];
        }

        $rates = SpaceRate::allForSpaces($spaceIds);
        $blackouts = Blackout::forSpaces($spaceIds);
        $photos = Media::primaryPhotosOfSpaces($spaceIds);

        $result = [];
        foreach ($spaces as $space) {
            $space['rates'] = $rates[$space['id']] ?? [];
            $space['blackouts'] = $blackouts[$space['id']] ?? [];
            $space['photo'] = $photos[$space['id']] ?? null;
            $result[] = $space;
        }

        return $result;
    }

    // The checks shared by add() and update(). Throws a 422 for the first problem found.
    // Returns the space with its name trimmed.
    private static function checkBasics(array $space): array
    {
        if (!is_string($space['name']) || trim($space['name']) === '') {
            throw new HttpError(422, 'Give the space a name');
        }
        $space['name'] = trim($space['name']);
        if (mb_strlen($space['name']) > 100) {
            throw new HttpError(422, 'The name must be 100 characters or fewer');
        }
        if (!in_array($space['type'], self::TYPES, true)) {
            throw new HttpError(422, 'Unknown space type');
        }
        if (!is_int($space['capacity']) || $space['capacity'] < 1) {
            throw new HttpError(422, 'Capacity must be a whole number of at least 1');
        }
        if (!is_bool($space['isAccommodation'])) {
            throw new HttpError(422, 'isAccommodation must be true or false');
        }
        if (!is_bool($space['isHireable'])) {
            throw new HttpError(422, 'isHireable must be true or false');
        }
        if (!$space['isAccommodation'] && !$space['isHireable']) {
            throw new HttpError(422, 'Tick at least one: overnight stays or event hire');
        }
        if ($space['description'] !== null && !is_string($space['description'])) {
            throw new HttpError(422, 'The description must be text');
        }

        return $space;
    }
}
