<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Extension\ExtensionDefinition;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionsFile;
use Preflow\Folio\Extension\ModelExtension;

/**
 * folio:extensions:enable and :disable: `<name> [--part=<id>]` written to config/folio/extensions.json.
 * Disabling a whole extension never boots the app, so it also recovers one that breaks boot
 * or whose folder is gone; enabling and part changes validate against the booted registry
 * (except enabling what a site model file extends while boot fails for it: the recovery).
 * Nothing a site model file extends ("extends": true) is disabled.
 */
abstract class ExtensionsToggleCommand
{
    /** @param string|null $basePath the app root (default: the working directory) */
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    abstract protected function on(): bool;

    /** @param string[] $args */
    public function execute(array $args): int
    {
        $verb = $this->on() ? 'enable' : 'disable';
        $names = [];
        $part = null;
        $bad = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--part=') && $part === null) {
                $part = substr($arg, 7);
                $bad = $bad || $part === '';
            } elseif (str_starts_with($arg, '-')) {
                $bad = true; // unknown option, a bare --part or a second --part
            } else {
                $names[] = $arg;
            }
        }
        if ($bad || count($names) !== 1) {
            echo "Usage: preflow folio:extensions:{$verb} <name> [--part=<id>]\n";

            return 1;
        }
        $name = $names[0];
        $basePath = rtrim($this->basePath ?? (getcwd() ?: '.'), '/');
        try {
            $modelsPath = Application::create($basePath)->config()->get('data.models_path');
        } catch (\Throwable) {
            $modelsPath = null; // a config that fails to load must not block the recovery disable
        }
        $modelsPath = is_string($modelsPath) ? $modelsPath : $basePath . '/config/models';
        $file = new ExtensionsFile($basePath . '/config/folio/extensions.json', $modelsPath);

        if (!$this->on() && $part === null) {
            $definition = $this->discovered($basePath, $name); // fails (true) while the file has a stale entry: nothing to check then
            try {
                if ($definition instanceof ExtensionDefinition) {
                    ModelExtension::assertCanDisable($definition, null, $modelsPath);
                }
            } catch (ExtensionException $e) {
                echo $e->getMessage() . "\n";

                return 1;
            }
            if (!$file->remove($name)) {
                echo "Extension \"{$name}\" is not enabled in config/folio/extensions.json.\n";

                return 1;
            }
            echo "Disabled {$name} in config/folio/extensions.json" . ($this->discovered($basePath, $name) !== null ? '' : " ({$name} is not discovered; its stale entry was removed)") . "\n";

            return 0;
        }

        try {
            try {
                $app = Application::create($basePath);
                $app->boot();
                $definition = $app->container()->get(ExtensionRegistry::class)->get($name);
            } catch (FrontendConfigException $e) {
                $definition = $this->on() ? $this->extendedBy($basePath, $name, $part, $modelsPath) : null;
                if ($definition === null) {
                    throw $e;
                }
            }
            $this->on() ? $file->enable($definition, $part) : $file->disable($definition, $part);
        } catch (ExtensionException $e) {
            echo $e->getMessage() . "\n";

            return 1;
        }
        echo ucfirst($verb) . 'd ' . ($part === null ? $name : "{$name} part {$part}") . " in config/folio/extensions.json\n";

        return 0;
    }

    /**
     * The discovered extension of this name, null when there is none; true when discovery itself
     * fails (after its entry is gone, so the file can't fail discovery).
     */
    private function discovered(string $basePath, string $name): ExtensionDefinition|true|null
    {
        try {
            $registry = ExtensionRegistry::discover($basePath, debug: true);
        } catch (\Throwable) {
            return true;
        }
        foreach ($registry->all() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Boot fails while a site model file extends a type of a switched-off extension (or part):
     * enabling that one is the recovery, so it goes ahead on discovery alone.
     */
    private function extendedBy(string $basePath, string $name, ?string $part, string $modelsPath): ?ExtensionDefinition
    {
        $definition = $this->discovered($basePath, $name);
        if (!$definition instanceof ExtensionDefinition) {
            return null;
        }
        $patched = array_intersect(ModelExtension::typeKeys($definition, $part), array_keys(ModelExtension::files($modelsPath)));

        return $patched !== [] ? $definition : null;
    }
}
