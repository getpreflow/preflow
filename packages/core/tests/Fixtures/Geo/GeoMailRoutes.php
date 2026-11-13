<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Geo;

use Nyholm\Psr7\Response;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A route for InternalRenderMailTest: /mail queues a mail each time it renders. */
final class GeoMailRoutes implements MiddlewareInterface
{
    public function __construct(private readonly Notifier $notifier) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/mail') {
            return $handler->handle($request);
        }
        $this->notifier->sendLater(new Email('page@example.com', 'Hello', 'A mail queued by a GET.'));

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><head><title>Mail</title></head><body><main><h1>Mail</h1><p>Text.</p></main></body></html>');
    }
}
