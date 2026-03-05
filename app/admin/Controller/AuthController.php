<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Audit;
use core\AdminUserService;
use core\Auth;
use core\Controller;
use core\Response;
use core\Security;

final class AuthController extends Controller
{
    public function login(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $scope = 'admin_login_' . $ip;
            $max = (int) (($this->config['security']['login_rate_limit'] ?? 5));
            $window = (int) (($this->config['security']['login_rate_window'] ?? 300));

            if (!Security::checkLoginAttempts($scope, $max, $window)) {
                Audit::write('admin.login.blocked', ['ip' => $ip, 'window' => $window]);
                Response::text('Too many attempts, please retry later', 429);
                return;
            }

            $token = (string) ($_POST['_csrf'] ?? '');
            if (!Security::validateCsrf($token)) {
                Audit::write('admin.login.csrf_failed', ['ip' => $ip]);
                Response::text('Invalid CSRF token', 403);
                return;
            }

            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $user = (new AdminUserService())->findByUsername($username);

            if (!is_array($user) || !$this->verifyPassword($password, $user)) {
                Security::increaseLoginAttempts($scope, $window, $max);
                Audit::write('admin.login.failed', ['username' => $username, 'ip' => $ip]);
                $this->assign('error', '用户名或密码错误');
                $this->assign('_csrf', Security::rotateCsrfToken());
                $this->display('admin/login.html');
                return;
            }

            Security::resetLoginAttempts($scope);
            Auth::login($username, (string) ($user['role'] ?? 'editor'));
            Security::rotateCsrfToken();
            Audit::write('admin.login.success', ['username' => $username, 'ip' => $ip]);
            header('Location: /admin/dashboard/index');
            return;
        }

        $this->assign('_csrf', Security::csrfToken());
        $this->assign('error', '');
        $this->display('admin/login.html');
    }

    public function logout(): void
    {
        $user = Auth::user();
        Audit::write('admin.logout', ['username' => $user['username'] ?? '']);
        Auth::logout();
        header('Location: /admin/auth/login');
    }

    /** @param array<string,mixed> $user */
    private function verifyPassword(string $password, array $user): bool
    {
        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash !== '' && password_verify($password, $hash)) {
            return true;
        }

        $legacy = (string) ($user['password'] ?? '');
        return $legacy !== '' && hash_equals($legacy, $password);
    }
}
