<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

/**
 * The model keeps when it was created and last written: DataManager::save(), insert() and
 * update() set the `createdAt` property when it is empty and the `updatedAt` property on every
 * write (each only where the model has it). Put it on the class or on one of the properties
 * (as `preflow make:model` does). A `?\DateTimeImmutable` property is stored as
 * 'Y-m-d H:i:s' text; a `string` property gets that text, an `int` one the Unix time.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
final class Timestamps
{
    public const string CREATED_AT = 'createdAt';
    public const string UPDATED_AT = 'updatedAt';
}
