<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CleansOnWrite;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "richtext" field's value: HTML, shape "rich_text". Cleaned on every step (HtmlCleaner): the
 * input to store, the stored value to output (whatever a driver holds, an import's included) and
 * every typed write (CleansOnWrite, through StoredCleaning). A string is cleaned, and so is each
 * string of a map (a translatable field's locales); any other value passes through unchanged.
 *
 * @since 0.93.0
 */
final class RichTextValue implements FieldValue, CleansOnWrite, DescribesShapes
{
    /** @param \Closure(): HtmlCleaner $cleaner asked on each clean, so a binding made after boot counts */
    public function __construct(private readonly \Closure $cleaner)
    {
    }

    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return $this->cleaned($input);
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return $this->cleaned($stored);
    }

    public function clean(mixed $value, TypeFieldDefinition $field): mixed
    {
        return $this->cleaned($value);
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'rich_text';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('rich_text');
    }

    private function cleaned(mixed $value): mixed
    {
        if (is_string($value)) {
            return $value === '' ? '' : ($this->cleaner)()->clean($value);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $one): mixed => is_string($one) && $one !== '' ? ($this->cleaner)()->clean($one) : $one, $value);
        }

        return $value;
    }
}
