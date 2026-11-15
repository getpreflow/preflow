<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\TypeDefinition;

/**
 * The fields a write changed, for the activity log: a record's values compared with the stored
 * ones as stored (JSON text decoded, numbers and booleans as text, empty values alike), so a
 * record built in PHP and the same record read back compare equal.
 *
 * @internal
 * @since 0.77
 */
final class ChangedFields
{
    /**
     * The type's fields (not the id) whose value differs; with no $before (a new record) the
     * fields that have a value. A field missing from $now is not written, so it did not change.
     *
     * @param array<string, mixed> $now
     * @param array<string, mixed>|null $before
     * @return list<string>
     */
    public static function between(TypeDefinition $def, array $now, ?array $before): array
    {
        $changed = [];
        foreach ($def->fields as $name => $field) {
            if ($name === $def->idField || !array_key_exists($name, $now)) {
                continue;
            }
            $after = self::normal($field->type, $now[$name]);
            if ($before === null ? $after !== null : $after !== self::normal($field->type, $before[$name] ?? null)) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /** The value as comparable text, null when empty. */
    private static function normal(string $type, mixed $value): ?string
    {
        if ($type === 'boolean') {
            return in_array($value, [true, 1, '1'], true) ? '1' : null;
        }
        $value = self::asStored($value);
        if (self::isEmpty($value)) {
            return null;
        }

        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function asStored(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && str_contains('[{', $value[0])) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        return match (true) {
            is_array($value) => array_map(self::asStored(...), $value),
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $value,
        };
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::isEmpty($item)) {
                return false;
            }
        }

        return true;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
