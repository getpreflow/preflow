<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * How a field is named for people, for messages the core gives about a field (a select's
 * "options_from" names its source: "No choices yet: add values to Possible answers first.").
 * Optional, beside TypeLabels: the bound TypeLabels (an admin package's display labels) may also
 * implement it, and DataManager::fieldLabels() then uses it; without it the core names a field by
 * its label (a record field's in the locale) or its humanized name.
 *
 * @since 0.92.0
 */
interface FieldLabels
{
    /**
     * The field's label in the locale: a field of the record type $type, or of a block ($type null).
     */
    public function fieldLabel(TypeFieldDefinition $field, string $locale, ?TypeDefinition $type = null): string;
}
