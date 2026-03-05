<?php

declare(strict_types=1);

namespace core;

use Throwable;

final class AdminUserService
{
    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $fromDb = $this->findByUsernameFromDb($username);
        if (is_array($fromDb)) {
            return $fromDb;
        }

        $adminConfig = require __DIR__ . '/../config/admin.php';
        $user = $adminConfig['users'][$username] ?? null;
        return is_array($user) ? $user : null;
    }

    /** @return array<string,mixed>|null */
    private function findByUsernameFromDb(string $username): ?array
    {
        $app = require __DIR__ . '/../config/app.php';
        if (($app['db']['enabled'] ?? false) !== true) {
            return null;
        }

        try {
            $pdo = DB::conn($app['db']);
            $stmt = $pdo->prepare('SELECT username, password_hash, role_code AS role, status FROM apt_admin_user WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }
            if ((int) ($row['status'] ?? 0) !== 1) {
                return null;
            }
            return $row;
        } catch (Throwable) {
            return null;
        }
    }
}
