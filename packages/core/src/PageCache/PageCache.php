<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;

/**
 * The page cache's public calls. Bound in the container whether the cache is on or not: off,
 * every call does nothing.
 *
 * changed() is for writes the data layer cannot see, such as a bare $pdo->exec():
 *
 *     $pageCache->changed('default:member');          // the member table changed (many rows)
 *     $pageCache->changed('default:member#42');       // one member
 *     $pageCache->changed('menu');                    // a site's own tag
 *     $pageCache->changed('*');                       // everything
 *
 * See DataTags for the vocabulary. The changes are merged and invalidated once, at the end of the
 * request or command.
 *
 * It also holds the state of the request being rendered for the cache, which the middleware
 * and the live parts share: storing() is true while a page renders that may be stored (an
 * anonymous GET that missed), and refuse() lets code that put something personal in the page
 * keep it from being stored:
 *
 *     $pageCache->refuse('shows the visitor's basket');
 *
 * And the developer's freshness dial for what a part of a page reads (DependencyRecorder), and
 * the page's expiry:
 *
 *     $others = $pageCache->within(Freshness::NextVisit, null, fn () => $jobs->ofCompany($id));
 *     $pageCache->within(Freshness::Timed, 43200, fn () => ...);   // ignores writes, 12 h at most
 *     $pageCache->expiresAt($event->startsAt);                    // stale once the event began
 *
 * A write forgets (and rebuilds) a page that read what it changed fresh, marks it stale when the
 * page read it next-visit, and ignores it for a timed read: that page expires after its time
 * instead. Both are no-ops outside a render for the cache and in a live part.
 *
 * Since 0.81 the page being rendered, its refusal and the freshness scopes belong to the render
 * frame (frame slots): a render in a frame of its own (a rebuild, verify's second render, an
 * audit page) neither stores into nor refuses the page of the frame around it. Outside every
 * frame (a script, a unit test) the cache keeps its own. A request asked for inside a visitor's
 * render (Application::handle() in a page) is part of that page until it begins its own, as
 * before 0.81 (nestIn()): its live parts leave placeholders and refuse() reaches that page.
 */
final class PageCache
{
    /**
     * Request attribute: true on an internal render that rebuilds a page (Rebuild::request()):
     * never served from the cache, stored.
     */
    public const string REBUILD = PageCacheMiddleware::REBUILD;

    /**
     * @param (\Closure(string): string)|null $storageName the name a storage is reported under
     *        (DataManager::reportedStorage()): 'mysql:member' becomes 'default:member' when mysql
     *        is the default driver, as the data layer's own tags are
     * @param EnabledMarker|null $marker while the cache is off: removed by the first changed()
     */
    public function __construct(
        private readonly ?ChangeLog $changes = null,
        private readonly ?\Closure $storageName = null,
        private readonly ?EnabledMarker $marker = null,
        private readonly ?DependencyRecorder $recorder = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->own = new CacheRenderState();
    }

    /** The current frame's page and refusal, or the cache's own outside every frame. */
    private function rendering(): CacheRenderState
    {
        return Contexts::depth() === 0
            ? $this->own
            : Contexts::frame()->slot(CacheRenderState::class, static fn (): CacheRenderState => new CacheRenderState());
    }

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private bool $markerRemoved = false;

    /** The page rendered for the cache outside every frame. */
    private readonly CacheRenderState $own;

    /** @var array<string, array{0: \Closure(): mixed, 1: \Closure(mixed): void}> */
    private array $carried = [];

    public function enabled(): bool
    {
        return $this->changes !== null;
    }

    public function changed(string ...$tags): void
    {
        if ($tags === []) {
            return;
        }
        if ($this->changes === null) {
            // Off: the pages stored while it was on may now be old (EnabledMarker).
            if ($this->marker !== null && !$this->markerRemoved) {
                $this->markerRemoved = true;
                $this->marker->remove();
            }

            return;
        }
        $changed = [];
        foreach ($tags as $tag) {
            $colon = strpos($tag, ':');
            if ($colon !== false && $this->storageName !== null && !DataTags::exact($tag)) {
                $tag = ($this->storageName)(substr($tag, 0, $colon)) . substr($tag, $colon);
            }
            array_push($changed, ...DataTags::changes($tag));
        }
        $this->changes->changed($changed);
    }

    /**
     * The page being rendered depends on $tags, for what the data layer can't see or reports
     * too broadly (a site's own tag such as 'menu', DataTags::address()). Recorded with the
     * freshness of the scope around; outside a render for the cache it does nothing. A storage
     * prefix is mapped as in changed().
     */
    public function depends(string ...$tags): void
    {
        if ($this->recorder === null || !$this->recorder->recording()) {
            return;
        }
        foreach ($tags as $tag) {
            $colon = strpos($tag, ':');
            if ($colon !== false && $this->storageName !== null && !DataTags::exact($tag)) {
                $tag = ($this->storageName)(substr($tag, 0, $colon)) . substr($tag, $colon);
            }
            $this->recorder->record($tag);
        }
    }

