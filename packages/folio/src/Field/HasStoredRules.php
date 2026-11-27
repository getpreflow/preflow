<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Optional for a field type whose form takes fewer shapes than the type stores and reads (a date
 * input posts "Y-m-d", while the type also reads an ISO 8601 date and time with an offset that
 * server code stored). storedRules() checks the stored shapes: it is what every write runs
 * (CoreFieldRules gives it to the core as the kind's checks, so saveType(), imports and seeds
 * refuse only what the type cannot read). rules() stays the check of what the editor typed:
 * Folio's RecordValidator runs it in place of storedRules() for a record's field.
 *
 * storedRules() gives rule names or value objects: the validator finds them in the core's list
 * by value (a name as it is, an object by its class and properties).
 *
 * A type whose rules read the other fields (HasRecordRules, HasBlockRules) is no kind of its own
 * and does not use this.
 *
 * @since 0.92.1
 */
interface HasStoredRules
{
    /**
     * @param array<string, mixed> $config the field's config
     * @return list<string|\Preflow\Validation\ValidationRule>
     */
    public function storedRules(array $config): array;
}
