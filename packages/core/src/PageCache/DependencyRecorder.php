<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * What a page read while it rendered: the tags of its reads (DataTags), each with the freshness
 * it needs. It records only between start() and stop(); outside, record() returns at once.
 *
 * Reads are Fresh unless they happen inside within(): the developer's dial for a dependency
 * ("other jobs of this company" may be NextVisit or Timed). A tag read with several freshnesses
 * keeps the strictest (Fresh > NextVisit > Timed); of two Timed reads, the shorter time.
 *
 * Reads inside within() scopes that nest take the innermost scope's freshness: the dial is set
 * where the read happens ("these other jobs"), the scope around it doesn't override it.
 *
 * A recording also keeps the earliest expiry asked for while it was open (expiresAt(): an event
 * date, a sale ending at midnight), which the middleware stores as the page's expiresAt.
 *
 * Recordings nest: a start() inside another one records into both, so what an inner render read
 * (and an expiry it asked for) also counts for the page around it.
 */
final class DependencyRecorder
{
    /** @var list<array<string, array{0: Freshness, 1: ?int}>> the open recordings, innermost last */
    private array $frames = [];

    private Freshness $freshness = Freshness::Fresh;

    private ?int $seconds = null;

    /** @var list<?float> the earliest expiry of each open recording (unix time), parallel to $frames */
    private array $expiries = [];

    /** @var list<array{0: Freshness, 1: ?int}> the freshness scopes around the current one (enter()) */
    private array $scopes = [];

    /**
     * Opens a recording. Keep what it returns and hand it to stop(): a recording opened inside
     * and never stopped (a render that threw in between) is then closed with it, and the outer
     * page still gets its own reads. Prefer capture(), which can't be left open.
     *
     * @return int the recording, for stop()
     */
    public function start(): int
    {
        $this->frames[] = [];
        $this->expiries[] = null;

        return count($this->frames) - 1;
    }

    /**
     * Ends recording $frame (the innermost one when null), and any opened inside it.
     *
     * @return array<string, array{0: Freshness, 1: ?int}> tag => [freshness, seconds of a Timed one]
     */
    public function stop(?int $frame = null): array
    {
        $frame ??= count($this->frames) - 1;
        if ($frame < 0 || !isset($this->frames[$frame])) {
            return [];
        }
        $tags = $this->frames[$frame];
        array_splice($this->frames, $frame);
        array_splice($this->expiries, $frame);

        return $tags;
    }

    /**
     * Runs $fn inside its own recording, closed even when $fn throws.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return array{0: T, 1: array<string, array{0: Freshness, 1: ?int}>}
     */
    public function capture(\Closure $fn): array
    {
        $frame = $this->start();
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->stop($frame);
            throw $e;
        }

        return [$result, $this->stop($frame)];
    }

    public function recording(): bool
    {
        return $this->frames !== [];
    }

    /**
     * The earliest expiry asked for in recording $frame (the innermost one when null) so far,
     * a unix time; null when none was. Ask before stop().
     */
    public function expiry(?int $frame = null): ?float
    {
        $frame ??= count($this->frames) - 1;

        return $this->expiries[$frame] ?? null;
    }

    /**
     * The page being recorded must be refreshed by $at at the latest (an event date): every
     * open recording keeps the earliest. Outside a recording (and in a live part) it does nothing.
     */
    public function expiresAt(\DateTimeInterface|float|int $at): void
    {
        if ($this->frames === []) {
            return;
        }
        $at = $at instanceof \DateTimeInterface ? (float) $at->format('U.u') : (float) $at;
        foreach ($this->expiries as $i => $known) {
            $this->expiries[$i] = $known === null ? $at : min($known, $at);
        }
    }

    /**
     * Runs $fn with nothing recorded, not even into the recordings open around it: what a live
     * part reads while a page is served is the visitor's, never a dependency of the page.
     * Recordings $fn opens and leaves open are closed when it returns.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function outside(\Closure $fn): mixed
    {
        $saved = [$this->frames, $this->expiries, $this->freshness, $this->seconds, $this->scopes];
        $this->frames = [];
        $this->expiries = [];
        // A freshness scope around a live part is not its: what it reads is no dependency at all.
        $this->freshness = Freshness::Fresh;
        $this->seconds = null;
        $this->scopes = [];
        try {
            return $fn();
        } finally {
            [$this->frames, $this->expiries, $this->freshness, $this->seconds, $this->scopes] = $saved;
        }
    }

    /**
     * Runs $fn with its reads recorded as $freshness ($seconds: how long a Timed one may be old,
     * 1 or more; ignored for the others). A scope inside another one wins for its reads.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     * @throws \InvalidArgumentException Timed without seconds (or with fewer than 1)
     */
    public function within(Freshness $freshness, ?int $seconds, \Closure $fn): mixed
    {
        $this->enter($freshness, $seconds);
        try {
            return $fn();
        } finally {
            $this->leave();
        }
    }

    /**
     * Opens a freshness scope, as within() does, for code that can't pass a closure (a compiled
     * Blade view). Close it with leave(), in a finally.
     *
     * @throws \InvalidArgumentException Timed without seconds (or with fewer than 1)
     */
    public function enter(Freshness $freshness, ?int $seconds = null): void
    {
        if ($freshness === Freshness::Timed && ($seconds === null || $seconds < 1)) {
            throw new \InvalidArgumentException('A timed freshness needs its time: 1 second or more.');
        }
        $this->scopes[] = [$this->freshness, $this->seconds];
        $this->freshness = $freshness;
        $this->seconds = $freshness === Freshness::Timed ? $seconds : null;
    }

    /** Closes the scope enter() opened last. */
    public function leave(): void
    {
        $before = array_pop($this->scopes);
        if ($before !== null) {
            [$this->freshness, $this->seconds] = $before;
        }
    }

    /** Notes reads of $tags (with "any record of its table" for a record, DataTags::dependencies()). */
    public function record(string ...$tags): void
    {
        if ($this->frames === []) {
            return;
        }
        foreach ($tags as $tag) {
            foreach (DataTags::dependencies($tag) as $dependency) {
                foreach ($this->frames as $i => $frame) {
                    $this->frames[$i][$dependency] = isset($frame[$dependency])
                        ? self::merged($frame[$dependency], $this->freshness, $this->seconds)
                        : [$this->freshness, $this->seconds];
                }
            }
        }
    }

    /**
     * @param array{0: Freshness, 1: ?int} $known
     * @return array{0: Freshness, 1: ?int}
     */
    private static function merged(array $known, Freshness $freshness, ?int $seconds): array
    {
        if ($freshness->stricterThan($known[0])) {
            return [$freshness, $seconds];
        }
        if ($freshness === Freshness::Timed && $known[0] === Freshness::Timed && $seconds !== null
            && ($known[1] === null || $seconds < $known[1])) {
            return [$freshness, $seconds];
        }

        return $known;
    }
}
