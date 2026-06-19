<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class TextFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'text';
    }

    protected function inputType(): string
    {
        return 'textarea';
    }
}
