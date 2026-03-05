<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Auth;
use core\Controller;
use core\ModelRegistry;
use core\Response;

final class ModelController extends Controller
{
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }

        $registry = new ModelRegistry();
        $rows = [];

        foreach ($registry->all() as $key => $cfg) {
            $rows[] = [
                'code' => (string) $key,
                'name' => (string) ($cfg['name'] ?? ''),
                'table' => (string) ($cfg['table'] ?? ''),
                'module' => (string) ($cfg['module'] ?? ''),
                'field_count' => (string) count($cfg['fields'] ?? []),
            ];
        }

        $this->assign('title', '模型管理');
        $this->assign('rows', $rows);
        $this->display('admin/models.html');
    }

    public function detail(string $code): void
    {
        if (!$this->guard()) {
            return;
        }

        $registry = new ModelRegistry();
        $model = $registry->get($code);
        if ($model === null) {
            Response::text('Model not found', 404);
            return;
        }

        $fields = [];
        foreach (($model['fields'] ?? []) as $fieldCode => $field) {
            $fields[] = [
                'code' => (string) $fieldCode,
                'type' => (string) ($field['type'] ?? ''),
                'required' => ((bool) ($field['required'] ?? false)) ? 'yes' : 'no',
                'ai_understand' => ((bool) ($field['ai_understand'] ?? false)) ? 'yes' : 'no',
                'seo_weight' => (string) ($field['seo_weight'] ?? 0),
                'geo_weight' => (string) ($field['geo_weight'] ?? 0),
                'embedding' => ((bool) ($field['embedding'] ?? false)) ? 'yes' : 'no',
            ];
        }

        $this->assign('title', '模型字段详情 - ' . (string) ($model['name'] ?? $code));
        $this->assign('model_code', $code);
        $this->assign('fields', $fields);
        $this->display('admin/model-detail.html');
    }

    public function preview(string $code): void
    {
        if (!$this->guard()) {
            return;
        }

        $registry = new ModelRegistry();
        $model = $registry->get($code);
        if ($model === null) {
            Response::text('Model not found', 404);
            return;
        }

        $controls = [];
        foreach (($model['fields'] ?? []) as $fieldCode => $field) {
            $type = (string) ($field['type'] ?? 'text');
            $controls[] = [
                'field' => (string) $fieldCode,
                'type' => $type,
                'html' => $this->buildControl((string) $fieldCode, $type),
            ];
        }

        $this->assign('title', '表单预览 - ' . (string) ($model['name'] ?? $code));
        $this->assign('controls', $controls);
        $this->display('admin/model-preview.html');
    }

    private function guard(): bool
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return false;
        }

        if (!Auth::can('content.article.view')) {
            Response::text('Forbidden', 403);
            return false;
        }

        return true;
    }

    private function buildControl(string $field, string $type): string
    {
        $name = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');

        return match ($type) {
            'textarea', 'editor' => '<textarea name="' . $name . '" rows="3" cols="40"></textarea>',
            'image' => '<input type="file" name="' . $name . '">',
            'images' => '<input type="file" name="' . $name . '[]" multiple>',
            'number' => '<input type="number" name="' . $name . '">',
            'select' => '<select name="' . $name . '"><option>Option</option></select>',
            'radio' => '<label><input type="radio" name="' . $name . '" value="1"> Yes</label>',
            'checkbox' => '<label><input type="checkbox" name="' . $name . '[]" value="1"> Tag</label>',
            'datetime' => '<input type="datetime-local" name="' . $name . '">',
            default => '<input type="text" name="' . $name . '">',
        };
    }
}
