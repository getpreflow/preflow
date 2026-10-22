<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionsFile;

/**
 * folio:extensions:enable and :disable: `<name> [--part=<id>]` written to config/folio/extensions.json.
 * Disabling a whole extension never boots the app, so it also recovers one that breaks boot
 * or whose folder is gone; enabling and part changes validate against the booted registry.
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
        $file = new ExtensionsFile($basePath . '/config/folio/extensions.json');

        if (!$this->on() && $part === null) {
            if (!$file->remove($name)) {
                echo "Extension \"{$name}\" is not enabled in config/folio/extensions.json.\n";

                return 1;
            }
            echo "Disabled {$name} in config/folio/extensions.json" . ($this->discovered($basePath, $name) ? '' : " ({$name} is not discovered; its stale entry was removed)") . "\n";

            return 0;
        }

        try {
            $app = Application::create($basePath);
            $app->boot();
            $definition = $app->container()->get(ExtensionRegistry::class)->get($name);
            $this->on() ? $file->enable($definition, $part) : $file->disable($definition, $part);
        } catch (ExtensionException $e) {
            echo $e->getMessage() . "\n";

            return 1;
        }
        echo ucfirst($verb) . 'd ' . ($part === null ? $name : "{$name} part {$part}") . " in config/folio/extensions.json\n";

        return 0;
    }

    /** Whether an extension of this name is discovered (after its entry is gone, so the file can't fail discovery); true when discovery itself fails. */
    private function discovered(string $basePath, string $name): bool
    {
        try {
            $registry = ExtensionRegistry::discover($basePath, debug: true);
        } catch (\Throwable) {
            return true;
        }
        foreach ($registry->all() as $definition) {
            if ($definition->name === $name) {
                return true;
            }
        }

        return false;
    }
}
