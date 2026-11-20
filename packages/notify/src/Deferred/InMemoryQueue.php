<?php

declare(strict_types=1);

namespace Preflow\Notify\Deferred;

/**
 * notify's own queue (0.80's Notifier queue, moved): a list in memory, sent in FIFO order by
 * flush(), at most $limit sends per flush so a failure handler that queues again cannot loop
 * forever. While discarding() runs, what is pushed is dropped. Nothing captures here: record()
 * keeps nothing.
 *
 * @since 0.81.0
 */
final class InMemoryQueue implements DeferredQueue
{
    /** @var list<\Closure(): void> */
    private array $queued = [];

    /** How many discarding() calls are running; push() drops while above 0. */
    private int $discarding = 0;

    /**
     * @param int $limit most sends one flush() runs; the rest stay queued and the limit is logged
     * @param (\Closure(string): void)|null $log where the limit line goes (error_log when null)
     */
    public function __construct(
        private readonly int $limit = 100,
        private readonly ?\Closure $log = null,
    ) {}

    public function push(\Closure $send, string $summary): void
    {
        if ($this->discarding > 0) {
            return;
        }
        $this->queued[] = $send;
    }

    public function flush(): void
    {
        $handled = 0;
        while ($this->queued !== []) {
            if ($handled++ >= $this->limit) {
                $line = 'Deferred notifications: flush limit reached, ' . count($this->queued) . ' left unsent.';
                $this->log !== null ? ($this->log)($line) : error_log($line);
                break;
            }
            $send = array_shift($this->queued);
            $send();
        }
    }

    public function discarding(\Closure $fn): mixed
    {
        $this->discarding++;
        try {
            return $fn();
        } finally {
            $this->discarding--;
        }
    }

    public function policy(): string
    {
        return $this->discarding > 0 ? self::DISCARD : self::DELIVER;
    }

    public function record(string $summary): void
    {
        // Nothing captures without a render around it.
    }
}
