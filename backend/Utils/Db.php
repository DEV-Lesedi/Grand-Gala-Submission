<?php

declare(strict_types=1);

namespace GrandGala\Utils;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

// Shared connection. Availability, stock, or rate writes must use transaction().
class Db
{
    // One connection for the whole request.
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }

        return self::$pdo;
    }

    // New connection. App code uses pdo(); tests use this to act as a second user.
    public static function connect(): PDO
    {
        $config = require dirname(__DIR__) . '/config/database.php';
        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4';

        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // keeps ints as ints
        ]);

        // Server is UTC. Set venue time so NOW() matches Clock::now().
        // Otherwise a hold of NOW() + 15 minutes can already look expired.
        $pdo->exec("SET time_zone = '" . self::venueOffset() . "'");

        return $pdo;
    }

    // Offset from UTC, such as '+02:00'.
    private static function venueOffset(): string
    {
        $zone = new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'Africa/Johannesburg');

        return (new DateTimeImmutable('now', $zone))->format('P');
    }

    // Placeholders only. The ids go to execute(), never into the SQL.
    public static function questionMarks(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    // On throw, the work is rolled back and the error is rethrown.
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();

        // Join an open transaction; the outer call commits or rolls back.
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $pdo->commit();

        return $result;
    }
}
