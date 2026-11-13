<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\Target;
use Psr\Http\Message\ResponseInterface;

/**
 * Settings → SEO audit ({prefix}/seo-audit): the last report of the audit; the screen itself only
 * reads (running it: SeoAuditRunTest). Without auth (a fresh app per request) for the screen;
 * signed in for the permission.
 */
final class SeoAuditScreenTest extends TestCase
{
    use PublishingScaffold;

    private const string ASSET = '0123456789abcdef0123456789abcdef';

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function fetch(string $uri): ResponseInterface
    {
        $app = $this->booted ?? $this->app();

        return $app->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    private function file(): string
    {
        return AuditReport::file($this->dir);
    }

    /** A report as the command leaves it. */
    private function report(bool $complete = true, int $done = 12, int $total = 12): AuditReport
    {
        $report = new AuditReport('2026-11-05T09:30:00+00:00', 'https://site.test');
        $report->finishedAt = $complete ? '2026-11-05T09:34:00+00:00' : null;
        $report->total = $total;
        $report->cursor = $done;
        $report->findings = [
            new Finding('description.missing', Severity::Warning, 'The page has no meta description.', 'https://site.test/hello', 'en', new Target('record', 'post', 'p1')),
            new Finding('title.missing', Severity::Critical, 'The page has no <title>.', 'https://site.test/de/hallo', 'de', new Target('record', 'post', 'p1')),
            new Finding('og.image-alt-missing', Severity::Notice, 'The share image has no alt text.', 'https://site.test/media/2026/11/' . self::ASSET . '.jpg', '', new Target('asset', 'asset', self::ASSET)),
            new Finding('robots.missing', Severity::Warning, 'There is no robots.txt.'),
            new Finding('holz.photo-credit', Severity::Warning, 'The object has no photo credit.', 'https://site.test/objekt', 'en'),
            new Finding('description.missing', Severity::Warning, 'Sneaky.', 'javascript:alert(1)', 'en'),
        ];

        return $report;
    }

    public function test_route_asks_seo_audit(): void
    {
        $this->assertSame('seo.audit', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\SeoAuditController@index'));
    }

    public function test_permission_is_declared_for_folio(): void
    {
        $registry = $this->app()->container()->get(\Preflow\Auth\Access\Access::class)->registry();

        $this->assertTrue($registry->isDeclared('seo.audit'));
        $this->assertSame(['label' => 'SEO audit', 'group' => 'Folio'], array_intersect_key($registry->all()['seo.audit'] ?? [], ['label' => 1, 'group' => 1]));
    }

    public function test_without_a_report_it_says_how_to_run_one(): void
    {
        $res = $this->fetch('/folio/seo-audit');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#<h1>\s*SEO audit\s*</h1>#', $body);
        $this->assertStringContainsString('<code>preflow seo:audit</code>', $body);
        $this->assertStringContainsString('No audit has run', $body);
        // Under Settings in the sidebar, on every site.
        $this->assertMatchesRegularExpression('#<span class="folio-nav-label">Settings</span>.*?<a href="/folio/seo-audit" class="active"#s', $body);
        $this->assertFileDoesNotExist($this->file(), 'the screen starts no run');
    }

    public function test_an_unreadable_report_counts_as_none(): void
    {
        $this->write('storage/data/seo-audit.json', '{"version": 1, "pages": "nope"');

        $body = (string) $this->fetch('/folio/seo-audit')->getBody();

        $this->assertStringContainsString('No audit has run', $body);
    }

    public function test_a_complete_report_shows_counts_and_findings_by_check_with_links(): void
    {
        $this->report()->save($this->file());

        $res = $this->fetch('/folio/seo-audit');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('No audit has run', $body);
        $this->assertStringContainsString('https://site.test', $body);
        $this->assertStringContainsString('Complete', $body);
        $this->assertStringContainsString('12 pages', $body);
        // Counts per severity.
        $this->assertMatchesRegularExpression('#Critical\s*</span>\s*<strong[^>]*>1</strong>#', $body);
        $this->assertMatchesRegularExpression('#Warnings\s*</span>\s*<strong[^>]*>4</strong>#', $body);
        $this->assertMatchesRegularExpression('#Notices\s*</span>\s*<strong[^>]*>1</strong>#', $body);
        // Grouped by check, most serious first; a check's human label, else its id.
        $critical = strpos($body, 'Page without a title');
        $warning = strpos($body, 'Page without a description');
        $notice = strpos($body, 'Share image without alt text');
        $this->assertNotFalse($critical);
        $this->assertNotFalse($warning);
        $this->assertNotFalse($notice);
        $this->assertLessThan($warning, $critical);
        $this->assertLessThan($notice, $warning);
        $this->assertStringContainsString('<code>holz.photo-credit</code>', $body);
        $this->assertStringContainsString('robots.txt missing', $body);
        // The messages as the report has them (escaped).
        $this->assertStringContainsString('The page has no &lt;title&gt;.', $body);
        // Links: the page, the record's edit form, the asset in the library.
        $this->assertStringContainsString('<a href="https://site.test/de/hallo" target="_blank" rel="noopener">Open page</a>', $body);
        $this->assertStringContainsString('<a href="/folio/post/p1/edit">Edit</a>', $body);
        $this->assertStringContainsString('<a href="/folio/assets/' . self::ASSET . '">Open in library</a>', $body);
        $this->assertStringContainsString('<a href="https://site.test/objekt" target="_blank" rel="noopener">Open page</a>', $body);
        // A URL that is no web address is shown, not linked.
        $this->assertStringNotContainsString('href="javascript:', $body);
        $this->assertStringContainsString('javascript:alert(1)', $body);
    }

    public function test_a_long_check_shows_the_first_hundred_findings_and_says_how_many_more(): void
    {
        $report = $this->report();
        $report->findings = [];
        for ($i = 1; $i <= 150; $i++) {
            $report->findings[] = new Finding('description.missing', Severity::Warning, 'No description.', 'https://site.test/many/' . $i, 'en');
        }
        $report->save($this->file());

        $body = (string) $this->fetch('/folio/seo-audit')->getBody();

        $this->assertSame(100, substr_count($body, '<code>https://site.test/many/'));
        $this->assertStringContainsString('https://site.test/many/100<', $body);
        $this->assertStringNotContainsString('https://site.test/many/101<', $body);
        $this->assertStringContainsString('and 50 more', $body);
        // The summary still counts all of them.
        $this->assertMatchesRegularExpression('#<summary>.*?<span class="folio-count">150</span>#s', $body);
    }

    public function test_short_checks_show_every_finding_without_a_more_line(): void
    {
        $this->report()->save($this->file());

        $this->assertStringNotContainsString(' more', (string) $this->fetch('/folio/seo-audit')->getBody());
    }

    public function test_critical_checks_are_open_and_the_others_folded(): void
    {
        $this->report()->save($this->file());

        $body = (string) $this->fetch('/folio/seo-audit')->getBody();

        $this->assertMatchesRegularExpression('#<details class="folio-audit-check" open>\s*<summary>[^<]*(?:<[^s/][^>]*>[^<]*)*Page without a title#s', $body);
        $this->assertSame(1, substr_count($body, '<details class="folio-audit-check" open>'));
        $this->assertSame(4, substr_count($body, '<details class="folio-audit-check">'));
    }

    public function test_an_incomplete_report_says_how_far_it_got(): void
    {
        $this->report(complete: false, done: 3, total: 10)->save($this->file());

        $body = (string) $this->fetch('/folio/seo-audit')->getBody();

        $this->assertStringContainsString('Incomplete: 3 of 10 pages', $body);
        // Without auth everyone may run it here: Continue, not "run the command again" (read only: SeoAuditRunTest).
        $this->assertStringContainsString('>Continue</button>', $body);
        $this->assertStringNotContainsString('Run the command again to continue', $body);
    }

    public function test_a_report_without_findings_says_so(): void
    {
        $report = $this->report();
        $report->findings = [];
        $report->save($this->file());

        $this->assertStringContainsString('Nothing to fix', (string) $this->fetch('/folio/seo-audit')->getBody());
    }

    public function test_the_screen_never_writes(): void
    {
        $this->report(complete: false, done: 3, total: 10)->save($this->file());
        $before = (string) file_get_contents($this->file());
        touch($this->file(), 1_700_000_000);
        clearstatcache();

        $this->assertSame(200, $this->fetch('/folio/seo-audit')->getStatusCode());
        clearstatcache();

        $this->assertSame($before, file_get_contents($this->file()));
        $this->assertSame(1_700_000_000, filemtime($this->file()));
        $this->assertSame(['seo-audit.json'], array_values(array_filter(scandir(dirname($this->file())), static fn (string $f): bool => str_starts_with($f, 'seo-audit') || str_starts_with($f, '.seo-audit'))));
        $this->assertGreaterThanOrEqual(400, $this->post('/folio/seo-audit', [])->getStatusCode(), 'nothing to post');
        clearstatcache();
        $this->assertSame($before, file_get_contents($this->file()));
    }

    #[RunInSeparateProcess]
    public function test_users_without_seo_audit_get_403_and_no_nav_item(): void
    {
        $this->report()->save($this->file());
        $this->signInAs('editor');

        $this->assertSame(403, $this->fetch('/folio/seo-audit')->getStatusCode());
        $this->assertStringNotContainsString('href="/folio/seo-audit"', (string) $this->fetch('/folio/post')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_users_with_seo_audit_see_it_and_links_they_may_follow(): void
    {
        $this->report()->save($this->file());
        $this->signInAs('auditor');

        $res = $this->fetch('/folio/seo-audit');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#<a href="/folio/seo-audit" class="active"#', $body);
        // May view posts, not the asset library: no link to it.
        $this->assertStringContainsString('<a href="/folio/post/p1/edit">Edit</a>', $body);
        $this->assertStringNotContainsString('/folio/assets/' . self::ASSET, $body);
    }

    /** Signed in with auth and roles: "auditor" may see the audit and posts, "editor" only posts. */
    private function signInAs(string $role): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'auditor@site.test', 'password_hash' => $hash, 'roles' => ['auditor']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_seo_audit_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'auditor' => ['folio.dashboard', 'post.view', 'seo.audit'],
            'editor' => ['folio.dashboard', 'post.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $this->booted = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->booted->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $this->booted->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => $role . '@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }
}
