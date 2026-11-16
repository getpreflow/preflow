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
}
