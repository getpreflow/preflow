<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** A redirect store that can also be listed, for the rules that look across all entries. */
interface ListedRedirectStore extends RedirectStore
{
    /** @return list<Redirect> every entry, exact and area */
    public function entries(): array;

    /** The entry with this id (an address's id, see Redirect::id()). */
    public function entry(string $id): ?Redirect;
}
