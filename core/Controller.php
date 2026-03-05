<?php

declare(strict_types=1);

namespace core;

class Controller
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /** @param array<string,mixed> $config */
    public function __construct(protected array $config = [])
    {
    }

    protected function assign(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    protected function display(string $template): void
    {
        (new View())->render($template, $this->data);
    }

    protected function json(array $payload, int $code = 200): void
    {
        Response::json($payload, $code);
    }
}
