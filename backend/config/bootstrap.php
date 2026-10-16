<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. From Grand_Gala_Backend run: composer install\n");
    exit(1);
}

require $autoload;

// getenv(), not $_ENV: this setup does not copy terminal variables into $_ENV.
$envFile = '.env';
if (getenv('APP_ENV') === 'testing') {
    $envFile = '.env.testing';
}
if (is_file($root . '/' . $envFile)) {
    Dotenv\Dotenv::createImmutable($root, $envFile)->safeLoad();
}

// No .env on Railway. Copy real env vars into $_ENV; a loaded .env file already won.
foreach (getenv() as $name => $value) {
    if (!isset($_ENV[$name])) {
        $_ENV[$name] = $value;
    }
}

// A terminal APP_ENV wins over the .env file.
if (getenv('APP_ENV') !== false) {
    $_ENV['APP_ENV'] = getenv('APP_ENV');
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Johannesburg');

// A fixed "today" is only applied for tests.
if ($envFile === '.env.testing' && isset($_ENV['APP_TODAY'])) {
    GrandGala\Services\Clock::freeze($_ENV['APP_TODAY']);
}
