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
 */
final class RecordValidator
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly DataManager $dm,
    ) {}

    /** @throws ValidationException */
    public function validate(DynamicRecord $record): void
    {
        $typeDef = $record->getType();
        $errors = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if (!$fieldType instanceof BlocksFieldType) {
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
     * (e.g. select's in:…, date's format). Keyed by field, because the data
     * layer merges extra rules per field.
     *
     * @return array<string, list<string>>
     */
    public function rules(TypeDefinition $typeDef): array
    {
        $rules = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $merged = array_values(array_unique([
                ...$fieldDef->validate,
                ...$this->fieldTypes->get($fieldDef->type)->rules($fieldDef->config),
            ]));
            if ($merged !== []) {
                $rules[$name] = $merged;
            }
        }

        return $rules;
    }
}
