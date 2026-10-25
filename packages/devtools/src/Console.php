<?php

declare(strict_types=1);

namespace Preflow\DevTools;

final class Console
{
    /** @var array<string, Command\CommandInterface> */
    private array $commands = [];

    /**
     * @param string|null   $basePath app root whose config/commands.php lists extra
     *                                command classes (default: the working directory)
     * @param resource|null $stderr   warnings stream (default STDERR)
     */
    public function __construct(?string $basePath = null, mixed $stderr = null)
    {
        $this->register(new Command\ServeCommand());
        $this->register(new Command\MigrateCommand());
        $this->register(new Command\MakeComponentCommand());
        $this->register(new Command\MakeControllerCommand());
        $this->register(new Command\MakeModelCommand());
        $this->register(new Command\MakeMigrationCommand());
        $this->register(new Command\RoutesListCommand());
        $this->register(new Command\CacheClearCommand());
        $this->register(new Command\CacheListCommand());
        $this->register(new Command\SeedCommand());
        $this->register(new Command\KeyGenerateCommand());
        $this->register(new Command\AuthUserCommand());
        $this->register(new Command\NotifyTestCommand());

        $basePath ??= getcwd() ?: '.';
        $this->registerPackageCommands($basePath . '/vendor/composer/installed.json', $stderr ?? STDERR);
        $this->registerAppCommands($basePath . '/config/commands.php', $stderr ?? STDERR);
    }

    /**
     * Commands installed packages bring: extra.preflow.commands in their composer.json lists
     * command classes (constructed without arguments), so a site needs no config/commands.php
     * line for them. The app's own list is registered after and wins on a name clash.
     *
     * @param resource $stderr
     */
    private function registerPackageCommands(string $installed, mixed $stderr): void
    {
        if (!is_file($installed)) {
            return;
        }
        $data = json_decode((string) file_get_contents($installed), true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            $classes = is_array($package) ? ($package['extra']['preflow']['commands'] ?? []) : [];
            $this->registerClasses(is_array($classes) ? $classes : [], 'package ' . (is_array($package) ? (string) ($package['name'] ?? '?') : '?'), $stderr);
        }
    }

    /**
     * Commands an app (or a package it uses, e.g. Folio) lists by class name;
     * each is constructed without arguments. Bad entries are warned about and
     * skipped, so one broken entry never takes the whole CLI down.
     *
     * @param resource $stderr
     */
    private function registerAppCommands(string $file, mixed $stderr): void
    {
        if (!is_file($file)) {
            return;
        }
        try {
            $classes = require $file;
        } catch (\Throwable $e) {
            fwrite($stderr, 'Warning: config/commands.php failed to load (' . $e->getMessage() . "); its commands are unavailable.\n");
            return;
        }
        $this->registerClasses(is_array($classes) ? $classes : [], 'config/commands.php', $stderr);
    }

    /**
     * @param array<mixed> $classes
     * @param resource $stderr
     */
    private function registerClasses(array $classes, string $source, mixed $stderr): void
    {
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, Command\CommandInterface::class)) {
                fwrite($stderr, "Warning: {$source} lists " . (is_string($class) ? $class : get_debug_type($class)) . ", which is not a command class; skipped.\n");
                continue;
            }
            try {
                $this->register(new $class());
            } catch (\Throwable $e) {
                fwrite($stderr, "Warning: {$source} lists {$class}, which could not be created (" . $e->getMessage() . "); skipped.\n");
            }
        }
    }

    public function register(Command\CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($name === 'help' || $name === '--help') {
            $this->printHelp();
            return 0;
        }

        if (!isset($this->commands[$name])) {
            fwrite(STDERR, "Unknown command: {$name}\n\n");
            $this->printHelp();
            return 1;
        }

        return $this->commands[$name]->execute($args);
    }

    private function printHelp(): void
    {
        echo "Preflow CLI\n\n";
        echo "Usage: preflow <command> [arguments]\n\n";
        echo "Available commands:\n";
        $width = max(22, ...array_map(static fn (string $n): int => strlen($n) + 2, array_keys($this->commands)));
        foreach ($this->commands as $name => $command) {
            echo "  " . str_pad($name, $width) . $command->getDescription() . "\n";
        }
        echo "\n";
    }
}
