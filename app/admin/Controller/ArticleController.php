<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Audit;
use core\Auth;
use core\Controller;
use core\Response;
use core\Security;
use modules\article\ArticleService;

final class ArticleController extends Controller
{
    public function index(): void
    {
        if (!$this->guard('content.article.view')) {
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(5, min(100, (int) ($_GET['per_page'] ?? 20)));
        $keyword = trim((string) ($_GET['keyword'] ?? ''));
        $status = (string) ($_GET['status'] ?? 'all');
        $sort = (string) ($_GET['sort'] ?? 'published_at');
        $direction = (string) ($_GET['direction'] ?? 'desc');

        $service = new ArticleService();
        $total = $service->totalForAdmin($keyword, $status);
        $offset = ($page - 1) * $perPage;
        if ($offset >= $total && $total > 0) {
            $page = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
        }

        $rows = $service->listForAdmin($perPage, $offset, $keyword, $status, $sort, $direction);

        $this->assign('title', '文章管理');
        $this->assign('rows', $rows);
        $this->assign('keyword', $keyword);
        $this->assign('status_filter', $status);
        $this->assign('per_page', (string) $perPage);
        $this->assign('sort', $sort);
        $this->assign('direction', $direction);
        $this->assign('total', (string) $total);
        $this->assign('page_links', $this->buildPageLinks($page, $perPage, $total, $keyword, $status, $sort, $direction));
        $this->assign('return_url', '/admin/article/index?' . http_build_query([
            'page' => $page,
            'per_page' => $perPage,
            'keyword' => $keyword,
            'status' => $status,
            'sort' => $sort,
            'direction' => $direction,
        ]));
        $this->assign('_csrf', Security::csrfToken());
        $this->display('admin/article-list.html');
    }

    public function bulk(): void
    {
        if (!$this->guard('content.article.edit')) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Response::text('Method not allowed', 405);
            return;
        }

        $token = (string) ($_POST['_csrf'] ?? '');
        if (!Security::validateCsrf($token)) {
            Response::text('Invalid CSRF token', 403);
            return;
        }

        $action = trim((string) ($_POST['bulk_action'] ?? ''));
        $slugs = array_values(array_filter(array_map('strval', (array) ($_POST['slugs'] ?? []))));
        $service = new ArticleService();

        if ($slugs === [] || !in_array($action, ['publish', 'draft', 'delete'], true)) {
            Audit::write('article.bulk.rejected', [
                'action' => $action,
                'selected' => count($slugs),
                'user' => (string) (Auth::user()['username'] ?? ''),
            ]);
            $this->redirectToList($this->resolveReturnFromRequest());
            return;
        }

        $affected = 0;
        if ($action === 'publish') {
            $affected = $service->bulkUpdateStatusBySlugs($slugs, 1);
        } elseif ($action === 'draft') {
            $affected = $service->bulkUpdateStatusBySlugs($slugs, 0);
        } elseif ($action === 'delete') {
            if (!$this->guard('content.article.delete')) {
                return;
            }
            $affected = $service->bulkDeleteBySlugs($slugs);
        }

        Audit::write('article.bulk', [
            'action' => $action,
            'affected' => $affected,
            'selected' => count($slugs),
            'user' => (string) (Auth::user()['username'] ?? ''),
        ]);
        $this->redirectToList($this->resolveReturnFromRequest());
    }

    public function create(): void
    {
        if (!$this->guard('content.article.create')) {
            return;
        }

        $service = new ArticleService();
        $returnUrl = $this->resolveReturnFromRequest();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = (string) ($_POST['_csrf'] ?? '');
            if (!Security::validateCsrf($token)) {
                Response::text('Invalid CSRF token', 403);
                return;
            }

            $payload = [
                'slug' => (string) ($_POST['slug'] ?? ''),
                'title' => (string) ($_POST['title'] ?? ''),
                'content' => (string) ($_POST['content'] ?? ''),
                'author' => (string) ($_POST['author'] ?? ''),
                'published_at' => (string) ($_POST['published_at'] ?? ''),
                'status' => (int) ($_POST['status'] ?? 1),
            ];
            $ok = $service->create($payload);

            if ($ok) {
                Audit::write('article.create', [
                    'slug' => (string) $payload['slug'],
                    'status' => (int) $payload['status'],
                    'user' => (string) (Auth::user()['username'] ?? ''),
                ]);
                $this->redirectToList($returnUrl);
                return;
            }

            Audit::write('article.create.failed', [
                'slug' => (string) $payload['slug'],
                'user' => (string) (Auth::user()['username'] ?? ''),
            ]);
            $this->assign('error', '创建失败（可能 slug 已存在或字段不完整）');
            $this->assign('slug', (string) $payload['slug']);
            $this->assign('article_title', (string) $payload['title']);
            $this->assign('article_content', (string) $payload['content']);
            $this->assign('author', (string) $payload['author']);
            $this->assign('published_at', (string) $payload['published_at']);
            $this->assign('status', (string) $payload['status']);
        } else {
            $this->assign('error', '');
            $this->assign('slug', '');
            $this->assign('article_title', '');
            $this->assign('article_content', '');
            $this->assign('author', '');
            $this->assign('published_at', date('Y-m-d H:i:s'));
            $this->assign('status', '1');
        }

