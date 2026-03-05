<?php

declare(strict_types=1);

namespace modules\article;

use core\DB;
use PDO;
use Throwable;

final class ArticleService
{
    /** @return array<int,array<string,mixed>> */
    public function list(int $limit = 10, int $offset = 0, ?string $updatedAfter = null): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $updatedAfterTs = $this->normalizeTimestampFilter($updatedAfter);
        if ($updatedAfter !== null && $updatedAfterTs === null) {
            return [];
        }

        $rows = $this->listFromDb($limit, $offset, $updatedAfterTs === null ? null : date('Y-m-d H:i:s', $updatedAfterTs));
        if ($rows !== null) {
            return $rows;
        }

        if (!$this->allowJsonFallback()) {
            return [];
        }

        $all = $this->allFromJson();
        $all = array_values(array_filter($all, static fn(array $row): bool => (int) ($row['status'] ?? 1) === 1));
        if ($updatedAfterTs !== null) {
            $all = array_values(array_filter($all, static function (array $row) use ($updatedAfterTs): bool {
                $time = strtotime((string) ($row['updated_at'] ?? $row['published_at'] ?? ''));
                return $time !== false && $time >= $updatedAfterTs;
            }));
        }

        return array_slice($all, $offset, $limit);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $dbRow = $this->findBySlugFromDb($slug);
        if ($dbRow !== null) {
            return $dbRow;
        }

        if (!$this->allowJsonFallback()) {
            return null;
        }

        foreach ($this->allFromJson() as $item) {
            if (($item['slug'] ?? '') === $slug && (int) ($item['status'] ?? 1) === 1) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function findAnyBySlug(string $slug): ?array
    {
        $pdo = $this->db();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('SELECT id, slug, title, content, author, published_at, updated_at, status FROM apt_content_article WHERE slug = :slug LIMIT 1');
                $stmt->execute([':slug' => $slug]);
                $row = $stmt->fetch();
                return is_array($row) ? $row : null;
            } catch (Throwable) {
                // fallback to json
            }
        }

        if (!$this->allowJsonFallback()) {
            return null;
        }

        foreach ($this->allFromJson() as $item) {
            if (($item['slug'] ?? '') === $slug) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 后台列表：支持草稿、搜索与状态筛选。
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForAdmin(int $limit = 20, int $offset = 0, string $keyword = '', string $status = 'all', string $sort = 'published_at', string $direction = 'desc'): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $keyword = trim($keyword);
        $status = $this->normalizeStatusFilter($status);
        $sort = $this->normalizeSort($sort);
        $direction = $this->normalizeSortDirection($direction);

        $rows = $this->listForAdminFromDb($limit, $offset, $keyword, $status, $sort, $direction);
        if ($rows !== null) {
            return $rows;
        }

        if (!$this->allowJsonFallback()) {
            return [];
        }

        $all = $this->allFromJson();
        $all = $this->filterRowsForAdmin($all, $keyword, $status);
        usort($all, function (array $a, array $b) use ($sort, $direction): int {
            $left = (string) ($a[$sort] ?? '');
            $right = (string) ($b[$sort] ?? '');
            $cmp = strcmp($left, $right);
            if ($cmp === 0) {
                $cmp = strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? ''));
            }

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return array_slice($all, $offset, $limit);
    }

    public function totalForAdmin(string $keyword = '', string $status = 'all'): int
    {
        $keyword = trim($keyword);
        $status = $this->normalizeStatusFilter($status);

        $count = $this->countForAdminFromDb($keyword, $status);
        if ($count !== null) {
            return $count;
        }

        if (!$this->allowJsonFallback()) {
            return 0;
        }

        return count($this->filterRowsForAdmin($this->allFromJson(), $keyword, $status));
    }

