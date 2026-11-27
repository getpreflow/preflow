<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\Locales;
use Preflow\Folio\Content\TranslatableText;

/**
 * A field type whose value holds texts it does not show, and so does not report as translatable:
 * an areas field's columns its layout lacks (kept, "Not shown in this layout"), a block field its
 * show_when hides. TranslationStatus::stamp() asks it for every stored text, so a save keeps their
 * fingerprints, sources and machine marks for when they show again, as a page's hidden areas keep
 * theirs. A field type holding no such texts need not implement it (its translatableTexts() are all).
 *
 * @since 0.92.0
 */
interface ReportsStoredTranslatableTexts extends ReportsTranslatableTexts
{
    /**
     * Every text the stored value holds, shown or not, with paths and labels as translatableTexts()
     * gives them (relative to the field); the shown ones first.
     *
     * @param mixed                $value  the field's value as fromStorage() returns it
     * @param array<string, mixed> $config the field's config
     * @return list<TranslatableText>
     */
    public function storedTranslatableTexts(mixed $value, array $config, Locales $locales): array;
}
