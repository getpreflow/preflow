<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\DevTools\Command\MigrateCommand;

/** `preflow migrate` prints the registered warnings after its steps and never lets them change the exit code. */
final class MigrateWarningsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrate_warn_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array{0: int, 1: string} */
    private function migrate(array $args = []): array
    {
        $dir = $this->dir;
        $command = new MigrateCommand(static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->registerProvider(new class extends ServiceProvider {
                public function register(Container $container): void {}
                public function boot(Container $container): void
                {
                    $registry = $container->get(MigrationRegistry::class);
                    $registry->afterMigrate('step', static fn (): array => ['stepped'], sqlOnly: false);
                    $registry->warning('layouts', static fn (): array => ['first line', 'second line']);
                    $registry->warning('broken', static fn (): array => throw new \RuntimeException('no table'));
                    $registry->warning('sql.only', static fn (): array => ['SQL only'], sqlOnly: true);
                }
            });
            $app->boot();

            return $app;
        });
        ob_start();
        $code = $command->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    private function sqlite(): void
    {
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];');
    }

    public function test_warnings_print_in_migrate_and_pretend_without_changing_the_exit_code(): void
    {
        $this->sqlite();

        [$code, $out] = $this->migrate();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("[step] stepped\n", $out);
        $this->assertStringContainsString("[layouts] warning: first line\n[layouts] warning: second line\n", $out);
        $this->assertStringContainsString('[broken] warning: could not be worked out: no table', $out);
        $this->assertStringContainsString('[sql.only] warning: SQL only', $out);
        $this->assertGreaterThan(strpos($out, '[step]'), strpos($out, '[layouts] warning'), 'after the steps');
        $this->assertStringEndsWith("Done.\n", $out);

        [$code, $out] = $this->migrate(['--pretend']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('[layouts] warning: first line', $out);
        $this->assertStringNotContainsString('stepped', $out, 'no step ran');
    }

    public function test_a_site_without_sql_prints_only_the_warnings_for_any_storage(): void
    {
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('[layouts] warning: first line', $out);
        $this->assertStringNotContainsString('SQL only', $out);
    }
}
