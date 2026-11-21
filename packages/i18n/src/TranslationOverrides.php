<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * Texts an admin changed (a layer above the site's lang/ files and the package files).
 * The Translator asks for a key of an `admin` scope group (or under an overridable() prefix)
 * only when the current render's surface has overrides on (since 0.81 the render context's
 * admin surface, which Folio gives admin requests), so public pages never see those; a group
 * made alwaysOverride() is asked on every surface, public pages included. Implementations should
 * answer from a per-locale cache: the translator asks again for every overridable key.
 */
interface TranslationOverrides
{
    /** The override for a full key ("folio.common.save") in a locale, or null. */
    public function get(string $locale, string $key): ?string;
}
