<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Audit;
use core\Auth;
use core\Controller;
use core\DB;
use core\Response;
use core\Security;
use PDO;
use Throwable;

final class SettingController extends Controller
{
    /** @var array<string,mixed> */
    private array $supportedKeys = [
        'name' => '',
        'base_url' => '',
        'api.default_page_size' => 20,
        'api.max_page_size' => 100,
        'api.rate_limit' => 120,
        'security.login_rate_limit' => 5,
        'security.login_rate_window' => 300,
        'security.admin_ip_allowlist' => [],
    ];

    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }

        if (($this->config['db']['enabled'] ?? false) !== true) {
            Response::text('配置中心仅在 db.enabled=true 时可用', 400);
            return;
        }

        $values = $this->loadCurrentValues();
        ['query' => $historyQuery, 'operator' => $historyOperator, 'start' => $historyStart, 'end' => $historyEnd, 'limit' => $historyLimit] = $this->resolveHistoryFilters();

        $this->assign('title', '系统配置中心');
        $this->assign('_csrf', Security::csrfToken());
        $this->assign('name', (string) ($values['name'] ?? ''));
        $this->assign('base_url', (string) ($values['base_url'] ?? ''));
        $this->assign('api_default_page_size', (string) ($values['api.default_page_size'] ?? 20));
        $this->assign('api_max_page_size', (string) ($values['api.max_page_size'] ?? 100));
        $this->assign('api_rate_limit', (string) ($values['api.rate_limit'] ?? 120));
        $this->assign('security_login_rate_limit', (string) ($values['security.login_rate_limit'] ?? 5));
        $this->assign('security_login_rate_window', (string) ($values['security.login_rate_window'] ?? 300));
        $allowlist = $values['security.admin_ip_allowlist'] ?? [];
        $allowlistText = is_array($allowlist)
            ? implode("\n", array_map(static fn(mixed $v): string => (string) $v, $allowlist))
            : '';
        $this->assign('security_admin_ip_allowlist', $allowlistText);

        $this->assign('notice', (string) ($_GET['notice'] ?? ''));
        $this->assign('history_query', $historyQuery);
        $this->assign('history_operator', $historyOperator);
        $this->assign('history_start', $historyStart);
        $this->assign('history_end', $historyEnd);
        $this->assign('history_limit', (string) $historyLimit);
        $this->assign('history_export_query', http_build_query([
            'hq' => $historyQuery,
            'hop' => $historyOperator,
            'hs' => $historyStart,
            'he' => $historyEnd,
            'hlimit' => $historyLimit,
        ]));
        $historyRows = $this->buildHistoryRows($historyQuery, $historyOperator, $historyStart, $historyEnd, $historyLimit);
        $this->assign('history', $historyRows);
        $this->assign('history_count', (string) count($historyRows));
        $stats = $this->buildChangeStats($historyRows);
        $this->assign('history_stats', $stats);
        $this->assign('history_stats_count', (string) count($stats));
        $this->display('admin/settings.html');
    }



    public function export(): void
    {
        if (!$this->guard()) {
            return;
        }

        if (($this->config['db']['enabled'] ?? false) !== true) {
            Response::text('配置中心仅在 db.enabled=true 时可用', 400);
            return;
        }

        ['query' => $historyQuery, 'operator' => $historyOperator, 'start' => $historyStart, 'end' => $historyEnd, 'limit' => $historyLimit] = $this->resolveHistoryFilters();
        $rows = $this->buildHistoryRows($historyQuery, $historyOperator, $historyStart, $historyEnd, $historyLimit);

        $user = Auth::user();
        Audit::write('system.settings.export', [
            'username' => (string) ($user['username'] ?? ''),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'filters' => [
                'query' => $historyQuery,
                'operator' => $historyOperator,
                'start' => $historyStart,
                'end' => $historyEnd,
                'limit' => $historyLimit,
            ],
            'row_count' => count($rows),
        ]);

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            Response::text('Export failed', 500);
            return;
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['time', 'operator', 'ip', 'changes']);
        foreach ($rows as $row) {
            fputcsv($output, [
                (string) ($row['time'] ?? ''),
                (string) ($row['username'] ?? ''),
                (string) ($row['ip'] ?? ''),
                (string) ($row['changes'] ?? ''),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        if ($csv === false) {
            Response::text('Export failed', 500);
            return;
        }

        Response::text($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="settings-history.csv"',
        ]);
    }

    public function save(): void
    {
        if (!$this->guard()) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Response::text('Method Not Allowed', 405);
            return;
        }

        if (($this->config['db']['enabled'] ?? false) !== true) {
            Response::text('配置中心仅在 db.enabled=true 时可用', 400);
            return;
        }

        $token = (string) ($_POST['_csrf'] ?? '');
        if (!Security::validateCsrf($token)) {
            Response::text('Invalid CSRF token', 403);
            return;
        }

        $updates = $this->resolveUpdates();
        if ($updates === null) {
            return;
        }

        try {
            $pdo = DB::conn($this->config['db']);
            $before = $this->loadCurrentValues($pdo);
            $stmt = $pdo->prepare('INSERT INTO apt_system_setting (key_name, value_json, updated_at) VALUES (:key_name, :value_json, NOW()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json), updated_at=NOW()');

            $pdo->beginTransaction();
            foreach ($updates as $key => $value) {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json)) {
                    continue;
                }
                $stmt->execute([
                    ':key_name' => $key,
                    ':value_json' => $json,
                ]);
            }
            $pdo->commit();

            $changes = [];
            foreach ($updates as $key => $newValue) {
                $oldValue = $before[$key] ?? null;
                if ($oldValue !== $newValue) {
                    $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
                }
            }

            $user = Auth::user();
            Audit::write('system.settings.updated', [
                'username' => (string) ($user['username'] ?? ''),
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'keys' => array_keys($updates),
                'changes' => $changes,
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::text('保存失败: ' . $e->getMessage(), 500);
            return;
        }

        header('Location: /admin/setting/index?notice=saved');
    }

    /** @return array<string,mixed> */
    private function loadCurrentValues(?PDO $pdo = null): array
    {
        $values = [
            'name' => (string) ($this->config['name'] ?? $this->supportedKeys['name']),
            'base_url' => (string) ($this->config['base_url'] ?? $this->supportedKeys['base_url']),
            'api.default_page_size' => (int) ($this->config['api']['default_page_size'] ?? $this->supportedKeys['api.default_page_size']),
            'api.max_page_size' => (int) ($this->config['api']['max_page_size'] ?? $this->supportedKeys['api.max_page_size']),
            'api.rate_limit' => (int) ($this->config['api']['rate_limit'] ?? $this->supportedKeys['api.rate_limit']),
            'security.login_rate_limit' => (int) ($this->config['security']['login_rate_limit'] ?? $this->supportedKeys['security.login_rate_limit']),
            'security.login_rate_window' => (int) ($this->config['security']['login_rate_window'] ?? $this->supportedKeys['security.login_rate_window']),
            'security.admin_ip_allowlist' => is_array($this->config['security']['admin_ip_allowlist'] ?? null) ? $this->config['security']['admin_ip_allowlist'] : [],
        ];

        $pdo ??= DB::conn($this->config['db']);
        $rows = $pdo->query('SELECT key_name, value_json FROM apt_system_setting')->fetchAll();
        if (!is_array($rows)) {
            return $values;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key_name'] ?? '');
            if (!array_key_exists($key, $this->supportedKeys)) {
                continue;
            }
            $decoded = json_decode((string) ($row['value_json'] ?? ''), true);
            if ($decoded === null && strtolower(trim((string) ($row['value_json'] ?? ''))) !== 'null') {
                continue;
            }
            $values[$key] = $decoded;
        }

        return $values;
    }

    /** @return array<string,mixed>|null */
    private function resolveUpdates(): ?array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $baseUrl = trim((string) ($_POST['base_url'] ?? ''));
        $defaultPage = (int) ($_POST['api_default_page_size'] ?? 20);
        $maxPage = (int) ($_POST['api_max_page_size'] ?? 100);
        $rateLimit = (int) ($_POST['api_rate_limit'] ?? 120);
        $loginRate = (int) ($_POST['security_login_rate_limit'] ?? 5);
        $loginWindow = (int) ($_POST['security_login_rate_window'] ?? 300);
        $allowlistRaw = trim((string) ($_POST['security_admin_ip_allowlist'] ?? ''));

        if ($name === '') {
            Response::text('站点名称不能为空', 400);
            return null;
        }
        if ($defaultPage < 1 || $defaultPage > 1000) {
            Response::text('api.default_page_size 必须在 1~1000', 400);
            return null;
        }
        if ($maxPage < $defaultPage || $maxPage > 1000) {
            Response::text('api.max_page_size 必须在 default_page_size~1000', 400);
            return null;
        }
        if ($rateLimit < 1 || $rateLimit > 5000) {
            Response::text('api.rate_limit 必须在 1~5000', 400);
            return null;
        }
        if ($loginRate < 1 || $loginRate > 100) {
            Response::text('security.login_rate_limit 必须在 1~100', 400);
            return null;
        }
        if ($loginWindow < 30 || $loginWindow > 86400) {
            Response::text('security.login_rate_window 必须在 30~86400 秒', 400);
            return null;
        }

        $allowlist = [];
        if ($allowlistRaw !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $allowlistRaw) ?: [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $allowlist[] = $line;
            }
            $allowlist = array_values(array_unique($allowlist));
        }

        return [
            'name' => $name,
            'base_url' => $baseUrl,
            'api.default_page_size' => $defaultPage,
            'api.max_page_size' => $maxPage,
            'api.rate_limit' => $rateLimit,
            'security.login_rate_limit' => $loginRate,
            'security.login_rate_window' => $loginWindow,
            'security.admin_ip_allowlist' => $allowlist,
        ];
    }

    /** @return array{query:string,operator:string,start:string,end:string,limit:int} */
    private function resolveHistoryFilters(): array
    {
        $query = trim((string) ($_GET['hq'] ?? ''));
        $operator = trim((string) ($_GET['hop'] ?? ''));
        $start = $this->normalizeDateTimeInput((string) ($_GET['hs'] ?? ''));
        $end = $this->normalizeDateTimeInput((string) ($_GET['he'] ?? ''));
        $limit = (int) ($_GET['hlimit'] ?? 20);

        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $startTs = $start === '' ? null : strtotime($start);
        $endTs = $end === '' ? null : strtotime($end);
        if (is_int($startTs) && is_int($endTs) && $startTs > $endTs) {
            [$start, $end] = [$end, $start];
        }

        return [
            'query' => $query,
            'operator' => $operator,
            'start' => $start,
            'end' => $end,
            'limit' => $limit,
        ];
    }

    /** @return array<int,array<string,string>> */
    private function buildHistoryRows(string $query, string $operator, string $start, string $end, int $limit): array
    {
        $rows = [];
        $startTs = $start === '' ? null : strtotime($start);
        $endTs = $end === '' ? null : strtotime($end);
        $queryLower = $query === '' ? '' : mb_strtolower($query, 'UTF-8');

        foreach (Audit::search(['event' => 'system.settings.updated', 'ip' => '', 'query' => ''], $limit) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ctx = is_array($item['context'] ?? null) ? $item['context'] : [];
            $username = (string) ($ctx['username'] ?? '');

            if ($operator !== '' && $username !== $operator) {
                continue;
            }

            $changes = $ctx['changes'] ?? [];
            $diffs = [];
            if (is_array($changes)) {
                foreach ($changes as $key => $pair) {
                    if (!is_array($pair)) {
                        continue;
                    }
                    $old = json_encode($pair['old'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $new = json_encode($pair['new'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $diffs[] = (string) $key . ': ' . ($old === false ? 'null' : $old) . ' -> ' . ($new === false ? 'null' : $new);
                }
            }
            $changeText = $diffs === [] ? '-' : implode("\n", $diffs);

            $time = (string) ($item['time'] ?? '');
            $timeTs = strtotime($time);
            if (($startTs !== null || $endTs !== null) && !is_int($timeTs)) {
                continue;
            }
            if (is_int($timeTs) && is_int($startTs) && $timeTs < $startTs) {
                continue;
            }
            if (is_int($timeTs) && is_int($endTs) && $timeTs > $endTs) {
                continue;
            }

            if ($queryLower !== '') {
                $haystack = mb_strtolower($changeText . ' ' . $time . ' ' . (string) ($ctx['ip'] ?? '') . ' ' . $username, 'UTF-8');
                if (!str_contains($haystack, $queryLower)) {
                    continue;
                }
            }

            $rows[] = [
                'time' => $time,
                'username' => $username,
                'ip' => (string) ($ctx['ip'] ?? ''),
                'changes' => $changeText,
            ];
        }

        return $rows;
    }

    /** @param array<int,array<string,string>> $rows
     *  @return array<int,array{key:string,count:string}> */
    private function buildChangeStats(array $rows): array
    {
        $counter = [];
        foreach ($rows as $row) {
            $changes = (string) ($row['changes'] ?? '');
            if ($changes === '' || $changes === '-') {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $changes) ?: [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                $key = trim((string) strstr($line, ':', true));
                if ($key === '') {
                    continue;
                }
                $counter[$key] = (int) ($counter[$key] ?? 0) + 1;
            }
        }

        arsort($counter);
        $result = [];
        foreach ($counter as $key => $count) {
            $result[] = [
                'key' => (string) $key,
                'count' => (string) $count,
            ];
            if (count($result) >= 10) {
                break;
            }
        }

        return $result;
    }

    private function normalizeDateTimeInput(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('T', ' ', $value);
        $ts = strtotime($value);
        if (!is_int($ts)) {
            return '';
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function guard(): bool
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return false;
        }

        if (!Auth::can('system.settings.manage') && !Auth::can('*')) {
            Response::text('Forbidden', 403);
            return false;
        }

        return true;
    }
}
