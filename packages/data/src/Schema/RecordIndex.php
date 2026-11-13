<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\SchemaGrammar;

/**
 * An index a typed sync gives a record table (RecordTableSync::indexes()): unique over a field and
 * its scope, or plain over an indexed field or a composite index's fields. $freeText names a
 * column stored as free text: the index can't be made, and sync warns instead. $bytes is the most
 * its key takes on MySQL (utf8mb4: four bytes a character); past MySQL's limit sync warns there too.
 */
final readonly class RecordIndex
{
    /** MySQL caps an index key at 3072 bytes. */
    public const int MYSQL_MAX_KEY_BYTES = 3072;

    /** @param list<string> $columns in index order (a unique index: the field, then its scope) */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public ?string $freeText = null,
        public int $bytes = 0,
    ) {}

    /** Whether the key is past what the server allows (MySQL's 3072 bytes; no limit elsewhere). */
    public function tooLongFor(SchemaGrammar $grammar): bool
    {
        return $grammar instanceof MysqlGrammar && $this->bytes > self::MYSQL_MAX_KEY_BYTES;
    }
}
