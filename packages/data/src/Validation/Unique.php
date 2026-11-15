<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Data\DataManager;
use Preflow\Data\LocaleValue;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Schema\TextColumns;
use Preflow\Data\Text\TextMatch;
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
 * A text field's values compare by the text rule (TextColumns, TextMatch::equals(): case is
 * ignored, accents count: "Müller" is a duplicate of "MÜLLER", "Muller" is not), as the column's
 * unique index does on SQL storage; other fields (ids, exact fields, numbers) compare exactly.
 * Each scope field compares by its own kind. Empty values (null, '') are never duplicates, nor
 * is a value whose scope holds a null.
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
            $idField = $def->idField;
            $translatable = in_array($this->field, $def->translatableFields(), true);
            $text = TextColumns::of($def, $this->dm->fieldKinds());
        } else {
            /** @var class-string<Model> $def */
            $meta = ModelMetadata::for($def);
            $query = $this->dm->query($def);
            $idField = $meta->idField;
            $translatable = false;
            // A class marked #[Entity(type: …)] is its type's definition (as DataManager::query()
            // reads it); a plain model class has its #[Field] properties.
            $registry = $this->dm->getTypeRegistry();
            $text = $meta->type !== null && $registry !== null && $registry->has($meta->type)
                ? TextColumns::of($registry->get($meta->type), $this->dm->fieldKinds())
                : TextColumns::ofModel($meta, $this->dm->fieldKinds());
        }
        $same = static fn (string $name, mixed $stored, string $given): bool => is_scalar($stored)
            && (in_array($name, $text, true) && is_string($stored) ? TextMatch::equals($stored, $given) : (string) $stored === $given);

        // The query narrows the rows down on every storage: IN compares as text (a number stored
        // as "5" is a duplicate of 5), by the text rule on a text field, and a column with
        // another collation may match more. The comparison below decides.
        $query->where($this->field, 'IN', [$value]);
        foreach ($scope as $name => $scoped) {
            $query->where($name, 'IN', [(string) $scoped]);
        }
        if ($translatable && $this->locale !== null) {
            $query->locale($this->locale);
        }

        foreach ($query->get()->items() as $row) {
            $data = $row->toArray();
            if ($this->exceptId !== null && (string) ($data[$idField] ?? '') === (string) $this->exceptId) {
                continue;
            }
            $stored = $translatable && $def instanceof TypeDefinition
                ? LocaleValue::pick($data[$this->field] ?? null, $def->locales, $this->locale ?? $def->locales->default)
                : ($data[$this->field] ?? null);
            if (!$same($this->field, $stored, $value)) {
                continue;
            }
            foreach ($scope as $name => $scoped) {
                if (!$same((string) $name, $data[$name] ?? null, (string) $scoped)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
