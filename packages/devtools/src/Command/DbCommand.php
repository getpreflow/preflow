<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Dumps;
use Preflow\Data\Maintenance;
use Preflow\Data\MaintenanceReport;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\TextRuleConversion;

/**
 * The database commands that run through the storage's own, prepared connection, where the
 * database's tools cannot (a SQLite file's text columns use the preflow_text collation, which the
 * sqlite3 tool lacks: its VACUUM, PRAGMA integrity_check and restoring a .dump fail there):
 *
 *   db:check    Maintenance::check(): every problem found, exit 1 when there is one; then the
 *               MaintenanceReport lines of a store that has them (MySQL: CHECK TABLE is the check,
 *               a collation report and what is not applicable follow); then, as
 *               warnings, the text columns of Preflow's tables that compare neither by the text
 *               rule nor exactly (TextRuleConversion::unconverted())
 *   db:vacuum   Maintenance::compact()
 *   db:dump     Dumps::dump() to a file, or to standard output without one
 *   db:restore  Dumps::restore() from a file ("-": standard input) into an empty database
 *
 * Each takes --storage=<name>; without it, the default storage when it has the capability, else
 * the one storage that has it. A storage without the capability (JSON files; MySQL/MariaDB has db:check and
 * db:vacuum since 0.84, not dump and restore: mysqldump works on Preflow's tables) is refused with a pointer to those tools. Messages go to
 * standard error, so a dump to standard output stays clean.
 *
 * @since 0.78
 */
final class DbCommand implements CommandInterface
{
    private const array COMMANDS = [
        'db:check' => [Maintenance::class, 'Check the database through Preflow\'s connection (integrity and foreign keys; --storage=<name>)'],
        'db:vacuum' => [Maintenance::class, 'Compact the database through Preflow\'s connection (--storage=<name>)'],
        'db:dump' => [Dumps::class, 'Write the database as text db:restore reads ([file], default standard output; --storage=<name>)'],
        'db:restore' => [Dumps::class, 'Restore a db:dump into an empty database (<file>, "-" for standard input; --storage=<name>)'],
    ];

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application
     * @param resource|null $stdout where a dump without a file goes (default STDOUT)
     * @param resource|null $stdin what restore reads for "-" (default STDIN)
     * @param resource|null $stderr where messages go (default STDERR)
     */
    public function __construct(
        private readonly string $name,
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stdout = null,
        private readonly mixed $stdin = null,
        private readonly mixed $stderr = null,
    ) {
        if (!isset(self::COMMANDS[$name])) {
            throw new \InvalidArgumentException("Unknown database command \"{$name}\".");
        }
    }

    /** @return list<self> one per database command */
    public static function all(): array
    {
        return array_map(static fn (string $name): self => new self($name), array_keys(self::COMMANDS));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return self::COMMANDS[$this->name][1];
    }