    public function total(?string $updatedAfter = null): int
    {
        $updatedAfterTs = $this->normalizeTimestampFilter($updatedAfter);
        if ($updatedAfter !== null && $updatedAfterTs === null) {
            return 0;
        }

        $normalizedUpdatedAfter = $updatedAfterTs === null ? null : date('Y-m-d H:i:s', $updatedAfterTs);
        $count = $this->countFromDb($normalizedUpdatedAfter);
        if ($count !== null) {
            return $count;
        }

        if (!$this->allowJsonFallback()) {
            return 0;
        }

        if ($updatedAfter === null) {
            return count(array_values(array_filter($this->allFromJson(), static fn(array $row): bool => (int) ($row['status'] ?? 1) === 1)));
        }

        return count($this->list(100000, 0, $normalizedUpdatedAfter));
    }

    /** @param array<string,mixed> $input */
    public function create(array $input): bool
    {
        $data = $this->normalizeInput($input);
        if ($data['slug'] === '' || $data['title'] === '' || $data['content'] === '') {
            return false;
        }

        $pdo = $this->db();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('INSERT INTO apt_content_article (slug,title,content,author,published_at,status,created_at,updated_at) VALUES (:slug,:title,:content,:author,:published_at,:status,NOW(),NOW())');
                return $stmt->execute([
                    ':slug' => $data['slug'],
                    ':title' => $data['title'],
                    ':content' => $data['content'],
                    ':author' => $data['author'],
                    ':published_at' => $data['published_at'],
                    ':status' => $data['status'],
                ]);
            } catch (Throwable) {
                return false;
            }
        }

        if (!$this->allowJsonFallback()) {
            return false;
        }

        $rows = $this->allFromJson();
        foreach ($rows as $row) {
            if (($row['slug'] ?? '') === $data['slug']) {
                return false;
            }
        }

        $nextId = 1;
        foreach ($rows as $row) {
            $nextId = max($nextId, (int) ($row['id'] ?? 0) + 1);
        }

        $rows[] = [
            'id' => $nextId,
            'slug' => $data['slug'],
            'title' => $data['title'],
            'content' => $data['content'],
            'author' => $data['author'],
            'published_at' => $data['published_at'],
            'updated_at' => date('Y-m-d H:i:s'),
            'status' => $data['status'],
        ];

        return $this->saveJson($rows);
    }

    /** @param array<string,mixed> $input */
    public function updateBySlug(string $slug, array $input): bool
    {
        $data = $this->normalizeInput($input);
        $data['slug'] = $slug;

        $pdo = $this->db();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('UPDATE apt_content_article SET title=:title, content=:content, author=:author, published_at=:published_at, status=:status, updated_at=NOW() WHERE slug=:slug');
                return $stmt->execute([
                    ':slug' => $slug,
                    ':title' => $data['title'],
                    ':content' => $data['content'],
                    ':author' => $data['author'],
                    ':published_at' => $data['published_at'],
                    ':status' => $data['status'],
                ]);
            } catch (Throwable) {
                return false;
            }
        }

        if (!$this->allowJsonFallback()) {
            return false;
        }

        $rows = $this->allFromJson();
        $updated = false;
        foreach ($rows as &$row) {
            if (($row['slug'] ?? '') === $slug) {
                $row['title'] = $data['title'];
                $row['content'] = $data['content'];
                $row['author'] = $data['author'];
                $row['published_at'] = $data['published_at'];
                $row['updated_at'] = date('Y-m-d H:i:s');
                $row['status'] = $data['status'];
                $updated = true;
                break;
            }
        }
        unset($row);

        return $updated ? $this->saveJson($rows) : false;
    }



    /** @param array<int,string> $slugs */
    public function bulkUpdateStatusBySlugs(array $slugs, int $status): int
    {
        $status = $status === 1 ? 1 : 0;
        $slugs = $this->normalizeSlugs($slugs);
        if ($slugs === []) {
            return 0;
        }

        $pdo = $this->db();
        if ($pdo instanceof PDO) {
            try {
                $placeholders = implode(',', array_fill(0, count($slugs), '?'));
                $sql = 'UPDATE apt_content_article SET status = ?, updated_at = NOW() WHERE slug IN (' . $placeholders . ')';
                $stmt = $pdo->prepare($sql);
                $params = array_merge([$status], $slugs);
                $stmt->execute($params);
                $count = $stmt->rowCount();
                return is_int($count) ? $count : 0;
            } catch (Throwable) {
                return 0;
            }
        }

        if (!$this->allowJsonFallback()) {
            return 0;
        }

        $rows = $this->allFromJson();
        $affected = 0;
        foreach ($rows as &$row) {
            if (in_array((string) ($row['slug'] ?? ''), $slugs, true)) {
                if ((int) ($row['status'] ?? 1) !== $status) {
                    $row['status'] = $status;
                    $row['updated_at'] = date('Y-m-d H:i:s');
                }
                $affected++;
            }
        }
        unset($row);

        return $affected > 0 && $this->saveJson($rows) ? $affected : 0;
    }

    /** @param array<int,string> $slugs */
    public function bulkDeleteBySlugs(array $slugs): int
    {
        $slugs = $this->normalizeSlugs($slugs);
        if ($slugs === []) {
            return 0;
        }

        $pdo = $this->db();
        if ($pdo instanceof PDO) {
            try {
                $placeholders = implode(',', array_fill(0, count($slugs), '?'));
                $stmt = $pdo->prepare('DELETE FROM apt_content_article WHERE slug IN (' . $placeholders . ')');
                $stmt->execute($slugs);
                $count = $stmt->rowCount();
                return is_int($count) ? $count : 0;
            } catch (Throwable) {
                return 0;
            }
        }

        if (!$this->allowJsonFallback()) {
            return 0;
        }

        $rows = $this->allFromJson();
        $before = count($rows);
        $rows = array_values(array_filter($rows, static fn(array $r): bool => !in_array((string) ($r['slug'] ?? ''), $slugs, true)));
        $deleted = $before - count($rows);

        return $deleted > 0 && $this->saveJson($rows) ? $deleted : 0;
    }

    public function deleteBySlug(string $slug): bool
    {
        $pdo = $this->db();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('DELETE FROM apt_content_article WHERE slug=:slug');
                return $stmt->execute([':slug' => $slug]);
            } catch (Throwable) {
                return false;
            }
        }

        if (!$this->allowJsonFallback()) {
            return false;
        }

        $rows = $this->allFromJson();
        $filtered = array_values(array_filter($rows, static fn(array $r): bool => ($r['slug'] ?? '') !== $slug));
        if (count($filtered) === count($rows)) {
            return false;
        }

        return $this->saveJson($filtered);
    }

    /** @return array<int,array<string,mixed>> */
    private function allFromJson(): array
    {
        $file = __DIR__ . '/data.json';
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** @return array<int,array<string,mixed>>|null */
    private function listFromDb(int $limit, int $offset, ?string $updatedAfter): ?array
    {
        $pdo = $this->db();
        if (!$pdo instanceof PDO) {
            return null;
        }

        try {
            if ($updatedAfter !== null) {
                $stmt = $pdo->prepare('SELECT id, slug, title, content, author, published_at, updated_at, status FROM apt_content_article WHERE updated_at >= :updated_after AND status = 1 ORDER BY published_at DESC LIMIT :limit OFFSET :offset');
                $stmt->bindValue(':updated_after', $updatedAfter);
            } else {
                $stmt = $pdo->prepare('SELECT id, slug, title, content, author, published_at, updated_at, status FROM apt_content_article WHERE status = 1 ORDER BY published_at DESC LIMIT :limit OFFSET :offset');
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function findBySlugFromDb(string $slug): ?array
    {
        $pdo = $this->db();
        if (!$pdo instanceof PDO) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT id, slug, title, content, author, published_at, updated_at, status FROM apt_content_article WHERE slug = :slug AND status = 1 LIMIT 1');
            $stmt->execute([':slug' => $slug]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function countFromDb(?string $updatedAfter): ?int
    {
        $pdo = $this->db();
        if (!$pdo instanceof PDO) {
            return null;
        }

        try {
            if ($updatedAfter !== null) {
                $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM apt_content_article WHERE status = 1 AND updated_at >= :updated_after');
                $stmt->execute([':updated_after' => $updatedAfter]);
            } else {
                $stmt = $pdo->query('SELECT COUNT(*) AS c FROM apt_content_article WHERE status = 1');
            }

            $row = $stmt->fetch();
            return (int) ($row['c'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function listForAdminFromDb(int $limit, int $offset, string $keyword, string $status, string $sort, string $direction): ?array
    {
        $pdo = $this->db();
        if (!$pdo instanceof PDO) {
            return null;
        }

        try {
            $where = [];
            $params = [];
            if ($status !== 'all') {
                $where[] = 'status = :status';
                $params[':status'] = (int) $status;
            }

            if ($keyword !== '') {
                $where[] = '(title LIKE :kw OR slug LIKE :kw OR author LIKE :kw)';
                $params[':kw'] = '%' . $keyword . '%';
            }

            $sql = 'SELECT id, slug, title, content, author, published_at, updated_at, status FROM apt_content_article';
            if ($where !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY ' . $sort . ' ' . strtoupper($direction) . ', slug ASC LIMIT :limit OFFSET :offset';

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return null;
        }
    }

    private function countForAdminFromDb(string $keyword, string $status): ?int
    {
        $pdo = $this->db();
        if (!$pdo instanceof PDO) {
            return null;
        }

        try {
            $where = [];
            $params = [];
            if ($status !== 'all') {
                $where[] = 'status = :status';
                $params[':status'] = (int) $status;
            }

            if ($keyword !== '') {
                $where[] = '(title LIKE :kw OR slug LIKE :kw OR author LIKE :kw)';
                $params[':kw'] = '%' . $keyword . '%';
            }

            $sql = 'SELECT COUNT(*) AS c FROM apt_content_article';
            if ($where !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $row = $stmt->fetch();

            return (int) ($row['c'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterRowsForAdmin(array $rows, string $keyword, string $status): array
    {
        $normalizedKeyword = strtolower($keyword);

        return array_values(array_filter($rows, static function (array $row) use ($normalizedKeyword, $status): bool {
            $rowStatus = (int) ($row['status'] ?? 1);
            if ($status !== 'all' && $rowStatus !== (int) $status) {
                return false;
            }

            if ($normalizedKeyword === '') {
                return true;
            }

            $haystack = strtolower(
                (string) ($row['title'] ?? '') . ' ' .
                (string) ($row['slug'] ?? '') . ' ' .
                (string) ($row['author'] ?? '')
            );

            return str_contains($haystack, $normalizedKeyword);
        }));
    }

    private function normalizeStatusFilter(string $status): string
    {
        return in_array($status, ['all', '0', '1'], true) ? $status : 'all';
    }

    private function normalizeTimestampFilter(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    private function normalizeSort(string $sort): string
    {
        return in_array($sort, ['published_at', 'title', 'author', 'slug', 'updated_at'], true) ? $sort : 'published_at';
    }

    private function normalizeSortDirection(string $direction): string
    {
        return in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';
    }

    /** @param array<int,string> $slugs @return array<int,string> */
    private function normalizeSlugs(array $slugs): array
    {
        $cleaned = [];
        foreach ($slugs as $slug) {
            $value = trim((string) $slug);
            if ($value !== '') {
                $cleaned[$value] = $value;
            }
        }

        return array_values($cleaned);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeInput(array $input): array
    {
        return [
            'slug' => trim((string) ($input['slug'] ?? '')),
            'title' => trim((string) ($input['title'] ?? '')),
            'content' => trim((string) ($input['content'] ?? '')),
            'author' => trim((string) ($input['author'] ?? 'APTCMS')),
            'published_at' => trim((string) ($input['published_at'] ?? date('Y-m-d H:i:s'))),
            'status' => ((int) ($input['status'] ?? 1)) === 1 ? 1 : 0,
        ];
    }

    private function allowJsonFallback(): bool
    {
        $config = require __DIR__ . '/../../config/app.php';
        if (($config['db']['enabled'] ?? false) !== true) {
            return true;
        }

        return ($config['db']['fallback_to_json'] ?? true) === true;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function saveJson(array $rows): bool
    {
        $file = __DIR__ . '/data.json';
        return file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function db(): ?PDO
    {
        $config = require __DIR__ . '/../../config/app.php';
        if (($config['db']['enabled'] ?? false) !== true) {
            return null;
        }

        try {
            return DB::conn($config['db']);
        } catch (Throwable) {
            return null;
        }
    }
}
