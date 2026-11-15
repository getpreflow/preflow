<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * Keeps nothing: the log of a site without data storage (core's binding until the data layer
 * binds its own).
 *
 * @since 0.77
 */
final class NullActivityLog implements ActivityLog
{
    public function record(Activity $activity): void
    {
    }

    public function recent(int $limit, ?array $subjects = null, ?string $before = null): ActivityPage
    {
        return new ActivityPage();
    }
}
