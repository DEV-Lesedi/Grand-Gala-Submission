<?php

declare(strict_types=1);

namespace GrandGala\Tests\Support;

use RuntimeException;

// Serves public/index.php with php -S on a free port for the whole test run.
class ApiServer
{
    // The running php -S process (null until started)
    private static $process = null;

    private static int $port = 0;

    public static function baseUrl(): string
    {
        if (self::$process === null) {
            self::start();
        }

        return 'http://127.0.0.1:' . self::$port;
    }

    private static function start(): void
    {
        $root = dirname(__DIR__, 2);
        self::$port = self::freePort();

        $env = getenv();
        $env['APP_ENV'] = 'testing';
        // The null device's path differs by OS: /dev/null on Unix, NUL on Windows.
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root . '/public', $root . '/public/index.php'],
            [1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']],
            $pipes,
            $root,
            $env,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start php -S for the feature tests');
        }

        self::$process = $process;
        register_shutdown_function([ApiServer::class, 'stop']);

        self::waitUntilListening();
    }

    // Stops php -S when the tests finish
    public static function stop(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port for the test server');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitUntilListening(): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', self::$port);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException('php -S did not start within 5 seconds');
    }
}
