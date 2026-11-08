<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Core\PageCache\Refresher;

/**
 * pages:work [--budget=50]: rebuilds the pages the page cache queued (writes, stale pages, a
 * deploy's warm-up) for up to the budget in seconds. For the cron, every minute:
 *
 *     * * * * * cd /path/to/site/current && php preflow pages:work >/dev/null 2>&1
 *
 * Rebuilds render through the whole app as anonymous visitors (preflow/core's Refresher::work()).
 * The queue's leases keep it from building a page the work after a response is building.
 */
final class PagesWorkCommand implements CommandInterface
{
    public const float DEFAULT_BUDGET = 50.0;

    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'pages:work'; }

    public function getDescription(): string { return 'Rebuild the pages the page cache queued, for the cron (pages:work [--budget=50] seconds)'; }

    public function execute(array $args): int
    {
        $budget = self::DEFAULT_BUDGET;
        foreach ($args as $arg) {
            $value = PagesRefreshCommand::budget($arg);
            if ($value === null) {
                echo "Unknown option: {$arg} (pages:work [--budget=SECONDS])\n";

                return 1;
            }
            $budget = $value;
        }

        try {
            $app = $this->app();
            $container = $app->container();
            if (!$container->bound(Refresher::class)) {
                echo "The page cache is off: nothing to rebuild.\n";

                return 0;
            }
            if (!$container->bound(PageCacheMiddleware::class)) {
                echo "The page cache does not store pages with app.debug on: nothing rebuilt.\n";

                return 0;
            }
            $refresher = $container->get(Refresher::class);
            $started = microtime(true);
            $rebuilt = $refresher->work($budget);
            printf("Rebuilt %d page(s) in %.1f s; %d queued.\n", $rebuilt, microtime(true) - $started, $refresher->queued());

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");

            return 1;
        }
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
