<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

/**
 * The container ids that may hold a page lookup ({@see PageExists}), asked in order when a request
 * needs to know whether a page answers at a path (config/i18n.php redirect_bare). Core's own
 * PageExists comes first; a package with a name of its own for the contract adds that name
 * (preflow/routing adds its Redirect\PageExists), so core never names it.
 *
 * Nothing is built here: an id counts once something is bound under it, and its lookup is built only
 * when a request asks. A lookup bound at any time before the request (a provider, an
 * onProvidersBooted or onRequest hook) is asked. The boot check of redirect_bare sees only the
 * bindings made by the end of the onProvidersBooted hooks.
 *
 * @since 0.78
 */
final class PageLookups
{
    /** @var list<string> */
    private array $ids = [PageExists::class];

    /** Ask the lookup bound under $id too (one that implements PageExists), after the ids added before. */
    public function add(string $id): void
    {
        if (!in_array($id, $this->ids, true)) {
            $this->ids[] = $id;
        }
    }

    /** @return list<string> core's PageExists first, then the ids added, in order */
    public function ids(): array
    {
        return $this->ids;
    }
}
