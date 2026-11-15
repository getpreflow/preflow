<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Application;

/**
 * activity:prune — applies the activity log's retention (ActivityLog::prune(): with the data
 * layer's log, config/activity.php keep_days and keep_rows). Run it nightly from cron; members:prune
 * runs it too. Prints the count only.
 *
 * @since 0.77
 */
final class ActivityPruneCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'activity:prune'; }

    public function getDescription(): string { return 'Delete activity log rows past their retention (run from cron)'; }

    public function execute(array $args): int
    {
        try {
            $app = $this->app();
            $deleted = $app->container()->get(ActivityLog::class)->prune();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'activity:prune — ' . $e->getMessage() . "\n");

            return 1;
        }
        echo "activity:prune — deleted {$deleted} " . ($deleted === 1 ? 'row' : 'rows') . "\n";

        return 0;
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }
        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
