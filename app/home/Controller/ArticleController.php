<?php

declare(strict_types=1);

namespace app\home\Controller;

use ai\Agent\GeoAgent;
use ai\Agent\SeoAgent;
use core\Controller;
use core\Response;
use modules\article\ArticleService;

final class ArticleController extends Controller
{
    public function index(): void
    {
        $this->assign('title', '文章列表');
        $this->assign('articles', (new ArticleService())->list(20));
        $this->display('article/list.html');
    }

    public function detail(string $slug): void
    {
        $article = (new ArticleService())->findBySlug($slug);
        if ($article === null) {
            Response::text('Article not found', 404);
            return;
        }

        $seo = (new SeoAgent())->generateMeta($article);
        $geo = (new GeoAgent())->buildKnowledge($article);

        $this->assign('title', $article['title']);
        $this->assign('slug', $article['slug']);
        $this->assign('content', $article['content']);
        $this->assign('author', $article['author']);
        $this->assign('published_at', $article['published_at']);
        $this->assign('seo_title', $seo['title']);
        $this->assign('seo_description', $seo['description']);
        $this->assign('canonical', $seo['canonical']);
        $this->assign('json_ld', json_encode($geo['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->display('article/detail.html');
    }
}
