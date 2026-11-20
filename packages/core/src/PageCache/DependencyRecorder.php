<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Preflow\Core\Context\Contexts;

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
 *
 * Since 0.81 the recordings and freshness scopes belong to the render frame (a frame slot,
 * RecordingState): a render in a frame of its own (a rebuild, verify's second render, an audit
 * page, a request asked for inside another) starts with none open, so what it reads is never a
 * dependency of the page of the frame around it, and that page's scopes are not its. Outside
 * every frame (a script, a unit test) the recorder keeps its own. A request asked for inside a
 * visitor's render (Application::handle() in a page) is part of that page, as before 0.81
 * (nestIn()): what it reads and the expiries it asks for also reach the recordings of the frame
 * around it, with the freshness of its own scope, else of the scope around the request.
 */
final class DependencyRecorder
{
    /** What is recorded outside every frame. */
    private readonly RecordingState $own;

    public function __construct()
    {
        $this->own = new RecordingState();
    }

    /** The current frame's recordings, or the recorder's own outside every frame. */
    private function state(): RecordingState
    {
        return Contexts::depth() === 0
            ? $this->own
            : Contexts::frame()->slot(RecordingState::class, static fn (): RecordingState => new RecordingState());
    }

    /**
     * Opens a recording. Keep what it returns and hand it to stop(): a recording opened inside
     * and never stopped (a render that threw in between) is then closed with it, and the outer
     * page still gets its own reads. Prefer capture(), which can't be left open.
     *
     * @param float|null $at when the render started (unix time): an expiry at or before it is
     *        ignored for this recording, it says nothing new (null: every expiry counts)
     * @return int the recording, for stop()
     */
    public function start(?float $at = null): int
    {
        $state = $this->state();
        $state->frames[] = [];
        $state->expiries[] = null;
        $state->starts[] = $at;
        $state->ignored[] = null;

        return count($state->frames) - 1;
    }

    /**
     * Ends recording $frame (the innermost one when null), and any opened inside it.
     *
     * @return array<string, array{0: Freshness, 1: ?int}> tag => [freshness, seconds of a Timed one]
     */
    public function stop(?int $frame = null): array
    {
        $state = $this->state();
        $frame ??= count($state->frames) - 1;
        if ($frame < 0 || !isset($state->frames[$frame])) {
            return [];
        }
        $tags = $state->frames[$frame];
        array_splice($state->frames, $frame);
        array_splice($state->expiries, $frame);
        array_splice($state->starts, $frame);
        array_splice($state->ignored, $frame);

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

    /** Whether a recording is open in the current frame, or in the page a nested request is part of. */
    public function recording(): bool
    {
        for ($state = $this->state(); $state !== null; $state = $state->parent) {
            if ($state->suspended > 0) {
                return false;
            }
            if ($state->frames !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Makes the current frame part of the page $parent renders (a request asked for inside a
     * visitor's render or a rebuild): what it reads and the expiries it asks for reach $parent's
     * open recordings too. The application calls it for a nested request that is not internal.
     *
     * @internal Application
     * @since 0.81.0
     */
    public function nestIn(\Preflow\Core\Context\RenderFrame $parent): void
    {
        $this->state()->parent = $parent->slot(RecordingState::class, static fn (): RecordingState => new RecordingState());
    }

    /**
     * Runs $fn with nothing recorded in the current frame, nor forwarded to the page it is part of:
     * a live part being filled is the visitor's, never a dependency of a page.
     *
     * @internal LiveParts
     * @since 0.81.0
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function suspended(\Closure $fn): mixed
    {
        $state = $this->state();
        $state->suspended++;
        try {
            return $fn();
        } finally {
            $state->suspended--;
        }
    }

    /**
     * The earliest expiry asked for in recording $frame (the innermost one when null) so far,
     * a unix time; null when none was. Ask before stop().
     */
    public function expiry(?int $frame = null): ?float
    {
        $state = $this->state();
        $frame ??= count($state->frames) - 1;

        return $state->expiries[$frame] ?? null;
    }

    /**
     * The earliest expiry recording $frame ignored because it lay at or before its start (a past
     * event date), null when none did. Ask before stop().
     */
    public function ignoredExpiry(?int $frame = null): ?float
    {
        $state = $this->state();
        $frame ??= count($state->frames) - 1;

        return $state->ignored[$frame] ?? null;
    }

    /**
     * The page being recorded must be refreshed by $at at the latest (an event date): every
     * open recording of the current frame keeps the earliest. Outside a recording it does nothing.
     */
    public function expiresAt(\DateTimeInterface|float|int $at): void
    {
        $at = $at instanceof \DateTimeInterface ? (float) $at->format('U.u') : (float) $at;
        for ($state = $this->state(); $state !== null && $state->suspended === 0; $state = $state->parent) {
            foreach ($state->expiries as $i => $known) {
                if ($state->starts[$i] !== null && $at <= $state->starts[$i]) {
                    $state->ignored[$i] = min($state->ignored[$i] ?? $at, $at); // already past when this render started

                    continue;
                }
                $state->expiries[$i] = $known === null ? $at : min($known, $at);
            }
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
        $state = $this->state();
        $state->scopes[] = [$state->freshness, $state->seconds];
        $state->freshness = $freshness;
        $state->seconds = $freshness === Freshness::Timed ? $seconds : null;
    }

    /** Closes the scope enter() opened last. */
    public function leave(): void
    {
        $state = $this->state();
        $before = array_pop($state->scopes);
        if ($before !== null) {
            [$state->freshness, $state->seconds] = $before;
        }
    }

    /** Notes reads of $tags (with "any record of its table" for a record, DataTags::dependencies()). */
    public function record(string ...$tags): void
    {
        $own = $this->state();
        [$freshness, $seconds] = self::freshnessOf($own);
        for ($state = $own; $state !== null && $state->suspended === 0; $state = $state->parent) {
            if ($state->frames === []) {
                continue;
            }
            foreach ($tags as $tag) {
                foreach (DataTags::dependencies($tag) as $dependency) {
                    foreach ($state->frames as $i => $frame) {
                        $state->frames[$i][$dependency] = isset($frame[$dependency])
                            ? self::merged($frame[$dependency], $freshness, $seconds)
                            : [$freshness, $seconds];
                    }
                }
            }
        }
    }

    /**
     * The freshness reads in $state are recorded with: its own scope's, else (a nested request
     * with no scope of its own) the scope around the request in the page it is part of.
     *
     * @return array{0: Freshness, 1: ?int}
     */
    private static function freshnessOf(RecordingState $state): array
    {
        while ($state->scopes === [] && $state->parent !== null) {
            $state = $state->parent;
        }

        return [$state->freshness, $state->seconds];
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
