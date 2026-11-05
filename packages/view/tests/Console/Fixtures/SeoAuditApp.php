<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Console\Fixtures;

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

/** The public pages of SeoAuditCommandTest's app: the paths in $paths, in that order. */
final class SeoAuditPages extends ServiceProvider
{
    /** @var list<string> */
    public static array $paths = ['/about', '/contact'];

    public function register(Container $container): void
    {
        $container->get(PublicPages::class)->source(
            // In the listed order.
            static fn (string $locale): array => array_map(
                static fn (string $path, int $order): PublicPage => new PublicPage(ltrim($path, '/'), $path, order: $order),
                self::$paths,
                array_keys(self::$paths),
            ),
        );
    }
}

/** Routes of SeoAuditCommandTest's app: one that is slow, one that queues a mail. */
final class SeoAuditRoutes implements MiddlewareInterface
{
    public function __construct(private readonly Notifier $notifier) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/slow' => $this->slow(),
            '/mail' => $this->mail(),
            default => $handler->handle($request),
        };
    }

    private function slow(): ResponseInterface
    {
        usleep(1_100_000);

        return self::page('Slow');
    }

    private function mail(): ResponseInterface
    {
        $this->notifier->sendLater(new Email('ada@example.com', 'Hello', 'A mail queued by a GET.'));

        return self::page('Mail');
    }

    private static function page(string $title): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], "<html><head><title>{$title}</title></head><body><h1>{$title}</h1></body></html>");
    }
}
