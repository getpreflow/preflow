<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * How a type is named for people ("Jobs", "Stellen"), for texts that mention record types
 * (a mail saying which records still point at an account). Optional: a package that needs the
 * name uses this binding when there is one, else the type's schema label or its key. An admin
 * package binds its own display labels.
 */
interface TypeLabels
{
    /** The label of the type in the locale. */
    public function label(string $type, string $locale): string;
}
