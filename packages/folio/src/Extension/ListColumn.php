<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * A column of the type's admin list, after the type's own, ordered by position. The method
 * is fn(DynamicRecord $record): string|Badge|Link, shown as escaped text, for a Badge as a
 * Folio badge pill, for a Link as a link.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ListColumn
{
    public function __construct(
        public readonly string $label,
        public readonly int $position = 100,
    ) {}
}
