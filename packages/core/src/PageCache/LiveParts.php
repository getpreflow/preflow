<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The live parts of cached pages: what differs per visitor (a guest's album count, "in your
 * album" states, a cart count), rendered for every visitor while the rest of the page comes
 * from the cache. Twig's {% live %}, Blade's @live and components marked #[Live] are live parts.
 *
 * While a page renders for the cache (PageCache::storing()), a live part is not rendered: it
 * leaves a signed placeholder naming how to render it (LivePlaceholder), and the page is
 * stored with it. When the page is served, on a hit and on the miss that stored it, the page
 * cache fills every placeholder (fill()): each part is rendered by the integration that made
 * it (register()), with the visitor's request in RequestContext. At any other time (cache off,
 * a signed-in visitor, an internal render) a live part renders in place, from the same inputs.
 *
 * Filling is outside the page: a part's reads are not dependencies of the page, PageCache::refuse()
 * inside it does nothing, and a part that throws is replaced by ERROR (and logged), the rest of
 * the page is served. Before rendering, the placeholders of one kind are handed to that kind's
 * prefetch together (components: one prefetchLive() call per class).
 */
final class LiveParts
{
    /** What a part that failed while filling is replaced with. */
    public const string ERROR = '<div hidden data-live-error="true"></div>';

    /** @var array<string, array{0: \Closure(array<string, mixed>): string, 1: (\Closure(list<array<string, mixed>>): void)|null}> */
    private array $kinds = [];

    /**
     * @param (\Closure(string): void)|null $log error_log() when null
     */
    public function __construct(
        private readonly LivePlaceholder $placeholders,
        private readonly PageCache $cache,
        private readonly DependencyRecorder $recorder,
        private readonly ?\Closure $log = null,
    ) {}

    /**
     * Registers how parts of $kind are rendered when a page is served: $render gets a part's
     * recipe and returns its HTML; $prefetch, when given, gets the recipes of all parts of
     * $kind in the page first.
     *
     * @param \Closure(array<string, mixed>): string $render
     * @param (\Closure(list<array<string, mixed>>): void)|null $prefetch
     */
    public function register(string $kind, \Closure $render, ?\Closure $prefetch = null): void
    {
        $this->kinds[$kind] = [$render, $prefetch];
    }

    /**
     * The placeholder to print instead of rendering the part, null when it renders in place
     * (no page is being rendered for the cache, or $kind is not registered).
     *
     * @param array<string, mixed> $recipe scalars and arrays only
     * @throws \InvalidArgumentException when a value is not a scalar, null or array
     */
    public function placeholder(string $kind, array $recipe): ?string
    {
        LivePlaceholder::assertPlain($recipe);
        if (!$this->cache->storing() || !isset($this->kinds[$kind])) {
            return null;
        }

        return $this->placeholders->make($kind, $recipe);
    }

    /** Whether $body may hold live parts. */
    public function in(string $body): bool
    {
        return LivePlaceholder::in($body);
    }

    /**
     * $body with every signed placeholder replaced by its part, rendered for this request.
     * Markers that don't verify, or name a kind nobody registered, stay as they are.
     */
    public function fill(string $body): string
    {
        if (!LivePlaceholder::in($body)) {
            return $body;
        }
        $parts = [];
        foreach ($this->placeholders->find($body) as $marker => [$kind, $recipe]) {
            if (isset($this->kinds[$kind])) {
                $parts[$marker] = [$kind, $recipe];
            }
        }
        if ($parts === []) {
            return $body;
        }

        // No page is rendered for the cache meanwhile (nothing refuses or records into one).
        $before = $this->cache->begin(null);
        try {
            $map = $this->recorder->outside(fn (): array => $this->render($parts));
        } finally {
            $this->cache->end($before);
        }

        // One pass: what a part printed is never read for placeholders again.
        return strtr($body, $map);
    }

    /**
     * @param array<string, array{0: string, 1: array<string, mixed>}> $parts
     * @return array<string, string> placeholder => HTML
     */
    private function render(array $parts): array
    {
        $byKind = [];
        foreach ($parts as [$kind, $recipe]) {
            $byKind[$kind][] = $recipe;
        }
        foreach ($byKind as $kind => $recipes) {
            $prefetch = $this->kinds[$kind][1];
            if ($prefetch === null) {
                continue;
            }
            try {
                $prefetch($recipes);
            } catch (\Throwable $e) {
                $this->log("page cache: prefetching live parts ({$kind}) failed: " . $e->getMessage());
            }
        }

        $map = [];
        foreach ($parts as $marker => [$kind, $recipe]) {
            try {
                $map[$marker] = ($this->kinds[$kind][0])($recipe);
            } catch (\Throwable $e) {
                $this->log("page cache: a live part ({$kind}) failed: " . $e->getMessage());
                $map[$marker] = self::ERROR;
            }
        }

        return $map;
    }

    private function log(string $message): void
    {
        $this->log !== null ? ($this->log)($message) : error_log($message);
    }
}
