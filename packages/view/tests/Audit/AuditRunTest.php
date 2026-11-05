<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\AuditRun;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuditRunTest extends TestCase
{
    private string $dir;
    private string $file;
    private \DateTimeImmutable $clock;
    /** Seconds each render takes on the fake clock. */
    private int $renderSeconds = 10;
    /** @var list<string> paths rendered, in order */
    private array $rendered = [];
    /** @var array<string, int> path => status other than 200 */
    private array $statuses = [];
    /** @var array<string, list<array{0: string, 1: string}>> locale => [title, url] */
    private array $pages;
    private AuditRegistry $registry;
    private CountingRunCheck $runCheck;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/audit_run_' . bin2hex(random_bytes(4));
        $this->file = $this->dir . '/storage/data/seo-audit.json';
        $this->clock = new \DateTimeImmutable('2026-11-05T10:00:00+00:00');
        $this->pages = [
            'en' => [['Home', 'https://site.test/'], ['About', '/about']],
            'de' => [['Start', 'https://site.test/de']],
        ];

        $this->registry = new AuditRegistry();
        $this->registry->addPage(new class implements PageCheck {
            public function id(): string { return 'page.seen'; }
            public function check(RenderedPage $page): array
            {
                return [new Finding('page.seen', Severity::Notice, 'Seen.', $page->url, $page->locale)];
            }
        });
        $this->runCheck = new CountingRunCheck();
        $this->registry->addRun($this->runCheck);
        $this->registry->addSite(new class implements SiteCheck {
            public function id(): string { return 'site.once'; }
            public function check(PageRenderer $renderer): array
            {
                return [new Finding('site.once', Severity::Warning, 'Site-wide.')];
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/storage/data/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir . '/storage/data');
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    private function auditRun(): AuditRun
    {
        $renderer = new PageRenderer(
            function (ServerRequestInterface $request): ResponseInterface {
                $path = $request->getUri()->getPath();
                $this->rendered[] = $path;
                $this->clock = $this->clock->modify('+' . $this->renderSeconds . ' seconds');
                $status = $this->statuses[$path] ?? 200;

                return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><head><title>t</title></head><body>' . $path . '</body></html>');
            },
            'https://site.test',
            locales: ['en', 'de'],
            defaultLocale: 'en',
        );
        $public = new PublicPages();
        // Listed in order (PublicPages sorts by order, then title).
        $public->source(fn (string $locale): array => array_map(
            static fn (array $p, int $i): PublicPage => new PublicPage($p[0], $p[1], order: $i),
            $this->pages[$locale] ?? [],
            array_keys($this->pages[$locale] ?? []),
        ));

        return new AuditRun($this->registry, $renderer, $public, $this->file, fn (): \DateTimeImmutable => $this->clock);
    }

    /** @return array<string, int> check id => number of findings */
    private static function checks(AuditReport $report): array
    {
        $ids = array_count_values(array_map(static fn (Finding $f): string => $f->check, $report->findings));
        ksort($ids);

        return $ids;
    }

    public function test_a_fresh_run_over_three_pages_writes_a_complete_report(): void
    {
        $progress = [];
        $report = $this->auditRun()->run(600, null, null, false, static function (int $done, int $total, string $url) use (&$progress): void {
            $progress[] = "$done/$total $url";
        });

        $this->assertSame(['/', '/about', '/de'], $this->rendered);
        $this->assertSame(['1/3 https://site.test/', '2/3 https://site.test/about', '3/3 https://site.test/de'], $progress);
        $this->assertTrue($report->complete());
        $this->assertSame(3, $report->total);
        $this->assertSame(3, $report->done());
        $this->assertSame(['page.seen' => 3, 'run.count' => 1, 'site.once' => 1], self::checks($report));
        $this->assertSame(['critical' => 0, 'warning' => 1, 'notice' => 4], $report->counts());
        $this->assertSame('3 pages', $report->findings[array_key_last($report->findings) - 1]->message);

        $json = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame('complete', $json['status']);
        $this->assertSame('2026-11-05T10:00:00+00:00', $json['started_at']);
        $this->assertSame('2026-11-05T10:00:30+00:00', $json['finished_at']);
        $this->assertSame('https://site.test', $json['origin']);
        $this->assertSame(['total' => 3, 'done' => 3], $json['pages']);
        $this->assertSame(['critical' => 0, 'warning' => 1, 'notice' => 4], $json['counts']);
        $this->assertCount(5, $json['findings']);
        // Pages keep the locale they were listed under.
        $this->assertSame(['en', 'en', 'de'], array_column(array_slice($json['findings'], 0, 3), 'locale'));
    }

    public function test_a_budget_stop_leaves_an_incomplete_report_and_the_next_run_completes_it(): void
    {
        // Each render takes 10 s: after the first page, another one would pass the 15 s budget.
        $report = $this->auditRun()->run(15, null, null, false, null);

        $this->assertFalse($report->complete());
        $this->assertSame(1, $report->done());
        $this->assertSame(3, $report->total);
        $this->assertSame(['/'], $this->rendered);
        $this->assertSame(['page.seen' => 1], self::checks($report));

        $json = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame('incomplete', $json['status']);
        $this->assertNull($json['finished_at']);
        $this->assertSame(['total' => 3, 'done' => 1], $json['pages']);
        $this->assertSame([['/', 'en'], ['/about', 'en'], ['/de', 'de']], $json['state']['queue']);
        $this->assertSame(1, $json['state']['cursor']);
        $this->assertSame(['run.count' => ['https://site.test/']], $json['state']['run']);

        // A new process: a new run check that only knows what the report kept.
        $this->runCheck = new CountingRunCheck();
        $this->registry = new AuditRegistry();
        $this->registry->addPage(new class implements PageCheck {
            public function id(): string { return 'page.seen'; }
            public function check(RenderedPage $page): array
            {
                return [new Finding('page.seen', Severity::Notice, 'Seen.', $page->url, $page->locale)];
            }
        });
        $this->registry->addRun($this->runCheck);
        $second = $this->auditRun()->run(600, null, null, false, null);

        $this->assertSame(['/', '/about', '/de'], $this->rendered);
        $this->assertTrue($second->complete());
        $this->assertSame(3, $second->done());
        $this->assertSame(['page.seen' => 3, 'run.count' => 1], self::checks($second));
        $this->assertSame('3 pages', $second->findings[3]->message);
        $this->assertSame('2026-11-05T10:00:00+00:00', $second->startedAt);
    }

    public function test_progress_is_saved_while_the_run_goes_on(): void
    {
        $saved = [];
        $this->auditRun()->run(600, null, null, false, function (int $done) use (&$saved): void {
            $saved[$done] = AuditReport::load($this->file)?->done();
        });

        // Pages take 10 s each, so the run saves after every page but the last (then it finishes and saves).
        $this->assertSame([1 => null, 2 => 1, 3 => 2], $saved);
    }

    public function test_every_run_renders_at_least_one_page(): void
    {
        $report = $this->auditRun()->run(1, null, null, false, null);

        $this->assertSame(1, $report->done());
        $this->assertFalse($report->complete());
    }

    public function test_fresh_after_a_complete_run_starts_over(): void
    {
        $this->auditRun()->run(600, null, null, false, null);
        $this->pages['en'][] = ['Contact', '/contact'];

        $report = $this->auditRun()->run(600, null, null, true, null);

        $this->assertSame(['/', '/about', '/de', '/', '/about', '/contact', '/de'], $this->rendered);
        $this->assertTrue($report->complete());
        $this->assertSame(4, $report->total);
        $this->assertSame(['page.seen' => 4, 'run.count' => 1, 'site.once' => 1], self::checks($report));
        $this->assertSame('4 pages', $report->findings[4]->message);
        $this->assertSame('2026-11-05T10:00:30+00:00', $report->startedAt);
    }

    public function test_a_run_after_a_complete_one_starts_over_too(): void
    {
        $this->auditRun()->run(600, null, null, false, null);

        $report = $this->auditRun()->run(600, null, null, false, null);

        $this->assertCount(6, $this->rendered);
        $this->assertSame(['page.seen' => 3, 'run.count' => 1, 'site.once' => 1], self::checks($report));
    }

    public function test_fresh_drops_an_incomplete_run(): void
    {
        $this->auditRun()->run(15, null, null, false, null);

        $report = $this->auditRun()->run(600, null, null, true, null);

        $this->assertSame(['/', '/', '/about', '/de'], $this->rendered);
        $this->assertSame(['page.seen' => 3, 'run.count' => 1, 'site.once' => 1], self::checks($report));
        $this->assertSame('3 pages', $report->findings[3]->message);
    }

    public function test_a_report_for_another_origin_is_not_continued(): void
    {
        $other = new AuditReport('2026-11-01T00:00:00+00:00', 'https://elsewhere.test');
        $other->queue = [['/old', 'en'], ['/older', 'en']];
        $other->total = 2;
        $other->save($this->file);

        $report = $this->auditRun()->run(600, null, null, false, null);

        $this->assertSame(['/', '/about', '/de'], $this->rendered);
        $this->assertSame('https://site.test', $report->origin);
    }

    public function test_a_page_that_fails_gets_one_critical_finding_and_the_run_goes_on(): void
    {
        $this->statuses['/about'] = 500;
        $this->statuses['/de'] = 404;

        $report = $this->auditRun()->run(600, null, null, false, null);

        $this->assertTrue($report->complete());
        $this->assertSame(['/', '/about', '/de'], $this->rendered);
        $this->assertSame(['page.seen' => 1, 'run.count' => 1, 'site.once' => 1, 'status.error' => 2], self::checks($report));
        $errors = array_values(array_filter($report->findings, static fn (Finding $f): bool => $f->check === 'status.error'));
        $this->assertSame(Severity::Critical, $errors[0]->severity);
        $this->assertSame('https://site.test/about', $errors[0]->url);
        $this->assertSame('en', $errors[0]->locale);
        $this->assertStringContainsString('500', $errors[0]->message);
        $this->assertSame(Severity::Critical, $errors[1]->severity);
        $this->assertSame('de', $errors[1]->locale);
        $this->assertStringContainsString('404', $errors[1]->message);
        // The run check saw only the page that answered 200.
        $this->assertSame('1 pages', array_values(array_filter($report->findings, static fn (Finding $f): bool => $f->check === 'run.count'))[0]->message);
    }

    public function test_page_checks_skip_a_page_that_is_not_html(): void
    {
        $registry = $this->registry;
        $public = new PublicPages();
        $public->source(static fn (): array => [new PublicPage('Feed', '/feed.json')]);
        $renderer = new PageRenderer(
            static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], '{}'),
            'https://site.test',
        );

        $report = (new AuditRun($registry, $renderer, $public, $this->file, fn (): \DateTimeImmutable => $this->clock))->run(600, null, null, false, null);

        $this->assertSame(['run.count' => 1, 'site.once' => 1], self::checks($report));
        $this->assertSame('0 pages', $report->findings[0]->message);
    }

    public function test_a_page_listed_twice_is_audited_once(): void
    {
        $this->pages['de'][] = ['About', 'https://site.test/about'];

        $report = $this->auditRun()->run(600, null, null, false, null);

        $this->assertSame(['/', '/about', '/de'], $this->rendered);
        $this->assertSame(3, $report->total);
    }

    public function test_a_limit_audits_the_first_pages_only(): void
    {
        $report = $this->auditRun()->run(600, 2, null, false, null);

        $this->assertSame(['/', '/about'], $this->rendered);
        $this->assertTrue($report->complete());
        $this->assertSame(2, $report->total);
        $this->assertSame(['page.seen' => 2, 'run.count' => 1, 'site.once' => 1], self::checks($report));
    }

    public function test_one_url_audits_that_page_only_and_leaves_the_report_file_alone(): void
    {
        $this->auditRun()->run(15, null, null, false, null);
        $saved = file_get_contents($this->file);

        $report = $this->auditRun()->run(600, null, 'https://site.test/de?x=1', false, null);

        $this->assertSame(['/', '/de'], $this->rendered);
        $this->assertTrue($report->complete());
        $this->assertSame(1, $report->total);
        // A one-page check: no site-wide checks, and the saved (incomplete) run is kept to continue.
        $this->assertSame(['page.seen' => 1, 'run.count' => 1], self::checks($report));
        $this->assertSame('de', $report->findings[0]->locale);
        $this->assertSame($saved, file_get_contents($this->file));
    }

    public function test_a_failing_check_is_reported_and_the_run_goes_on(): void
    {
        $this->registry->addPage(new class implements PageCheck {
            public function id(): string { return 'page.broken'; }
            public function check(RenderedPage $page): array { throw new \RuntimeException('oops'); }
        });

        $report = $this->auditRun()->run(600, null, null, false, null);

        $this->assertTrue($report->complete());
        $this->assertSame(['page.broken' => 3, 'page.seen' => 3, 'run.count' => 1, 'site.once' => 1], self::checks($report));
        $broken = array_values(array_filter($report->findings, static fn (Finding $f): bool => $f->check === 'page.broken'))[0];
        $this->assertSame(Severity::Warning, $broken->severity);
        $this->assertStringContainsString('oops', $broken->message);
    }
}

/** Counts the pages it saw; its state is the list of their URLs. */
final class CountingRunCheck implements RunCheck
{
    /** @var list<string> */
    private array $urls = [];

    public function id(): string { return 'run.count'; }

    public function collect(RenderedPage $page): void
    {
        $this->urls[] = $page->url;
    }

    public function finish(): array
    {
        return [new Finding('run.count', Severity::Notice, count($this->urls) . ' pages')];
    }

    public function state(): array
    {
        return $this->urls;
    }

    public function restore(array $state): void
    {
        $this->urls = array_values(array_map('strval', $state));
    }
}
