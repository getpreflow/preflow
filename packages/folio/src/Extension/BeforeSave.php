<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Runs on the admin form's save (create and update) before validation: fn(DynamicRecord $record): void,
 * which may set() fields. Not on reorder, nor on restore, discard or autosave of a working copy.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class BeforeSave
{
}
