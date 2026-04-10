<?php

declare(strict_types=1);

namespace Preflow\DevTools;

final class Console
{
    /** @var array<string, Command\CommandInterface> */
    private array $commands = [];

    public function __construct()
    {
        $this->register(new Command\ServeCommand());
        $this->register(new Command\MigrateCommand());
        $this->register(new Command\MakeComponentCommand());
        $this->register(new Command\MakeControllerCommand());
        $this->register(new Command\MakeModelCommand());
        $this->register(new Command\MakeMigrationCommand());
        $this->register(new Command\RoutesListCommand());
        $this->register(new Command\CacheClearCommand());
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
        foreach ($this->commands as $name => $command) {
            echo "  " . str_pad($name, 22) . $command->getDescription() . "\n";
        }
        echo "\n";
    }
}
