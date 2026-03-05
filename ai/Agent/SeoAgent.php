<?php

declare(strict_types=1);

namespace ai\Agent;

final class SeoAgent
{
    /** @param array<string,mixed> $article @return array<string,string> */
    public function generateMeta(array $article): array
    {
        $title = (string) ($article['title'] ?? '');
        $content = strip_tags((string) ($article['content'] ?? ''));
        $description = mb_substr($content, 0, 120);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => '/article/' . ($article['slug'] ?? '') . '.html',
        ];
    }
}
