<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

/**
 * Marks a property as a relation to another type. It is written to the field's
 * `relation` config under the keys a model JSON file uses.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Relation
{
    public function __construct(
        public readonly string $to,
        public readonly string $onDelete = 'restrict',
        public readonly ?string $onSelfDelete = null,
        public readonly ?string $labelField = null,
    ) {}
}
