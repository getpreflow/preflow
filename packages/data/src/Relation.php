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
        public OnDelete $onSelfDelete,
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

        $onDelete = self::rule($config, 'onDelete', OnDelete::Detach, $type, $field->name);
        // Own-account deletion: unset follows onDelete; a set value is parsed like onDelete.
        $onSelfDelete = self::rule($config, 'onSelfDelete', $onDelete, $type, $field->name);

        $labelField = $config['labelField'] ?? null;

        return new self(
            type: $type,
            field: $field->name,
            target: $to,
            multiple: (bool) ($config['multiple'] ?? false),
            onDelete: $onDelete,
            onSelfDelete: $onSelfDelete,
            labelField: is_string($labelField) && $labelField !== '' ? $labelField : null,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function rule(array $config, string $key, OnDelete $default, string $type, string $field): OnDelete
    {
        if (!isset($config[$key])) {
            return $default;
        }
        $raw = $config[$key];
        $rule = is_scalar($raw) ? OnDelete::tryFrom((string) $raw) : null;
        if ($rule === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown %s "%s" on relation %s.%s (use detach, cascade or restrict)',
                $key,
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
                $type,
                $field,
            ));
        }

        return $rule;
    }
}
