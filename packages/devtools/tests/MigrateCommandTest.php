<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\DevTools\Command\MigrateCommand;

final class MigrateCommandTest extends TestCase
{
    private string $dir;

    /** @var array<string, array{0: list<string>, 1: bool}> further steps: name => [lines, sqlOnly] */
    private array $steps = [];

    /** @var array<string, array{0: list<string>, 1: bool}> further checks (--pretend): name => [lines, sqlOnly] */
    private array $checks = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrate_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/migrations', 0777, true);
        mkdir($this->dir . '/pkg_migrations', 0777, true);
        $this->migration($this->dir . '/migrations/2026_01_01_000001_create_apps.php', 'apps');
        $this->migration($this->dir . '/pkg_migrations/2026_01_01_000002_create_pkgs.php', 'pkgs');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function migration(string $file, string $table): void
    {
        file_put_contents($file, <<<PHP
        <?php
        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        return new class extends Migration {
            public function up(Schema \$schema): void
            {
                \$schema->create('{$table}', function (\$t) { \$t->uuid('uuid')->primary(); });
            }
        };
        PHP);
    }

    private function sqliteConfig(): void
    {
        file_put_contents(
            $this->dir . '/config/data.php',
            '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];',
        );
    }

    private function command(): MigrateCommand
    {
        $dir = $this->dir;
        $pkg = $this->dir . '/pkg_migrations';
        $steps = $this->steps;
        $checks = $this->checks;

        return new MigrateCommand(function () use ($dir, $pkg, $steps, $checks): Application {
            $app = Application::create($dir);
            $app->registerProvider(new class($pkg, $steps, $checks) extends ServiceProvider {
                /**
                 * @param array<string, array{0: list<string>, 1: bool}> $steps
                 * @param array<string, array{0: list<string>, 1: bool}> $checks
                 */
                public function __construct(private readonly string $pkg, private readonly array $steps, private readonly array $checks) {}
                public function register(Container $container): void {}
                public function boot(Container $container): void
                {
                    $registry = $container->get(MigrationRegistry::class);
                    $registry->addPath($this->pkg);
                    $registry->afterMigrate('seed', static fn (): array => ['seeded 3 rows']);
                    $registry->afterMigrate('marks', static fn (): array => ['marked 2 rows'], sqlOnly: false);
                    foreach ($this->steps as $name => [$lines, $sqlOnly]) {
                        $registry->afterMigrate($name, static fn (): array => $lines, sqlOnly: $sqlOnly);
                    }
                    foreach ($this->checks as $name => [$lines, $sqlOnly]) {
                        $registry->check($name, static fn (): array => $lines, sqlOnly: $sqlOnly);
                    }
                }
            });
            $app->boot();

            return $app;
        });
    }

    /** @return array{0:int,1:string} */
    private function migrate(array $args = []): array
    {
        ob_start();
        $code = $this->command()->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    private function tables(): array
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/app.sqlite');

        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function test_runs_app_and_package_migrations(): void
    {
        $this->sqliteConfig();

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code);
        $this->assertContains('apps', $this->tables());
        $this->assertContains('pkgs', $this->tables());
        $this->assertContains('preflow_throttle', $this->tables());
        $this->assertContains('auth_tokens', $this->tables());
        $this->assertContains('auth_remember', $this->tables());
        $this->assertStringContainsString('5 pending migration(s)', $out);
        // The activity log's tables, by the post-migrate step (0.77).
        $this->assertContains('preflow_activity', $this->tables());
        $this->assertContains('preflow_activity_last', $this->tables());
        $this->assertStringContainsString('[activity.table] created table preflow_activity', $out);
    }

    public function test_runs_after_migrate_steps_and_prints_changes(): void
    {
        $this->sqliteConfig();

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[seed] seeded 3 rows', $out);
        $this->assertStringContainsString('[marks] marked 2 rows', $out, 'a storage-neutral step runs on SQL too');
    }

    public function test_a_refused_conversion_exits_one_after_all_steps(): void
    {
        $this->sqliteConfig();
        $this->steps = [
            'convert' => [['refused: people.title: 1 value is there more than once when case is ignored; the column keeps comparing exactly until they differ', '  "Müller" in r1, r2'], true],
            'later' => [['ran after it'], true],
        ];

        [$code, $out] = $this->migrate();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("[convert] refused: people.title: 1 value is there more than once", $out);
        $this->assertStringContainsString('[convert]   "Müller" in r1, r2', $out);
        $this->assertStringContainsString('[later] ran after it', $out, 'the later steps still ran');
        $this->assertStringEndsWith("Some values are there more than once under the text rule (see \"refused:\" above); nothing was changed or removed. Rename one record of each group, then run migrate again.\n", $out);
        $this->assertStringNotContainsString('Error', $out);
    }

    public function test_a_json_only_site_with_a_refusal_exits_one(): void
    {
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');
        $this->steps = ['check' => [['refused: people.title: 1 value is there more than once when case is ignored; saving one of these records is refused until they differ'], false]];

        [$code, $out] = $this->migrate();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('[check] refused: people.title', $out);
    }

