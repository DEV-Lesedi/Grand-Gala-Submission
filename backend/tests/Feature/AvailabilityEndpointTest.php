<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

// GET /api/availability?spaceId=&from=&to=: one space's booking calendar.
// GET /api/availability?spaceIds=1,2&from=&to=: several at once, keyed by space id (one request, not one per space).
class AvailabilityEndpointTest extends FeatureTestCase
{
    public function testItReturnsTheCalendarOfASpace(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $this->createStay($villaId, '2026-12-12', '2026-12-13', 'confirmed');

        $response = $this->get('/api/availability?spaceId=' . $villaId . '&from=2026-12-11&to=2026-12-13');

        self::assertSame(200, $response['status']);
        self::assertSame([
            ['date' => '2026-12-11', 'state' => 'free'],
            ['date' => '2026-12-12', 'state' => 'booked'],
            ['date' => '2026-12-13', 'state' => 'free'],
        ], $response['body']);
    }

    public function testItReturnsSeveralCalendarsInOneAnswerKeyedById(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $roomId = $this->createSpace(['sp_is_accommodation' => 1]);
        $this->createStay($roomId, '2026-12-12', '2026-12-13', 'confirmed');

        $response = $this->get('/api/availability?spaceIds=' . $villaId . ',' . $roomId . '&from=2026-12-12&to=2026-12-12');

        self::assertSame(200, $response['status']);
        self::assertSame([
            (string) $villaId => [['date' => '2026-12-12', 'state' => 'free']],
            (string) $roomId => [['date' => '2026-12-12', 'state' => 'booked']],
        ], $response['body']);
    }

    // A space that's switched off (or gone) is left out rather than failing the whole answer
    public function testSeveralCalendarsLeaveOutSwitchedOffSpaces(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1]);
        $offId = $this->createSpace(['sp_is_active' => 0]);

        $response = $this->get('/api/availability?spaceIds=' . $villaId . ',' . $offId . '&from=2026-12-12&to=2026-12-12');

        self::assertSame(200, $response['status']);
        self::assertSame([(string) $villaId], array_map('strval', array_keys($response['body'])));
    }

    public function testSeveralCalendarsNeedRealIdsAndAtMostTwenty(): void
    {
        $bad = $this->get('/api/availability?spaceIds=1,abc&from=2026-12-12&to=2026-12-12');
        $tooMany = $this->get('/api/availability?spaceIds=' . implode(',', range(1, 21)) . '&from=2026-12-12&to=2026-12-12');

        self::assertSame(422, $bad['status']);
        self::assertSame(422, $tooMany['status']);
    }

    public function testASwitchedOffSpaceIsNotFound(): void
    {
        $villaId = $this->createSpace(['sp_is_active' => 0]);

        $response = $this->get('/api/availability?spaceId=' . $villaId . '&from=2026-12-11&to=2026-12-13');

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Space not found'], $response['body']);
    }

    public function testAnUnknownSpaceIsNotFound(): void
    {
        $response = $this->get('/api/availability?spaceId=999999999&from=2026-12-11&to=2026-12-13');

        self::assertSame(404, $response['status']);
    }

    public function testMissingDatesAreRejected(): void
    {
        $villaId = $this->createSpace();

        $response = $this->get('/api/availability?spaceId=' . $villaId);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'From date must be YYYY-MM-DD'], $response['body']);
    }

    public function testAMissingSpaceIsRejected(): void
    {
        $response = $this->get('/api/availability?from=2026-12-11&to=2026-12-13');

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'Space is required'], $response['body']);
    }

    public function testADateSentAsAListIsRejected(): void
    {
        $villaId = $this->createSpace();

        // ?from[]=... makes PHP give an array instead of a string
        $response = $this->get('/api/availability?spaceId=' . $villaId . '&from[]=2026-12-11&to=2026-12-13');

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'From date must be YYYY-MM-DD'], $response['body']);
    }

    public function testMoreThanAYearAtOnceIsRejected(): void
    {
        $villaId = $this->createSpace();

        $response = $this->get('/api/availability?spaceId=' . $villaId . '&from=2026-01-01&to=2027-06-30');

        self::assertSame(422, $response['status']);
    }
}
