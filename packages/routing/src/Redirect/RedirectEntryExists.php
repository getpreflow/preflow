<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

use Preflow\Core\Routing\PageExists as CorePageExists;

/**
 * Whether a redirect entry for that language (exact, or an area above it) answers an address,
 * whatever its target; an entry for every language does not count (since 0.78.3). Core's bare redirect (config/i18n.php redirect_bare) asks it, registered with
 * PageLookups::addAnswer(): an old address that an entry answers goes to the visitor's language
 * first, and the entry answers there (two hops).
 *
 * It is deliberately not Redirect\PageExists: an entry is no page. The redirect rules ("a page wins
 * over an exact entry", "no chains") and the clean-path one hop ask pages only.
 *
 * The store may be bound after boot (an admin package), so the resolver comes through a closure
 * that is called per question; with no store nothing answers. A store that fails answers nothing.
 *
 * @since 0.78.2
 */
final class RedirectEntryExists implements CorePageExists
{
    /** @var \Closure(): ?RedirectResolver */
    private readonly \Closure $resolver;

    /** @param RedirectResolver|(\Closure(): ?RedirectResolver) $resolver */
    public function __construct(RedirectResolver|\Closure $resolver)
    {
        $this->resolver = $resolver instanceof RedirectResolver ? static fn (): RedirectResolver => $resolver : $resolver;
    }

    public function exists(string $locale, string $path): bool
    {
        $clean = RedirectPath::normalize($path);
        if ($clean === null) {
            return false;
        }

        try {
            $entry = ($this->resolver)()?->find($clean, $locale)[0];

            // An entry for every language ('') gives the same target whatever the language, so the
            // language redirect would only add a hop: it does not count.
            return $entry !== null && $entry->locale !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
