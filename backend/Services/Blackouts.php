<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Blackout;
use GrandGala\Models\Space;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * One blackout row per space. A villa blackout also blocks its rooms.
 */
class Blackouts
{
    // One row per space. A missing space rolls the whole save back.
    public static function add(string $start, string $end, string $reason, array $spaceIds): array
    {
        Dates::check($start, 'Start');
        Dates::check($end, 'End');
        if ($end < $start) {
            throw new HttpError(422, 'The end date must be on or after the start date');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpError(422, 'A reason is required');
        }
        if (mb_strlen($reason) > 255) {
            throw new HttpError(422, 'The reason must be 255 characters or fewer');
        }

        // The same space twice would give it two identical blackouts
        $spaceIds = array_values(array_unique($spaceIds));
        if (count($spaceIds) === 0) {
            throw new HttpError(422, 'Choose at least one space');
        }

        return Db::transaction(function () use ($start, $end, $reason, $spaceIds) {
            $ids = [];
            foreach ($spaceIds as $spaceId) {
                if (Space::venueAndKind((int) $spaceId) === null) {
                    throw new HttpError(404, 'Space not found'); // undoes the rows already added
                }
                $ids[] = Blackout::insert((int) $spaceId, $start, $end, $reason);
            }

            return $ids;
        });
    }

    // Removes a blackout so its dates can be booked again
    public static function delete(int $blackoutId): void
    {
        if (!Blackout::delete($blackoutId)) {
            throw new HttpError(404, 'Blackout not found');
        }
    }
}
