<?php

declare(strict_types=1);

namespace GrandGala\Utils;

class Request
{
    // Signed-in user or null. Keys: id, name, surname, email, phone, role.
    private static ?array $user = null;

    private string $method;
    private string $path;
    private array $query;
    private array $body;
    private array $params = [];

    public function __construct(string $method, string $path, array $query, array $body)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
    }

    public static function fromGlobals(): Request
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return new Request($method, $path, $_GET, self::readBody());
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    // Empty array when the request had no body.
    public function all(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function allQuery(): array
    {
        return $this->query;
    }

    // Missing, or a list such as ?from[]=x, returns ''.
    public function queryText(string $key): string
    {
        $value = $this->query($key);
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    // Missing, a number, or a list returns ''.
    public function inputText(string $key): string
    {
        $value = $this->input($key);
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    // Empty body is []. Non-object JSON is a 400. PayFast form posts are parsed as a form.
    private static function readBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            $form = [];
            parse_str($raw, $form);

            return $form;
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpError(400, 'Request body must be JSON');
        }
        if (!is_array($data)) {
            throw new HttpError(400, 'Request body must be a JSON object');
        }

        return $data;
    }
}
