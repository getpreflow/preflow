<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Validation\ValidationRule;

/**
 * Rules a package adds for the records of some types, by field, beside the fields' own
 * ("validate") and their types' (FieldKinds::rules()): preflow/blocks' page layout rules on
 * "_layout", "_areas" and the slug of a type with "layouts". Each closure gets the type and gives
 * the rules for it ([] for a type it has none for).
 *
 * Every validation of a typed record runs them after the field types' rules (DataManager::validate(),
 * saveType()), so Folio, the REST API, imports and seeds check the same; rules a caller gives for a
 * field replace them, as they replace "validate". Those added with drafts: true also run in
 * DataManager::validateStorable() (a draft, a restored revision), with or without a validator
 * factory.
 *
 * @since 0.92.0
 */
final class RecordRules
{
    /** @var list<array{\Closure(TypeDefinition): array<string, list<string|ValidationRule>>, bool}> */
    private array $rules = [];

    /**
     * @param \Closure(TypeDefinition): array<string, list<string|ValidationRule>> $rules
     * @param bool $drafts also run for drafts (validateStorable())
     */
    public function add(\Closure $rules, bool $drafts = false): void
    {
        $this->rules[] = [$rules, $drafts];
    }

    /**
     * The rules for this type's records, by field, in the order they were added; with $drafts only
     * those added with drafts: true.
     *
     * @return array<string, list<string|ValidationRule>>
     */
    public function of(TypeDefinition $def, bool $drafts = false): array
    {
        $out = [];
        foreach ($this->rules as [$rules, $forDrafts]) {
            if ($drafts && !$forDrafts) {
                continue;
            }
            foreach ($rules($def) as $field => $fieldRules) {
                foreach ($fieldRules as $rule) {
                    $out[$field][] = $rule;
                }
            }
        }

        return $out;
    }
}
