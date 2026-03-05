<?php

declare(strict_types=1);

function assertTrue(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$message}\n");
}

require __DIR__ . '/../core/Auth.php';
require __DIR__ . '/../core/Security.php';
require __DIR__ . '/../core/RateLimiter.php';
require __DIR__ . '/../core/DB.php';
require __DIR__ . '/../core/ConfigRepository.php';
require __DIR__ . '/../core/Audit.php';
require __DIR__ . '/../core/Router.php';
require __DIR__ . '/../core/Controller.php';
require __DIR__ . '/../core/ModelRegistry.php';
require __DIR__ . '/../core/PluginManager.php';
require __DIR__ . '/../core/AdminUserService.php';
require __DIR__ . '/../modules/article/ArticleService.php';
require __DIR__ . '/../ai/Agent/SeoAgent.php';
require __DIR__ . '/../ai/Agent/GeoAgent.php';
require __DIR__ . '/../app/api/Controller/AiController.php';
require __DIR__ . '/../app/admin/Controller/SettingController.php';

$loadedConfig = core\ConfigRepository::load(__DIR__ . '/..');
assertTrue(is_array($loadedConfig) && (string) ($loadedConfig['name'] ?? '') !== '', 'ConfigRepository should load config array');

$router = new core\Router();
$route = $router->parse('/article/ai-cms-design.html');
assertTrue($route['controller'] === 'article' && $route['action'] === 'detail', 'Router should map article detail path');

$settingControllerClass = new ReflectionClass('app\admin\Controller\SettingController');
assertTrue($settingControllerClass->hasMethod('export'), 'SettingController should provide export action');
assertTrue($settingControllerClass->hasMethod('buildChangeStats'), 'SettingController should provide history stats method');
assertTrue($settingControllerClass->hasMethod('normalizeDateTimeInput'), 'SettingController should normalize datetime filter input');

$apiRoute = $router->parse('/api/ai/content.json');
assertTrue($apiRoute['app'] === 'api' && $apiRoute['action'] === 'content', 'Router should map api json path');

$aiAliasRoute = $router->parse('/ai/knowledge.json');
assertTrue($aiAliasRoute['app'] === 'api' && $aiAliasRoute['action'] === 'knowledge', 'Router should map ai alias json path');

$siteMapRoute = $router->parse('/sitemap.xml');
assertTrue($siteMapRoute['controller'] === 'seo' && $siteMapRoute['action'] === 'sitemap', 'Router should map sitemap path');

$robotsRoute = $router->parse('/robots.txt');
assertTrue($robotsRoute['controller'] === 'seo' && $robotsRoute['action'] === 'robots', 'Router should map robots path');

$healthRoute = $router->parse('/healthz');
assertTrue($healthRoute['controller'] === 'system' && $healthRoute['action'] === 'health', 'Router should map health route');

$installRoute = $router->parse('/install/index/run');
assertTrue($installRoute['app'] === 'install' && $installRoute['controller'] === 'index' && $installRoute['action'] === 'run', 'Router should map install route');

$modelPreviewRoute = $router->parse('/admin/model/preview/article');
assertTrue($modelPreviewRoute['app'] === 'admin' && $modelPreviewRoute['controller'] === 'model' && $modelPreviewRoute['action'] === 'preview', 'Router should map model preview route');

$modelRegistry = new core\ModelRegistry();
$modelConfig = $modelRegistry->get('article');
assertTrue(is_array($modelConfig) && (($modelConfig['table'] ?? '') === 'apt_content_article'), 'ModelRegistry should resolve article model');

$auditExportRoute = $router->parse('/admin/audit/export');
assertTrue($auditExportRoute['app'] === 'admin' && $auditExportRoute['controller'] === 'audit' && $auditExportRoute['action'] === 'export', 'Router should map audit export route');


$apiCtrl = new app\api\Controller\AiController([]);
$paginationMethod = new ReflectionMethod($apiCtrl, 'pagination');
$paginationMethod->setAccessible(true);
$backupGet = $_GET;
$_GET = ['updated_after' => 'not-a-date'];
$pagination = $paginationMethod->invoke($apiCtrl);
assertTrue(($pagination[4] ?? null) === 'invalid updated_after', 'AiController should reject invalid updated_after param');


$_GET = ['cursor' => 'abc'];
$pagination = $paginationMethod->invoke($apiCtrl);
assertTrue(($pagination[4] ?? null) === 'invalid cursor', 'AiController should reject invalid cursor param');

$_GET = ['page' => '0'];
$pagination = $paginationMethod->invoke($apiCtrl);
assertTrue(($pagination[4] ?? null) === 'invalid page', 'AiController should reject invalid page param');

