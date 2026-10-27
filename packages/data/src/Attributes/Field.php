<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public readonly ?bool $searchable = null,
        public readonly bool $translatable = false,
        public readonly ?string $transform = null,
        /** @var bool|list<string> true, or the names of the fields that scope the uniqueness */
        public readonly bool|array $unique = false,
        public readonly bool $index = false,
        public readonly ?int $length = null,
    ) {}
}
