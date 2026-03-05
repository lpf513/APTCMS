<?php

declare(strict_types=1);

namespace core;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;

    /** @param array<string,mixed> $config */
    public static function conn(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $dbname = (string) ($config['dbname'] ?? 'aptcms');
        $user = (string) ($config['user'] ?? 'root');
        $password = (string) ($config['password'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
        self::$pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }
}
