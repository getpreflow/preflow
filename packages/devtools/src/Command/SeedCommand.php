<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Seed;

/**
 * Runs the seeders in app/Seeds through the booted application: each is built by the
 * container (constructor injection), gets the container's DataManager (so #[Validate]
 * rules apply) and sees .env, which Application::create loads. A seeder marked
 * #[Seed(debugOnly: true)] is skipped by a full run while app.debug is off; `--class` runs it
 * there only with `--force` (since 0.80.0).
 */
final class SeedCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'db:seed'; }
    public function getDescription(): string { return 'Run database seeders (--class=<Name>, --force)'; }

    public function execute(array $args): int
    {
        $class = null;
        $force = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--class=')) {
                $class = substr($arg, strlen('--class='));
            } elseif ($arg === '--force') {
                $force = true;
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
            $debug = (int) $app->config()->get('app.debug', 0) !== 0;

            if ($class !== null) {
                $name = str_contains($class, '\\') ? ltrim($class, '\\') : 'App\\Seeds\\' . $class;
                if (!class_exists($name)) {
                    echo "Seeder {$class} not found.\n";
                    return 1;
                }
                if (!$debug && !$force && self::debugOnly($name)) {
                    echo "Seeder {$class} is marked debug only and app.debug is off; pass --force to run it anyway.\n";
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
                if ($class === null && !$debug && self::debugOnly($name)) {
                    echo "  Skipped: {$label} (debug only; app.debug is off)\n";
                    continue;
                }
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

    /** @param class-string $name */
    private static function debugOnly(string $name): bool
    {
        foreach ((new \ReflectionClass($name))->getAttributes(Seed::class) as $attribute) {
            if ($attribute->newInstance()->debugOnly) {
                return true;
            }
        }

        return false;
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
