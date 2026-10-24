<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A declared pointer from a field of one type to records of another.
 */
final readonly class Relation
{
    public function __construct(
        public string $type,
        public string $field,
        public string $target,
        public bool $multiple,
        public OnDelete $onDelete,
        public ?string $labelField,
    ) {}

    /**
     * The relation a field declares, or null when it is no relation field or names no target.
     */
    public static function fromField(string $type, TypeFieldDefinition $field, FieldKinds $kinds): ?self
    {
        if (!$kinds->isRelation($field->type)) {
            return null;
        }
        $config = $field->config['relation'] ?? null;
        $to = is_array($config) ? ($config['to'] ?? null) : null;
        if (!is_string($to) || $to === '') {
            return null;
        }

        $onDelete = OnDelete::tryFrom((string) ($config['onDelete'] ?? OnDelete::Detach->value));
        if ($onDelete === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown onDelete "%s" on relation %s.%s (use detach, cascade or restrict)',
                is_scalar($config['onDelete']) ? (string) $config['onDelete'] : get_debug_type($config['onDelete']),
                $type,
                $field->name,
            ));
        }

        $labelField = $config['labelField'] ?? null;

        return new self(
            type: $type,
            field: $field->name,
            target: $to,
            multiple: (bool) ($config['multiple'] ?? false),
            onDelete: $onDelete,
            labelField: is_string($labelField) && $labelField !== '' ? $labelField : null,
        );
    }
}
