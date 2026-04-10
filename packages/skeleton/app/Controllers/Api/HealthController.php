<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Preflow\Routing\Attributes\Route;
use Preflow\Routing\Attributes\Get;
use Nyholm\Psr7\Response;

#[Route('/api')]
final class HealthController
{
    #[Get('/health')]
    public function health(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'ok',
            'framework' => 'Preflow',
            'php' => PHP_VERSION,
        ]));
    }
}
