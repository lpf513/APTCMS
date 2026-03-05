<?php

declare(strict_types=1);

namespace core;

final class RateLimiter
{
    public function hit(string $key, int $windowSeconds, int $maxAttempts): bool
    {
        $now = time();
        $payload = $this->read($key);
        $attempts = array_values(array_filter($payload['attempts'] ?? [], static fn(int $ts): bool => $ts >= ($now - $windowSeconds)));

        $attempts[] = $now;
        $this->write($key, ['attempts' => $attempts]);

        return count($attempts) <= $maxAttempts;
    }

    public function check(string $key, int $windowSeconds, int $maxAttempts): bool
    {
        $now = time();
        $payload = $this->read($key);
        $attempts = array_values(array_filter($payload['attempts'] ?? [], static fn(int $ts): bool => $ts >= ($now - $windowSeconds)));
        $this->write($key, ['attempts' => $attempts]);

        return count($attempts) < $maxAttempts;
    }

    public function reset(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** @return array<string,mixed> */
    private function read(string $key): array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return ['attempts' => []];
        }

        $payload = json_decode((string) file_get_contents($file), true);
        return is_array($payload) ? $payload : ['attempts' => []];
    }

    /** @param array<string,mixed> $payload */
    private function write(string $key, array $payload): void
    {
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->path($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function path(string $key): string
    {
        return __DIR__ . '/../cache/rate_' . md5($key) . '.json';
    }
}
