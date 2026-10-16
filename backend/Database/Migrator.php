<?php

declare(strict_types=1);

namespace GrandGala\Database;

use GrandGala\Utils\Db;
use PDO;
use RuntimeException;

// Numbered .sql files in Database/Migrations, each only once.
// schema_migrations remembers which files have already run.
class Migrator
{
    // Files that ran this time. Tests may pass their own folder.
    public static function migrate(?string $folder = null): array
    {
        if ($folder === null) {
            $folder = __DIR__ . '/Migrations';
        }

        self::createDatabaseIfMissing();
        $pdo = Db::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $alreadyRun = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        // Names sort so 001 runs before 002.
        $ranNow = [];
        foreach (glob($folder . '/*.sql') as $path) {
            $filename = basename($path);
            if (in_array($filename, $alreadyRun)) {
                continue;
            }

            // No transaction: MySQL applies CREATE and ALTER immediately.
            foreach (self::statementsIn(file_get_contents($path)) as $statement) {
                $pdo->exec($statement);
            }

            $record = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
            $record->execute([$filename]);
            $ranNow[] = $filename;
        }

        return $ranNow;
    }

    // Drops "--" lines, then splits on ";". Never put a ";" inside migration text.
    private static function statementsIn(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            if (!str_starts_with(trim($line), '--')) {
                $lines[] = $line;
            }
        }

        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }

        return $statements;
    }

    // A new server may not have the database yet.
    private static function createDatabaseIfMissing(): void
    {
        $config = require dirname(__DIR__) . '/config/database.php';

        // The name is interpolated, so only letters, numbers, and underscore.
        if (preg_match('/^[A-Za-z0-9_]+$/', $config['name']) !== 1) {
            throw new RuntimeException('DB_NAME may only contain letters, numbers and _');
        }

        $server = new PDO(
            'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';charset=utf8mb4',
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $config['name'] . '` CHARACTER SET utf8mb4');
    }
}
