<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Attributes;

/** Marks a Folio extension: a class extending Preflow\Folio\Extension\Extension. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FolioExtension
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description = '',
        public readonly string $icon = 'ui-puzzle',
        public readonly string $version = '1.0.0',
    ) {}
}
