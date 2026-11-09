<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Data\DataManager;

/**
 * Runs the seeders in app/Seeds through the booted application: each is built by the
 * container (constructor injection), gets the container's DataManager (so #[Validate]
 * rules apply) and sees .env, which Application::create loads.
 */
final class SeedCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'db:seed'; }
    public function getDescription(): string { return 'Run database seeders (--class=<Name>)'; }

    public function execute(array $args): int
    {
        $class = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--class=')) {
                $class = substr($arg, strlen('--class='));
            }
        }

        $seederDir = (getcwd() ?: '.') . '/app/Seeds';
        if ($this->appFactory === null && !is_dir($seederDir)) {
            echo "No seeders directory found (app/Seeds/).\n";
            return 0;
        }

        try {
            $app = $this->app();
            $seederDir = $app->basePath('app/Seeds');
            if (!is_dir($seederDir)) {
                echo "No seeders directory found (app/Seeds/).\n";
                return 0;
            }
            if (!is_file($app->basePath('config/data.php'))) {
                fwrite(STDERR, "Error: config/data.php not found.\n");
                return 1;
            }
            $container = $app->container();
            $data = $container->get(DataManager::class);

            if ($class !== null) {
                $name = str_contains($class, '\\') ? ltrim($class, '\\') : 'App\\Seeds\\' . $class;
                if (!class_exists($name)) {
                    echo "Seeder {$class} not found.\n";
                    return 1;
                }
                $classes = [$class => $name];
            } else {
                $classes = [];
                $files = glob($seederDir . '/*.php') ?: [];
                sort($files);
                foreach ($files as $file) {
                    $short = basename($file, '.php');
                    if (class_exists('App\\Seeds\\' . $short)) {
                        $classes[$short] = 'App\\Seeds\\' . $short;
                    }
                }
            }

            echo "Running seeders...\n";
            foreach ($classes as $label => $name) {
                try {
                    $container->make($name)->run($data);
                } catch (\Throwable $e) {
                    echo "Seeder {$label} failed: {$e->getMessage()}\n";
                    return 1;
                }
                echo "  Seeded: {$label}\n";
            }

            echo "Done.\n";
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
