<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

/**
 * The model keeps when it was created and last written: DataManager::save(), insert() and
 * update() of a plain model (not a class-backed type) set the created property when it is
 * empty and the updated property on every write: `createdAt` or `created_at`, `updatedAt` or
 * `updated_at`, the first the model has (snake_case matches the columns of the migrations'
 * `timestamps()`). Put it on the class or on one of the properties (as `preflow make:model`
 * does). A `?\DateTimeImmutable` property is stored as 'Y-m-d H:i:s' text; a `string` (or
 * untyped) property gets that text, an `int` one the Unix time. Other types (a mutable
 * `\DateTime` too) are not stamped.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
final class Timestamps
{
    public const string CREATED_AT = 'createdAt';
    public const string UPDATED_AT = 'updatedAt';

    /** @var list<string> the created property's names, the first the model has is stamped */
    public const array CREATED_NAMES = ['createdAt', 'created_at'];

    /** @var list<string> the updated property's names, the first the model has is stamped */
    public const array UPDATED_NAMES = ['updatedAt', 'updated_at'];

    /**
     * The property of these names the class has first, or null.
     *
     * @param class-string $class
     * @param list<string> $names
     */
    public static function propertyOf(string $class, array $names): ?string
    {
        foreach ($names as $name) {
            if (property_exists($class, $name)) {
                return $name;
            }
        }

        return null;
    }
}
