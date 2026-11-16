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

    /** @var list<string> */
    private array $answers = [];

    /**
     * Ask the lookup bound under $id (one that implements PageExists) for the bare redirect only: it
     * says whether something answers an address where no page is, such as a redirect entry. It is not a
     * page: whatever must tell pages apart from answers (a page wins over a redirect entry) asks ids()
     * and never these.
     *
     * @since 0.78.2
     */
    public function addAnswer(string $id): void
    {
        if (!in_array($id, $this->answers, true)) {
            $this->answers[] = $id;
        }
    }

    /**
     * @return list<string> the ids added with addAnswer(), in order
     *
     * @since 0.78.2
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /** @return list<string> core's PageExists first, then the ids added, in order; pages only (see answers()) */
    public function ids(): array
    {
        return $this->ids;
    }
}
