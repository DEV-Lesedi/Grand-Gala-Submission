#!/usr/bin/env php
<?php

// Loads demo data. Safe to run again: it only adds what is missing.

declare(strict_types=1);

use GrandGala\Database\Migrator;
use GrandGala\Database\Seeds\AccountsSeed;
use GrandGala\Database\Seeds\DemoBookingsSeed;
use GrandGala\Database\Seeds\DemoSeed;
use GrandGala\Database\Seeds\VenueSeed;

require dirname(__DIR__) . '/config/bootstrap.php';

if (($_ENV['APP_ENV'] ?? 'local') === 'production') {
    fwrite(STDERR, "Refusing to seed while APP_ENV=production.\n");
    exit(1);
}

Migrator::migrate();
// The venue, booking types and main spaces first: the other seeds build on them.
VenueSeed::run();
// AccountsSeed first: user_type rows (including customer) that the other seeds need.
AccountsSeed::run();
DemoSeed::run();
DemoBookingsSeed::run();

echo "Seeded the demo data.\n";