    /**
     * Runs $fn with what it reads recorded as $freshness ($seconds: the time of a Timed one, 1 or
     * more; ignored for the others, unlike the templates' `every`, which only timed takes).
     * Nested, the innermost wins. The dial applies where a read runs: a value read inside and
     * shown outside keeps the freshness it was read with.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     * @throws \InvalidArgumentException Timed without seconds
     */
    public function within(Freshness $freshness, ?int $seconds, \Closure $fn): mixed
    {
        if ($this->recorder === null) {
            self::scope($freshness, $freshness === Freshness::Timed ? $seconds : null); // the same mistakes fail with the cache off

            return $fn();
        }

        return $this->recorder->within($freshness, $seconds, $fn);
    }

    /** The page being rendered is stale from $at on (the earliest of several counts). */
    public function expiresAt(\DateTimeInterface $at): void
    {
        $this->recorder?->expiresAt($at);
    }

    /**
     * Opens a freshness scope as a template names it: 'fresh', 'next-visit' or 'timed', with
     * $every ('12h', '30m', '2d' or seconds) for timed only. Close it with leave().
     *
     * @internal the {% freshness %} tag and the Blade @freshness directive
     * @throws \InvalidArgumentException an unknown name, timed without $every, $every on another
     */
    public function enter(mixed $freshness, mixed $every = null): void
    {
        // mixed, checked here: compiled Blade views don't declare strict_types (true would be 1 s)
        if (!is_string($freshness) && !$freshness instanceof Freshness) {
            throw new \InvalidArgumentException(sprintf('A freshness is a name, %s given.', get_debug_type($freshness)));
        }
        if ($every !== null && !is_int($every) && !is_string($every)) {
            throw new \InvalidArgumentException(sprintf('A freshness time is a duration, %s given.', get_debug_type($every)));
        }
        [$freshness, $seconds] = self::scope($freshness, $every);
        $this->recorder?->enter($freshness, $seconds);
    }

    /** @internal the {% freshness %} tag and the Blade @freshness directive */
    public function leave(): void
    {
        $this->recorder?->leave();
    }

    /**
     * The page expires at $at (a DateTimeInterface, a unix time or a date string; null: no
     * expiry, an event without a date) or $in from now ('1h', '30m', seconds).
     *
     * @internal the {% expires %} tag and the Blade @expires directive
     * @throws \InvalidArgumentException neither or both given, a date or duration that doesn't parse
     */
    public function expires(mixed $at = null, mixed $in = null): void
    {
        if ($in !== null && !is_int($in) && !is_string($in)) {
            throw new \InvalidArgumentException(sprintf('expires in takes a duration, %s given.', get_debug_type($in)));
        }
        if ($in !== null) {
            if ($at !== null) {
                throw new \InvalidArgumentException('expires takes `at` a date or `in` a duration, not both.');
            }
            $seconds = Duration::seconds($in); // checked with the cache off too
            $this->recorder?->expiresAt(($this->clock)() + $seconds);

            return;
        }
        if ($at === null || $at === '') {
            return;
        }
        $at = match (true) {
            $at instanceof \DateTimeInterface => $at,
            is_int($at), is_float($at) => (float) $at,
            is_string($at) => self::date($at),
            default => throw new \InvalidArgumentException(sprintf('expires at takes a date, %s given.', get_debug_type($at))),
        };
        $this->recorder?->expiresAt($at);
    }

