#!/usr/bin/env php
<?php

declare(strict_types=1);

use GrandGala\Database\Migrator;

require dirname(__DIR__) . '/config/bootstrap.php';

$applied = Migrator::migrate();
if ($applied === []) {
    fwrite(STDOUT, "Database is up to date.\n");
    exit(0);
}

foreach ($applied as $filename) {
    fwrite(STDOUT, "Applied {$filename}\n");
}
