<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * What writes changed in this process, merged: an import of 300 jobs is one invalidation, not 300.
 * flush() hands the pending tags to the invalidator (Refresher::invalidate()) in one call; the
 * Application flushes at the end of each request, before the response goes out, and a shutdown
 * function flushes whatever is left (a command, a cron import, writes after the response).
 *
 * changed() does no IO: the invalidator is only called, and the index only opened, by flush().
 *
 * A long command (an import) can also flush early: every $flushEvery seconds, or once more than
 * $flushAbove tags are pending. Then a killed process (cyon ends long SSH runs, an OOM kill runs
 * no shutdown function) loses at most that much. What was flushed early is sent again by the
 * final flush(): a page rebuilt between the two from rows not committed yet is invalidated too.
 */
final class ChangeLog
{
    /** @var array<string, true> */
    private array $pending = [];

    private bool $registered = false;

    /** @var array<string, true> flushed early, sent again by the final flush() */
    private array $early = [];

    private ?float $since = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param \Closure(list<string>): void $invalidate called with the merged tags
     * @param bool $flushAtShutdown register a shutdown function on the first change
     * @param float|null $flushEvery flush early once the oldest pending change is this many seconds old (commands)
     * @param int $flushAbove flush early once more tags than this are pending, 0 = never (commands)
     * @param (\Closure(): float)|null $clock microtime(true) when null
     */
    public function __construct(
        private readonly \Closure $invalidate,
        private readonly bool $flushAtShutdown = true,
        private readonly ?float $flushEvery = null,
        private readonly int $flushAbove = 0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** @param iterable<string> $tags tags that changed (DataTags::changes() already applied) */
    public function changed(iterable $tags): void
    {
        foreach ($tags as $tag) {
            $this->pending[$tag] = true;
        }
        if ($this->pending === []) {
            return;
        }
        if ($this->flushEvery !== null || $this->flushAbove > 0) {
            $now = ($this->clock)();
            $this->since ??= $now;
            if (($this->flushAbove > 0 && count($this->pending) > $this->flushAbove)
                || ($this->flushEvery !== null && $now - $this->since >= $this->flushEvery)) {
                $this->early += $this->pending;
                $this->send(array_keys($this->pending));
                $this->pending = [];
                $this->since = null;
            }
        }
        if ($this->flushAtShutdown && !$this->registered && $this->pending !== []) {
            $this->registered = true;
            $self = \WeakReference::create($this);
            register_shutdown_function(static function () use ($self): void {
                $self->get()?->flush();
            });
        }
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_map(strval(...), array_keys($this->pending));
    }

    /**
     * Invalidates what is pending, in one call. A failure is logged, never thrown: the write
     * itself has happened, and pages still expire after the safety maximum age.
     */
    public function flush(): void
    {
        if ($this->pending === [] && $this->early === []) {
            return;
        }
        $tags = array_keys($this->pending + $this->early);
        $this->pending = [];
        $this->early = [];
        $this->since = null;
        $this->send($tags);
    }

    /** @param list<int|string> $tags */
    private function send(array $tags): void
    {
        $tags = array_map(strval(...), $tags);
        try {
            ($this->invalidate)($tags);
        } catch (\Throwable $e) {
            error_log('Page cache: could not invalidate ' . implode(', ', array_slice($tags, 0, 10))
                . (count($tags) > 10 ? ' …' : '') . ': ' . $e->getMessage());
        }
    }
}
