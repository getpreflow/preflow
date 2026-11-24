<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use Preflow\Core\Application;
use Preflow\Core\PageCache\Cacheability;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Components\Component;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\Htmx\ComponentToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A site's app booted as the web serves it (not as the console), for tests of what only web
 * requests do: the page cache serves and stores. Its page cache verifies every page it stores
 * (page_cache.verify 'always'): each is rendered a second time as another anonymous visitor, and
 * a page that differs outside its live parts is not stored and is logged
 * ("page cache: {url} differs per visitor outside live parts"), so a personal value the site
 * forgot to put in a live part shows up in its suite.
 *
 *     $response = WebApplication::boot($basePath)->handle($request);
 *
 * Boot one per request, as the server does.
 *
 * Since 0.81 a request can carry the context it renders in, for that request only (the next
 * one is a plain visit again): at() renders it as of a time, withContext() derives its context
 * any other way. Both work with any Application's handle(), and with actingAs().
 *
 *     $app->handle(WebApplication::at(new \DateTimeImmutable('2026-03-01T09:00:00Z'), $request));
 *     $app->handle(WebApplication::withContext(static fn (RenderContext $c) => $c->in('de'), $request));
 */
final class WebApplication
{
    public static function boot(string $basePath, string $verify = PageCacheConfig::VERIFY_ALWAYS): Application
    {
        // A $_SESSION outside an active session is residue of earlier tests in this process, never a visitor's.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            unset($_SESSION);
        }
        $app = Application::create($basePath);
        $app->setRunningInConsole(false);
        $app->setPageCacheVerify($verify);
        $app->boot();

