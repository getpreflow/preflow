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
 */
final class ChangeLog
{
    /** @var array<string, true> */
    private array $pending = [];

    private bool $registered = false;

    /**
     * @param \Closure(list<string>): void $invalidate called with the merged tags
     * @param bool $flushAtShutdown register a shutdown function on the first change
     */
    public function __construct(
        private readonly \Closure $invalidate,
        private readonly bool $flushAtShutdown = true,
    ) {}

    /** @param iterable<string> $tags tags that changed (DataTags::changes() already applied) */
    public function changed(iterable $tags): void
    {
        foreach ($tags as $tag) {
            $this->pending[$tag] = true;
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
        if ($this->pending === []) {
            return;
        }
        $tags = $this->pending();
        $this->pending = [];
        try {
            ($this->invalidate)($tags);
        } catch (\Throwable $e) {
            error_log('Page cache: could not invalidate ' . implode(', ', array_slice($tags, 0, 10))
                . (count($tags) > 10 ? ' …' : '') . ': ' . $e->getMessage());
        }
    }
}
