<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * What an empty field of the type means, shown as its placeholder in the edit form (e.g. the
 * standard text a site words in its lang files, which applies while nothing is stored). The
 * method is fn(string $locale): ?string, asked when the form renders, with the locale being
 * edited; null shows none. It takes the place of the "falls back to" placeholder of a
 * translatable field.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class FieldPlaceholder
{
    public function __construct(
        public readonly string $field,
    ) {}
}
