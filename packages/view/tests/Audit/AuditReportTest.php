<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\Target;

final class AuditReportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/audit_report_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir . '/data');
        @rmdir($this->dir);
    }

    public function test_the_default_file_is_in_the_apps_data_storage(): void
    {
        $this->assertSame('/srv/site/storage/data/seo-audit.json', AuditReport::file('/srv/site'));
        $this->assertSame('/srv/site/storage/data/seo-audit.json', AuditReport::file('/srv/site/'));
    }

    public function test_saves_and_loads_the_report_in_its_schema(): void
    {
        $report = new AuditReport('2026-11-05T10:00:00+00:00', 'https://site.test');
        $report->queue = [['/', 'en'], ['/de', 'de']];
        $report->total = 2;
        $report->cursor = 1;
        $report->run = ['title.duplicate' => ['seen' => ['x']]];
        $report->findings = [
            new Finding('title.missing', Severity::Critical, 'No title.', 'https://site.test/', 'en', new Target('record', 'page', '7')),
            new Finding('alt.missing', Severity::Notice, 'No alt.', 'https://site.test/', 'en'),
        ];
        $file = $this->dir . '/data/seo-audit.json';

        $report->save($file);

        $json = json_decode((string) file_get_contents($file), true);
        $this->assertSame(1, $json['version']);
        $this->assertSame('2026-11-05T10:00:00+00:00', $json['started_at']);
        $this->assertNull($json['finished_at']);
        $this->assertSame('incomplete', $json['status']);
        $this->assertSame('https://site.test', $json['origin']);
        $this->assertSame(['total' => 2, 'done' => 1], $json['pages']);
        $this->assertSame(['critical' => 1, 'warning' => 0, 'notice' => 1], $json['counts']);
        $this->assertSame('title.missing', $json['findings'][0]['check']);
        $this->assertSame(['kind' => 'record', 'type' => 'page', 'id' => '7'], $json['findings'][0]['target']);
        $this->assertSame([['/', 'en'], ['/de', 'de']], $json['state']['queue']);
        $this->assertSame(1, $json['state']['cursor']);
        $this->assertSame(['title.duplicate' => ['seen' => ['x']]], $json['state']['run']);

        $loaded = AuditReport::load($file);
        $this->assertNotNull($loaded);
        $this->assertSame($json, $loaded->toArray());
        $this->assertEquals($report->findings, $loaded->findings);
    }

    public function test_an_empty_run_state_stays_an_object_in_json(): void
    {
        $file = $this->dir . '/r.json';
        (new AuditReport('2026-11-05T10:00:00+00:00', 'https://site.test'))->save($file);

        $this->assertStringContainsString('"run": {}', (string) file_get_contents($file));
    }

    public function test_a_missing_broken_or_other_version_file_loads_as_null(): void
    {
        @mkdir($this->dir, 0777, true);

        $this->assertNull(AuditReport::load($this->dir . '/none.json'));

        file_put_contents($this->dir . '/broken.json', '{not json');
        $this->assertNull(AuditReport::load($this->dir . '/broken.json'));

        file_put_contents($this->dir . '/v2.json', json_encode(['version' => 2]));
        $this->assertNull(AuditReport::load($this->dir . '/v2.json'));
    }

    public function test_the_file_is_replaced_atomically(): void
    {
        $file = $this->dir . '/data/seo-audit.json';
        $first = new AuditReport('2026-11-05T10:00:00+00:00', 'https://first.test');
        $first->save($file);
        // A second name for the first file: a rename swaps the directory entry and leaves it as it was,
        // writing in place would change it too.
        link($file, $this->dir . '/data/old.json');

        (new AuditReport('2026-11-05T11:00:00+00:00', 'https://second.test'))->save($file);

        $this->assertSame('https://second.test', AuditReport::load($file)?->origin);
        $this->assertSame('https://first.test', AuditReport::load($this->dir . '/data/old.json')?->origin);
        // No temp file left behind.
        $this->assertSame(['old.json', 'seo-audit.json'], array_values(array_diff(scandir($this->dir . '/data') ?: [], ['.', '..'])));
    }
}