        $this->assign('_csrf', Security::csrfToken());
        $this->assign('return_url', $returnUrl !== '' ? $returnUrl : '/admin/article/index');
        $this->assign('title', '新增文章');
        $this->display('admin/article-form.html');
    }

    public function edit(string $slug): void
    {
        if (!$this->guard('content.article.edit')) {
            return;
        }

        $service = new ArticleService();
        $returnUrl = $this->resolveReturnFromRequest();
        $article = $service->findAnyBySlug($slug);
        if ($article === null) {
            Response::text('Article not found', 404);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = (string) ($_POST['_csrf'] ?? '');
            if (!Security::validateCsrf($token)) {
                Response::text('Invalid CSRF token', 403);
                return;
            }

            $ok = $service->updateBySlug($slug, [
                'title' => (string) ($_POST['title'] ?? ''),
                'content' => (string) ($_POST['content'] ?? ''),
                'author' => (string) ($_POST['author'] ?? ''),
                'published_at' => (string) ($_POST['published_at'] ?? ''),
                'status' => (int) ($_POST['status'] ?? 1),
            ]);

            if ($ok) {
                Audit::write('article.update', [
                    'slug' => $slug,
                    'status' => (int) ($_POST['status'] ?? 1),
                    'user' => (string) (Auth::user()['username'] ?? ''),
                ]);
                $this->redirectToList($returnUrl);
                return;
            }

            Audit::write('article.update.failed', [
                'slug' => $slug,
                'user' => (string) (Auth::user()['username'] ?? ''),
            ]);
            $this->assign('error', '更新失败');
            $article = $service->findAnyBySlug($slug) ?? $article;
        } else {
            $this->assign('error', '');
        }

        $this->assign('title', '编辑文章');
        $this->assign('_csrf', Security::csrfToken());
        $this->assign('return_url', $returnUrl !== '' ? $returnUrl : '/admin/article/index');
        $this->assign('slug', (string) ($article['slug'] ?? ''));
        $this->assign('article_title', (string) ($article['title'] ?? ''));
        $this->assign('article_content', (string) ($article['content'] ?? ''));
        $this->assign('author', (string) ($article['author'] ?? ''));
        $this->assign('published_at', (string) ($article['published_at'] ?? ''));
        $this->assign('status', (string) ($article['status'] ?? 1));
        $this->display('admin/article-form.html');
    }

    public function delete(string $slug): void
    {
        if (!$this->guard('content.article.delete')) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Response::text('Method not allowed', 405);
            return;
        }

        $token = (string) ($_POST['_csrf'] ?? '');
        if (!Security::validateCsrf($token)) {
            Response::text('Invalid CSRF token', 403);
            return;
        }

        $ok = (new ArticleService())->deleteBySlug($slug);
        Audit::write($ok ? 'article.delete' : 'article.delete.failed', [
            'slug' => $slug,
            'user' => (string) (Auth::user()['username'] ?? ''),
        ]);
        $this->redirectToList();
    }

    private function guard(string $permission): bool
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return false;
        }

        if (!Auth::can($permission) && !Auth::can('*')) {
            Response::text('Forbidden', 403);
            return false;
        }

        return true;
    }


    private function redirectToList(string $candidateReturn = ''): void
    {
        $return = trim($candidateReturn);
        if ($this->isSafeArticleReturnUrl($return)) {
            header('Location: ' . $return);
            return;
        }

        header('Location: /admin/article/index');
    }

    private function resolveReturnFromRequest(): string
    {
        $raw = (string) ($_POST['_return'] ?? $_GET['_return'] ?? '');
        $raw = trim($raw);
        return $this->isSafeArticleReturnUrl($raw) ? $raw : '';
    }

    private function isSafeArticleReturnUrl(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        return $path === '/admin/article/index';
    }

    /** @return array<int,array<string,string>> */
    private function buildPageLinks(int $page, int $perPage, int $total, string $keyword, string $status, string $sort, string $direction): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);

        $links = [];
        for ($i = $start; $i <= $end; $i++) {
            $query = http_build_query([
                'page' => $i,
                'per_page' => $perPage,
                'keyword' => $keyword,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
            ]);
            $links[] = [
                'label' => (string) $i,
                'url' => '/admin/article/index?' . $query,
                'current' => $i === $page ? '1' : '0',
            ];
        }

        return $links;
    }
}
