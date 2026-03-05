<?php

declare(strict_types=1);

namespace core;

abstract class Model
{
    protected static string $table;

    public static function table(): string
    {
        return static::$table;
    }
}
