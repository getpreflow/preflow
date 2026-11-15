<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

/**
 * A grammar whose server has full-text indexes (MySQL/MariaDB FULLTEXT). A grammar without them does
 * not implement it, and the table sync leaves a type's "fullText" declaration alone there.
 *
 * @since 0.78
 */
interface FullTextIndexes
{
    /** @param list<string> $columns */
    public function createFullTextIndex(string $name, string $table, array $columns): string;

    /**
     * The full-text indexes of a table.
     *
     * @return array<string, list<string>> index name => its columns, in index order
     */
    public function fullTextIndexes(\PDO $pdo, string $table): array;
}
