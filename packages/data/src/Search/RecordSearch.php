<?php

declare(strict_types=1);

namespace Preflow\Data\Search;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\RecordTitle;
use Preflow\Data\TypeDefinition;

/**
 * Searches records of several types at once: a loose search per type (every word in some text
 * field, QueryBuilder::searchLoose()), at most $perType records each, hits grouped by type in
 * the order the types are given and named by RecordTitle.
 *
 * Within a type, ranking orders, it never changes the set: when the storage can score the type
 * (DataManager::canScore(): RankedSearch, "fullText": true, and on MySQL its FULLTEXT index), the
 * first CANDIDATES loose-search hits are scored and the best $perType come first (ties in storage
 * order), each with its score. Otherwise the hits are the first $perType in storage order, as
 * before 0.78, without a score. The same records match on every storage; only the order differs.
 *
 * It reads working copies and checks no permission: the caller passes only the types the
 * person may read.
 *
 * @since 0.77
 */
final class RecordSearch
{
    /** A shorter term (after trimming) finds nothing. */
    public const int MIN_TERM_LENGTH = 2;

    /**
     * Loose-search hits per type a ranking storage scores; the best $perType of these are listed.
     *
     * @since 0.78
     */
    public const int CANDIDATES = 200;

    public function __construct(
        private readonly DataManager $dataManager,
    ) {}

    /**
     * @param list<string> $types type keys, in the order their hits are listed; an unknown one is skipped
     * @return list<RecordHit>
     */
    public function search(string $term, array $types, int $perType = 5): array
    {
        $term = trim($term);
        $registry = $this->dataManager->getTypeRegistry();
        if (mb_strlen($term) < self::MIN_TERM_LENGTH || $registry === null || $perType < 1) {
            return [];
        }

        $hits = [];
        foreach (array_values(array_unique($types)) as $type) {
            if (!$registry->has($type)) {
                continue;
            }
            $def = $registry->get($type);
            if ($this->dataManager->canScore($def)) {
                array_push($hits, ...$this->ranked($def, $term, $perType));
                continue;
            }
            foreach ($this->dataManager->queryType($type)->searchLoose($term)->limit($perType)->get()->items() as $record) {
                if ($record instanceof DynamicRecord) {
                    $hits[] = new RecordHit($type, (string) $record->getId(), RecordTitle::of($record));
                }
            }
        }

        return $hits;
    }

    /**
     * The best $perType of the type's first CANDIDATES loose-search hits by score (a stable sort:
     * ties keep storage order).
     *
     * @return list<RecordHit>
     */
    private function ranked(TypeDefinition $def, string $term, int $perType): array
    {
        $records = array_values(array_filter(
            $this->dataManager->queryType($def->key)->searchLoose($term)->limit(self::CANDIDATES)->get()->items(),
            static fn (mixed $record): bool => $record instanceof DynamicRecord,
        ));
        if ($records === []) {
            return [];
        }
        $ids = array_map(static fn (DynamicRecord $record): string => (string) $record->getId(), $records);
        $scores = $this->dataManager->scoreIn($def, $term, $ids);
        $ranked = [];
        foreach ($records as $i => $record) {
            $ranked[] = ['record' => $record, 'id' => $ids[$i], 'score' => (float) ($scores[$ids[$i]] ?? 0.0)];
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']); // stable since PHP 8.0

        return array_map(
            static fn (array $hit): RecordHit => new RecordHit($def->key, $hit['id'], RecordTitle::of($hit['record']), $hit['score']),
            array_slice($ranked, 0, $perType),
        );
    }
}