    public function execute(array $args): int
    {
        $storage = null;
        $file = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--storage=')) {
                $storage = substr($arg, strlen('--storage='));
            } elseif ($file === null) {
                $file = $arg;
            }
        }

        try {
            $app = $this->app();
            if (!is_file($app->basePath('config/data.php'))) {
                throw new \RuntimeException('No data configuration found (config/data.php).');
            }
            $dm = $app->container()->get(DataManager::class);
            $capability = self::COMMANDS[$this->name][0];
            [$storage, $driver] = $this->storage($dm, $capability, $storage);

            return match ($this->name) {
                'db:check' => $this->check($storage, $driver, $this->collations($app, $dm)),
                'db:vacuum' => $this->vacuum($storage, $driver),
                'db:dump' => $this->dump($storage, $driver, $file),
                'db:restore' => $this->restore($storage, $driver, $file),
            };
        } catch (\Throwable $e) {
            $this->say('Error: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * The text rule's conversion as `preflow migrate` sees it: on the storage migrate runs the
     * migrations on without --storage (MigrateCommand::defaultStorage()), where the framework's
     * declared tables and the tables with recorded intent are. Null without the data layer's
     * conversion (an application that does not bind it).
     */
    private function collations(Application $app, DataManager $dm): ?TextRuleConversion
    {
        $container = $app->container();
        if (!$container->bound(TextRuleConversion::class)) {
            return null;
        }
        $registry = $container->get(MigrationRegistry::class);
        if ($registry->storage() === null) {
            $registry->ranOn(MigrateCommand::defaultStorage($dm));
        }

        return $container->get(TextRuleConversion::class);
    }

    /** Text columns that compare neither by the rule nor exactly are listed as warnings: they do not fail the check. */
    private function check(string $storage, Maintenance $driver, ?TextRuleConversion $collations): int
    {
        $problems = $driver->check();
        foreach ($problems as $problem) {
            $this->say("[{$storage}] {$problem}");
        }
        if ($driver instanceof MaintenanceReport) {
            foreach ($driver->report() as $line) {
                $this->say("[{$storage}] {$line}"); // information, not problems: never fails the check (0.84)
            }
        }
        foreach ($collations?->unconverted($storage) ?? [] as $warning) {
            $this->say("[{$storage}] {$warning}");
        }
        $this->say($problems === [] ? "[{$storage}] No problems found." : "[{$storage}] " . count($problems) . ' problem(s) found.');

        return $problems === [] ? 0 : 1;
    }

    private function vacuum(string $storage, Maintenance $driver): int
    {
        $driver->compact();
        $this->say("[{$storage}] Compacted.");

        return 0;
    }

    private function dump(string $storage, Dumps $driver, ?string $file): int
    {
        if ($file === null) {
            $driver->dump($this->stdout ?? STDOUT);

            return 0;
        }
        if (file_exists($file)) {
            throw new \RuntimeException("{$file} exists; choose a new file.");
        }
        $out = @fopen($file, 'x') ?: throw new \RuntimeException("Cannot write {$file}.");
        try {
            $driver->dump($out);
        } catch (\Throwable $e) {
            fclose($out);
            unlink($file);
            throw $e;
        }
        fclose($out);
        $this->say("[{$storage}] Dumped to {$file}.");

        return 0;
    }

    private function restore(string $storage, Dumps $driver, ?string $file): int
    {
        if ($file === null) {
            throw new \RuntimeException('Name the dump to restore (a file, or "-" for standard input).');
        }
        if ($file === '-') {
            $driver->restore($this->stdin ?? STDIN);
        } else {
            $in = @fopen($file, 'r') ?: throw new \RuntimeException("Cannot read {$file}.");
            try {
                $driver->restore($in);
            } finally {
                fclose($in);
            }
        }
        $this->say("[{$storage}] Restored.");

        return 0;
    }

    /**
     * @param class-string<Maintenance|Dumps> $capability
     * @return array{0: string, 1: Maintenance&Dumps}
     */
    private function storage(DataManager $dm, string $capability, ?string $storage): array
    {
        if ($storage !== null) {
            $driver = $dm->driver($storage);
            if (!$driver instanceof $capability) {
                throw new \RuntimeException("Storage [{$storage}] has no {$this->name}: its driver does not implement {$capability}. " . self::elsewhere());
            }

            return [$storage, $driver];
        }
        $names = $dm->driverNames();
        if (in_array('default', $names, true) && $dm->driver('default') instanceof $capability) {
            return ['default', $dm->driver('default')];
        }
        $found = [];
        foreach ($names as $name) {
            $driver = $dm->driver($name);
            if ($driver instanceof $capability && !in_array($driver, $found, true)) {
                $found[$name] = $driver;
            }
        }
        if (count($found) === 1) {
            return [(string) array_key_first($found), reset($found)];
        }
        if ($found === []) {
            throw new \RuntimeException("No storage here has {$this->name}. " . self::elsewhere());
        }

        throw new \RuntimeException('Several storages have ' . $this->name . ' (' . implode(', ', array_keys($found)) . '); choose one with --storage=<name>.');
    }

    private static function elsewhere(): string
    {
        return 'Use the database\'s own tools where they work on Preflow\'s tables (MySQL/MariaDB: mysqlcheck, mysqldump; JSON files: copy the folder).';
    }

    private function say(string $line): void
    {
        fwrite($this->stderr ?? STDERR, $line . "\n");
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
