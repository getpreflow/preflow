<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class NumberFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'number';
    }

    protected function inputType(): string
    {
        return 'number';
    }
}
