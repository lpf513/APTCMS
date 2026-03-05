<?php

declare(strict_types=1);

namespace core;

final class View
{
    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): void
    {
        $compiled = (new Template())->compile($template);
        extract($data, EXTR_SKIP);
        require $compiled;
    }
}
