<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Auth;
use core\Controller;

final class DashboardController extends Controller
{
    public function index(): void
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return;
        }

        $user = Auth::user();
        $this->assign('title', 'APTCMS 管理后台');
        $this->assign('content', '这里是后台入口（模型管理、SEO/GEO、插件管理将继续扩展）。');
        $this->assign('username', (string) ($user['username'] ?? '')); 
        $this->assign('role', (string) ($user['role'] ?? '')); 
        $this->display('admin/dashboard.html');
    }
}
