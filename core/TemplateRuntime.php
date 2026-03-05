<?php

declare(strict_types=1);

namespace core;

use modules\article\ArticleService;

final class TemplateRuntime
{
    /** @return array<int,array<string,mixed>> */
    public static function list(string $model, int $limit = 10): array
    {
        if ($model === 'article') {
            return (new ArticleService())->list($limit);
        }

        return [];
    }

    /** @param array<string,mixed> $context */
    public static function aiSummary(array $context): string
    {
        $content = (string) ($context['content'] ?? '');
        if ($content === '') {
            return '暂无摘要。';
        }

        return mb_substr(strip_tags($content), 0, 120) . '...';
    }

    /** @param array<string,mixed> $context @return array<int,array<string,mixed>> */
    public static function aiRelated(array $context, int $limit = 5): array
    {
        $currentSlug = (string) ($context['slug'] ?? '');
        $articles = (new ArticleService())->list($limit + 1);
        $filtered = array_filter($articles, static fn(array $item): bool => ($item['slug'] ?? '') !== $currentSlug);
        return array_slice(array_values($filtered), 0, $limit);
    }
}
