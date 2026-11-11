<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Extension\Extension;
use Preflow\Folio\Extension\ExtensionDefinition;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Extension\ModelExtension;

/**
 * folio:extensions:uninstall <name> [--force]: runs the extension's uninstall() in this environment
 * and forgets its installed version, so a later apply installs it afresh. Only a disabled extension
 * that is installed here, whose types no site model file extends; asks first unless --force.
 * Nothing else ever calls uninstall().
 */
final class ExtensionsUninstallCommand implements CommandInterface
{
    private const string USAGE = 'Usage: preflow folio:extensions:uninstall <name> [--force]';

    /** @var resource */
    private $input;

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (default: the working directory's)
     * @param resource|null                  $input      where the confirmation is read (default: STDIN)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        $input = null,
    ) {
        $this->input = $input ?? STDIN;
    }

    public function getName(): string { return 'folio:extensions:uninstall'; }
    public function getDescription(): string { return 'Run a disabled extension\'s uninstall() and forget its installed version (<name> [--force])'; }

    public function execute(array $args): int
    {
        $names = [];
        $force = false;
        $bad = false;
        foreach ($args as $arg) {
            if ($arg === '--force') {
                $force = true;
            } elseif (str_starts_with($arg, '-')) {
                $bad = true;
            } else {
                $names[] = $arg;
            }
        }
        if ($bad || count($names) !== 1) {
            echo self::USAGE . "\n";

            return 1;
        }
        $name = $names[0];

        try {
            $app = $this->app();
        } catch (\Throwable $e) {
            echo "The app does not boot, so nothing was uninstalled: {$e->getMessage()}\n";

            return 1;
        }
        $c = $app->container();
        $registry = $c->get(ExtensionRegistry::class);
        $definition = self::find($registry, $name);
        if ($definition === null) {
            echo "Unknown extension \"{$name}\".\n";

            return 1;
        }
        if ($registry->isEnabled($name)) {
            echo "Can't uninstall {$name}: it is enabled. Run preflow folio:extensions:disable {$name} first.\n";

            return 1;
        }
        $modelsPath = $app->config()->get('data.models_path');
        try {
            ModelExtension::assertCanDisable($definition, null, is_string($modelsPath) ? $modelsPath : $app->basePath('config/models'));
        } catch (ExtensionException $e) {
            echo str_replace("Can't disable", "Can't uninstall", $e->getMessage()) . "\n";

            return 1;
        }
        $state = $c->get(ExtensionState::class);
        $installed = $state->installedVersion($name);
        if ($installed === null) {
            echo "Extension \"{$name}\" is not installed here: nothing to uninstall.\n";

            return 1;
        }
        if (!$force) {
            echo "Uninstall {$name} {$installed}? Its uninstall() runs and may delete its data; this cannot be undone. [y/N] ";
            $answer = strtolower(trim((string) fgets($this->input)));
            if ($answer !== 'y' && $answer !== 'yes') {
                echo "\nCancelled. (Without a terminal to answer, run it with --force.)\n";

                return 1;
            }
        }

        $extension = $c->get($definition->class);
        assert($extension instanceof Extension);
        try {
            $extension->uninstall($c);
        } catch (\Throwable $e) {
            echo "{$name}: uninstall() failed, its installed version is kept: {$e->getMessage()}\n";

            return 1;
        }
        $state->forget($name);
        echo "uninstalled {$name} {$installed}\n";

        return 0;
    }

    private static function find(ExtensionRegistry $registry, string $name): ?ExtensionDefinition
    {
        foreach ($registry->all() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        return null;
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
