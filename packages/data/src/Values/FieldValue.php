<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\TypeFieldDefinition;

/**
 * What a field type's value means, without any adapter (0.93): an input value (an API body's, a
 * form's after its own parsing, an import's) to what saveType() stores, a stored value to a
 * JSON-safe output value, and the name of the output's shape for the description. Registered per
 * field type on FieldKinds (FieldKinds::value()), like its rules; a type without one reads and
 * writes its value unchanged (RawValue, shape "raw").
 *
 * Each method handles one locale's value: the callers walk a translatable field's map per locale.
 * toStored() converts and never refuses: a value it cannot convert passes through unchanged, so
 * the rules (saveType(), FieldKinds::rules()) refuse it with their message.
 *
 * Optional companions: DescribesShapes (the shapes' descriptions), CleansOnWrite, MergesInput,
 * ListsReferences.
 *
 * @since 0.93.0
 */
interface FieldValue
{
    /** One locale's input value as saveType() stores it; what does not convert passes through. */
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed;

    /**
     * One locale's stored value, as a driver returns it (JSON text or numeric text on SQL
     * included), as a JSON-safe output value of shape().
     */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed;

    /** The name of the output value's shape for this field ("string", "id_list", …). */
    public function shape(TypeFieldDefinition $field): string;
}
