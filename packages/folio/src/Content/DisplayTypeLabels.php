<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\FieldLabels;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeLabels;
use Preflow\I18n\Labels;
use Preflow\I18n\Translator;

/**
 * Names a type the way Folio does ({@see DisplayLabels::typeIn()}), for texts of other packages that
 * are made outside an admin request (a mail a visitor's action triggers): with the app's translator.
 * Since 0.92 it names fields too (FieldLabels), for the core's messages that name one, as Folio's
 * own did: a record field by its label in the locale (its map per language), a block field by its
 * label, a translation key translated.
 */
final class DisplayTypeLabels implements TypeLabels, FieldLabels
{
    /** @param \Closure(): Translator $translator the app's translator, resolved on use */
    public function __construct(
        private readonly DisplayLabels $labels,
        private readonly \Closure $translator,
    ) {}

    public function label(string $type, string $locale): string
    {
        return $this->labels->typeIn($type, $locale, ($this->translator)());
    }

    public function fieldLabel(TypeFieldDefinition $field, string $locale, ?TypeDefinition $type = null): string
    {
        $translator = ($this->translator)();
        if ($type !== null) {
            $label = Labels::pick($field->labels ?: $field->label, $locale, $translator->getFallbackLocale());
        } elseif ($field->label !== null && preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_]+)+$/', $field->label) === 1) {
            // A block field's label may be a translation key (as FolioText::label() reads it).
            $label = $translator->get($field->label, [], $locale);
        } else {
            $label = $field->label;
        }

        return $label ?? (new \Preflow\Form\FieldRenderer())->humanize($field->name);
    }
}
