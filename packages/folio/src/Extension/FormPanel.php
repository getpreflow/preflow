<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A panel on the type's edit form (not on create), in its extras section before or after
 * Folio's own (revisions, publishing), or in a right-hand column beside the fields (position 'side'). The method is fn(DynamicRecord $record): ScreenView|string|null;
 * a string is shown as escaped text, a ScreenView is rendered as a template, and null hides the panel
 * (no heading, no box; no side column or extras section on its account). An empty string still renders an empty panel.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class FormPanel
{
    public function __construct(
        public readonly string $label,
        public readonly string $position = 'after',
    ) {}
}
