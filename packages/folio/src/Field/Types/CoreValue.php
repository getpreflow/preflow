<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\FieldValue;

/**
 * A field type on its core value (0.93, UsesFieldKinds): the value the kinds hold for the type's
 * key, else defaultValue(). stored() and output() are the value's toStored() and toOutput() for a
 * field of this type with $config (FieldType::toStorage() and fromStorage() get no config: the
 * types that need one, such as a relation's "multiple", pass what the value's shape tells).
 *
 * @internal
 */
trait CoreValue
{
    private ?FieldKinds $coreKinds = null;

    private ?FieldValue $ownValue = null;

    public function useFieldKinds(FieldKinds $kinds): void
    {
        $this->coreKinds = $kinds;
    }

    /** The value used without kinds, or when they hold none for the key. */
    abstract protected function defaultValue(): FieldValue;

    protected function coreValue(): FieldValue
    {
        if ($this->coreKinds !== null && $this->coreKinds->hasValue($this->key())) {
            return $this->coreKinds->valueOf($this->coreField());
        }

        return $this->ownValue ??= $this->defaultValue();
    }

    /** @param array<string, mixed> $config */
    protected function coreField(array $config = []): TypeFieldDefinition
    {
        return new TypeFieldDefinition($this->key(), $this->key(), config: $config);
    }

    /**
     * One locale's input as the core stores it.
     *
     * @param array<string, mixed> $config
     */
    protected function stored(mixed $input, array $config = []): mixed
    {
        return $this->coreValue()->toStored($input, $this->coreField($config));
    }

    /**
     * One locale's stored value as the core reads it out.
     *
     * @param array<string, mixed> $config
     */
    protected function output(mixed $stored, array $config = []): mixed
    {
        return $this->coreValue()->toOutput($stored, $this->coreField($config));
    }
}
