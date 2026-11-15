<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * When AtomicCounters::increment() restarts a counter: when the stored $field is below $below,
 * it writes $set and the counter as the increment alone. A fixed window of $seconds at $now is
 * new CounterReset('window_start', $now - $seconds + 1, ['window_start' => $now]): a window
 * that started $seconds ago or earlier has ended.
 *
 * @since 0.78
 */
final readonly class CounterReset
{
    /** @param array<string, scalar|null> $set the columns a restart writes */
    public function __construct(
        public string $field,
        public int $below,
        public array $set,
    ) {}
}
