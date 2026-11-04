<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Full validation of a record: rules inside blocks fields first (keyed
 * "<field>.<uid>.<leaf>"), then the type's rules plus each field type's own.
 * With a frontend resolver, the fields a type's public address is built from
 * also get "slug" ("slug:path" for a catch-all): no umlauts, capitals or spaces.
 */
final class RecordValidator
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly DataManager $dm,
        private readonly ?FrontendResolver $frontend = null,
    ) {}

    /** @throws ValidationException */
    public function validate(DynamicRecord $record): void
    {
        $typeDef = $record->getType();
        $errors = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if (!$fieldType instanceof BlocksFieldType || self::readonly($fieldDef->config)) {
                continue;
            }
            foreach ($fieldType->validateBlocks($record->get($name), $fieldDef->config, $typeDef->locales) as $key => $messages) {
                $errors[$key === '' ? $name : $name . '.' . $key] = $messages;
            }
        }
        if ($errors !== []) {
            throw new ValidationException(new ValidationResult($errors));
        }

        $this->dm->validate($record, $this->rules($typeDef));
    }

    /**
     * Per-field rules: the JSON "validate" list plus the field type's own rules
     * (e.g. select's in:…, date's format) and, for a unique or indexed string or a unique
     * field's scope, its length limit; a field of the type's frontend route, "slug" or
     * "slug:path" (a catch-all). Keyed by field, because the data
     * layer merges extra rules per field. A "readonly" field gets an empty list:
     * it is not validated on save (nor checked for being unique).
     *
     * @return array<string, list<string|\Preflow\Validation\ValidationRule>>
     */
    public function rules(TypeDefinition $typeDef): array
    {
        $rules = [];
        $lengthRules = $this->dm->fieldKinds()->lengthRules($typeDef);
        $addressRules = $this->addressRules($typeDef);
        foreach ($typeDef->fields as $name => $fieldDef) {
            if (self::readonly($fieldDef->config)) {
                // The editor can't touch it, so its stored value is not checked: it would block
                // every save (and "required" can't be met). An empty list replaces the type's own rules.
                $rules[$name] = [];
                continue;
            }
            $merged = array_values(array_unique([
                ...$fieldDef->validate,
                ...$this->fieldTypes->get($fieldDef->type)->rules($fieldDef->config),
            ]));
            if (isset($lengthRules[$name])) {
                $merged[] = $lengthRules[$name];
            }
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
        $rules = [];
        foreach ($route->paramNames as $name) {
            $rules[$name] = new AddressPart($typeDef->locales, str_contains($route->pattern, '{...' . $name . '}'));
        }

        return $rules;
    }

    /** @param array<string, mixed> $config */
    private static function readonly(array $config): bool
    {
        return ($config['readonly'] ?? false) === true;
    }
}
