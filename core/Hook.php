<?php

declare(strict_types=1);

namespace core;

final class Hook
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, mixed $payload = null): mixed
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $payload = $listener($payload);
        }

        return $payload;
    }
}
