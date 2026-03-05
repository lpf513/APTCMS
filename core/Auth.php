<?php

declare(strict_types=1);

namespace core;

use Throwable;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function login(string $username, string $role): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['admin_user'] = ['username' => $username, 'role' => $role];
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['admin_user']);
        session_regenerate_id(true);
    }

    /** @return array<string,string>|null */
    public static function user(): ?array
    {
        self::start();
        $user = $_SESSION['admin_user'] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        $role = $user['role'] ?? 'editor';
        $permissions = self::permissionsByRole((string) $role);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** @return array<int,string> */
    private static function permissionsByRole(string $role): array
    {
        $app = ConfigRepository::load(__DIR__ . '/..');
        if (($app['db']['enabled'] ?? false) === true) {
            try {
                $pdo = DB::conn($app['db']);
                $stmt = $pdo->prepare('SELECT permission_code FROM apt_rbac_role_permission WHERE role_code = :role');
                $stmt->execute([':role' => $role]);
                $rows = $stmt->fetchAll();
                if (is_array($rows) && $rows !== []) {
                    $permissions = [];
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['permission_code'])) {
                            $permissions[] = (string) $row['permission_code'];
                        }
                    }
                    return $permissions;
                }
            } catch (Throwable) {
                // fallback to config file rbac
            }
        }

        $rbac = require __DIR__ . '/../config/rbac.php';
        $permissions = $rbac['roles'][$role] ?? [];
        return is_array($permissions) ? array_values($permissions) : [];
    }
}
