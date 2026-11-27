<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Folio\Field\FieldRules;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HasBlockRules;
use Preflow\Folio\Field\HasRecordRules;
use Preflow\Folio\Field\HasStoredRules;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;

/**
 * Full validation of a record: the data layer's (DataManager::validate()), which since 0.92 also
 * checks the values inside block lists and page areas (preflow/blocks' BlockValueChecks) and has
 * Folio's field types' rules (CoreFieldRules, registered by FolioServiceProvider at boot; code
 * that builds a validator by hand registers them itself), so this walks no blocks and merges no
 * field-type rules itself. It gives each field the core's rules (DataManager::fieldRules()) plus Folio's own:
 * with a frontend resolver, the fields a type's public address is built from also get "slug"
 * ("slug:path" for a catch-all): no umlauts, capitals or spaces. A "readonly" field gets an empty
 * list: it is not validated on save, its blocks included (nor checked for being unique).
 *
 * With the field types (0.92.1), a field whose type's form takes fewer shapes than the type
 * stores (HasStoredRules: date) gets the type's rules() — what the editor's input posts — in
 * place of the stored-shape checks the core's writes run, as since 0.91. As Folio's RecordChecks
 * this also holds for RecordWriter's saves and publishing. Without them (code that builds a
 * validator by hand) it keeps the core's checks.
 */
final class RecordValidator
{
    /**
     * Since 0.92 the block walk and the field types' rules are the core's; the FieldTypeRegistry
     * (optional again since 0.92.1) only gives the form's input checks of a HasStoredRules type.
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?FrontendResolver $frontend = null,
        private readonly ?FieldTypeRegistry $fieldTypes = null,
    ) {}

    /** @throws ValidationException block errors and the fields' errors in one refusal */
    public function validate(DynamicRecord $record): void
    {
        $this->dm->validate($record, $this->rules($record->getType()));
    }

    /**
     * Per-field rules: the core's for the field (its "validate", its type's checks, a select's
     * options, the rules packages add, a bounded column's length), a HasStoredRules type's rules()
     * in place of its storedRules() and, for a field of the type's frontend route, "slug" or
     * "slug:path" (a catch-all). Keyed by field, because the data layer
     * takes a given list instead of its own. A "readonly" field gets an empty list: it is not
     * validated on save (nor checked for being unique, nor its blocks).
     *
     * @return array<string, list<string|\Preflow\Validation\ValidationRule>>
     */
    public function rules(TypeDefinition $typeDef): array
    {
        $rules = [];
        $core = $this->dm->fieldRules($typeDef);
        $addressRules = $this->addressRules($typeDef);
        foreach ($typeDef->fields as $name => $fieldDef) {
            if (self::readonly($fieldDef->config)) {
                // The editor can't touch it, so its stored value is not checked: it would block
                // every save (and "required" can't be met). An empty list replaces the type's own rules.
                $rules[$name] = [];
                continue;
            }
            $merged = $this->formRules($fieldDef, $core[$name] ?? []);
            if (isset($addressRules[$name])) {
                $merged[] = $addressRules[$name];
            }
            if ($merged !== []) {
                $rules[$name] = $merged;
            }
        }

        return $rules;
    }
    /**
     * The core's list for the field with a HasStoredRules type's storedRules() replaced by its
     * rules() (what the form's input posts), where the first stored rule stood. A stored rule is
     * found by value: a name as it is, an object of the same class with equal properties.
     *
     * @param list<string|ValidationRule> $core
     * @return list<string|ValidationRule>
     */
    private function formRules(TypeFieldDefinition $field, array $core): array
    {
        $type = $this->fieldTypes !== null && $this->fieldTypes->has($field->type) ? $this->fieldTypes->get($field->type) : null;
        if (!$type instanceof HasStoredRules || $type instanceof HasRecordRules || $type instanceof HasBlockRules) {
            return $core;
        }
        $stored = $type->storedRules($field->config);
        $rules = [];
        $placed = false;
        foreach ($core as $rule) {
            $isStored = false;
            foreach ($stored as $storedRule) {
                if (is_object($rule) ? is_object($storedRule) && $rule::class === $storedRule::class && $rule == $storedRule : $rule === $storedRule) {
                    $isStored = true;
                    break;
                }
            }
            if (!$isStored) {
                $rules[] = $rule;
            } elseif (!$placed) {
                array_push($rules, ...$type->rules($field->config));
                $placed = true;
            }
        }

        return $placed ? FieldRules::merge($rules) : $core;
    }

    /**
     * "slug" for each parameter of the type's route, "slug:path" for a catch-all.
     *
     * @return array<string, AddressPart>
     */
    private function addressRules(TypeDefinition $typeDef): array
    {
        $route = $this->frontend?->routeFor($typeDef->key);
        if ($route === null) {
            return [];
        }
        return array_map(static fn (string $mode): AddressPart => new AddressPart($typeDef->locales, $mode === 'path'), $route->addressModes());
    }

    /** @param array<string, mixed> $config */
    private static function readonly(array $config): bool
    {
        return ($config['readonly'] ?? false) === true;
    }
}
