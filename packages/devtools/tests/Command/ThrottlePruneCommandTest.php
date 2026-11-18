<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;
use Preflow\DevTools\Command\ThrottlePruneCommand;

/** throttle:prune deletes the rows whose window has ended and prints the count. */
final class ThrottlePruneCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/throttle_prune_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'key' => 'a-real-secret-key'];\n");
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
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

    public function test_deletes_the_rows_whose_window_has_ended(): void
    {
        $dm = $this->app()->container()->get(\Preflow\Data\DataManager::class);
        $this->app()->container()->get(Throttle::class)->hit('contact', 'new', 3600);
        $past = new Throttle($dm, 'a-real-secret-key', 'default', static fn (): int => time() - 7200);
        $past->hit('contact', 'old', 3600); // its window ended an hour ago; nothing counted since

        ob_start();
        $code = (new ThrottlePruneCommand(fn (): Application => $this->app()))->execute([]);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame("throttle:prune — deleted 1 row\n", $out);
        $this->assertCount(1, $dm->queryIn($past->definition())->get());
    }

    public function test_every_prunes_repeatedly_and_returns_before_a_minute(): void
    {
        $now = 1000.0;
        $sleeps = [];
        $command = new ThrottlePruneCommand(
            fn (): Application => $this->app(),
            static function () use (&$now): float { return $now; },
            static function (int $seconds) use (&$now, &$sleeps): void { $sleeps[] = $seconds; $now += $seconds; },
        );

        ob_start();
        $code = $command->execute(['--every=30']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame([30], $sleeps);
        $this->assertSame(2, substr_count($out, 'throttle:prune — deleted'));
        $this->assertLessThan(55, $now - 1000.0);
    }

    public function test_every_one_stops_before_the_next_run_would_start_at_55_seconds(): void
    {
        $now = 0.0;
        $sleeps = [];
        $command = new ThrottlePruneCommand(
            fn (): Application => $this->app(),
            static function () use (&$now): float { return $now; },
            static function (int $seconds) use (&$now, &$sleeps): void { $sleeps[] = $seconds; $now += $seconds; },
        );

        ob_start();
        $command->execute(['--every=1']);
        $out = (string) ob_get_clean();

        $this->assertCount(54, $sleeps);
        $this->assertSame(55, substr_count($out, 'throttle:prune — deleted'));
    }

    public function test_without_every_it_prunes_once_and_never_sleeps(): void
    {
        $sleeps = 0;
        $command = new ThrottlePruneCommand(fn (): Application => $this->app(), null, static function () use (&$sleeps): void { $sleeps++; });

        ob_start();
        $command->execute([]);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $sleeps);
        $this->assertSame(1, substr_count($out, 'throttle:prune — deleted'));
    }

    /** @return iterable<string, array{string}> */
    public static function badValues(): iterable
    {
        yield 'zero' => ['--every=0'];
        yield 'sixty' => ['--every=60'];
        yield 'negative' => ['--every=-5'];
        yield 'text' => ['--every=soon'];
        yield 'empty' => ['--every='];
        yield 'fraction' => ['--every=1.5'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function test_bad_every_values_are_refused_without_pruning(string $arg): void
    {
        $built = false;
        $command = new ThrottlePruneCommand(function () use (&$built): Application { $built = true; return $this->app(); });

        ob_start();
        $code = $command->execute([$arg]);
        ob_end_clean();

        $this->assertSame(1, $code);
        $this->assertFalse($built);
    }
}
