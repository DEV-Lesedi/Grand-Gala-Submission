<?php

declare(strict_types=1);

namespace GrandGala\Tests\Support;

use GrandGala\Database\Migrator;
use RuntimeException;

// Shared demo database: never drop or truncate. Setup migrates; each test removes its own rows.
class TestDatabase
{
    public static function prepare(): void
    {
        if (($_ENV['APP_ENV'] ?? '') !== 'testing') {
            throw new RuntimeException('Tests need APP_ENV=testing in .env.testing');
        }

        Migrator::migrate();
    }
}
