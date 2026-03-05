<?php

declare(strict_types=1);

namespace core;

final class Audit
{
    private const MAX_LOG_BYTES = 5242880;
    private const MAX_ARCHIVES = 5;

    public static function write(string $event, array $context = []): void
    {
        $line = json_encode([
            'time' => date('c'),
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($line)) {
            return;
        }

        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir . '/audit.log';
        self::rotateIfNeeded($file);
        file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
    }

    /** @return array<int,array<string,mixed>> */
    public static function latest(int $limit = 100): array
    {
        $file = __DIR__ . '/../cache/audit.log';
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $lines = array_slice($lines, -$limit);
        $rows = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string,string> $filters */
    public static function search(array $filters, int $limit = 100): array
    {
        $file = __DIR__ . '/../cache/audit.log';
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $event = trim($filters['event'] ?? '');
        $ip = trim($filters['ip'] ?? '');
        $query = trim($filters['query'] ?? '');
        $limit = max(1, min(500, $limit));

        $matched = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            if ($event !== '' && !str_contains((string) ($row['event'] ?? ''), $event)) {
                continue;
            }
            if ($ip !== '' && !str_contains((string) ($row['ip'] ?? ''), $ip)) {
                continue;
            }
            if ($query !== '') {
                $context = json_encode($row['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!str_contains((string) ($row['event'] ?? ''), $query)
                    && !str_contains((string) ($row['ip'] ?? ''), $query)
                    && !str_contains((string) $context, $query)
                ) {
                    continue;
                }
            }
            $matched[] = $row;
            if (count($matched) >= $limit) {
                break;
            }
        }

        return $matched;
    }

    private static function rotateIfNeeded(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $size = filesize($file);
        if (!is_int($size) || $size < self::MAX_LOG_BYTES) {
            return;
        }

        $dir = dirname($file);
        $timestamp = date('Ymd-His');
        $archive = $dir . '/audit-' . $timestamp . '.log';
        rename($file, $archive);

        $archives = glob($dir . '/audit-*.log');
        if (!is_array($archives)) {
            return;
        }

        rsort($archives);
        $archives = array_slice($archives, self::MAX_ARCHIVES);
        foreach ($archives as $old) {
            if (is_file($old)) {
                unlink($old);
            }
        }
    }
}
