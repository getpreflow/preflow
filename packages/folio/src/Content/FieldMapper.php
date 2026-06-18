<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final class FieldMapper
{
    public static function inputFor(string $fieldType): string
    {
        return match ($fieldType) {
            'text' => 'textarea',
            'integer', 'int', 'float', 'number' => 'number',
            default => 'text',
        };
    }
}
