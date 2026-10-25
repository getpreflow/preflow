<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Csrf\ExpiredSessionHandler;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Keeps the posted title in the new session and sends the visitor to the sign-in. */
final class RedirectingExpiredSessionHandler implements ExpiredSessionHandler
{
    public function handle(ServerRequestInterface $request): ?ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $body = (array) $request->getParsedBody();
        $session->set('pending_title', $body['title'] ?? null);
        $token = $request->getAttribute(CsrfToken::class);

        return new Response(303, [
            'Location' => '/login?next=' . $request->getUri()->getPath(),
            'X-Has-Token' => $token instanceof CsrfToken ? 'yes' : 'no',
        ]);
    }
}
