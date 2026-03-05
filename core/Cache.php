<?php

declare(strict_types=1);

namespace core;

final class Cache
{
    public function get(string $key): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }

        return $payload['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 300): void
    {
        $payload = [
            'expires_at' => time() + $ttl,
            'value' => $value,
        ];
        file_put_contents($this->path($key), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function path(string $key): string
    {
        return __DIR__ . '/../cache/data_' . md5($key) . '.json';
    }
}
