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
        /** The field kind (string, select, asset, blocks, ...); inferred from the property type when null. */
        public readonly ?string $type = null,
        /** @var string|array<string, string>|null a label, or one per locale */
        public readonly string|array|null $label = null,
        /** @var string|array<string, string>|null help text, or one per locale */
        public readonly string|array|null $help = null,
        /** @var array<string, mixed> kind-specific settings (options, accept, ...), written at field level as a JSON file does */
        public readonly array $config = [],
        public readonly mixed $default = null,
    ) {}
}
