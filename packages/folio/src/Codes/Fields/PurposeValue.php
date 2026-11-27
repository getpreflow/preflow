<?php

declare(strict_types=1);

namespace Preflow\Folio\Codes\Fields;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\Core\SelectValue;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "codes_purpose" field's value (Folio's codes admin, 0.93), shape "string": the purpose's key
 * as a select stores its option (SelectValue: none is null, a number its text). That the key is
 * one of config/codes.php's purposes is the field's rule (PurposeFieldType::rules()).
 *
 * @since 0.93.0
 */
final class PurposeValue implements FieldValue, DescribesShapes
{
    private readonly SelectValue $select;

    public function __construct()
    {
        $this->select = new SelectValue();
    }

    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return $this->select->toStored($input, $field);
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return $this->select->toOutput($stored, $field);
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'string';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('string');
    }
}
