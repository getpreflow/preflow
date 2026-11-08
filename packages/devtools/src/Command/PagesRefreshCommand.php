<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Core\PageCache\Refresher;

/**
 * pages:refresh {url} | --all [--budget=50]: refreshes the page cache by hand.
 *
 * - pages:refresh /about (or https://example.com/about): every cached copy of that URL is
 *   forgotten (each locale and fragment variant) and the page is rebuilt;
 * - pages:refresh --all: every page is forgotten and every known full page without a query is
 *   queued and rebuilt.
 *
 * It rebuilds for up to --budget seconds; what is left is queued for `pages:work`.
 */
final class PagesRefreshCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'pages:refresh'; }

    public function getDescription(): string { return 'Refresh cached pages: pages:refresh <url> or pages:refresh --all [--budget=50]'; }

    public function execute(array $args): int
    {
        $all = false;
        $budget = PagesWorkCommand::DEFAULT_BUDGET;
        $urls = [];
        foreach ($args as $arg) {
            if ($arg === '--all') {
                $all = true;
            } elseif (str_starts_with($arg, '--')) {
                $value = self::budget($arg);
                if ($value === null) {
                    echo "Unknown option: {$arg} (pages:refresh <url> | --all [--budget=SECONDS])\n";

                    return 1;
                }
                $budget = $value;
            } else {
                $urls[] = $arg;
            }
        }
        if ($all === ($urls !== []) || count($urls) > 1) {
            echo "Give one URL or --all: pages:refresh <url> | pages:refresh --all\n";

            return 1;
        }

        try {
            $container = $this->app()->container();
            if (!$container->bound(Refresher::class)) {
                echo "The page cache is off: nothing to refresh.\n";

                return 0;
            }
            $refresher = $container->get(Refresher::class);
            if ($all) {
                $queued = $refresher->refreshAll();
                echo "Forgot every page; {$queued} queued for rebuilding.\n";
            } else {
                try {
                    $forgotten = $refresher->refresh($urls[0]);
                } catch (\InvalidArgumentException $e) {
                    echo $e->getMessage() . "\n";

                    return 1;
                }
                echo "Forgot {$forgotten} cached cop" . ($forgotten === 1 ? 'y' : 'ies') . " of {$urls[0]}.\n";
            }
            if ($container->bound(PageCacheMiddleware::class)) {
                $rebuilt = $refresher->work($budget);
                echo "Rebuilt {$rebuilt} page(s); {$refresher->queued()} queued.\n";
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /** The seconds of a --budget=N option, null when $arg is not one (or not a positive number). */
    public static function budget(string $arg): ?float
    {
        if (!str_starts_with($arg, '--budget=')) {
            return null;
        }
        $value = substr($arg, strlen('--budget='));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
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
