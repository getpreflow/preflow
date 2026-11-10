<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Routing\RouteEntry;
use Preflow\Routing\Router;

/**
 * routes:list prints the routes of the booted app, in the order they match: file pages,
 * attribute controllers, and the routes packages add (Folio's admin and frontend, media), then
 * the component endpoint. Redirects are not routes (the redirect middleware answers them first).
 * When the app can't boot, it lists the file pages alone and says so.
 */
final class RoutesListCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'routes:list'; }
    public function getDescription(): string { return 'List all registered routes'; }

    public function execute(array $args): int
    {
        $rows = [];
        try {
            $app = $this->app();
            $container = $app->container();
            $router = $container->has(RouterInterface::class) ? $container->get(RouterInterface::class) : null;
            if ($router instanceof Router) {
                foreach ($router->getCollection()->all() as $entry) {
                    $rows[] = self::row($entry);
                }
            }
            if ($container->has('preflow.component_endpoint')) {
                $rows[] = ['ANY', '/--component/*', 'endpoint', get_class($container->get('preflow.component_endpoint'))];
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'The app did not boot (' . $e->getMessage() . "); listing the file pages only.\n");
            $rows = [];
            $pagesDir = (getcwd() ?: '.') . '/app/pages';
            if (is_dir($pagesDir)) {
                foreach ((new \Preflow\Routing\FileRouteScanner($pagesDir))->scan() as $entry) {
                    $rows[] = self::row($entry);
                }
            }
        }

        // A long pattern widens its column rather than running into the mode.
        $width = max(35, ...array_map(static fn (array $row): int => mb_strlen($row[1]) + 2, $rows ?: [['', '']]));
        $line = str_repeat('─', $width + 35) . "\n";
        echo "Registered Routes\n";
        echo $line;
        echo str_pad('Method', 8) . str_pad('Pattern', $width) . str_pad('Mode', 12) . "Handler\n";
        echo $line;
        foreach ($rows as [$method, $pattern, $mode, $handler]) {
            echo str_pad($method, 8) . str_pad($pattern, $width) . str_pad($mode, 12) . $handler . "\n";
        }
        echo $line;

        return 0;
    }

    /** @return array{string, string, string, string} */
    private static function row(RouteEntry $entry): array
    {
        return [$entry->method, $entry->pattern, $entry->mode->value, $entry->handler];
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
