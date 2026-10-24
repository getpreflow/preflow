<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class StringFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText
{
    public function key(): string
    {
        return 'string';
    }

    protected function inputType(): string
    {
        return 'text';
    }
}
