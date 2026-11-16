<?php

declare(strict_types=1);

namespace Preflow\Data\Search;

/**
 * One record a RecordSearch found: its type, its id and its title (RecordTitle, default locale),
 * and its score when its storage ranked it (RankedSearch, higher is better; null in storage order).
 *
 * @since 0.77
 */
final readonly class RecordHit
{
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        /** @since 0.78 */
        public ?float $score = null,
    ) {}
}
