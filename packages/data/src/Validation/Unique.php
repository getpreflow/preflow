<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\LocaleValue;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The value is not already another record's value of $field (validation.unique). DataManager
 * adds it for every unique field when it validates a save; app code uses it to validate input
 * before saving.
 *
 * $type is a definition, a type key of the registry or a model class. $scope names the fields
 * that scope the uniqueness, read from the validated data (['purpose']), or gives their values
 * (['purpose' => 'invite']). $exceptId is the record being saved, never its own duplicate. With
 * $locale the value is compared with that locale's value of a translatable field.
 *
 * Values compare exactly (case and accents count), as the unique index does on every database.
 * Empty values (null, '') are never duplicates, nor is a value whose scope holds a null.
 */
final class Unique implements ValidationRule
{
    /** @param array<int|string, mixed> $scope */
    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeDefinition|string $type,
        private readonly string $field,
        private readonly array $scope = [],
        private readonly string|int|null $exceptId = null,
        private readonly ?string $locale = null,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return true;
        }
        $scope = [];
        foreach ($this->scope as $key => $entry) {
            if (is_int($key)) {
                $scope[(string) $entry] = $context->getValue((string) $entry);
            } else {
                $scope[$key] = $entry;
            }
        }
        foreach ($scope as $scoped) {
            if ($scoped === null || !is_scalar($scoped)) {
                return true; // a null in the index never collides
            }
        }

        return $this->taken((string) $value, $scope) ? new Message('validation.unique') : true;
    }

    /** @param array<string, scalar> $scope */
    private function taken(string $value, array $scope): bool
    {
        $def = is_string($this->type) && !is_subclass_of($this->type, Model::class)
            ? ($this->dm->getTypeRegistry() ?? throw new \LogicException('Unique: no type registry for type "' . $this->type . '".'))->get($this->type)
            : $this->type;
        if ($def instanceof TypeDefinition) {
            $query = $this->dm->queryIn($def);
            [$storage, $idField] = [$def->storage, $def->idField];
            $translatable = in_array($this->field, $def->translatableFields(), true);
        } else {
            /** @var class-string<Model> $def */
            $meta = ModelMetadata::for($def);
            $query = $this->dm->query($def);
            [$storage, $idField] = [$meta->storage, $meta->idField];
            $translatable = false;
        }

        // SQL narrows the rows down (a column without exact collation may match more); the
        // comparison below decides. The JSON driver compares typed values, so it reads them all.
        if ($this->dm->driver($storage) instanceof PdoDriver) {
            $query->where($this->field, '=', $value);
            foreach ($scope as $name => $scoped) {
                $query->where($name, '=', $scoped);
            }
            if ($translatable && $this->locale !== null) {
                $query->locale($this->locale);
            }
        }

        foreach ($query->get()->items() as $row) {
            $data = $row->toArray();
            if ($this->exceptId !== null && (string) ($data[$idField] ?? '') === (string) $this->exceptId) {
                continue;
            }
            $stored = $translatable && $def instanceof TypeDefinition
                ? LocaleValue::pick($data[$this->field] ?? null, $def->locales, $this->locale ?? $def->locales->default)
                : ($data[$this->field] ?? null);
            if (!is_scalar($stored) || (string) $stored !== $value) {
                continue;
            }
            foreach ($scope as $name => $scoped) {
                $other = $data[$name] ?? null;
                if (!is_scalar($other) || (string) $other !== (string) $scoped) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
