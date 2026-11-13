<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Runs before every save through the RecordWriter (the form, autosave, bulk set, a list move, a
 * translation, a delete's detaches, an app's own saves), before validation:
 * fn(DynamicRecord $record): void, which may set() fields. A second parameter typed
 * Preflow\Publishing\WriteKind gets the kind of write (fn(DynamicRecord $record, WriteKind $kind)),
 * so the hook can leave some alone. Not on restore or discard of a working copy.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class BeforeSave
{
}
