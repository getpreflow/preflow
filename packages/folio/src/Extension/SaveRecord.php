<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Writes the record itself on the admin form's save (create and update), instead of Folio's
 * saveType: fn(DynamicRecord $record, ?DynamicRecord $previous): void, where $previous is the
 * stored record before the change (null for a new one). It runs after #[BeforeSave] and
 * validation and before #[AfterSave], for types without publishing; one per type. Throw
 * Preflow\Validation\ValidationException to refuse the save: the form is shown again with its
 * errors (422). For a type whose store has rules Folio's whole-record write would break, e.g. a
 * counter that something else changes at the same time.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class SaveRecord
{
}
