<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Audit;
use core\Auth;
use core\Controller;
use core\Response;

final class AuditController extends Controller
{
    public function index(): void
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return;
        }

        if (!Auth::can('system.audit.view') && !Auth::can('*')) {
            Response::text('Forbidden', 403);
            return;
        }

        ['event' => $event, 'ip' => $ip, 'query' => $query, 'limit' => $limit] = $this->resolveFilters();

        $rows = [];
        foreach (Audit::search(['event' => $event, 'ip' => $ip, 'query' => $query], $limit) as $row) {
            $row['context'] = json_encode($row['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rows[] = $row;
        }

        $this->assign('title', '系统审计日志');
        $this->assign('event', $event);
        $this->assign('ip', $ip);
        $this->assign('query', $query);
        $this->assign('limit', (string) $limit);
        $this->assign('export_query', http_build_query([
            'event' => $event,
            'ip' => $ip,
            'q' => $query,
            'limit' => $limit,
        ]));
        $this->assign('rows', $rows);
        $this->display('admin/audit.html');
    }

    public function export(): void
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return;
        }

        if (!Auth::can('system.audit.view') && !Auth::can('*')) {
            Response::text('Forbidden', 403);
            return;
        }

        ['event' => $event, 'ip' => $ip, 'query' => $query, 'limit' => $limit] = $this->resolveFilters();

        $rows = Audit::search(['event' => $event, 'ip' => $ip, 'query' => $query], $limit);
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            Response::text('Export failed', 500);
            return;
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['time', 'event', 'ip', 'context']);
        foreach ($rows as $row) {
            $context = json_encode($row['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            fputcsv($output, [
                (string) ($row['time'] ?? ''),
                (string) ($row['event'] ?? ''),
                (string) ($row['ip'] ?? ''),
                $context === false ? '' : $context,
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
            'Content-Disposition' => 'attachment; filename="audit-export.csv"',
        ]);
    }

    /** @return array{event:string,ip:string,query:string,limit:int} */
    private function resolveFilters(): array
    {
        $event = trim((string) ($_GET['event'] ?? ''));
        $ip = trim((string) ($_GET['ip'] ?? ''));
        $query = trim((string) ($_GET['q'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 100);
        if ($limit <= 0) {
            $limit = 100;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        return [
            'event' => $event,
            'ip' => $ip,
            'query' => $query,
            'limit' => $limit,
        ];
    }
}
