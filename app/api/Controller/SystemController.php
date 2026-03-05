<?php

declare(strict_types=1);

namespace app\api\Controller;

use core\Controller;

final class SystemController extends Controller
{
    public function health(): void
    {
        $this->json([
            'status' => 'ok',
            'name' => (string) ($this->config['name'] ?? 'APTCMS'),
            'time' => date('c'),
        ]);
    }
}
