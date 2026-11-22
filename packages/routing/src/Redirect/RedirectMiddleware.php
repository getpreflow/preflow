<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

use Nyholm\Psr7\Response;
use Preflow\Core\Context\Writes;
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
 * store and log failures never turn the 404 into a 500. The hit counter and the not-found log
 * are 'log' writes of the write policy (Writes, storage 'redirects'): a render that skips writes
 * (a preview, the SEO audit) or refuses them (a preview fragment) is answered the same way but
 * counts no hit and records nothing, and the skipped write is reported. So is an internal render
 * whose purpose leaves no trace (InternalRender::is()).
 */
final class RedirectMiddleware implements MiddlewareInterface
{
    private const CACHE_CONTROL = 'public, max-age=86400';

    private readonly RedirectResolver $resolver;

    /**
     * @param \Closure(): \DateTimeImmutable $now
     * @param (\Closure(string): void)|null  $warn
     * @param (\Closure(): string)|null      $urlPrefix the locale prefix the visitor's URL carried ('/de' or ''),
     *                                                  so a target equal to the visited address is recognised
     */
    public function __construct(
        private readonly RedirectStore $store,
        ?RedirectTargets $targets,
        private readonly ?NotFoundLog $log,
        private readonly NonPublicPaths $nonPublic,
        private readonly NotFoundFilter $filter,
        private readonly string $defaultLocale,
        private readonly \Closure $now,
        private readonly ?\Closure $warn = null,
        private readonly ?\Closure $urlPrefix = null,
    ) {
        $this->resolver = new RedirectResolver($store, $targets);
    }

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

        try {
            [$entry, $rest] = $this->resolver->find($path, $locale);
        } catch (\Throwable $e) {
            $this->warn('Redirect lookup failed: ' . $e->getMessage());
            throw $notFound;
        }

        if ($entry === null) {
            $this->record($request, $path, $locale);
            throw $notFound;
        }

        try {
            $url = $this->resolver->url($entry, $rest, $locale);
        } catch (\Throwable $e) {
            $this->warn('Redirect target lookup failed: ' . $e->getMessage());
            throw $notFound;
        }

        $this->hit($request, $entry);

        if ($url === null || $this->isVisitedAddress($url, $request, $rawPath)) {
            throw $notFound;
        }

        $location = RedirectResolver::withQuery($url, $request->getUri()->getQuery());

        // A protocol-relative Location would send the visitor to another host.
        if (str_starts_with($location, '//') || str_starts_with($location, '/\\')) {
            throw $notFound;
        }

        return new Response($entry->status, [
            'Location' => $location,
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
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

    private function hit(ServerRequestInterface $request, Redirect $entry): void
    {
        if (!self::writes($request, 'redirect-hits', $entry->id)) {
            return;
        }

        try {
            $this->store->hit($entry, ($this->now)());
        } catch (\Throwable $e) {
            $this->warn('Redirect hit not counted: ' . $e->getMessage());
        }
    }

    private function record(ServerRequestInterface $request, string $path, string $locale): void
    {
        if ($this->log === null || $this->filter->ignores($path) || !self::writes($request, 'not-found', $path)) {
            return;
        }

        try {
            $this->log->record($path, $locale, ($this->now)());
        } catch (\Throwable $e) {
            $this->warn('Not-found address not recorded: ' . $e->getMessage());
        }
    }

    /**
     * Whether the log write runs: the write policy first (it reports a skipped write), then
     * whether the render leaves no trace (InternalRender::is()) where writes are allowed.
     */
    private static function writes(ServerRequestInterface $request, string $type, string $id): bool
    {
        return Writes::allowed('redirects', $type, $id, 'log') && !InternalRender::is($request);
    }

    private function warn(string $message): void
    {
        if ($this->warn !== null) {
            ($this->warn)($message);
        }
    }
}
