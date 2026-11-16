<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Search\Fixtures;

use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Query;
use Preflow\Data\RankedSearch;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

/**
 * A storage that ranks, for tests without MySQL: JSON files (a JsonFileDriver it delegates to) plus
 * RankedSearch, where a record's score is how often the term's words occur in the fields (ignoring
 * case). Fields are scorable once declare()d for their table, as a FULLTEXT index would make them.
 */
final class RankedFakeDriver implements StorageDriver, RankedSearch
{
    /** @var array<string, list<list<string>>> table => declared field sets (sorted) */
    private array $declared = [];

    /** @var list<array{table: string, term: string, ids: list<string|int>}> every score() call */
    public array $scored = [];

    public function __construct(
        private readonly JsonFileDriver $json,
    ) {}

    /** @param list<string> $fields */
    public function declare(string $table, array $fields): void
    {
        sort($fields);
        $this->declared[$table][] = $fields;
    }

    /** @var array<string, bool> canScore() answers, kept as MysqlDriver keeps them */
    private array $answers = [];

    /** A failure score() throws although the index is there (a lost connection). */
    public ?\RuntimeException $failure = null;

    /** The index goes away behind the driver's back (another process ran a migration): cached answers stay. */
    public function drop(string $table): void
    {
        unset($this->declared[$table]);
    }

    public function canScore(string $table, array $fields): bool
    {
        sort($fields);

        return $this->answers[$table . '|' . implode(',', $fields)] ??= in_array($fields, $this->declared[$table] ?? [], true);
    }

    public function score(string $table, string $term, array $fields, array $ids, string $idField = 'uuid'): array
    {
        $this->scored[] = ['table' => $table, 'term' => $term, 'ids' => $ids];
        sort($fields);
        if ($this->failure !== null || !in_array($fields, $this->declared[$table] ?? [], true)) {
            // As MysqlDriver: a failed score() forgets the table's answers, so canScore() asks again.
            $this->answers = array_filter($this->answers, static fn (string $key): bool => !str_starts_with($key, $table . '|'), ARRAY_FILTER_USE_KEY);
            throw $this->failure ?? new \RuntimeException("Can't find FULLTEXT index matching the column list");
        }
        $words = preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scores = [];
        foreach ($ids as $id) {
            $row = $this->json->findOne($table, $id, $idField);
            $text = $row === null ? '' : mb_strtolower(implode(' ', self::texts(array_intersect_key($row, array_flip($fields)))));
            $score = 0;
            foreach ($words as $word) {
                $score += mb_substr_count($text, $word);
            }
            $scores[$id] = (float) $score;
        }

        return $scores;
    }

    /** @return list<string> every string in $value, nested ones too (a translatable field's locales) */
    private static function texts(mixed $value): array
    {
        if (is_array($value)) {
            return array_merge([], ...array_map(self::texts(...), array_values($value)));
        }

        return is_scalar($value) ? [(string) $value] : [];
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->json->findOne($type, $id, $idField);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        return $this->json->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->json->save($type, $id, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->json->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->json->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->json->lastInsertId();
    }
}
