<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class StringFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText, \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'string';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Short;
    }

    protected function inputType(): string
    {
        return 'text';
    }
}