    public function test_pretend_runs_the_read_only_checks(): void
    {
        $this->sqliteConfig();
        $this->checks = ['would' => [['refused: people.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ', '  "Müller" in r1, r2'], true]];

        [$code, $out] = $this->migrate(['--pretend']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('[would]   "Müller" in r1, r2', $out);
        $this->assertStringContainsString('Rename one record of each group, then run migrate again.', $out);
        $this->assertStringNotContainsString('seeded', $out, 'no step ran');
        $this->assertNotContains('apps', $this->tables());
    }

    /** The real text-rule steps on a fresh SQLite site: the migrations' tables are recorded, nothing refused. */
    public function test_a_fresh_site_records_its_tables_and_has_nothing_to_refuse(): void
    {
        $this->sqliteConfig();

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('refused:', $out);
        $this->assertStringNotContainsString('no recorded text rule', $out);
        $pdo = new \PDO('sqlite:' . $this->dir . '/app.sqlite');
        $this->assertSame(['apps', 'pkgs'], $pdo->query("SELECT DISTINCT table_name FROM preflow_migration_columns WHERE table_name IN ('apps', 'pkgs') ORDER BY table_name")->fetchAll(\PDO::FETCH_COLUMN));
        [$code, $out] = $this->migrate(['--pretend']);
        $this->assertSame([0, "Nothing to migrate.\n"], [$code, $out]);
    }

    public function test_pretend_runs_nothing(): void
    {
        $this->sqliteConfig();

        [$code, $out] = $this->migrate(['--pretend']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2026_01_01_000001_create_apps.php', $out);
        $this->assertStringContainsString('2026_01_01_000002_create_pkgs.php', $out);
        $this->assertStringNotContainsString('seeded', $out);
        $this->assertStringNotContainsString('marked', $out);
        $this->assertNotContains('apps', $this->tables());
    }

    public function test_json_only_site_runs_the_storage_neutral_steps_and_exits_0(): void
    {
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code);
        // No schema migrations and no SQL-only step (seed); the step registered with sqlOnly: false runs.
        $this->assertSame("No SQL storage configured: no schema migrations.\n[marks] marked 2 rows\nDone.\n", $out);
        $this->assertFileDoesNotExist($this->dir . '/app.sqlite');

        [$code, $out] = $this->migrate(['--pretend']);
        $this->assertSame([0, "No SQL storage configured: no schema migrations.\n"], [$code, $out], '--pretend runs no step');

        unlink($this->dir . '/config/data.php'); // no data configuration at all: no SQL storage either
        $this->assertSame([0, "Nothing to migrate: no SQL storage configured.\n"], $this->migrate());
    }

    public function test_default_storage_is_the_sql_storage_the_site_has(): void
    {
        $json = new \Preflow\Data\Driver\JsonFileDriver($this->dir . '/data');
        $sql = new \Preflow\Data\Driver\SqliteDriver(new \PDO('sqlite::memory:'));
        $other = new \Preflow\Data\Driver\SqliteDriver(new \PDO('sqlite::memory:'));
        $dm = static fn (array $drivers): \Preflow\Data\DataManager => new \Preflow\Data\DataManager($drivers);

        // The default storage when it is SQL; else the one SQL storage (a MySQL site with JSON content).
        $this->assertSame('default', MigrateCommand::defaultStorage($dm(['sqlite' => $sql, 'default' => $sql])));
        $this->assertSame('mysql', MigrateCommand::defaultStorage($dm(['json' => $json, 'default' => $json, 'mysql' => $sql])));
        $this->assertSame('sqlite', MigrateCommand::defaultStorage($dm(['json' => $json, 'default' => $json, 'sqlite' => $sql, 'throttle' => $sql])));
        // Two SQL storages and a JSON default: the site has to say which.
        $this->assertNull(MigrateCommand::defaultStorage($dm(['json' => $json, 'default' => $json, 'sqlite' => $sql, 'mysql' => $other])));
    }

    public function test_explicit_storage_that_is_unknown_or_not_sql_exits_1(): void
    {
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');

        foreach (['--storage=sqlite', '--storage=json'] as $arg) {
            [$code, $out] = $this->migrate([$arg]);
            $this->assertSame(1, $code, $arg);
            $this->assertStringNotContainsString('seeded', $out, $arg);
        }
    }

    public function test_aliases_of_one_sql_storage_count_once(): void
    {
        $json = new \Preflow\Data\Driver\JsonFileDriver($this->dir . '/data');
        $sqlite = new \Preflow\Data\Driver\SqliteDriver(new \PDO('sqlite::memory:'));

        $dm = new \Preflow\Data\DataManager(['default' => $json, 'mysql' => $sqlite, 'db' => $sqlite]);

        $this->assertSame('mysql', MigrateCommand::defaultStorage($dm));
    }

    public function test_a_storage_without_pdo_connection_is_not_migrated(): void
    {
        $plain = new \Preflow\Testing\PlainDriver(new \Preflow\Data\Driver\SqliteDriver(new \PDO('sqlite::memory:')));
        $dir = $this->dir;
        $command = new MigrateCommand(static function () use ($dir, $plain): Application {
            $app = Application::create($dir);
            $app->boot();
            $app->container()->instance(\Preflow\Data\DataManager::class, new \Preflow\Data\DataManager(['plain' => $plain], 'plain'));

            return $app;
        });
        $this->sqliteConfig();

        ob_start();
        $code = $command->execute(['--storage=plain']);
        ob_end_clean();

        $this->assertSame(1, $code);
    }
}
