<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The text that names a record wherever one is listed (search hits, activity rows, relation
 * labels): its type's titleField, or else the first field of type "string" that is not
 * hidden, read in a locale. A record without such text is named by its id.
 *
 * @since 0.77
 */
final class RecordTitle
{
    /** @param ?string $locale the locale to read a translatable title in; null: the type's default locale */
    public static function of(DynamicRecord $record, ?string $locale = null): string
    {
        $type = $record->getType();
        $field = $type->titleField ?? self::defaultField($type);
        if ($field !== null) {
            $value = $record->localized($locale ?? $type->locales->default)->get($field);
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) $record->getId();
    }

    /** The first field of type "string" that is not hidden, or null. */
    private static function defaultField(TypeDefinition $type): ?string
    {
        foreach ($type->fields as $name => $def) {
            if ($def->type === 'string' && ($def->config['hidden'] ?? false) !== true) {
                return $name;
            }
        }

        return null;
    }
}
