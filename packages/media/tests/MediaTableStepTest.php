<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Media\MediaServiceProvider;

/** `preflow migrate` creates the asset table of a site without Folio (the media.table step). */
final class MediaTableStepTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/media_migrate_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents(
            $this->dir . '/config/data.php',
            '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];',
        );
        file_put_contents($this->dir . '/config/providers.php', '<?php return [' . MediaServiceProvider::class . '::class];');
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

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @return array{0: int, 1: string} */
    private function migrate(): array
    {
        ob_start();
        $code = (new MigrateCommand(fn () => $this->app()))->execute([]);

        return [$code, (string) ob_get_clean()];
    }

    /** @return list<string> */
    private function tables(): array
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/app.sqlite');

        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function test_migrate_creates_the_asset_table_without_folio(): void
    {
        file_put_contents($this->dir . '/config/media.php', '<?php return ["storage" => "sqlite"];');

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertContains('folio_assets', $this->tables());
        $this->assertStringContainsString('created table folio_assets', $out);

        [$code, $out] = $this->migrate();
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('folio_assets', $out);
    }

    public function test_migrate_warns_once_without_bin_dir(): void
    {
        file_put_contents($this->dir . '/config/media.php', '<?php return ["storage" => "sqlite"];');

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertSame(1, substr_count($out, 'media: bin_dir is not set; deleted assets are removed for good (see the media README)'), $out);
    }

    public function test_migrate_does_not_warn_with_bin_dir(): void
    {
        file_put_contents($this->dir . '/config/media.php', '<?php return ["storage" => "sqlite", "bin_dir" => ' . var_export($this->dir . '/storage/media-bin', true) . '];');

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('bin_dir', $out);
    }

    public function test_migrate_adds_the_binned_at_column(): void
    {
        file_put_contents($this->dir . '/config/media.php', '<?php return ["storage" => "sqlite"];');

        $this->migrate();

        $pdo = new \PDO('sqlite:' . $this->dir . '/app.sqlite');
        $columns = $pdo->query('PRAGMA table_info(folio_assets)')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertContains('binned_at', array_column($columns, 'name'));
    }

    public function test_json_storage_registers_no_step(): void
    {
        $this->assertArrayNotHasKey('media.table', $this->app()->container()->get(MigrationRegistry::class)->steps());
    }

    public function test_sql_storage_registers_the_step(): void
    {
        file_put_contents($this->dir . '/config/media.php', '<?php return ["storage" => "sqlite"];');

        $this->assertArrayHasKey('media.table', $this->app()->container()->get(MigrationRegistry::class)->steps());
    }
}
