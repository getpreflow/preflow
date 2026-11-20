<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Isolation;

use Preflow\Core\Container\Container;
use Preflow\Core\Http\CookieQueue;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Every GET queues a cookie and a mail, as a page with side effects does; then the page renders. */
final class IsolationSideEffects implements MiddlewareInterface
{
    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            $this->container->get(CookieQueue::class)->queue('page=visited; Path=/');
            $this->container->get(Notifier::class)->sendLater(new Email('page@site.test', 'Visited', 'A mail queued by a GET.'));
        }

        return $handler->handle($request);
    }
}
