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

    #[Get('/debug-session')]
    public function debugSession(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'session_id' => $session?->getId(),
            'session_started' => $session?->isStarted(),
            'session_data' => $_SESSION ?? null,
            'cookie' => $_COOKIE ?? [],
            'session_status' => session_status(),
            'session_name' => session_name(),
        ], JSON_PRETTY_PRINT));
    }
}
