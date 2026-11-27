<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\FieldKinds;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * What every typed write cleans before the driver (0.93): DataManager::storageValues() runs
 * apply() first, so saveType() (validated or not), saveTypeIfVersion(), class-backed types,
 * updateWhen(), updateIf(), updateFieldsIfVersion() and importers that call storageValues() all
 * store the same clean bytes on every driver, and the change-date comparison compares those.
 * Rows a script writes straight to a driver are not cleaned; the read side cleans again.
 *
 * Cleaned: each field whose value implements CleansOnWrite (rich text; every locale of a
 * translatable field, the value handles the map, given as a map also when a SQL driver read it
 * back as JSON text), then each system key a package registered
 * (systemKey(): preflow/blocks' "_areas"). DataManager::cleaning() gives the manager's one.
 *
 * @since 0.93.0
 */
final class StoredCleaning
{
    /** @var array<string, \Closure(mixed, TypeDefinition): mixed> system key => its step */
    private array $systemKeys = [];

    public function __construct(private readonly FieldKinds $kinds)
    {
    }

    /**
     * $data with its fields of $def cleaned (fields()), but those the type transforms itself, then
     * its registered system keys.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function apply(TypeDefinition $def, array $data): array
    {
        // A field the type transforms itself holds its transformer's storage form here (as
        // storageValues() leaves it); cleaning that as HTML would break it.
        $data = $this->fields($data, array_diff_key($def->fields, $def->transformers));
        foreach ($this->systemKeys as $key => $clean) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $clean($data[$key], $def);
            }
        }

        return $data;
    }

    /**
     * $values with each value of a field in $fields whose value cleans on write (CleansOnWrite)
     * cleaned; other keys unchanged. Any values: a record's, a block's, a read's.
     *
     * @param array<string, mixed> $values
     * @param array<string, TypeFieldDefinition> $fields
     * @return array<string, mixed>
     */
    public function fields(array $values, array $fields): array
    {
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $value = $this->kinds->valueOf($field);
            if ($value instanceof CleansOnWrite) {
                $values[$name] = $value->clean(self::localeMap($values[$name], $field), $field);
            }
        }

        return $values;
    }

    /**
     * A translatable field's value as its map: a SQL driver reads the map back as JSON text, which
     * a record found and saved again hands in (cleaning that text as HTML would break the JSON).
     */
    private static function localeMap(mixed $value, TypeFieldDefinition $field): mixed
    {
        if ($field->translatable && is_string($value) && str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }

    /**
     * Cleans the system key $key (one starting with "_", such as "_areas") on every write that
     * holds it: $clean gets the value and the type and gives the value to store. One step per key;
     * registering a key again replaces its step.
     *
     * @param \Closure(mixed $value, TypeDefinition $def): mixed $clean
     */
    public function systemKey(string $key, \Closure $clean): void
    {
        $this->systemKeys[$key] = $clean;
    }
}
