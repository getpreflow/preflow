<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that scores records against a search term, so a search can put the best matches
 * first. It orders, it never finds: the records come from loose search (QueryBuilder::searchLoose())
 * on every storage, and this only scores the ids it is given. The engine is the driver's own
 * (MySQL/MariaDB: MATCH … AGAINST over a FULLTEXT index).
 *
 * Call it through DataManager::canScore() and DataManager::scoreIn(), which pass the type's table
 * and its loose-search text fields (Search\LooseFields::text()) and only ask for a type that
 * declares full-text search ("fullText": true). A storage without this interface leaves hits in
 * storage order.
 *
 * @since 0.78
 */
interface RankedSearch extends StorageDriver
{
    /**
     * Whether score() can score $table over exactly $fields (as a set, in any order): MySQL needs
     * a FULLTEXT index over exactly those columns. False for a table it does not know.
     *
     * @param list<string> $fields
     */
    public function canScore(string $table, array $fields): bool;

    /**
     * The score of each record $ids names for $term over $fields: higher is better, 0.0 for a
     * record that does not match or does not exist. Every given id is a key, in the given order.
     * Only called when canScore($table, $fields) is true.
     *
     * @param list<string> $fields
     * @param list<string|int> $ids
     * @return array<string|int, float>
     */
    public function score(string $table, string $term, array $fields, array $ids, string $idField = 'uuid'): array;
}
