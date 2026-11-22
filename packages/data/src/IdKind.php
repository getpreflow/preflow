<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Whether a type's records have string ids or number ids. DataManager::idKind() answers it for a
 * type; a model class states it by its id property (ofModel()).
 *
 * @since 0.82.0
 */
enum IdKind: string
{
    /** A text id: given by the caller, or made by the data layer for a new record (a UUID or 32 hex characters). */
    case String = 'string';

    /** A number id: given by the caller, or numbered by the storage for a new record (auto-increment). */
    case Int = 'int';

    /**
     * The id kind a model class states: String when its id property is typed string (also
     * ?string), Int otherwise (int, untyped, a union, or no such property). DataManager gives a
     * new record of the class its id by this rule (a string id it makes, a number the storage gives).
     *
     * @param class-string $modelClass
     * @since 0.82.0
     */
    public static function ofModel(string $modelClass, string $idField): self
    {
        if (!property_exists($modelClass, $idField)) {
            return self::Int;
        }
        $type = (new \ReflectionProperty($modelClass, $idField))->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === 'string' ? self::String : self::Int;
    }
}
