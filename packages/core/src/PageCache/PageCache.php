<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

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
 */
final class PageCache
{
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
    }

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private ?PageKey $key = null;

    private bool $markerRemoved = false;

    /** @var array<string, array{0: \Closure(): mixed, 1: \Closure(mixed): void}> */
    private array $carried = [];

    private ?string $refusal = null;

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
        return $this->key !== null;
    }

    /** The key of the page being rendered for the cache, null when none is. */
    public function key(): ?PageKey
    {
        return $this->key;
    }

    /** Keeps the page being rendered from being stored ($reason is what the middleware reports). */
    public function refuse(string $reason): void
    {
        if ($this->key !== null) {
            $this->refusal ??= $reason;
        }
    }

    /** Why the page being rendered must not be stored, null when nothing refused it. */
    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * Starts rendering $key for the cache ($key null: a render that is not stored, such as an
     * internal render inside one). Returns the state before, for end().
     *
     * @internal the page cache middleware
     * @return array{0: ?PageKey, 1: ?string}
     */
    public function begin(?PageKey $key): array
    {
        $before = [$this->key, $this->refusal];
        $this->key = $key;
        $this->refusal = null;

        return $before;
    }

    /**
     * Ends a render begun with begin(), restoring the state it returned.
     *
     * @internal the page cache middleware
     * @param array{0: ?PageKey, 1: ?string} $before
     */
    public function end(array $before): void
    {
        [$this->key, $this->refusal] = $before;
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
