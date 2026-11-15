<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * A page of ActivityLog::recent(), newest first. $next is the cursor for the page after this
 * one (pass it as $before), null on the last page. A cursor is opaque: keep it as it is.
 *
 * @since 0.77
 */
final readonly class ActivityPage
{
    /** @param list<Activity> $items */
    public function __construct(
        public array $items = [],
        public ?string $next = null,
    ) {}
}
