<?php

return [
    'article' => [
        'name' => '文章',
        'table' => 'apt_content_article',
        'module' => 'article',
        'template' => [
            'list' => 'article/list.html',
            'detail' => 'article/detail.html',
        ],
        'fields' => [
            'title' => ['type' => 'text', 'required' => true, 'ai_understand' => true, 'seo_weight' => 10, 'geo_weight' => 10, 'embedding' => true],
            'summary' => ['type' => 'textarea', 'required' => false, 'ai_understand' => true, 'seo_weight' => 8, 'geo_weight' => 9, 'embedding' => true],
            'content' => ['type' => 'editor', 'required' => true, 'ai_understand' => true, 'seo_weight' => 10, 'geo_weight' => 10, 'embedding' => true],
            'cover' => ['type' => 'image', 'required' => false, 'ai_understand' => false, 'seo_weight' => 2, 'geo_weight' => 2, 'embedding' => false],
            'gallery' => ['type' => 'images', 'required' => false, 'ai_understand' => false, 'seo_weight' => 1, 'geo_weight' => 1, 'embedding' => false],
            'priority' => ['type' => 'number', 'required' => false, 'ai_understand' => false, 'seo_weight' => 3, 'geo_weight' => 2, 'embedding' => false],
            'category' => ['type' => 'select', 'required' => true, 'ai_understand' => true, 'seo_weight' => 5, 'geo_weight' => 4, 'embedding' => false],
            'status' => ['type' => 'radio', 'required' => true, 'ai_understand' => false, 'seo_weight' => 1, 'geo_weight' => 1, 'embedding' => false],
            'tags' => ['type' => 'checkbox', 'required' => false, 'ai_understand' => true, 'seo_weight' => 7, 'geo_weight' => 8, 'embedding' => true],
            'author' => ['type' => 'text', 'required' => true, 'ai_understand' => true, 'seo_weight' => 4, 'geo_weight' => 6, 'embedding' => false],
            'published_at' => ['type' => 'datetime', 'required' => true, 'ai_understand' => true, 'seo_weight' => 3, 'geo_weight' => 5, 'embedding' => false],
        ],
    ],
];
