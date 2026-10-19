<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Field;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\AbstractScalarFieldType;

/** App-registered field type that replaces the built-in "string". */
final class TestStringType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'string';
    }

    protected function inputType(): string
    {
        return 'text';
    }

    protected function editorAttrs(FieldContext $ctx): array
    {
        return ['data-test-string' => true];
    }
}
