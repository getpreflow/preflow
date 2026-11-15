<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Application;
use Preflow\DevTools\Command\ActivityPruneCommand;
use Preflow\DevTools\Console;

/** activity:prune applies the log's retention (config/activity.php) and prints the count. */
final class ActivityPruneCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/activity_prune_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'key' => 'a-real-secret-key'];\n");
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "json", "drivers" => ["json" => ["path" => ' . var_export($this->dir . '/data', true) . ']]];');
        file_put_contents($this->dir . '/config/activity.php', "<?php\nreturn ['keep_rows' => 2];\n");
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

    public function test_prunes_beyond_the_kept_rows(): void
    {
        $log = $this->app()->container()->get(ActivityLog::class);
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $log->record(new Activity('page', $id, $id, ActivityKind::Created, at: '2026-11-15T10:00:0' . ord($id) % 10 . '+00:00'));
        }

        ob_start();
        $code = (new ActivityPruneCommand(fn (): Application => $this->app()))->execute([]);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame("activity:prune — deleted 3 rows\n", $out);
        $this->assertCount(2, $this->app()->container()->get(ActivityLog::class)->recent(10)->items);
    }

    public function test_is_a_built_in_command(): void
    {
        ob_start();
        (new Console($this->dir))->run(['preflow', 'help']);
        $this->assertStringContainsString('activity:prune', (string) ob_get_clean());
    }
}
