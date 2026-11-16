<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;

/**
 * throttle:prune — deletes the attempt-limit rows whose window has ended (Throttle::prune()). The
 * throttle prunes them itself on the next request that counts; run this from cron when a site
 * promises a retention ("kept for one hour") however quiet it is. Prints the count only.
 *
 * @since 0.78
 */
final class ThrottlePruneCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'throttle:prune'; }

    public function getDescription(): string { return 'Delete attempt-limit rows whose window has ended (run from cron)'; }

    public function execute(array $args): int
    {
        try {
            $app = $this->app();
            if (!$app->container()->has(Throttle::class) || !is_file($app->basePath('config/data.php'))) {
                echo "throttle:prune — no data storage configured: nothing to prune\n";

                return 0;
            }
            $deleted = $app->container()->get(Throttle::class)->prune();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'throttle:prune — ' . $e->getMessage() . "\n");

            return 1;
        }
        echo "throttle:prune — deleted {$deleted} " . ($deleted === 1 ? 'row' : 'rows') . "\n";

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
