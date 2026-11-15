<?php
declare(strict_types=1);
namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public readonly string $table,
        public readonly string $storage = 'default',
        /** The type key that makes this class a data type (as a model JSON file's `key`); null keeps it an ordinary model. */
        public readonly ?string $type = null,
        /** The field that names a record of the type (a model file's `titleField`); null: the first string field that is not hidden. @since 0.77 */
        public readonly ?string $titleField = null,
        /** The type declares full-text search: on MySQL the table sync indexes its loosely searchable text fields (a model file's `fullText`). @since 0.78 */
        public readonly bool $fullText = false,
    ) {}
}
