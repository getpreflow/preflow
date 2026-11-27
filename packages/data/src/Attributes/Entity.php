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
        /** Editors and APIs read its records, never create, change or delete them (a model file's `readonly`). @since 0.78 */
        public readonly bool $readonly = false,
        /** Editors and APIs may create its records; false: they change and delete only (a model file's `creatable`). @since 0.78 */
        public readonly bool $creatable = true,
        /**
         * The type's page layouts, the keys of a model file's "layouts" ({allowed, default, pinned,
         * slug_field}); null: none. Data writes them into the schema unread; preflow/blocks checks them
         * at boot. An admin file may not set them too. @since 0.93.0
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $layouts = null,
    ) {}
}
