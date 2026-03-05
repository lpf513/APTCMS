<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Auth;
use core\Controller;
use core\PluginManager;
use core\Response;
use core\Security;

final class PluginController extends Controller
{
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }

        $root = __DIR__ . '/../../../plugins';
        $rows = (new PluginManager())->list($root);
        $this->assign('title', '插件管理');
        $this->assign('_csrf', Security::csrfToken());
        $this->assign('rows', $rows);
        $this->display('admin/plugins.html');
    }

    public function change(): void
    {
        if (!$this->guard()) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Response::text('Method Not Allowed', 405);
            return;
        }

        $token = (string) ($_POST['_csrf'] ?? '');
        if (!Security::validateCsrf($token)) {
            Response::text('Invalid CSRF token', 403);
            return;
        }

        $action = trim((string) ($_POST['action_name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));

        $manager = new PluginManager();
        $pluginRoot = __DIR__ . '/../../../plugins';

        if ($action === 'install') {
            $result = $manager->installFromUrl($pluginRoot, $url);
            if ($result['ok'] !== true) {
                Response::text('Plugin install failed: ' . $result['message'], 400);
                return;
            }
            header('Location: /admin/plugin/index');
            return;
        }

        if ($action === 'upgrade') {
            if ($code === '') {
                Response::text('Plugin code required', 400);
                return;
            }
            $result = $manager->upgradeFromUrl($pluginRoot, $code, $url);
            if ($result['ok'] !== true) {
                Response::text('Plugin upgrade failed: ' . $result['message'], 400);
                return;
            }
            header('Location: /admin/plugin/index');
            return;
        }

        if ($code === '') {
            Response::text('Plugin code required', 400);
            return;
        }

        $ok = match ($action) {
            'enable' => $manager->enable($code),
            'disable' => $manager->disable($code),
            'uninstall' => $manager->uninstall($code),
            'restore' => $manager->restore($code),
            default => false,
        };

        if (!$ok) {
            Response::text('Plugin action failed', 500);
            return;
        }

        header('Location: /admin/plugin/index');
    }

    private function guard(): bool
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return false;
        }

        if (!Auth::can('system.plugin.view') && !Auth::can('*')) {
            Response::text('Forbidden', 403);
            return false;
        }

        return true;
    }
}
