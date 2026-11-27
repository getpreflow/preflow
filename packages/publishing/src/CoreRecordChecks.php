<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;

/**
 * The default RecordChecks (since 0.93; before, NoRecordChecks): the core's rules of each field,
 * so a publish and a RecordWriter save refuse what saveType() refuses, with the same keys and
 * messages, with or without an admin installed. Each field gets DataManager::fieldRules() (its
 * "validate", its type's checks such as a date's stored shapes, a select's options, the rules
 * packages add, a bounded column's length), plus what the optional closure adds for the type
 * (after the core's). A field whose config has "readonly": true gets an empty list: no adapter
 * lets anyone change it, so its stored value is not checked (nor whether it is unique).
 *
 * Folio's FolioRecordChecks is this plus its address rules.
 *
 * @since 0.93.0
 */
final class CoreRecordChecks implements RecordChecks
{
    /**
     * @param (\Closure(TypeDefinition): array<string, list<string|ValidationRule>>)|null $extraRules
     *        rules added per field (or system key) of the type, after the core's
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?\Closure $extraRules = null,
    ) {}

    /** @throws ValidationException naming each failed field */
    public function validate(DynamicRecord $record): void
    {
        $this->dm->validate($record, $this->rules($record->getType()));
    }

    /**
     * Per field: the core's rules plus the extra ones; an empty list for a read-only field. A
     * field without any rule is left out. Keyed by field, because the data layer's validate()
     * takes a given list in place of its own.
     *
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(TypeDefinition $def): array
    {
        $core = $this->dm->fieldRules($def);
        $extra = $this->extraRules !== null ? ($this->extraRules)($def) : [];
        $rules = [];
        foreach ($def->fields as $name => $field) {
            if (($field->config['readonly'] ?? false) === true) {
                $rules[$name] = [];
                continue;
            }
            $merged = [...($core[$name] ?? []), ...($extra[$name] ?? [])];
            if ($merged !== []) {
                $rules[$name] = $merged;
            }
        }
        // Extra rules of a key that is no field (a system key such as _areas), after the core's
        // (a key left out here keeps the core's own rules in validate()).
        foreach ($extra as $name => $list) {
            if (!isset($def->fields[$name]) && $list !== []) {
                $rules[$name] = [...($core[$name] ?? []), ...$list];
            }
        }

        return $rules;
    }
}
