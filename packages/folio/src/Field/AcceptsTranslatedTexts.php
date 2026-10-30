<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\Locales;

/**
 * The write-back half of ReportsTranslatableTexts: a field type whose value holds translatable
 * texts of its own takes translations back at the paths it reported. Machine translation
 * (FolioTextSource) writes through it, so the field type alone knows its structure.
 */
interface AcceptsTranslatedTexts
{
    /**
     * The field value (fromStorage shape) with $texts (paths relative to the field, as
     * translatableTexts() reports them) set for $locale; every other locale and every other
     * text stays as it is. Paths that name no translatable text of the value are ignored.
     *
     * @param mixed                 $value  the field's value as fromStorage() returns it
     * @param array<string, string> $texts  path => text
     * @param array<string, mixed>  $config the field's config
     */
    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed;
}
