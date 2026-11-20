<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * What DependencyRecorder records into for one render frame (a frame slot): the open recordings
 * and the freshness scopes. A render in a frame of its own (a rebuild, verify's second render, an
 * audit page) starts with none, so nothing it reads counts for the page of the frame around it.
 * A request asked for inside a visitor's render (Application::handle() in a page) is part of that
 * page, as before 0.81: its state forwards what it reads and the expiries it asks for to $parent.
 *
 * @internal DependencyRecorder
 * @since 0.81.0
 */
final class RecordingState
{
    /** @var list<array<string, array{0: Freshness, 1: ?int}>> the open recordings, innermost last */
    public array $frames = [];

    public Freshness $freshness = Freshness::Fresh;

    public ?int $seconds = null;

    /** @var list<?float> the earliest expiry of each open recording (unix time), parallel to $frames */
    public array $expiries = [];

    /** @var list<?float> when each open recording's render started (start()), parallel to $frames */
    public array $starts = [];

    /** @var list<?float> the earliest expiry each open recording ignored (before its start) */
    public array $ignored = [];

    /** @var list<array{0: Freshness, 1: ?int}> the freshness scopes around the current one (enter()) */
    public array $scopes = [];

    /** The state of the frame this one is part of (a nested request), which gets its reads too. */
    public ?RecordingState $parent = null;

    /** Nothing is recorded, here or forwarded, while above 0 (LiveParts::fill()). */
    public int $suspended = 0;
}
