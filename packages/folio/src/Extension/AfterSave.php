<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Runs once the record is stored, on the same saves as #[BeforeSave]: fn(DynamicRecord $record): void,
 * or fn(DynamicRecord $record, WriteKind $kind) to get the kind of write (Preflow\Publishing\WriteKind:
 * e.g. leave autosaves alone). Not on restore or discard of a working copy.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class AfterSave
{
}
