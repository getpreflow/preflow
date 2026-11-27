<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Optional for a field type (or its value) whose input form takes fewer shapes than the type
 * stores and reads (a date input posts "Y-m-d", while the type also reads an ISO 8601 date and
 * time with an offset that server code stored). storedRules() checks the stored shapes: the
 * kind's checks every write runs (FieldKinds::rules(); the core's date, CoreValues, since 0.93),
 * so saveType(), imports, seeds and the API refuse only what the type cannot read. An adapter's
 * form (Folio's RecordValidator) runs its own narrower input check in their place.
 *
 * storedRules() gives rule names or value objects: an adapter finds them in the core's list by
 * value (a name as it is, an object by its class and properties).
 *
 * Moved from the admin package (0.92.1), where the old name stays as an alias until 0.95.
 *
 * @since 0.93.0
 */
interface HasStoredRules
{
    /**
     * @param array<string, mixed> $config the field's config
     * @return list<string|\Preflow\Validation\ValidationRule>
     */
    public function storedRules(array $config): array;
}
