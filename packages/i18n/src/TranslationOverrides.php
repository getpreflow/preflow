<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * Texts an admin changed (a layer above the site's lang/ files and the package files).
 * The Translator asks only when overrides are on (Translator::useOverrides(), turned on
 * for admin requests) and only for keys of an `admin` scope group, so public pages never
 * see an override. Implementations should answer from a per-locale cache.
 */
interface TranslationOverrides
{
    /** The override for a full key ("folio.common.save") in a locale, or null. */
    public function get(string $locale, string $key): ?string;
}
