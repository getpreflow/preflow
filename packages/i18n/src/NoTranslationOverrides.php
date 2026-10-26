<?php

declare(strict_types=1);

namespace Preflow\I18n;

/** The default: no overrides. */
final class NoTranslationOverrides implements TranslationOverrides
{
    public function get(string $locale, string $key): ?string
    {
        return null;
    }
}
