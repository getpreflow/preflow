<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionsFile;

/** folio:extensions:enable and :disable: `<name> [--part=<id>]` written to config/folio/extensions.json. */
abstract class ExtensionsToggleCommand
{
    use BootsApplication;

    abstract protected function on(): bool;

    /** @param string[] $args */
    public function execute(array $args): int
    {
        $name = null;
        $part = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--part=')) {
                $part = substr($arg, 7);
            } elseif (!str_starts_with($arg, '--')) {
                $name ??= $arg;
            }
        }
        $verb = $this->on() ? 'enable' : 'disable';
        if ($name === null || $part === '') {
            echo "Usage: preflow folio:extensions:{$verb} <name> [--part=<id>]\n";

            return 1;
        }

        $app = $this->app();
        try {
            $definition = $app->container()->get(ExtensionRegistry::class)->get($name);
            $file = new ExtensionsFile($app->basePath('config/folio/extensions.json'));
            $this->on() ? $file->enable($definition, $part) : $file->disable($definition, $part);
        } catch (ExtensionException $e) {
            echo $e->getMessage() . "\n";

            return 1;
        }
        echo ucfirst($verb) . 'd ' . ($part === null ? $name : "{$name} part {$part}") . " in config/folio/extensions.json\n";

        return 0;
    }
}
