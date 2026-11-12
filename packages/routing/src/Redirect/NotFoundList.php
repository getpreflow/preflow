<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** The not-found list's side of a saved redirect: the addresses it answers leave the list. */
interface NotFoundList
{
    /** Drops one address (locale '' covers every locale). */
    public function forget(string $locale, string $path): void;

    /** Drops an area: the address and everything below it (locale '' covers every locale). */
    public function forgetUnder(string $locale, string $area): void;
}
