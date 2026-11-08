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
 */
final class PageCache
{
    /**
     * @param (\Closure(string): string)|null $storageName the name a storage is reported under
     *        (DataManager::reportedStorage()): 'mysql:member' becomes 'default:member' when mysql
     *        is the default driver, as the data layer's own tags are
     */
    public function __construct(
        private readonly ?ChangeLog $changes = null,
        private readonly ?\Closure $storageName = null,
    ) {}

    public function enabled(): bool
    {
        return $this->changes !== null;
    }

    public function changed(string ...$tags): void
    {
        if ($this->changes === null || $tags === []) {
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
}
