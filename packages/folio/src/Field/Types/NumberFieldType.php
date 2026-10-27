<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class NumberFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'number';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Number;
    }

    protected function inputType(): string
    {
        return 'number';
    }

    /** An emptied number field holds no number. */
    public function fromStorage(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
