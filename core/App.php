<?php

declare(strict_types=1);

namespace core;

use Throwable;

final class App
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly string $basePath,
        private readonly array $config
    ) {
    }

    public function run(): void
    {
        date_default_timezone_set('Asia/Shanghai');
        $_SERVER['APT_REQUEST_ID'] = bin2hex(random_bytes(8));

        (new PluginManager())->boot($this->basePath . '/plugins');

        try {
            $router = new Router();
            $route = $router->parse($_SERVER['REQUEST_URI'] ?? '/');

            if ($route['app'] === 'admin') {
                $allowlist = $this->config['security']['admin_ip_allowlist'] ?? [];
                $allowlist = is_array($allowlist) ? $allowlist : [];
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                if (!Security::isAllowedIp($ip, $allowlist)) {
                    Audit::write('admin.ip.blocked', [
                        'ip' => $ip,
                        'allowlist' => $allowlist,
                        'request_id' => (string) ($_SERVER['APT_REQUEST_ID'] ?? ''),
                    ]);
                    Response::text('Forbidden', 403);
                    return;
                }
            }

            $controllerClass = sprintf('app\\%s\\Controller\\%sController', $route['app'], ucfirst($route['controller']));
            $action = $route['action'];

            if (!class_exists($controllerClass)) {
                Response::text('Controller not found', 404);
                return;
            }

            $controller = new $controllerClass($this->config);
            if (!method_exists($controller, $action)) {
                Response::text('Action not found', 404);
                return;
            }

            $controller->{$action}(...$route['params']);
        } catch (Throwable $e) {
            Audit::write('system.exception', [
                'request_id' => (string) ($_SERVER['APT_REQUEST_ID'] ?? ''),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            Response::json([
                'error' => 'Internal Server Error',
                'request_id' => (string) ($_SERVER['APT_REQUEST_ID'] ?? ''),
            ], 500);
        }
    }
}
