<?php

declare(strict_types=1);

namespace core;

final class Router
{
    /**
     * @return array{app:string,controller:string,action:string,params:array<int,string>}
     */
    public function parse(string $uri): array
    {
        $path = trim((string) parse_url($uri, PHP_URL_PATH), '/');

        if ($path === '') {
            return ['app' => 'home', 'controller' => 'home', 'action' => 'index', 'params' => []];
        }

        if ($path === 'sitemap.xml') {
            return ['app' => 'home', 'controller' => 'seo', 'action' => 'sitemap', 'params' => []];
        }

        if ($path === 'robots.txt') {
            return ['app' => 'home', 'controller' => 'seo', 'action' => 'robots', 'params' => []];
        }

        if ($path === 'ai/content.json') {
            return ['app' => 'api', 'controller' => 'ai', 'action' => 'content', 'params' => []];
        }

        if ($path === 'ai/knowledge.json') {
            return ['app' => 'api', 'controller' => 'ai', 'action' => 'knowledge', 'params' => []];
        }

        if ($path === 'healthz') {
            return ['app' => 'api', 'controller' => 'system', 'action' => 'health', 'params' => []];
        }

        $segments = array_values(array_filter(explode('/', $path)));

        if (($segments[0] ?? '') === 'admin') {
            return [
                'app' => 'admin',
                'controller' => $segments[1] ?? 'dashboard',
                'action' => $segments[2] ?? 'index',
                'params' => array_slice($segments, 3),
            ];
        }


        if (($segments[0] ?? '') === 'install') {
            return [
                'app' => 'install',
                'controller' => $segments[1] ?? 'index',
                'action' => $segments[2] ?? 'index',
                'params' => array_slice($segments, 3),
            ];
        }

        if (($segments[0] ?? '') === 'api') {
            return [
                'app' => 'api',
                'controller' => $segments[1] ?? 'ai',
                'action' => $this->normalizeApiAction($segments[2] ?? 'index'),
                'params' => array_slice($segments, 3),
            ];
        }

        $controller = $segments[0];
        if (($segments[1] ?? null) !== null && str_ends_with($segments[1], '.html')) {
            return [
                'app' => 'home',
                'controller' => $controller,
                'action' => 'detail',
                'params' => [basename($segments[1], '.html')],
            ];
        }

        return [
            'app' => 'home',
            'controller' => $controller,
            'action' => $segments[1] ?? 'index',
            'params' => array_slice($segments, 2),
        ];
    }

    private function normalizeApiAction(string $segment): string
    {
        return str_replace(['-', '.json'], ['', ''], $segment);
    }
}