        return $app;
    }

    /**
     * Fails unless the page cache would store the page at $path: it renders it as the cache does,
     * once more as another anonymous visitor, and compares the two. The failure says why (the
     * differing part of the page, or the refusal such as a cookie, a status, a session) and what
     * usually fixes it, as `php preflow pages:why` does. Nothing is stored or served, and it works
     * with app.debug on.
     *
     *     WebApplication::assertPageCacheable(WebApplication::boot($basePath), '/club');
     *
     * @since 0.85.0
     */
    public static function assertPageCacheable(Application $app, string $path, ?string $locale = null): void
    {
        if (!$app->container()->bound(Cacheability::class)) {
            Assert::fail('The page cache is off for this app (page_cache.enabled, and pdo_sqlite): nothing would be cached, so the assertion cannot pass.');
        }
        $result = $app->container()->get(Cacheability::class)->check($path, $locale);
        Assert::assertTrue(
            $result->cacheable,
            "The page cache would not store {$path}" . ($locale !== null ? " ({$locale})" : '') . ": {$result->reason}"
                . ($result->hint !== null ? "\nHint: {$result->hint}" : ''),
        );
    }

    /**
     * $request rendered as of $now: its context is frozen there (RenderContext::at()), so "now"
     * is $now for the whole request, and the page cache neither serves nor stores it. It works with
     * handle(); render() takes its context from its caller and ignores it.
     *
     * @since 0.81.0
     */
    public static function at(\DateTimeImmutable $now, ServerRequestInterface $request): ServerRequestInterface
    {
        return self::withContext(static fn (RenderContext $ctx): RenderContext => $ctx->at($now), $request);
    }

    /**
     * $request rendered in the context $derive makes of the one it would get
     * (Application::withDerivedContext()): another locale, working copies, a role view… Inside a
     * frame of something else (a preview, an audit) it is still never looser than that frame.
     * It works with handle(); render() takes its context from its caller and ignores it.
     *
     * @since 0.81.0
     * @param \Closure(RenderContext): RenderContext $derive
     */
    public static function withContext(\Closure $derive, ServerRequestInterface $request): ServerRequestInterface
    {
        return Application::withDerivedContext($request, static fn (RenderContext $ctx): RenderContext => $derive($ctx));
    }

    /**
     * Runs $action of a component the way the page does, so a test never reads an address or an
     * attribute out of the HTML. The action token is made as a short call makes it (signed with
     * the app's key; a component with state carries its key only), the state is taken from the
     * page's state carrier of that component, and the request goes to the component endpoint of
     * $app with the neutral fields: $params as the form fields, the state, and `_values` or
     * `_region` when $params has them under those names. The component is the one of $componentClass
     * with $key (as `key` in its props); a component without state needs no carrier.
     *
     *     $page = (string) $app->handle($request)->getBody();
     *     $answer = WebApplication::componentAction(WebApplication::boot($basePath), $page, Tally::class, 'bump', ['by' => 2]);
     *
     * For the next call take the new state from the answer (stateFrom()) and put it into the page.
     *
     * @param array<string, mixed> $params
     * @param string $localePrefix the prefix of a localised page ('/de'): its actions run in that locale
     * @param array<string, mixed> $props the props of a component without state (its token carries them,
     *        as the page's token does); a component with state carries its key only, so props fail there
     * @param array<string, string> $cookies the request's cookies, as the browser would send them (the
     *        request's cookie params and its Cookie header), e.g. a visitor token the action reads
     * @since 0.86.0 ($cookies 0.87.0)
     */
    public static function componentAction(
        Application $app,
        string $pageHtml,
        string $componentClass,
        string $action,
        array $params = [],
        ?string $key = null,
        string $localePrefix = '',
        array $props = [],
        array $cookies = [],
    ): ResponseInterface {
        $container = $app->container();
        $component = $container->has($componentClass) ? $container->get($componentClass) : $container->make($componentClass);
        if (!$component instanceof Component) {
            Assert::fail(sprintf('%s is no component.', $componentClass));
        }
        $hasState = $component->hasState();
        if ($hasState && $props !== []) {
            Assert::fail(sprintf('%s has state: its token carries the key only, so $props has no use; its state comes from the page.', $componentClass));
        }
        $component->setProps($props + ($key === null || $key === '' ? [] : ['key' => $key]));
        $id = $component->getComponentId();

        $body = $params;
        if ($hasState) {
            $state = self::stateFrom($pageHtml, $id);
            if ($state === null) {
                $found = self::carriedIds($pageHtml);
                Assert::fail(sprintf(
                    'The page has no state carrier for %s. Components with state on the page: %s.',
                    $id,
                    $found === [] ? 'none' : implode(', ', $found),
                ));
            }
            $body[DrivesComponentActions::STATE_FIELD] = [$id => $state];
        }

        $token = $container->get(ComponentToken::class)->encodeFor($component, $action);
        $request = (new ServerRequest('POST', 'http://localhost' . $localePrefix . '/--component/action?token=' . urlencode($token)))
            ->withParsedBody($body);
        if ($cookies !== []) {
            $request = $request
                ->withCookieParams($cookies)
                ->withHeader('Cookie', implode('; ', array_map(
                    static fn (string|int $name, string $value): string => $name . '=' . rawurlencode($value),
                    array_keys($cookies),
                    $cookies,
                )));
        }

        return $app->handle($request);
    }

    /**
     * The state the page, or an answer's state update, carries for $componentId, or null: the
     * value of the hidden input named `_state[<componentId>]`.
     *
     * @since 0.86.0
     */
    public static function stateFrom(string $html, string $componentId): ?string
    {
        return self::carriers($html)[$componentId] ?? null;
    }

    /** @return list<string> */
    private static function carriedIds(string $html): array
    {
        return array_keys(self::carriers($html));
    }

    /**
     * Every state field of $html: component id => state string.
     *
     * @return array<string, string>
     */
    private static function carriers(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $found = [];
        foreach ((new \DOMXPath($document))->query('//input[@type="hidden"]') ?: [] as $input) {
            \assert($input instanceof \DOMElement);
            $name = $input->getAttribute('name');
            $prefix = DrivesComponentActions::STATE_FIELD . '[';
            if (str_starts_with($name, $prefix) && str_ends_with($name, ']')) {
                $found[substr($name, strlen($prefix), -1)] = $input->getAttribute('value');
            }
        }

        return $found;
    }
}