    private static function date(string $at): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($at);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("expires at '{$at}': not a date.", 0, $e);
        }
    }

    /**
     * @return array{0: Freshness, 1: ?int}
     * @throws \InvalidArgumentException
     */
    private static function scope(Freshness|string $freshness, int|string|null $every): array
    {
        $freshness = Freshness::named($freshness);
        if ($freshness !== Freshness::Timed) {
            if ($every !== null) {
                throw new \InvalidArgumentException("Only a timed freshness takes a time ('{$freshness->value}' was given one).");
            }

            return [$freshness, null];
        }
        if ($every === null) {
            throw new \InvalidArgumentException("A timed freshness needs its time: 'timed' every '12h'.");
        }

        return [$freshness, Duration::seconds($every)];
    }

    /**
     * Whether the page being rendered may be stored (an anonymous GET the cache missed): parts
     * that differ per visitor must be live parts then.
     */
    public function storing(): bool
    {
        return $this->rendering()->effective()->key !== null;
    }

    /** The key of the page being rendered for the cache in the current frame, null when none is. */
    public function key(): ?PageKey
    {
        return $this->rendering()->effective()->key;
    }

    /** Keeps the page being rendered from being stored ($reason is what the middleware reports). */
    public function refuse(string $reason): void
    {
        $state = $this->rendering()->effective();
        if ($state->key !== null) {
            $state->refusal ??= $reason;
        }
    }

    /** Why the page being rendered must not be stored, null when nothing refused it. */
    public function refusal(): ?string
    {
        return $this->rendering()->effective()->refusal;
    }

    /**
     * Makes the current frame part of the page $parent renders (a request asked for inside a
     * visitor's render or a rebuild) until it begins a page of its own.
     *
     * @internal Application
     * @since 0.81.0
     */
    public function nestIn(\Preflow\Core\Context\RenderFrame $parent): void
    {
        $this->rendering()->parent = $parent->slot(CacheRenderState::class, static fn (): CacheRenderState => new CacheRenderState());
    }

    /**
     * Starts rendering $key for the cache in the current frame ($key null: a stretch of the frame
     * that is not stored, such as filling live parts). Returns the state before, for end().
     *
     * @internal the page cache middleware
     * @return array{0: ?PageKey, 1: ?string, 2: bool}
     */
    public function begin(?PageKey $key): array
    {
        $state = $this->rendering();
        $before = [$state->key, $state->refusal, $state->began];
        $state->key = $key;
        $state->refusal = null;
        $state->began = true;

        return $before;
    }

    /**
     * Ends a render begun with begin(), restoring the state it returned.
     *
     * @internal the page cache middleware
     * @param array{0: ?PageKey, 1: ?string, 2?: bool} $before
     */
    public function end(array $before): void
    {
        $state = $this->rendering();
        [$state->key, $state->refusal] = $before;
        $state->began = $before[2] ?? false;
    }

    /**
     * Carries render state through the cache for middleware that runs outside it and finishes
     * the response from what the page set while rendering (SEO's head tags from its page meta):
     * $capture is called when a page is stored and returns a JSON value (null: nothing to
     * carry), $restore gets it back on every hit, before the response leaves the page cache.
     *
     * @param \Closure(): mixed $capture
     * @param \Closure(mixed): void $restore
     */
    public function carry(string $name, \Closure $capture, \Closure $restore): void
    {
        $this->carried[$name] = [$capture, $restore];
    }

    /** @var list<\Closure(): ((\Closure(): void)|null)> */
    private array $isolations = [];

    /** @var list<array{0: string, 1: \Closure(): bool}> */
    private array $storeChecks = [];

    /**
     * Render isolation for request-scoped state a package keeps between renders (a content mode,
     * memos): $enter runs, in the current frame, around every render the cache may store (a
     * visitor's miss, verify's second render, every rebuild) and puts in place the state a public
     * render starts from; it returns what puts the earlier state back (or null), which runs after
     * the render, the last entered first.
     *
     * @deprecated 0.81, removed in 0.83: key memos on RenderContext::memoKey(), or add a context
     *             facet; only a public-default render of a visitor or a rebuild is stored anyway
     *
     * @param \Closure(): ((\Closure(): void)|null) $enter
     */
    public function isolate(\Closure $enter): void
    {
        Deprecations::once('Preflow\Core\PageCache\PageCache::isolate() is deprecated since 0.81 and is removed in 0.83; use RenderContext::memoKey() or a context facet.');
        $this->isolations[] = $enter;
    }

    /**
     * A check after every render the cache may store: when $when is true the page is not stored
     * (`refused:{$reason}`). Defence in depth for state a render should not have changed.
     *
     * @param \Closure(): bool $when
     */
    public function refuseWhen(string $reason, \Closure $when): void
    {
        $this->storeChecks[] = [$reason, $when];
    }

    /**
     * Runs $render with the isolate() callbacks around it, and asks the store checks
     * (refuseWhen()) after it, before the state is put back.
     *
     * @deprecated 0.81, removed in 0.83: key memos on RenderContext::memoKey(), or add a context facet
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public function isolated(\Closure $render): mixed
    {
        Deprecations::once('Preflow\Core\PageCache\PageCache::isolated() is deprecated since 0.81 and is removed in 0.83; use RenderContext::memoKey() or a context facet.');

        return $this->aroundRender($render);
    }

    /**
     * Runs $render, a render the cache may store, in the current frame: the deprecated isolate()
     * callbacks around it, the store checks (refuseWhen()) after it, before those are put back.
     *
     * @internal the page cache middleware
     * @since 0.81.0
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public function aroundRender(\Closure $render): mixed
    {
        $restores = [];
        try {
            foreach ($this->isolations as $enter) {
                $restore = $enter();
                if ($restore !== null) {
                    $restores[] = $restore;
                }
            }
            $result = $render();
            foreach ($this->storeChecks as [$reason, $when]) {
                if ($when()) {
                    $this->refuse($reason);
                }
            }

            return $result;
        } finally {
            foreach (array_reverse($restores) as $restore) {
                $restore();
            }
        }
    }

    /**
     * @internal the page cache middleware
     * @return array<string, mixed> name => value, for StoredPage::$state
     */
    public function captureState(): array
    {
        $state = [];
        foreach ($this->carried as $name => [$capture]) {
            $value = $capture();
            if ($value !== null) {
                $state[$name] = $value;
            }
        }

        return $state;
    }

    /**
     * @internal the page cache middleware
     * @param array<string, mixed> $state StoredPage::$state
     */
    public function restoreState(array $state): void
    {
        foreach ($state as $name => $value) {
            if (isset($this->carried[$name])) {
                ($this->carried[$name][1])($value);
            }
        }
    }
}