$_GET = ['limit' => '-5'];
$pagination = $paginationMethod->invoke($apiCtrl);
assertTrue(($pagination[4] ?? null) === 'invalid limit', 'AiController should reject invalid limit param');


$_GET = ['cursor' => '2', 'page' => '1'];
$pagination = $paginationMethod->invoke($apiCtrl);
assertTrue(($pagination[4] ?? null) === 'cursor and page are mutually exclusive', 'AiController should reject cursor/page mixed usage');

$_GET = ['limit' => '999999'];
$pagination = $paginationMethod->invoke($apiCtrl);
assertTrue(($pagination[4] ?? null) === 'limit exceeds max_page_size', 'AiController should reject limit beyond configured max');

$_GET = $backupGet;

$service = new modules\article\ArticleService();
$article = $service->findBySlug('ai-cms-design');
assertTrue(is_array($article), 'ArticleService should find article by slug');

$list = $service->list(2, 0);
assertTrue(count($list) <= 2, 'ArticleService should support limit/offset list');
$invalidTimeList = $service->list(10, 0, 'invalid-time');
assertTrue($invalidTimeList === [], 'ArticleService invalid updatedAfter should return empty list');
$invalidTimeTotal = $service->total('invalid-time');
assertTrue($invalidTimeTotal === 0, 'ArticleService invalid updatedAfter should return zero total');

$dataFile = __DIR__ . '/../modules/article/data.json';
$backup = (string) file_get_contents($dataFile);
$slug = 'selftest-' . substr(bin2hex(random_bytes(6)), 0, 10);

try {
    $created = $service->create([
        'slug' => $slug,
        'title' => 'Selftest Article',
        'content' => 'Selftest content',
        'author' => 'tester',
        'published_at' => date('Y-m-d H:i:s'),
    ]);
    assertTrue($created, 'ArticleService should create article');

    $updated = $service->updateBySlug($slug, [
        'title' => 'Selftest Article Updated',
        'content' => 'Selftest content updated',
        'author' => 'tester2',
        'published_at' => date('Y-m-d H:i:s'),
    ]);
    assertTrue($updated, 'ArticleService should update article by slug');

    $foundUpdated = $service->findBySlug($slug);
    assertTrue(is_array($foundUpdated) && ($foundUpdated['title'] ?? '') === 'Selftest Article Updated', 'ArticleService should read updated article');

    $deleted = $service->deleteBySlug($slug);
    assertTrue($deleted, 'ArticleService should delete article by slug');


    $createdDraft = $service->create([
        'slug' => $slug . '-draft',
        'title' => 'Selftest Draft',
        'content' => 'Draft content',
        'author' => 'tester',
        'published_at' => date('Y-m-d H:i:s'),
        'status' => 0,
    ]);
    assertTrue($createdDraft, 'ArticleService should create draft article');

    $publicDraft = $service->findBySlug($slug . '-draft');
    assertTrue($publicDraft === null, 'ArticleService public find should hide drafts');

    $adminDraft = $service->findAnyBySlug($slug . '-draft');
    assertTrue(is_array($adminDraft) && (int) ($adminDraft['status'] ?? 1) === 0, 'ArticleService admin find should include drafts');

    $adminList = $service->listForAdmin(10, 0, 'Selftest Draft', '0');
    assertTrue(count($adminList) >= 1, 'ArticleService admin list should support status and keyword filters');
    $adminDraftTotal = $service->totalForAdmin('Selftest Draft', '0');
    assertTrue($adminDraftTotal >= 1, 'ArticleService admin total should support status and keyword filters');

    $sortedAsc = $service->listForAdmin(10, 0, '', 'all', 'slug', 'asc');
    assertTrue(count($sortedAsc) >= 1, 'ArticleService admin list should support sorting params');
    if (count($sortedAsc) >= 2) {
        assertTrue(strcmp((string) ($sortedAsc[0]['slug'] ?? ''), (string) ($sortedAsc[1]['slug'] ?? '')) <= 0, 'ArticleService slug asc sort should be deterministic');
    }

    $publishedByBulk = $service->bulkUpdateStatusBySlugs([$slug . '-draft'], 1);
    assertTrue($publishedByBulk >= 1, 'ArticleService bulk publish should update selected slugs');

    $afterBulkPublished = $service->findBySlug($slug . '-draft');
    assertTrue(is_array($afterBulkPublished), 'ArticleService public find should show draft after bulk publish');

    $bulkDeleted = $service->bulkDeleteBySlugs([$slug . '-draft']);
    assertTrue($bulkDeleted >= 1, 'ArticleService bulk delete should remove selected slugs');
} finally {
    file_put_contents($dataFile, $backup);
}

