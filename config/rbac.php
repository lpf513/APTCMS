<?php

return [
    'roles' => [
        'super_admin' => ['*'],
        'editor' => [
            'content.article.view',
            'content.article.create',
            'content.article.edit',
            'content.article.delete',
            'seo.geo.view',
            'system.upload.manage',
            'system.audit.view',
            'system.plugin.view',
        ],
    ],
];
