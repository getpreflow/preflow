<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A panel on the type's edit form (not on create), in its extras section before or after
 * Folio's own (revisions, publishing), or in a right-hand column beside the fields (position 'side'). The method is fn(DynamicRecord $record): ScreenView|string;
 * a string is shown as escaped text, a ScreenView is rendered as a template.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class FormPanel
{
    public function __construct(
        public readonly string $label,
        public readonly string $position = 'after',
    ) {}
}
