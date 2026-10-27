<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Codes\Codes;
use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Migrator;

/** A temp app with the codes core on SQLite (no Folio), a controllable clock and the purposes p and t (ttl 30). */
abstract class CodesAppTestCase extends TestCase
{
    protected string $dir;
    protected \DateTimeImmutable $now;
    private ?Application $app = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/codes_core_' . bin2hex(random_bytes(4));
        $this->now = new \DateTimeImmutable('2026-10-22 12:00:00');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Codes', 'debug' => 1, 'engine' => 'twig', 'key' => 'codes-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'drivers' => ['sqlite' => ['path' => $this->dir . '/storage/app.sqlite']],
        ], true) . ";\n");
        $this->write('config/codes.php', "<?php\nreturn " . var_export([
            'purposes' => [
                'p' => ['label' => 'Plain', 'readable' => true],
                'q' => ['label' => 'Other', 'readable' => false],
                't' => ['label' => 'Ttl', 'readable' => false, 'ttl' => 30],
            ],
            'throttle' => ['max' => 10, 'window' => 900],
        ], true) . ";\n");
        @mkdir($this->dir . '/storage/locks', 0777, true);
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

    protected function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    protected function app(): Application
    {
        if ($this->app !== null) {
            return $this->app;
        }
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        $registry = $c->get(MigrationRegistry::class);
        $driver = $c->get(DataManager::class)->driver('default');
        if ($driver instanceof PdoDriver) {
            (new Migrator($driver->pdo(), $registry->paths()))->migrate();
        }
        foreach ($registry->steps() as $step) {
            $step();
        }

        return $this->app = $app;
    }

    protected function codes(): Codes
    {
        $c = $this->app()->container();

        return new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            require $this->dir . '/config/codes.php',
            fn (): \DateTimeImmutable => $this->now,
            $this->dir . '/storage/locks',
        );
    }
}
