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
 * Recordings nest: a start() inside another one records into both, so what an inner render read
 * also counts for the page around it.
 */
final class DependencyRecorder
{
    /** @var list<array<string, array{0: Freshness, 1: ?int}>> the open recordings, innermost last */
    private array $frames = [];

    private Freshness $freshness = Freshness::Fresh;

    private ?int $seconds = null;

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
     * Runs $fn with its reads recorded as $freshness ($seconds: how long a Timed one may be old).
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function within(Freshness $freshness, ?int $seconds, \Closure $fn): mixed
    {
        $before = [$this->freshness, $this->seconds];
        $this->freshness = $freshness;
        $this->seconds = $freshness === Freshness::Timed ? $seconds : null;
        try {
            return $fn();
        } finally {
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
