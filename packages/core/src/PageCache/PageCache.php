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
    ) {}

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
            if ($colon !== false && $this->storageName !== null) {
                $tag = ($this->storageName)(substr($tag, 0, $colon)) . substr($tag, $colon);
            }
            array_push($changed, ...DataTags::changes($tag));
        }
        $this->changes->changed($changed);
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
