<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockValueChecks;
use Preflow\Data\FieldKinds;
use Preflow\Data\RecordRules;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Folio's field types' rules, given to the core (0.92), so every write path checks them once —
 * Folio's form, imports, seeds and REST alike — and Folio's RecordValidator merges none of them:
 *
 * - a type's rules() (date's format, number's step, tags' limits, a blocks field's min/max, a
 *   site's own field types) as the core checks of its kind (FieldKinds::rules()), where no core
 *   package registered checks for that kind already;
 * - a type whose rules read the other fields (HasRecordRules, HasBlockRules) not as a kind: its
 *   recordRules() as rules the core adds for the records' fields (RecordRules::add()), its
 *   blockRules() (or rules()) for a block's field through BlockValueChecks::extraRules().
 *
 * Select options are the core's own (SelectRules). register() may be called again with the same
 * objects (FolioServiceProvider at boot, a RecordValidator built on its own): each is told once.
 *
 * @since 0.92.0
 */
final class CoreFieldRules
{
    /** @var \WeakMap<object, true>|null the RecordRules and BlockValueChecks told already */
    private static ?\WeakMap $told = null;

    public static function register(FieldTypeRegistry $types, FieldKinds $kinds, ?RecordRules $records = null, ?BlockValueChecks $blocks = null): void
    {
        foreach ($types->all() as $type) {
            self::registerType($type, $types, $kinds);
        }
        foreach ($types->aliases() as $alias => $key) {
            if ($types->has($key)) {
                self::registerKind($alias, $types->get($key), $types, $kinds);
            }
        }
        if ($records !== null && self::firstTime($records)) {
            $records->add(static function (TypeDefinition $def) use ($types): array {
                $rules = [];
                foreach ($def->fields as $name => $field) {
                    $type = $types->get($field->type);
                    if (!self::readsOthers($type)) {
                        continue;
                    }
                    $fieldRules = $type instanceof HasRecordRules ? $type->recordRules($field->config, $def, $types) : $type->rules($field->config);
                    if ($fieldRules !== []) {
                        $rules[$name] = $fieldRules;
                    }
                }

                return $rules;
            });
        }
        if ($blocks !== null && self::firstTime($blocks)) {
            $blocks->extraRules(static function (TypeFieldDefinition $field, BlockDefinition $block) use ($types): array {
                $type = $types->get($field->type);
                if (!self::readsOthers($type)) {
                    return [];
                }

                return $type instanceof HasBlockRules ? $type->blockRules($field->config, $block, $types) : $type->rules($field->config);
            });
        }
    }

    /** One type (also one registered after boot, FieldTypeRegistry::whenRegistered()) as a core kind. */
    public static function registerType(FieldType $type, FieldTypeRegistry $types, FieldKinds $kinds): void
    {
        self::registerKind($type->key(), $type, $types, $kinds);
    }

    private static function registerKind(string $key, FieldType $type, FieldTypeRegistry $types, FieldKinds $kinds): void
    {
        if (self::readsOthers($type) || $kinds->hasRules($key)) {
            return;
        }
        // The registry's type when the rules run: a type registered later under the key replaces it.
        $kinds->rules($key, static fn (array $config): array => $types->get($key)->rules($config));
    }

    /** A type whose rules read the record's or the block's other fields: not a kind of its own. */
    private static function readsOthers(FieldType $type): bool
    {
        return $type instanceof HasRecordRules || $type instanceof HasBlockRules;
    }

    private static function firstTime(object $target): bool
    {
        self::$told ??= new \WeakMap();
        if (isset(self::$told[$target])) {
            return false;
        }
        self::$told[$target] = true;

        return true;
    }
}
