<?php

declare(strict_types=1);

namespace core;

final class Template
{
    public function compile(string $template): string
    {
        $source = __DIR__ . '/../themes/' . $template;
        $cacheDir = __DIR__ . '/../cache';
        $target = $cacheDir . '/' . md5($template) . '.php';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        if (!is_file($source)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }

        if (!is_file($target) || filemtime($source) > filemtime($target)) {
            $html = (string) file_get_contents($source);
            $php = $this->compileTags($html);
            file_put_contents($target, $php);
        }

        return $target;
    }

    private function compileTags(string $html): string
    {
        $html = preg_replace('/\{\$(\w+)\.(\w+)\|raw\}/', '<?= (string) (($$1["$2"] ?? "")) ?>', $html);
        $html = preg_replace('/\{\$(\w+)\|raw\}/', '<?= (string) ($$1 ?? "") ?>', $html);

        $html = preg_replace('/\{\$(\w+)\.(\w+)\}/', '<?= htmlspecialchars((string) (($$1["$2"] ?? "")), ENT_QUOTES, "UTF-8") ?>', $html);
        $html = preg_replace('/\{\$(\w+)\}/', '<?= htmlspecialchars((string) ($$1 ?? ""), ENT_QUOTES, "UTF-8") ?>', $html);

        $html = preg_replace('/\{if\s+(.+?)\}/', '<?php if ($1): ?>', $html);
        $html = preg_replace('/\{elseif\s+(.+?)\}/', '<?php elseif ($1): ?>', $html);
        $html = str_replace('{else}', '<?php else: ?>', $html);
        $html = str_replace('{/if}', '<?php endif; ?>', $html);

        $html = preg_replace('/\{loop\s+items="\$(\w+)"\s+as="(\w+)"\}/', '<?php foreach ((array) ($$1 ?? []) as $$2): ?>', $html);
        $html = str_replace('{/loop}', '<?php endforeach; ?>', $html);

        $html = preg_replace(
            '/\{list\s+model="([a-z_]+)"\s+limit="(\d+)"\}/',
            '<?php foreach (\\core\\TemplateRuntime::list("$1", $2) as $__item): extract($__item, EXTR_OVERWRITE); ?>',
            $html
        );
        $html = str_replace('{/list}', '<?php endforeach; ?>', $html);

        $html = preg_replace('/\{ai_summary\s*\/\}/', '<?= htmlspecialchars(\\core\\TemplateRuntime::aiSummary(get_defined_vars()), ENT_QUOTES, "UTF-8") ?>', $html);
        $html = preg_replace('/\{ai_related\s+limit="(\d+)"\s*\/\}/', '<?php foreach (\\core\\TemplateRuntime::aiRelated(get_defined_vars(), $1) as $__item): extract($__item, EXTR_OVERWRITE); ?><li><a href="/article/<?= htmlspecialchars((string) $slug, ENT_QUOTES, "UTF-8") ?>.html"><?= htmlspecialchars((string) $title, ENT_QUOTES, "UTF-8") ?></a></li><?php endforeach; ?>', $html);

        return (string) $html;
    }
}
