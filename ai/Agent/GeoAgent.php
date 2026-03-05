<?php

declare(strict_types=1);

namespace ai\Agent;

final class GeoAgent
{
    /** @param array<string,mixed> $article @return array<string,mixed> */
    public function buildKnowledge(array $article): array
    {
        return [
            'summary' => mb_substr((string) ($article['content'] ?? ''), 0, 100),
            'qa' => [
                ['q' => '这篇内容讲什么？', 'a' => (string) ($article['title'] ?? '')],
            ],
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => (string) ($article['title'] ?? ''),
                'author' => (string) ($article['author'] ?? 'Unknown'),
                'datePublished' => (string) ($article['published_at'] ?? ''),
            ],
        ];
    }
}
