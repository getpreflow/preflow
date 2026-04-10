<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public readonly bool $searchable = false,
        public readonly bool $translatable = false,
        public readonly ?string $transform = null,
    ) {}
}
