<?php

declare(strict_types=1);

namespace GrandGala\API;

use GrandGala\Services\Clock;
use GrandGala\Utils\Db;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;
use Throwable;

// GET /api/health: always 200; database up or down is in the body.
class HealthController
{
    public function __invoke(Request $request): void
    {
        try {
            Db::pdo()->query('SELECT 1');
            $database = 'up';
        } catch (Throwable $error) {
            $database = 'down';
        }

        Response::json([
            'ok' => true,
            'today' => Clock::today(),
            'database' => $database,
        ]);
    }
}
