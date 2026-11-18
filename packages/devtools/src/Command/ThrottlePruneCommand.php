<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;

/**
 * throttle:prune — deletes the attempt-limit rows whose window has ended (Throttle::prune()). The
 * throttle prunes them itself on the first hit of a window; run this from cron when a site
 * promises a retention ("kept for one hour") however quiet it is. Prints the count per run.
 *
 * `--every=<seconds>` (1 to 59, since 0.79.0) prunes, sleeps and prunes again until the next run
 * would start at or after 55 seconds from the start, so one cron line a minute keeps rows no
 * longer than their window plus that interval:
 * `* * * * * cd /path/to/site/current && php preflow throttle:prune --every=30`.
 *
 * @since 0.78
 */
final class ThrottlePruneCommand implements CommandInterface
{
    /** Seconds from the start after which no further run begins (one cron line a minute never overlaps). */
    private const int BUDGET_SECONDS = 55;

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param (\Closure(): float)|null $clock seconds, fractions allowed (tests); default microtime(true)
     * @param (\Closure(int): void)|null $sleeper waits that many seconds (tests); default sleep()
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $sleeper = null,
    ) {}

    public function getName(): string { return 'throttle:prune'; }

    public function getDescription(): string { return 'Delete attempt-limit rows whose window has ended (--every=<seconds> repeats within a minute; run from cron)'; }

    public function execute(array $args): int
    {
        $every = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--every=')) {
                $value = substr($arg, strlen('--every='));
                if (!preg_match('/^[1-9][0-9]?$/', $value) || (int) $value > 59) {
                    fwrite(STDERR, "throttle:prune — --every needs a whole number of seconds from 1 to 59, got [{$value}]\n");

                    return 1;
                }
                $every = (int) $value;
            }
        }

        try {
            $app = $this->app();
            if (!$app->container()->has(Throttle::class) || !is_file($app->basePath('config/data.php'))) {
                echo "throttle:prune — no data storage configured: nothing to prune\n";

                return 0;
            }
            $throttle = $app->container()->get(Throttle::class);
            $clock = $this->clock ?? static fn (): float => microtime(true);
            $sleeper = $this->sleeper ?? static function (int $seconds): void { sleep($seconds); };
            $start = $clock();
            while (true) {
                $deleted = $throttle->prune();
                echo "throttle:prune — deleted {$deleted} " . ($deleted === 1 ? 'row' : 'rows') . "\n";
                if ($every === null || ($clock() - $start) + $every >= self::BUDGET_SECONDS) {
                    break;
                }
                $sleeper($every);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'throttle:prune — ' . $e->getMessage() . "\n");

            return 1;
        }

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
