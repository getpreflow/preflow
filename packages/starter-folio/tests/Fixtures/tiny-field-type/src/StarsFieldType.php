<?php

declare(strict_types=1);

namespace Acme\TinyFieldType;

use Preflow\Folio\Field\Types\AbstractScalarFieldType;

/** A star rating, 1 to 5: the field type the package adds. */
final class StarsFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'acme_stars';
    }

    protected function inputType(): string
    {
        return 'number';
    }
}
