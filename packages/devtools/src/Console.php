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
        $this->register(new Command\SeedCommand());
        $this->register(new Command\KeyGenerateCommand());
        $this->register(new Command\NotifyTestCommand());

        $this->registerAppCommands(($basePath ?? (getcwd() ?: '.')) . '/config/commands.php', $stderr ?? STDERR);
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
        foreach (is_array($classes) ? $classes : [] as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, Command\CommandInterface::class)) {
                fwrite($stderr, 'Warning: config/commands.php lists ' . (is_string($class) ? $class : get_debug_type($class)) . ", which is not a command class; skipped.\n");
                continue;
            }
            try {
                $this->register(new $class());
            } catch (\Throwable $e) {
                fwrite($stderr, "Warning: config/commands.php lists {$class}, which could not be created (" . $e->getMessage() . "); skipped.\n");
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
