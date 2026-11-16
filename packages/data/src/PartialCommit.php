<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * DataManager::transaction() across storages committed on some of them before a later commit
 * failed: what $committed names is stored, everything else rolled back. The failed commit is
 * the previous exception.
 *
 * @since 0.78
 */
final class PartialCommit extends \RuntimeException
{
    /**
     * @param list<string> $committed the storage names that committed, in the order they did
     */
    public function __construct(
        public readonly array $committed,
        \Throwable $previous,
    ) {
        parent::__construct(
            'Committed on [' . implode(', ', $committed) . '] before a later commit failed: ' . $previous->getMessage(),
            0,
            $previous,
        );
    }
}
