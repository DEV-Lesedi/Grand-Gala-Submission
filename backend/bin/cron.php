<?php

// Scheduled jobs: expire holds and quotations, complete finished stays and events.
// Run at least every 15 minutes. Never expose it over HTTP.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/config/bootstrap.php';

$results = \GrandGala\Services\CronJobs::run();
fwrite(STDOUT, json_encode($results, JSON_THROW_ON_ERROR) . PHP_EOL);
