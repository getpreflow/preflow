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

        return new MigrateCommand(function () use ($dir, $pkg): Application {
            $app = Application::create($dir);
            $app->registerProvider(new class($pkg) extends ServiceProvider {
                public function __construct(private readonly string $pkg) {}
                public function register(Container $container): void {}
                public function boot(Container $container): void
                {
                    $registry = $container->get(MigrationRegistry::class);
                    $registry->addPath($this->pkg);
                    $registry->afterMigrate('seed', static fn (): array => ['seeded 3 rows']);
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
    }

    public function test_runs_after_migrate_steps_and_prints_changes(): void
    {
        $this->sqliteConfig();

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('seeded 3 rows', $out);
    }

    public function test_pretend_runs_nothing(): void
    {
        $this->sqliteConfig();

        [$code, $out] = $this->migrate(['--pretend']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2026_01_01_000001_create_apps.php', $out);
        $this->assertStringContainsString('2026_01_01_000002_create_pkgs.php', $out);
        $this->assertStringNotContainsString('seeded', $out);
        $this->assertNotContains('apps', $this->tables());
    }

    public function test_json_only_site_exits_0(): void
    {
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code);
        $this->assertSame("Nothing to migrate: no SQL storage configured.\n", $out);
        $this->assertFileDoesNotExist($this->dir . '/app.sqlite');

        unlink($this->dir . '/config/data.php'); // no data configuration at all: no SQL storage either
        $this->assertSame([0, "Nothing to migrate: no SQL storage configured.\n"], $this->migrate());
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
}
