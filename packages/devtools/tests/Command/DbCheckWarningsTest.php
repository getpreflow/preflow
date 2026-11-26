<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\DevTools\Command\DbCommand;

/** `db:check` prints the registered warnings after its own lines; they never fail it. */
final class DbCheckWarningsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/db_warn_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_db_check_prints_warnings_and_still_passes(): void
    {
        $dir = $this->dir;
        $err = fopen('php://memory', 'w+');
        $command = new DbCommand('db:check', static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->registerProvider(new class extends ServiceProvider {
                public function register(Container $container): void {}
                public function boot(Container $container): void
                {
                    $registry = $container->get(MigrationRegistry::class);
                    $registry->warning('layouts', static fn (): array => ['first line', 'second line']);
                    $registry->warning('broken', static fn (): array => throw new \RuntimeException('no table'));
                }
            });
            $app->boot();

            return $app;
        }, null, null, $err);

        $code = $command->execute([]);
        rewind($err);
        $out = (string) stream_get_contents($err);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("warning: [layouts] first line\nwarning: [layouts] second line\n", $out);
        $this->assertStringContainsString('warning: [broken] could not be worked out: no table', $out);
        $this->assertGreaterThan(strpos($out, 'No problems found.'), strpos($out, 'warning: [layouts]'), 'after its own lines');
    }
}
