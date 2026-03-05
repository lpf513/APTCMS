<?php

declare(strict_types=1);

namespace app\api\Controller;

use ai\Agent\GeoAgent;
use core\Audit;
use core\Controller;
use core\Response;
use core\Security;
use modules\article\ArticleService;

final class AiController extends Controller
{
    public function content(): void
    {
        if (!$this->enforceRateLimit('content')) {
            return;
        }
        $service = new ArticleService();
        [$page, $limit, $offset, $updatedAfter, $error] = $this->pagination();
        if ($error !== null) {
            $this->badRequest($error);
            return;
        }
        $data = $service->list($limit, $offset, $updatedAfter);
        $total = $service->total($updatedAfter);
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? ''), '/');

        foreach ($data as &$row) {
            $slug = (string) ($row['slug'] ?? '');
            $row['url'] = $baseUrl . '/article/' . rawurlencode($slug) . '.html';
            $row['checksum'] = md5((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        unset($row);

        $payload = [
            'version' => '1.1',
            'generated_at' => $this->resolveGeneratedAt($data),
            'items' => $data,
            'meta' => $this->meta($page, $limit, $offset, $total, count($data), $updatedAfter),
        ];

        $this->respondWithEtag($payload);
    }

    public function knowledge(): void
    {
        if (!$this->enforceRateLimit('knowledge')) {
            return;
        }
        $service = new ArticleService();
        $agent = new GeoAgent();
        [$page, $limit, $offset, $updatedAfter, $error] = $this->pagination();
        if ($error !== null) {
            $this->badRequest($error);
            return;
        }
        $items = [];
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? ''), '/');

        foreach ($service->list($limit, $offset, $updatedAfter) as $article) {
            $slug = (string) ($article['slug'] ?? '');
            $items[] = [
                'slug' => $slug,
                'url' => $baseUrl . '/article/' . rawurlencode($slug) . '.html',
                'updated_at' => (string) ($article['updated_at'] ?? $article['published_at'] ?? ''),
                'knowledge' => $agent->buildKnowledge($article),
            ];
        }

        $total = $service->total($updatedAfter);
        $payload = [
            'version' => '1.1',
            'generated_at' => $this->resolveGeneratedAt($items),
            'items' => $items,
            'meta' => $this->meta($page, $limit, $offset, $total, count($items), $updatedAfter),
        ];

        $this->respondWithEtag($payload);
    }

    /** @return array{0:int,1:int,2:int,3:?string,4:?string} */
    private function pagination(): array
    {
        $defaultLimit = (int) ($this->config['api']['default_page_size'] ?? 20);
        $maxLimit = (int) ($this->config['api']['max_page_size'] ?? 100);

        $cursor = isset($_GET['cursor']) ? trim((string) $_GET['cursor']) : '';
        $pageRaw = isset($_GET['page']) ? trim((string) $_GET['page']) : '';
        if ($cursor !== '' && $pageRaw !== '') {
            return [1, $defaultLimit, 0, null, 'cursor and page are mutually exclusive'];
        }

        if ($cursor !== '') {
            $cursorValue = $this->positiveIntFromQuery('cursor');
            if ($cursorValue === null) {
                return [1, $defaultLimit, 0, null, 'invalid cursor'];
            }
            $page = $cursorValue;
        } else {
            $pageValue = $this->positiveIntFromQuery('page');
            if ($pageValue === null) {
                return [1, $defaultLimit, 0, null, 'invalid page'];
            }
            $page = $pageValue;
        }

        $limitValue = $this->positiveIntFromQuery('limit');
        if ($limitValue === null) {
            return [1, $defaultLimit, 0, null, 'invalid limit'];
        }

        if ($limitValue > $maxLimit) {
            return [1, $defaultLimit, 0, null, 'limit exceeds max_page_size'];
        }

        $limit = $limitValue;
        $offset = ($page - 1) * $limit;

        $updatedAfterRaw = isset($_GET['updated_after']) ? trim((string) $_GET['updated_after']) : '';
        if ($updatedAfterRaw === '') {
            return [$page, $limit, $offset, null, null];
        }

        $ts = strtotime($updatedAfterRaw);
        if ($ts === false) {
            return [$page, $limit, $offset, null, 'invalid updated_after'];
        }

        return [$page, $limit, $offset, date('Y-m-d H:i:s', $ts), null];
    }

    private function positiveIntFromQuery(string $key): ?int
    {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $key === 'limit'
                ? (int) ($this->config['api']['default_page_size'] ?? 20)
                : 1;
        }

        $value = trim((string) $raw);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    private function enforceRateLimit(string $action): bool
    {
        $max = (int) ($this->config['api']['rate_limit'] ?? 120);
        $window = (int) ($this->config['api']['rate_window'] ?? 60);
        if ($max <= 0 || $window <= 0) {
            return true;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $scope = sprintf('api_ai_%s_%s', $action, $ip);
        if (Security::hitRateLimit($scope, $window, $max)) {
            return true;
        }

        $payload = [
            'error' => 'rate limit exceeded',
            'error_code' => 'RATE_LIMITED',
        ];
        $requestId = (string) ($_SERVER['APT_REQUEST_ID'] ?? '');
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        Audit::write('api.ai.rate_limited', [
            'ip' => $ip,
            'action' => $action,
            'request_id' => $requestId,
        ]);

        Response::json($payload, 429, [
            'Retry-After' => (string) $window,
        ]);
        return false;
    }

    private function badRequest(string $message): void
    {
        $payload = [
            'error' => $message,
            'error_code' => 'INVALID_QUERY_PARAM',
            'invalid_param' => $this->invalidParamFromMessage($message),
        ];
        $requestId = (string) ($_SERVER['APT_REQUEST_ID'] ?? '');
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        Audit::write('api.ai.bad_request', [
            'error' => $message,
            'invalid_param' => $payload['invalid_param'] ?? 'unknown',
            'request_id' => $requestId,
            'query' => $_GET,
        ]);

        Response::json($payload, 400);
    }



    private function invalidParamFromMessage(string $message): string
    {
        return match (true) {
            str_contains($message, 'updated_after') => 'updated_after',
            str_contains($message, 'cursor') => 'cursor',
            str_contains($message, 'page') => 'page',
            str_contains($message, 'limit') => 'limit',
            default => 'unknown',
        };
    }

    /** @return array<string,mixed> */
    private function meta(int $page, int $limit, int $offset, int $total, int $count, ?string $updatedAfter): array
    {
        $hasMore = ($offset + $count) < $total;

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? (string) ($page + 1) : null,
            'updated_after' => $updatedAfter,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function respondWithEtag(array $payload): void
    {
        $etag = $this->buildEtag($payload);
        $ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($this->isEtagMatched($ifNoneMatch, $etag)) {
            Response::text('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=60',
            ]);
            return;
        }

        Response::json($payload, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function buildEtag(array $payload): string
    {
        $fingerprint = $payload;
        unset($fingerprint['generated_at']);
        $json = json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return '"' . md5((string) $json) . '"';
    }

    private function isEtagMatched(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === $etag || $candidate === ('W/' . $etag)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function resolveGeneratedAt(array $items): string
    {
        $last = 0;
        foreach ($items as $item) {
            $candidate = (string) ($item['updated_at'] ?? $item['published_at'] ?? '');
            $ts = strtotime($candidate);
            if ($ts !== false && $ts > $last) {
                $last = $ts;
            }
        }

        if ($last > 0) {
            return gmdate('c', $last);
        }

        return gmdate('c', 0);
    }
}
