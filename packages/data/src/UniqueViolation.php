<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Migration\Grammar\RecordIndexNames;

/**
 * A write refused by a unique index other than the primary key, with the index's name where the
 * database reports it (MySQL) or its columns where it reports those (SQLite). Nothing was
 * written. DataManager turns it into the field error validation.unique on the field the index
 * belongs to; an index it does not know (one made outside the type) stays this exception.
 */
final class UniqueViolation extends \PDOException
{
    use RecordIndexNames {
        indexName as private;
    }

    /** @param list<string> $columns */
    public function __construct(
        \PDOException $previous,
        public readonly ?string $index,
        public readonly array $columns = [],
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
        $this->code = $previous->getCode();
        $this->errorInfo = $previous->errorInfo;
    }

    /**
     * The unique field whose index this is: the index named for it in $table (RecordTableSync's
     * "uniq_" names), or the index over exactly its column and scope columns.
     *
     * @param array<string, list<string>> $unique field => scope fields (uniqueFields())
     */
    public function field(string $table, array $unique): ?string
    {
        foreach ($unique as $field => $scope) {
            $columns = [$field, ...$scope];
            sort($columns);
            $own = $this->columns;
            sort($own);
            if ($this->index !== null ? $this->index === $this->indexName('uniq', $table, $field) : $own === $columns) {
                return $field;
            }
        }

        return null;
    }
}
