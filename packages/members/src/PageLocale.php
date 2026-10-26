<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * Told the locale a member page renders in, before the page renders (the translator is
 * already set). Optional: a package whose layout renders content in its own locale state binds
 * one; Folio's members admin does, so the site layout shows its content in the page's locale.
 */
interface PageLocale
{
    public function set(string $locale): void;
}
