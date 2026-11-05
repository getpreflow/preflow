<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\NonPublicPaths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers a public GET/HEAD that ended in a thrown 404 from the redirect store (exact entry,
 * else the longest area above it), and notes the address in the not-found log when nothing
 * matched. A 404 response (a gated page) and every other method pass through untouched;
 * store and log failures never turn the 404 into a 500. An internal render (InternalRender)
 * is answered the same way but counts no hit and records nothing.
 */
final class RedirectMiddleware implements MiddlewareInterface
{
    private const CACHE_CONTROL = 'public, max-age=86400';

    /**
     * @param \Closure(): \DateTimeImmutable $now
     * @param (\Closure(string): void)|null  $warn
     * @param (\Closure(): string)|null      $urlPrefix the locale prefix the visitor's URL carried ('/de' or ''),
     *                                                  so a target equal to the visited address is recognised
     */
    public function __construct(
        private readonly RedirectStore $store,
        private readonly ?RedirectTargets $targets,
        private readonly ?NotFoundLog $log,
        private readonly NonPublicPaths $nonPublic,
        private readonly NotFoundFilter $filter,
        private readonly string $defaultLocale,
        private readonly \Closure $now,
        private readonly ?\Closure $warn = null,
        private readonly ?\Closure $urlPrefix = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (NotFoundHttpException $notFound) {
            return $this->answer($request, $notFound);
        }
    }

    private function answer(ServerRequestInterface $request, NotFoundHttpException $notFound): ResponseInterface
    {
        $method = $request->getMethod();
        $rawPath = $request->getUri()->getPath();

        if (($method !== 'GET' && $method !== 'HEAD') || $this->nonPublic->matches($rawPath)) {
            throw $notFound;
        }

        // Checked again normalized: '//folio/x' is still Folio.
        $path = RedirectPath::normalize($rawPath);
        if ($path === null || $this->nonPublic->matches($path)) {
            throw $notFound;
        }

        $locale = $request->getAttribute('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : $this->defaultLocale;
        $internal = InternalRender::is($request);

        try {
            [$entry, $rest] = $this->lookup($path, $locale);
        } catch (\Throwable $e) {
            $this->warn('Redirect lookup failed: ' . $e->getMessage());
            throw $notFound;
        }

        if ($entry === null) {
            if (!$internal) {
                $this->record($path, $locale);
            }
            throw $notFound;
        }

        try {
            $url = $this->targetUrl($entry, $rest, $locale);
        } catch (\Throwable $e) {
            $this->warn('Redirect target lookup failed: ' . $e->getMessage());
            throw $notFound;
        }

        if (!$internal) {
            $this->hit($entry);
        }

        if ($url === null || $this->isVisitedAddress($url, $request, $rawPath)) {
            throw $notFound;
        }

        $location = $this->withQuery($url, $request->getUri()->getQuery());

        // A protocol-relative Location would send the visitor to another host.
        if (str_starts_with($location, '//') || str_starts_with($location, '/\\')) {
            throw $notFound;
        }

        return new Response($entry->status, [
            'Location' => $location,
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
    }

    /** @return array{?Redirect, string} the entry and, for an area, the rest of the path below it */
    private function lookup(string $path, string $locale): array
    {
        $exact = $this->store->exact($path, $locale);
        if ($exact !== null) {
            return [$exact, ''];
        }

        $best = null;
        $bestRest = '';

        foreach ($this->store->areas($locale) as $area) {
            $rest = RedirectPath::under($path, $area->path);

            if ($rest !== null && ($best === null || strlen($area->path) > strlen($best->path))) {
                $best = $area;
                $bestRest = $rest;
            }
        }

        return [$best, $bestRest];
    }

    private function targetUrl(Redirect $entry, string $rest, string $locale): ?string
    {
        if ($entry->targetUrl !== null && $entry->targetUrl !== '') {
            if ($entry->area && $entry->mode === AreaMode::Prefix) {
                $url = rtrim($entry->targetUrl, '/') . $rest;

                return $url === '' ? '/' : $url;
            }

            return $entry->targetUrl;
        }

        if ($this->targets === null || $entry->targetType === null || $entry->targetId === null) {
            return null;
        }

        return $this->targets->url($entry->targetType, $entry->targetId, $entry->locale !== '' ? $entry->locale : $locale);
    }

    /** Whether the URL points at the address the visitor asked for (locale prefix included). */
    private function isVisitedAddress(string $url, ServerRequestInterface $request, string $rawPath): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        if (isset($parts['host']) && strcasecmp($parts['host'], $request->getUri()->getHost()) !== 0) {
            return false;
        }

        $prefix = $this->urlPrefix !== null ? ($this->urlPrefix)() : '';
        $visited = RedirectPath::normalize(rtrim($prefix, '/') . $rawPath);
        $target = RedirectPath::normalize($parts['path'] ?? '/');

        return $visited !== null && $visited === $target;
    }

    private function withQuery(string $url, string $query): string
    {
        if ($query === '') {
            return $url;
        }

        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $query . $fragment;
    }

    private function hit(Redirect $entry): void
    {
        try {
            $this->store->hit($entry, ($this->now)());
        } catch (\Throwable $e) {
            $this->warn('Redirect hit not counted: ' . $e->getMessage());
        }
    }

    private function record(string $path, string $locale): void
    {
        if ($this->log === null || $this->filter->ignores($path)) {
            return;
        }

        try {
            $this->log->record($path, $locale, ($this->now)());
        } catch (\Throwable $e) {
            $this->warn('Not-found address not recorded: ' . $e->getMessage());
        }
    }

    private function warn(string $message): void
    {
        if ($this->warn !== null) {
            ($this->warn)($message);
        }
    }
}
