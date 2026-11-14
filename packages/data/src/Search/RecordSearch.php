<?php

declare(strict_types=1);

namespace Preflow\Data\Search;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\RecordTitle;

/**
 * Searches records of several types at once: a loose search per type (every word in some text
 * field, QueryBuilder::searchLoose()), at most $perType records each, hits grouped by type in
 * the order the types are given and named by RecordTitle.
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
            foreach ($this->dataManager->queryType($type)->searchLoose($term)->limit($perType)->get()->items() as $record) {
                if ($record instanceof DynamicRecord) {
                    $hits[] = new RecordHit($type, (string) $record->getId(), RecordTitle::of($record));
                }
            }
        }

        return $hits;
    }
}
