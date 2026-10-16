<?php

declare(strict_types=1);

use GrandGala\Tests\Support\TestDatabase;

// With APP_ENV=testing, config/bootstrap.php loads .env.testing instead of .env
putenv('APP_ENV=testing');

require dirname(__DIR__) . '/config/bootstrap.php';

TestDatabase::prepare();
