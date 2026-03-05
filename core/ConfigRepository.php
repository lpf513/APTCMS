<?php

declare(strict_types=1);

namespace core;

use Throwable;

final class ConfigRepository
{
    /** @return array<string,mixed> */
    public static function load(string $basePath): array
    {
        $config = require $basePath . '/config/app.php';
        if (!is_array($config)) {
            return [];
        }

        if (($config['db']['enabled'] ?? false) !== true) {
            return $config;
        }

        try {
            $pdo = DB::conn($config['db']);
            $rows = $pdo->query('SELECT key_name, value_json FROM apt_system_setting')->fetchAll();
            if (!is_array($rows)) {
                return $config;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['key_name'] ?? ''));
                if ($key === '') {
                    continue;
                }

                $value = json_decode((string) ($row['value_json'] ?? ''), true);
                if ($value === null && strtolower(trim((string) ($row['value_json'] ?? ''))) !== 'null') {
                    continue;
                }

                self::setByDotPath($config, $key, $value);
            }
        } catch (Throwable) {
            return $config;
        }

        return $config;
    }

    /** @param array<string,mixed> $target */
    private static function setByDotPath(array &$target, string $path, mixed $value): void
    {
        $segments = array_values(array_filter(explode('.', $path), static fn(string $v): bool => $v !== ''));
        if ($segments === []) {
            return;
        }

        $cursor = &$target;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        if ($last !== null) {
            $cursor[$last] = $value;
        }
    }
}
