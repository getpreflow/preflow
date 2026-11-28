<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockValueChecks;
use Preflow\Data\FieldKinds;
use Preflow\Data\HasStoredRules;
use Preflow\Data\RecordRules;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Folio's field types' rules, given to the core (0.92), so every write path checks them once —
 * Folio's form, imports, seeds and REST alike — and Folio's RecordValidator merges none of them:
 *
 * - a type's rules() (number's step, tags' limits, a blocks field's min/max, a site's own field
 *   types) as the core checks of its kind (FieldKinds::rules()), where no core package registered
 *   checks for that kind already; a type whose form takes fewer shapes than it stores and reads
 *   (Preflow\Data\HasStoredRules, 0.92.1) gives its storedRules() instead, so saveType() from
 *   server code, imports and seeds refuse only a value the type cannot read (Folio's
 *   RecordValidator runs its rules() in their place). Date's stored rule is the core's since
 *   0.93 (preflow/data's CoreValues, on every DataManager's kinds): this leaves it in place;
 * - a type whose rules read the other fields (HasRecordRules, HasBlockRules) not as a kind: its
 *   recordRules() as rules the core adds for the records' fields (RecordRules::add()), its
 *   blockRules() (or rules()) for a block's field through BlockValueChecks::extraRules().
 *
 * Select options are the core's own (SelectRules). FolioServiceProvider calls register() once at
 * boot; code that builds Folio's pieces by hand (a test) calls it itself, once per DataManager. A
 * kind is told only while no core package registered it (FieldKinds::hasRules()), except number
 * (the core's numeric check is replaced by Folio's, which adds "step"), so calling it
 * again adds no rule twice; a RecordRules or BlockValueChecks given twice gets its rules twice.
 *
 * @since 0.92.0
 */
final class CoreFieldRules
{
    /**
     * Kinds whose core rules Folio's type replaces rather than leaves in place: Folio's rules are
     * the core's plus its own (number: the core's numeric check plus "step").
     */
    private const SUPERSEDING = ['number'];

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
        if ($records !== null) {
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
        if ($blocks !== null) {
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
        if (self::readsOthers($type) || ($kinds->hasRules($key) && !in_array($key, self::SUPERSEDING, true))) {
            return;
        }
        // The registry's type when the rules run: a type registered later under the key replaces it.
        $kinds->rules($key, static fn (array $config, ?TypeFieldDefinition $field = null): array => self::kindRules($types->get($key), $config, $field));
    }

    /**
     * The checks every write runs for a field of the type: storedRules() for a type whose form
     * takes fewer shapes than it stores (HasStoredRules), else its rules().
     *
     * A type that knows the field (HasFieldRules) gives its fieldRules() instead of its rules().
     *
     * @param array<string, mixed> $config
     * @return list<string|\Preflow\Validation\ValidationRule>
     */
    private static function kindRules(FieldType $type, array $config, ?TypeFieldDefinition $field = null): array
    {
        if ($type instanceof HasFieldRules && $field !== null) {
            return $type->fieldRules($field);
        }

        return $type instanceof HasStoredRules ? $type->storedRules($config) : $type->rules($config);
    }

    /** A type whose rules read the record's or the block's other fields: not a kind of its own. */
    private static function readsOthers(FieldType $type): bool
    {
        return $type instanceof HasRecordRules || $type instanceof HasBlockRules;
    }
}
