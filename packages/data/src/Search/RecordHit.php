<?php

declare(strict_types=1);

namespace Preflow\Data\Search;

/**
 * One record a RecordSearch found: its type, its id and its title (RecordTitle, default locale).
 *
 * @since 0.77
 */
final readonly class RecordHit
{
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
    ) {}
}