$seo = (new ai\Agent\SeoAgent())->generateMeta($article);
assertTrue(isset($seo['canonical']) && str_contains($seo['canonical'], '.html'), 'SeoAgent should generate canonical url');

$geo = (new ai\Agent\GeoAgent())->buildKnowledge($article);
assertTrue(isset($geo['schema']['@type']) && $geo['schema']['@type'] === 'Article', 'GeoAgent should generate article schema');

assertTrue(core\Security::isAllowedUpload('a.png', 'image/png'), 'Security should allow whitelisted upload');
assertTrue(!core\Security::isAllowedUpload('hack.php', 'text/x-php'), 'Security should deny dangerous upload');

$limiter = new core\RateLimiter();
$k = 'selftest_' . bin2hex(random_bytes(4));
$limiter->hit($k, 60, 2);
$limiter->hit($k, 60, 2);
assertTrue(!$limiter->check($k, 60, 2), 'RateLimiter should lock after max attempts in window');
$limiter->reset($k);


core\Audit::write('selftest.event', ['ok' => true]);
$auditRows = core\Audit::latest(5);
assertTrue(count($auditRows) >= 1, 'Audit should store and read latest events');
$auditSearchRows = core\Audit::search(['event' => 'selftest.event', 'ip' => '', 'query' => '"ok":true'], 5);
assertTrue(count($auditSearchRows) >= 1, 'Audit search should filter by event and context keywords');


$pluginManager = new core\PluginManager();
$plugins = $pluginManager->list(__DIR__ . '/../plugins');
assertTrue(count($plugins) >= 1, 'PluginManager should list installed plugins');
$firstPlugin = $plugins[0] ?? [];
assertTrue((string) ($firstPlugin['loadable'] ?? 'no') === 'yes', 'PluginManager should validate plugin compatibility');

$pluginCode = (string) ($firstPlugin['code'] ?? '');
assertTrue($pluginCode !== '', 'PluginManager should provide plugin code');

$disabledOk = $pluginManager->disable($pluginCode);
assertTrue($disabledOk, 'PluginManager should disable plugin');
$afterDisable = $pluginManager->list(__DIR__ . '/../plugins');
assertTrue((string) (($afterDisable[0]['lifecycle'] ?? '')) === 'disabled', 'Plugin lifecycle should become disabled');

$enabledOk = $pluginManager->enable($pluginCode);
assertTrue($enabledOk, 'PluginManager should enable plugin');
$afterEnable = $pluginManager->list(__DIR__ . '/../plugins');
assertTrue((string) (($afterEnable[0]['lifecycle'] ?? '')) === 'enabled', 'Plugin lifecycle should become enabled');

$installResult = $pluginManager->installFromUrl(__DIR__ . '/../plugins', 'ftp://invalid');
assertTrue($installResult['ok'] === false, 'PluginManager should reject non-http install url');

$adminConfig = require __DIR__ . '/../config/admin.php';
$adminHash = (string) ($adminConfig['users']['admin']['password_hash'] ?? '');
assertTrue(password_verify('aptcms123', $adminHash), 'Admin password hash should be valid');

$adminUser = (new core\AdminUserService())->findByUsername('admin');
assertTrue(is_array($adminUser) && (string) ($adminUser['role'] ?? '') !== '', 'AdminUserService should resolve admin user');

core\Auth::login('editor', 'editor');
assertTrue(!core\Auth::can('system.settings.manage'), 'Editor should not manage system settings');
core\Auth::logout();

core\Auth::login('admin', 'super_admin');
assertTrue(core\Auth::can('system.settings.manage'), 'Super admin should manage system settings');
core\Auth::logout();


$appConfigFile = __DIR__ . '/../config/app.php';
$appConfigBackup = (string) file_get_contents($appConfigFile);
try {
    $strictConfig = require $appConfigFile;
    if (is_array($strictConfig)) {
        $strictConfig['db']['enabled'] = true;
        $strictConfig['db']['fallback_to_json'] = false;
        $strictConfig['db']['host'] = '127.0.0.1';
        $strictConfig['db']['port'] = 1;
        $strictConfig['db']['dbname'] = 'invalid';
        $strictConfig['db']['user'] = 'invalid';
        $strictConfig['db']['password'] = 'invalid';
        file_put_contents($appConfigFile, "<?php

return " . var_export($strictConfig, true) . ";
");

        $strictService = new modules\article\ArticleService();
        assertTrue($strictService->list(5, 0) === [], 'ArticleService strict DB mode should not fallback to JSON list');
        assertTrue($strictService->findBySlug('ai-cms-design') === null, 'ArticleService strict DB mode should not fallback to JSON detail');
    }
} finally {
    file_put_contents($appConfigFile, $appConfigBackup);
}

fwrite(STDOUT, "All self tests passed.\n");
