<?php

declare(strict_types=1);

namespace GrandGala\Utils;

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Keep unicode as-is, and throw if the value cannot be encoded.
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function error(string $message, int $status): void
    {
        self::json(['error' => $message], $status);
    }

    // Like json(), but for a GET a browser or the app's offline cache (service worker) may
    // reuse without asking again: sends Cache-Control + an ETag, and answers 304 with no body
    // when the caller's If-None-Match already matches (the data hasn't changed since).
    public static function cacheable(array $data, int $maxAgeSeconds): void
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $etag = '"' . hash('sha256', $body) . '"';

        header('Cache-Control: public, max-age=' . $maxAgeSeconds);
        header('ETag: ' . $etag);

        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
    }
}
