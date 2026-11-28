<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\TypeFieldDefinition;

/**
 * A field type whose checks depend on the field itself, not only on its config (whether it is
 * required: the matrix). Its fieldRules() are the core checks of its kind for the field
 * (CoreFieldRules, through FieldKinds::rulesOf()), in place of rules().
 *
 * @since 0.94.0
 */
interface HasFieldRules
{
    /** @return list<string|\Preflow\Validation\ValidationRule> */
    public function fieldRules(TypeFieldDefinition $field): array;
}
