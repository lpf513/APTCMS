<?php

declare(strict_types=1);

namespace app\install\Controller;

use core\Controller;
use core\InstallService;

final class IndexController extends Controller
{
    public function index(): void
    {
        $this->assign('title', 'APTCMS 安装向导');
        $this->assign('message', '请先在 config/app.php 中设置 db.enabled=true 和数据库连接。');
        $this->display('install/index.html');
    }

    public function run(): void
    {
        $result = (new InstallService())->run($this->config);
        $this->assign('title', 'APTCMS 安装结果');
        $this->assign('message', (string) ($result['message'] ?? 'unknown'));
        $this->display('install/index.html');
    }
}
