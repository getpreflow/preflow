<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

interface RedirectStore
{
    /** The entry for exactly this path: the locale's own first, then the any-language one. */
    public function exact(string $path, string $locale): ?Redirect;

    /**
     * Area entries of the locale and the any-language ones.
     *
     * @return list<Redirect>
     */
    public function areas(string $locale): array;

    /** Counts a served redirect. */
    public function hit(Redirect $redirect, \DateTimeImmutable $at): void;
}
