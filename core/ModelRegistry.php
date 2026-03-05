<?php

declare(strict_types=1);

namespace core;

use Throwable;

final class ModelRegistry
{
    /** @return array<string,mixed> */
    public function all(): array
    {
        $fromDb = $this->allFromDb();
        if ($fromDb !== []) {
            return $fromDb;
        }

        $config = require __DIR__ . '/../config/models.php';
        return is_array($config) ? $config : [];
    }

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array
    {
        $all = $this->all();
        $model = $all[$name] ?? null;
        return is_array($model) ? $model : null;
    }

    /** @return array<string,mixed> */
    private function allFromDb(): array
    {
        $app = require __DIR__ . '/../config/app.php';
        if (($app['db']['enabled'] ?? false) !== true) {
            return [];
        }

        try {
            $pdo = DB::conn($app['db']);
            $models = $pdo->query('SELECT model_code, model_name, table_name, module_code, template_list, template_detail FROM apt_model ORDER BY id ASC')->fetchAll();
            if (!is_array($models) || $models === []) {
                return [];
            }

            $fieldsRaw = $pdo->query('SELECT model_code, field_code, field_type, required, ai_understand, seo_weight, geo_weight, embedding, sort_order FROM apt_model_field ORDER BY model_code ASC, sort_order ASC, id ASC')->fetchAll();
            $fieldsByModel = [];
            if (is_array($fieldsRaw)) {
                foreach ($fieldsRaw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $modelCode = (string) ($row['model_code'] ?? '');
                    $fieldCode = (string) ($row['field_code'] ?? '');
                    if ($modelCode === '' || $fieldCode === '') {
                        continue;
                    }

                    $fieldsByModel[$modelCode][$fieldCode] = [
                        'type' => (string) ($row['field_type'] ?? 'text'),
                        'required' => ((int) ($row['required'] ?? 0)) === 1,
                        'ai_understand' => ((int) ($row['ai_understand'] ?? 0)) === 1,
                        'seo_weight' => (int) ($row['seo_weight'] ?? 0),
                        'geo_weight' => (int) ($row['geo_weight'] ?? 0),
                        'embedding' => ((int) ($row['embedding'] ?? 0)) === 1,
                    ];
                }
            }

            $result = [];
            foreach ($models as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $code = (string) ($model['model_code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $result[$code] = [
                    'name' => (string) ($model['model_name'] ?? ''),
                    'table' => (string) ($model['table_name'] ?? ''),
                    'module' => (string) ($model['module_code'] ?? ''),
                    'template' => [
                        'list' => (string) ($model['template_list'] ?? ''),
                        'detail' => (string) ($model['template_detail'] ?? ''),
                    ],
                    'fields' => $fieldsByModel[$code] ?? [],
                ];
            }

            return $result;
        } catch (Throwable) {
            return [];
        }
    }
}
