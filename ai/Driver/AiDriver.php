<?php

declare(strict_types=1);

namespace ai\Driver;

interface AiDriver
{
    /** @param array<int, array<string, string>> $messages */
    public function chat(array $messages): string;

    /** @return array<int, float> */
    public function embedding(string $text): array;
}
