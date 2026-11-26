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
 * Checks (check()) judge a whole record rather than one field: preflow/blocks' values inside block
 * lists, keyed "<field>.<uid>.<leaf>". Every validation of a typed record runs them with the rules
 * the caller gave (a field given [] — Folio's read-only fields — is not checked), and refuses with
 * their errors and the field rules' errors in one ValidationException.
 *
 * @since 0.92.0
 */
final class RecordRules
{
    /** @var list<array{\Closure(TypeDefinition): array<string, list<string|ValidationRule>>, bool}> */
    private array $rules = [];

    /** @var list<\Closure(DynamicRecord, array<string, list<mixed>>): array<string, list<string>>> */
    private array $checks = [];

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

    /**
     * A check of whole records: it gets the record and the rules the caller gave per field
     * (DataManager::validate()'s $rules, [] for none), and gives its errors by key (each a list of
     * messages, in the validator's language), [] when the record passes.
     *
     * @param \Closure(DynamicRecord $record, array<string, list<mixed>> $given): array<string, list<string>> $check
     */
    public function check(\Closure $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * The checks, in the order they were added.
     *
     * @return list<\Closure(DynamicRecord, array<string, list<mixed>>): array<string, list<string>>>
     */
    public function checks(): array
    {
        return $this->checks;
    }
}
