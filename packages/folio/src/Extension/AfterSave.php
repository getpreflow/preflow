<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Runs after a successful save from the admin form (create and update): fn(DynamicRecord $record): void.
 * Not on reorder, nor on restore, discard or autosave of a working copy.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class AfterSave
{
}
