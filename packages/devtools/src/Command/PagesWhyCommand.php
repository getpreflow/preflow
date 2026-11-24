<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\PageCache\Cacheability;

/**
 * pages:why {path} [--locale=de]: whether the page cache would store a page, and if not, why.
 *
 * The page is rendered as the cache renders it and once more as another anonymous visitor, then
 * compared as `page_cache.verify` compares. Nothing is stored or served, so it works with
 * app.debug on, where the cache itself does not run.
 *
 * Exit codes: 0 would be cached, 1 would not be (the reason and a hint are printed), 2 a usage
 * error or a page that does not exist.
 */
final class PagesWhyCommand implements CommandInterface
{
    private const string USAGE = 'pages:why <path> [--locale=de]';

    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'pages:why'; }

    public function getDescription(): string { return 'Say whether a page would be cached, and why not: ' . self::USAGE; }

    public function execute(array $args): int
    {
        $path = null;
        $locale = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--locale=') && strlen($arg) > 9) {
                $locale = substr($arg, 9);
            } elseif (str_starts_with($arg, '--') || $path !== null) {
                echo 'Unexpected argument: ' . $arg . ' (' . self::USAGE . ")\n";

                return 2;
            } else {
                $path = $arg;
            }
        }
        if ($path === null) {
            echo 'Give the page to check: ' . self::USAGE . "\n";

            return 2;
        }

        try {
            $container = $this->app()->container();
            if (!$container->bound(Cacheability::class)) {
                echo "The page cache is off (page_cache.enabled, and pdo_sqlite): nothing would be cached.\n";

                return 1;
            }
            $result = $container->get(Cacheability::class)->check($path, $locale);
        } catch (\InvalidArgumentException $e) {
            echo $e->getMessage() . "\n";

            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");

            return 2;
        }

        if ($result->status === 404) {
            echo "Page not found: {$path} answers 404.\n";

            return 2;
        }
        if ($result->cacheable) {
            echo "{$path} would be cached.\n";

            return 0;
        }
        echo "{$path} would not be cached: {$result->reason}\n";
        if ($result->hint !== null) {
            echo "Hint: {$result->hint}\n";
        }

        return 1;
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
