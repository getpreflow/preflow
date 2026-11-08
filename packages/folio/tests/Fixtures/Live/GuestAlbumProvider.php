<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Live;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Http\RequestContext;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * A guest album as on holz-objekte: the cookie "album" holds the guest's item numbers ("1_2").
 * album_count() and in_album(n) read the visitor's request (RequestContext), so they belong in
 * live parts; album_numbers() is for the test that forgets that.
 */
final class GuestAlbumProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $numbers = static function () use ($container): array {
            $request = $container->get(RequestContext::class)->request;
            $value = $request?->getCookieParams()['album'] ?? '';

            return is_string($value) && $value !== '' ? array_map('intval', explode('_', $value)) : [];
        };
        // A form token from the visitor (as holz's FormToken, from the client's address): a registered
        // per-request value, so it may sit outside live parts.
        $token = static function () use ($container): string {
            $request = $container->get(RequestContext::class)->request;

            return 'guest-token-' . substr(hash('sha256', ($request?->getServerParams()['REMOTE_ADDR'] ?? '') . '|' . ($request?->getCookieParams()['album'] ?? '')), 0, 16);
        };
        $container->get(\Preflow\Core\PageCache\PerRequestValues::class)->register('guest_token', $token);
        $engine = $container->get(TemplateEngineInterface::class);
        $engine->addFunction(new TemplateFunctionDefinition('guest_token', $token));
        $engine->addFunction(new TemplateFunctionDefinition('album_count', static fn (): int => count($numbers())));
        $engine->addFunction(new TemplateFunctionDefinition('in_album', static fn (int|string $number): bool => in_array((int) $number, $numbers(), true)));
        // A site that reads the cookie from PHP's superglobal instead of the request.
        $engine->addFunction(new TemplateFunctionDefinition('raw_album', static fn (): string => (string) ($_COOKIE['album'] ?? '')));
        $engine->addFunction(new TemplateFunctionDefinition('album_numbers', static fn (): string => implode(',', $numbers())));
        $engine->addFunction(new TemplateFunctionDefinition('live_boom', static function (): string {
            throw new \RuntimeException('the album service is down');
        }));
    }
}
