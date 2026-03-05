<?php

declare(strict_types=1);

namespace core;

final class Response
{
    /** @param array<string,string> $headers */
    public static function json(array $payload, int $code = 200, array $headers = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        self::injectCommonHeaders();
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,string> $headers */
    public static function text(string $text, int $code = 200, array $headers = []): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        self::injectCommonHeaders();
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }

        echo $text;
    }

    private static function injectCommonHeaders(): void
    {
        $requestId = (string) ($_SERVER['APT_REQUEST_ID'] ?? '');
        if ($requestId !== '') {
            header('X-Request-Id: ' . $requestId);
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
