<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\PageCache\Freshness as Dial;

/**
 * The freshness of what a component reads, as a dependency of the cached page it is on
 * (Preflow\Core\PageCache\PageCache::within()), around its resolveState() and its render:
 *
 *     use Preflow\Core\PageCache\Freshness as Dial;
 *
 *     #[Freshness(Dial::NextVisit)]              // a write marks the page stale
 *     final class OtherJobs extends Component { ... }
 *
 *     #[Freshness(Dial::Timed, seconds: 43200)]  // writes ignored; the page expires after 12 h
 *     final class Ticker extends Component { ... }
 *
 * A freshness scope inside it (a template's {% freshness %}, a nested component's attribute)
 * wins for its own reads. On a #[Live] component it does nothing: a live part's reads are no
 * dependencies of the page. A parent class's attribute counts for its subclasses.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Freshness
{
    /** @throws \InvalidArgumentException Timed without seconds (or fewer than 1) */
    public function __construct(
        public readonly Dial $freshness,
        public readonly ?int $seconds = null,
    ) {
        if ($freshness === Dial::Timed && ($seconds === null || $seconds < 1)) {
            throw new \InvalidArgumentException('#[Freshness(Freshness::Timed)] needs its seconds: seconds: 43200.');
        }
    }
}
