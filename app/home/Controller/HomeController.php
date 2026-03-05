<?php

declare(strict_types=1);

namespace app\home\Controller;

use core\Controller;
use modules\article\ArticleService;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->assign('title', 'APTCMS');
        $this->assign('content', 'AI 原生轻量 CMS，默认即 SEO/GEO 友好。');
        $this->assign('articles', (new ArticleService())->list(10));
        $this->display('home/index.html');
    }
}
