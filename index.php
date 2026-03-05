<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $path = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$config = core\ConfigRepository::load(__DIR__);
$app = new core\App(__DIR__, $config);
$app->run();
