<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\Locales;
use Preflow\Folio\Content\TranslatableText;

/**
 * A field type whose value holds translatable texts of its own (locale maps
 * inside structured data). TranslationStatus asks it for them, so missing and
 * outdated translations are found inside it too.
 */
interface ReportsTranslatableTexts
{
    /**
     * The texts inside a stored value, with paths and labels relative to the field:
     * the caller prefixes the field's name ("{field}.") and label ("{Label} › ").
     *
     * @param mixed                $value  the field's value as fromStorage() returns it
     * @param array<string, mixed> $config the field's config
     * @return list<TranslatableText>
     */
    public function translatableTexts(mixed $value, array $config, Locales $locales): array;
}
