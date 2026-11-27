<?php

declare(strict_types=1);

namespace Preflow\Folio\Rbac\Fields;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Rbac\RoleList;

/**
 * A "rbac_permissions" field's value (Folio's rbac admin, 0.93), shape "string_list": permission names or patterns ("folio.dashboard", "post.*"), as
 * a list, also when given or stored as JSON text or a comma string (RoleList::normalize()): each
 * trimmed, once, without empty ones. Which names the form offers is the form's (PermissionsFieldType).
 *
 * @since 0.93.0
 */
final class PermissionsValue implements FieldValue, DescribesShapes
{
    /** @return list<string> */
    public function toStored(mixed $input, TypeFieldDefinition $field): array
    {
        return RoleList::normalize($input);
    }

    /** @return list<string> */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): array
    {
        return RoleList::normalize($stored);
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'string_list';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('string_list');
    }
}
