<?php

return [
    'name' => 'APTCMS',
    'version' => '1.0.0',
    'env' => 'dev',
    'base_url' => 'http://127.0.0.1:8080',
    'db' => [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'aptcms',
        'user' => 'root',
        'password' => '',
        'fallback_to_json' => true,
    ],
    'api' => [
        'default_page_size' => 20,
        'max_page_size' => 100,
        'rate_limit' => 120,
        'rate_window' => 60,
    ],
    'security' => [
        'login_rate_limit' => 5,
        'login_rate_window' => 300,
        'admin_ip_allowlist' => [],
    ],
];
