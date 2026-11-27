<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;
use Preflow\Fields\Values\Link;

/**
 * A "link" field's value, shape "link": the stored object {kind, target, label, new_tab}.
 *
 * toStored(): the object, or JSON text of it, as Link::toStored() writes it; no value is null; an
 * object that is no link (an unknown kind, a missing target) is passed on as its object for the
 * rule, other input unchanged. toOutput(): the object (JSON text on SQL decoded), null when the
 * value holds no link. references(): a record link's type and id.
 *
 * @since 0.93.0
 */
final class LinkValue implements FieldValue, DescribesShapes, ListsReferences
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return Link::fromStored($input)?->toStored() ?? Link::decode($input) ?? $input;
    }

    /** @return array{kind: string, target: array{type: string, id: string}|string, label: string|array<string, string>|null, new_tab: bool}|null */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return Link::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'link';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('link');
    }

    public function references(mixed $stored, TypeFieldDefinition $field): array
    {
        $link = Link::fromStored($stored);
        $type = $link?->recordType();
        $id = $link?->recordId();

        return $type !== null && $id !== null && $id !== '' ? [$type => [$id]] : [];
    }
}
