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

    public function recent(int $limit, ?array $subjects = null, ?string $before = null, ?string $by = null): ActivityPage
    {
        return new ActivityPage();
    }

    public function people(?array $subjects = null): array
    {
        return [];
    }

    public function prune(): int
    {
        return 0;
    }

    public function forget(string $subject, string $id): void
    {
    }
}
