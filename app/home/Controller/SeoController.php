<?php

declare(strict_types=1);

namespace app\home\Controller;

use core\Controller;
use modules\article\ArticleService;

final class SeoController extends Controller
{
    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'http://127.0.0.1:8080'), '/');
        $articles = (new ArticleService())->list(50000, 0);

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        echo "  <url><loc>{$baseUrl}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n";

        foreach ($articles as $article) {
            $slug = htmlspecialchars((string) ($article['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
            $lastmod = (string) ($article['updated_at'] ?? $article['published_at'] ?? date('Y-m-d H:i:s'));
            $lastmodIso = date('c', (int) strtotime($lastmod));
            echo "  <url><loc>{$baseUrl}/article/{$slug}.html</loc><lastmod>{$lastmodIso}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
        }

        echo "</urlset>\n";
    }

    public function robots(): void
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'http://127.0.0.1:8080'), '/');
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin/\n";
        echo "Sitemap: {$baseUrl}/sitemap.xml\n";
    }
}
