<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Fixtures;

use Nyholm\Psr7\Response;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The public pages of AuditsTest's app: only /mail. */
final class AuditsTestPages extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(PublicPages::class)->source(
            static fn (string $locale): array => [new PublicPage('Mail', '/mail')],
        );
    }
}

/**
 * Routes of AuditsTest's app: /mail queues a mail when it renders; /admin/step is an admin
 * request that queues its own mail, then runs a step of the audit ($step).
 */
final class AuditsTestRoutes implements MiddlewareInterface
{
    /** @var (\Closure(): void)|null the audit step /admin/step runs */
    public static ?\Closure $step = null;

    public function __construct(private readonly Notifier $notifier) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/mail' => $this->mail(),
            '/admin/step' => $this->step(),
            default => $handler->handle($request),
        };
    }

    private function mail(): ResponseInterface
    {
        $this->notifier->sendLater(new Email('visitor@example.com', 'Hello', 'A mail queued by a GET.'));

        return self::page('Mail');
    }

    private function step(): ResponseInterface
    {
        $this->notifier->sendLater(new Email('admin@example.com', 'Audit', 'The admin request\'s own mail.'));
        (self::$step)();

        return self::page('Step');
    }

    private static function page(string $title): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], "<html><head><title>{$title}</title></head><body><h1>{$title}</h1></body></html>");
    }
}
