<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\TypeDefinition;

/**
 * Optional for a field type whose rules depend on the other fields of the record's type (a
 * select with "options_from" reads its source field through that field's type). Where the
 * record's type is known (RecordValidator), recordRules() is used instead of FieldType::rules();
 * elsewhere rules() still applies, so it must stay a sensible fallback.
 *
 * @since 0.89
 */
interface HasRecordRules
{
    /**
     * @param array<string, mixed> $config the field's config
     * @return list<string|\Preflow\Validation\ValidationRule>
     */
    public function recordRules(array $config, TypeDefinition $type, FieldTypeRegistry $fieldTypes): array;
}
