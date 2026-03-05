<?php

declare(strict_types=1);

namespace core;

final class PluginManager
{
    public function boot(string $pluginRoot): void
    {
        $appVersion = $this->appVersion();
        $plugins = $this->scan($pluginRoot);
        $installed = array_column($plugins, null, 'name');
        $state = $this->state();

        foreach ($plugins as $plugin) {
            $code = $plugin['name'];
            if (in_array($code, $state['removed'], true) || in_array($code, $state['disabled'], true)) {
                continue;
            }

            $meta = $this->readMeta($plugin['path']);
            $validation = $this->validatePlugin($code, $meta, $appVersion, $installed);
            if ($validation['ok'] !== true) {
                Audit::write('plugin.skipped', ['plugin' => $code, 'reason' => $validation['reason']]);
                continue;
            }

            $bootstrap = $plugin['path'] . '/bootstrap.php';
            if (is_file($bootstrap)) {
                require_once $bootstrap;
            }
        }
    }

    /** @return array{ok:bool,message:string,code:string} */
    public function installFromUrl(string $pluginRoot, string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'message' => '仅支持 http/https 地址', 'code' => ''];
        }

        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'ZipArchive 扩展未启用', 'code' => ''];
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'apt_plugin_');
        if ($tmpZip === false) {
            return ['ok' => false, 'message' => '创建临时文件失败', 'code' => ''];
        }

        $data = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 20],
            'https' => ['timeout' => 20],
        ]));
        if (!is_string($data) || $data === '') {
            @unlink($tmpZip);
            return ['ok' => false, 'message' => '下载插件失败', 'code' => ''];
        }
        file_put_contents($tmpZip, $data);

        $extractDir = sys_get_temp_dir() . '/apt_plugin_extract_' . bin2hex(random_bytes(4));
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0775, true);
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tmpZip);
        if ($opened !== true) {
            @unlink($tmpZip);
            $this->rrmdir($extractDir);
            return ['ok' => false, 'message' => '插件压缩包无效', 'code' => ''];
        }
        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tmpZip);

        $pluginDir = $this->detectPluginRootDir($extractDir);
        if ($pluginDir === null) {
            $this->rrmdir($extractDir);
            return ['ok' => false, 'message' => '未找到 plugin.json', 'code' => ''];
        }

        $meta = $this->readMeta($pluginDir);
        $code = trim((string) ($meta['code'] ?? basename($pluginDir)));
        if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            $this->rrmdir($extractDir);
            return ['ok' => false, 'message' => '插件代码不合法', 'code' => ''];
        }

        $target = rtrim($pluginRoot, '/') . '/' . $code;
        if (is_dir($target)) {
            $this->rrmdir($target);
        }
        if (!is_dir($pluginRoot)) {
            mkdir($pluginRoot, 0775, true);
        }
        $this->rcopy($pluginDir, $target);
        $this->rrmdir($extractDir);

        $this->enable($code);
        Audit::write('plugin.installed', ['plugin' => $code, 'source' => $url]);
        return ['ok' => true, 'message' => '安装成功', 'code' => $code];
    }

    /** @return array{ok:bool,message:string,code:string} */
    public function upgradeFromUrl(string $pluginRoot, string $code, string $url): array
    {
        $result = $this->installFromUrl($pluginRoot, $url);
        if ($result['ok'] !== true) {
            return $result;
        }
        if ($result['code'] !== '' && $result['code'] !== $code) {
            return ['ok' => false, 'message' => '升级包插件代码与目标不一致', 'code' => $result['code']];
        }

        Audit::write('plugin.upgraded', ['plugin' => $code, 'source' => $url]);
        return ['ok' => true, 'message' => '升级成功', 'code' => $code];
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $pluginRoot): array
    {
        $rows = [];
        $appVersion = $this->appVersion();
        $plugins = $this->scan($pluginRoot);
        $installed = array_column($plugins, null, 'name');
        $state = $this->state();

        foreach ($plugins as $plugin) {
            $code = $plugin['name'];
            $meta = $this->readMeta($plugin['path']);
            $validation = $this->validatePlugin($code, $meta, $appVersion, $installed);

            $lifecycle = 'enabled';
            if (in_array($code, $state['removed'], true)) {
                $lifecycle = 'uninstalled';
            } elseif (in_array($code, $state['disabled'], true)) {
                $lifecycle = 'disabled';
            }

            $reason = (string) ($validation['reason'] ?? '');
            if ($lifecycle === 'disabled') {
                $reason = 'disabled by admin';
            }
            if ($lifecycle === 'uninstalled') {
                $reason = 'uninstalled by admin';
            }

            $rows[] = [
                'code' => $code,
                'name' => (string) ($meta['name'] ?? $code),
                'version' => (string) ($meta['version'] ?? '0.0.0'),
                'description' => (string) ($meta['description'] ?? ''),
                'enabled' => is_file($plugin['path'] . '/bootstrap.php') ? 'yes' : 'no',
                'loadable' => ($validation['ok'] === true && $lifecycle === 'enabled') ? 'yes' : 'no',
                'reason' => $reason,
                'lifecycle' => $lifecycle,
            ];
        }

        return $rows;
    }

    public function enable(string $code): bool
    {
        $state = $this->state();
        $state['removed'] = array_values(array_filter($state['removed'], static fn(string $v): bool => $v !== $code));
        $state['disabled'] = array_values(array_filter($state['disabled'], static fn(string $v): bool => $v !== $code));
        return $this->writeState($state);
    }

    public function disable(string $code): bool
    {
        $state = $this->state();
        $state['removed'] = array_values(array_filter($state['removed'], static fn(string $v): bool => $v !== $code));
        if (!in_array($code, $state['disabled'], true)) {
            $state['disabled'][] = $code;
        }

        return $this->writeState($state);
    }

    public function uninstall(string $code): bool
    {
        $state = $this->state();
        $state['disabled'] = array_values(array_filter($state['disabled'], static fn(string $v): bool => $v !== $code));
        if (!in_array($code, $state['removed'], true)) {
            $state['removed'][] = $code;
        }

        return $this->writeState($state);
    }

    public function restore(string $code): bool
    {
        return $this->enable($code);
    }

    /** @return array<int,array{name:string,path:string}> */
    private function scan(string $pluginRoot): array
    {
        if (!is_dir($pluginRoot)) {
            return [];
        }

        $entries = scandir($pluginRoot);
        if (!is_array($entries)) {
            return [];
        }

        $rows = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $pluginRoot . '/' . $name;
            if (is_dir($path)) {
                $rows[] = ['name' => $name, 'path' => $path];
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function readMeta(string $pluginPath): array
    {
        $file = $pluginPath . '/plugin.json';
        if (!is_file($file)) {
            return [];
        }

        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : [];
    }

    private function appVersion(): string
    {
        $app = require __DIR__ . '/../config/app.php';
        return (string) ($app['version'] ?? '1.0.0');
    }

    /** @param array<string,mixed> $meta @param array<string,array{name:string,path:string}> $installed @return array{ok:bool,reason:string} */
    private function validatePlugin(string $code, array $meta, string $appVersion, array $installed): array
    {
        $aptcmsConstraint = trim((string) ($meta['aptcms'] ?? ''));
        if ($aptcmsConstraint !== '' && !$this->matchVersionConstraint($appVersion, $aptcmsConstraint)) {
            return ['ok' => false, 'reason' => 'aptcms version mismatch'];
        }

        $requires = $meta['requires'] ?? [];
        if (!is_array($requires)) {
            return ['ok' => false, 'reason' => 'invalid requires format'];
        }

        foreach ($requires as $requirement) {
            if (!is_array($requirement)) {
                return ['ok' => false, 'reason' => 'invalid requires item'];
            }

            $requiredCode = trim((string) ($requirement['code'] ?? ''));
            $constraint = trim((string) ($requirement['constraint'] ?? ''));
            if ($requiredCode === '') {
                return ['ok' => false, 'reason' => 'missing dependency code'];
            }
            if (!isset($installed[$requiredCode])) {
                return ['ok' => false, 'reason' => 'missing dependency: ' . $requiredCode];
            }

            if ($constraint !== '') {
                $depMeta = $this->readMeta($installed[$requiredCode]['path']);
                $depVersion = (string) ($depMeta['version'] ?? '0.0.0');
                if (!$this->matchVersionConstraint($depVersion, $constraint)) {
                    return ['ok' => false, 'reason' => 'dependency version mismatch: ' . $requiredCode];
                }
            }
        }

        if (!is_file(($installed[$code]['path'] ?? '') . '/bootstrap.php')) {
            return ['ok' => false, 'reason' => 'bootstrap not found'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    private function matchVersionConstraint(string $version, string $constraint): bool
    {
        if (!preg_match('/^(>=|<=|>|<|=)?\s*([0-9]+(?:\.[0-9]+){0,2})$/', $constraint, $matches)) {
            return false;
        }

        $operator = $matches[1] !== '' ? $matches[1] : '=';
        $target = $matches[2];
        return version_compare($version, $target, $operator);
    }

    /** @return array{disabled:array<int,string>,removed:array<int,string>} */
    private function state(): array
    {
        $file = $this->stateFile();
        if (!is_file($file)) {
            return ['disabled' => [], 'removed' => []];
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload)) {
            return ['disabled' => [], 'removed' => []];
        }

        $disabled = array_values(array_filter($payload['disabled'] ?? [], static fn($v): bool => is_string($v) && $v !== ''));
        $removed = array_values(array_filter($payload['removed'] ?? [], static fn($v): bool => is_string($v) && $v !== ''));

        return ['disabled' => $disabled, 'removed' => $removed];
    }

    /** @param array{disabled:array<int,string>,removed:array<int,string>} $state */
    private function writeState(array $state): bool
    {
        $file = $this->stateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function stateFile(): string
    {
        return __DIR__ . '/../cache/plugin_state.json';
    }

    private function detectPluginRootDir(string $extractDir): ?string
    {
        if (is_file($extractDir . '/plugin.json')) {
            return $extractDir;
        }

        $entries = scandir($extractDir);
        if (!is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $extractDir . '/' . $entry;
            if (is_dir($path) && is_file($path . '/plugin.json')) {
                return $path;
            }
        }

        return null;
    }

    private function rcopy(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }

        $entries = scandir($src);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            if (is_dir($s)) {
                $this->rcopy($s, $d);
                continue;
            }
            copy($s, $d);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
