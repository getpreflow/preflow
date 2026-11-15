<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Activity;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Activity\ActivityPage;
use Preflow\Core\Activity\NullActivityLog;
use Preflow\Core\Application;
use Preflow\Data\Activity\StoredActivityLog;
use Preflow\Data\Migration\MigrationRegistry;

final class ActivityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/activity_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rm = function (string $p) use (&$rm): void {
            foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (in_array(basename($f), ['.', '..'], true)) {
                    continue;
                }
                is_dir($f) ? $rm($f) : unlink($f);
            }
            @rmdir($p);
        };
        $rm($this->dir);
    }

    public function test_kinds_are_lower_case_strings(): void
    {
        $this->assertSame(
            ['created', 'saved', 'published', 'unpublished', 'scheduled', 'restored', 'discarded', 'deleted', 'uploaded', 'replaced'],
            array_map(static fn (ActivityKind $k): string => $k->value, ActivityKind::cases()),
        );
        $this->assertSame(ActivityKind::Saved, ActivityKind::from('saved'));
    }

    public function test_an_activity_has_defaults(): void
    {
        $a = new Activity('page', 'p1', 'Home', ActivityKind::Saved);

        $this->assertSame('page', $a->subject);
        $this->assertSame('p1', $a->id);
        $this->assertSame('Home', $a->title);
        $this->assertSame([], $a->changed);
        $this->assertNull($a->by);
        $this->assertSame('', $a->at);
    }

    public function test_the_null_log_records_nothing(): void
    {
        $log = new NullActivityLog();
        $log->record(new Activity('page', 'p1', 'Home', ActivityKind::Created));

        $page = $log->recent(10);
        $this->assertInstanceOf(ActivityPage::class, $page);
        $this->assertSame([], $page->items);
        $this->assertNull($page->next);
        $this->assertSame(0, $log->prune());
        $this->assertSame([], $log->people());
        $this->assertSame([], $log->recent(10, null, null, 'u1')->items);
        $log->forget('page', 'p1');
    }

    public function test_core_binds_the_null_log_without_data(): void
    {
        $app = Application::create($this->dir);
        $app->boot();

        $this->assertInstanceOf(NullActivityLog::class, $app->container()->get(ActivityLog::class));
    }

    public function test_data_binds_the_stored_log_and_migrate_creates_its_table(): void
    {
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default' => 'sqlite', 'drivers' => ['sqlite' => ['path' => '{$this->dir}/app.sqlite']]];\n");
        file_put_contents($this->dir . '/config/activity.php', "<?php\nreturn ['keep_days' => 30, 'keep_rows' => 500];\n");
        $app = Application::create($this->dir);
        $app->boot();

        $log = $app->container()->get(ActivityLog::class);
        $this->assertInstanceOf(StoredActivityLog::class, $log);
        $this->assertSame($log, $app->container()->get(ActivityLog::class));
        $this->assertSame(30, $log->keepDays);
        $this->assertSame(500, $log->keepRows);

        $steps = $app->container()->get(MigrationRegistry::class)->steps();
        $this->assertArrayHasKey('activity.table', $steps);
        $this->assertContains('created table preflow_activity', $steps['activity.table']());
        $this->assertSame([], $steps['activity.table'](), 'a second run changes nothing');

        $log->record(new Activity('page', 'p1', 'Home', ActivityKind::Created, by: 'u1'));
        $items = $log->recent(5)->items;
        $this->assertCount(1, $items);
        $this->assertSame('Home', $items[0]->title);
    }

    public function test_activity_table_is_listed_for_conversions(): void
    {
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default' => 'sqlite', 'drivers' => ['sqlite' => ['path' => '{$this->dir}/app.sqlite']]];\n");
        $app = Application::create($this->dir);
        $app->boot();

        $tables = array_map(static fn (array $e): string => $e['def']->table, $app->container()->get(\Preflow\Data\Schema\RecordTables::class)->all());
        $this->assertContains('preflow_activity', $tables);
    }
}
