<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Http\SeoAuditController;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\AuditRun;
use Preflow\View\Audit\Audits;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;
use Preflow\View\Seo\PublicPages;
use Psr\Http\Message\ResponseInterface;

/**
 * Running the SEO audit from Settings → SEO audit (seo.audit.run): run (a plain POST, one step,
 * the page with its progress), step (htmx, the partial, until HX-Redirect to the finished
 * report), the states (running elsewhere, a failed step), the permission and the CSRF check,
 * and that the audited pages render anonymously while the admin's request stays as it was.
 * Signed in on one in-process app (the session lives there).
 */
final class SeoAuditRunTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;
    private string $token = '';

    /** Steps on the fake clock: every reading is 100 s later, so a step audits exactly one page. */
    private bool $slow = false;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true];\n");
        // Who the audited page sees as signed in: the audit must render it as a visitor.
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ record.title }}</title></head><body><h1>{{ record.title }}</h1>'
            . '<p class="who">{{ auth_user() ? "user:" ~ auth_user().getAuthId() : "nobody" }}</p><p class="globals">{{ globals_probe() }}</p></body></html>');
        // A type with publishing at /a/{slug}, and a published singleton it shows: the audit must see
        // the published snapshots, not the working copies the admin's request switches on.
        $this->writeModel('banner', [
            'label' => 'Banner',
            'singleton' => true,
            'publishing' => true,
            'fields' => ['text' => ['type' => 'string']],
        ]);
        $this->writeModel('article', [
            'label' => 'Articles',
            'publishing' => true,
            'frontend' => ['route' => '/a/{slug}'],
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']], 'slug' => ['type' => 'string', 'validate' => ['required']]],
        ]);
        $this->write('resources/folio/frontend/article.twig', '<html><head><title>{{ record.title }}</title></head><body><h1 class="article">{{ record.title }}</h1><p class="banner">{{ folio_get("banner").text|default("") }}</p></body></html>');
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Folio\\Tests\\Fixtures\\Audit\\GlobalsProbeProvider::class];\n");
        $publisher = $this->publisher();
        $publisher->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]), null);
        $publisher->publish($this->working('post', 'p2', ['title' => ['en' => 'World', 'de' => 'Welt'], 'slug' => ['en' => 'world', 'de' => 'welt'], '_locales' => ['en', 'de']]), null);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function file(): string
    {
        return AuditReport::file($this->dir);
    }

    public function test_the_routes_ask_seo_audit_and_the_step_budget_is_20_seconds(): void
    {
        $this->assertSame('seo.audit', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\SeoAuditController@run'));
        $this->assertSame('seo.audit', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\SeoAuditController@step'));
        $this->assertSame(20, SeoAuditController::STEP_SECONDS);
    }

    public function test_seo_audit_run_is_declared_for_folio_with_its_label(): void
    {
        $registry = $this->app()->container()->get(\Preflow\Auth\Access\Access::class)->registry();

        $this->assertTrue($registry->isDeclared('seo.audit.run'));
        $this->assertSame(['label' => 'Run the SEO audit', 'group' => 'Folio'], array_intersect_key($registry->all()['seo.audit.run'] ?? [], ['label' => 1, 'group' => 1]));
        $labels = new \Preflow\Folio\Content\DisplayLabels($this->app()->container()->get(\Preflow\Folio\Content\TypeCatalog::class));
        $this->assertSame('Run the SEO audit', $labels->permission('seo.audit.run', 'Run the SEO audit'));
        $this->assertSame('SEO audit', $labels->permission('seo.audit', 'SEO audit'));
    }

    #[RunInSeparateProcess]
    public function test_without_a_report_an_admin_gets_run_audit(): void
    {
        $this->signIn();

        $body = (string) $this->send('GET', '/folio/seo-audit')->getBody();

        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/seo-audit/run">\s*<input type="hidden" name="_csrf_token" value="' . preg_quote($this->token, '#') . '">\s*<button type="submit" class="btn btn-primary" data-busy-label="Auditing…">Run audit</button>#', $body);
        $this->assertStringContainsString('No audit has run on this site yet.', $body);
        $this->assertStringContainsString('<code>preflow seo:audit</code>', $body, 'the command is still named');
        $this->assertFileDoesNotExist($this->file(), 'the screen starts no run');
    }

    #[RunInSeparateProcess]
    public function test_run_steps_until_complete_then_the_report(): void
    {
        $this->signIn();
        $this->slowAudits();

        // Run: one step (one page on the slow clock), the page with the progress that polls step.
        $res = $this->send('POST', '/folio/seo-audit/run');
        $body = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('<h1>', $body, 'the whole page');
        $this->assertStringContainsString('hx-post="/folio/seo-audit/step" hx-trigger="load delay:300ms"', $body);
        $this->assertMatchesRegularExpression('#1 of (\d+) pages audited#', $body);
        preg_match('#1 of (\d+) pages audited#', $body, $m);
        $total = (int) $m[1];
        $this->assertGreaterThanOrEqual(4, $total, 'two posts in two languages, and the rest of the site');
        $this->assertStringNotContainsString('Incomplete: 1 of', $body, 'no status badge to go stale while htmx steps: the progress counts');
        $this->assertStringContainsString('class="folio-audit-counts"', $body, 'the report so far below');
        $this->assertStringContainsString('<noscript>', $body);

        // Step (htmx): the partial only, one page further, still polling, in the admin's language.
        for ($done = 2; $done < $total; $done++) {
            $res = $this->send('POST', '/folio/seo-audit/step', [], htmx: true);
            $partial = (string) $res->getBody();
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringStartsWith('<div id="folio-audit-progress"', ltrim($partial));
            $this->assertStringNotContainsString('<html', $partial);
            $this->assertStringContainsString($done . ' of ' . $total . ' pages audited', $partial, 'the admin\'s locale (en) after rendering de pages');
            $this->assertStringContainsString('hx-post="/folio/seo-audit/step"', $partial);
        }

        // The last step completes the report: htmx goes to it.
        $res = $this->send('POST', '/folio/seo-audit/step', [], htmx: true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('/folio/seo-audit', $res->getHeaderLine('HX-Redirect'));
        $report = AuditReport::load($this->file());
        $this->assertNotNull($report);
        $this->assertTrue($report->complete());
        $this->assertSame($total, $report->done());

        // The finished report; Run again starts over. Still signed in.
        $body = (string) $this->send('GET', '/folio/seo-audit')->getBody();
        $this->assertStringContainsString('Complete: ' . $total . ' pages', $body);
        $this->assertMatchesRegularExpression('#<input type="hidden" name="fresh" value="1">\s*<button type="submit" class="btn btn-secondary" data-busy-label="Auditing…">Run again</button>#', $body);
        $this->assertStringNotContainsString('hx-post="/folio/seo-audit/step"', $body);
    }

    #[RunInSeparateProcess]
    public function test_a_plain_post_that_completes_redirects_to_the_report_and_pages_render_anonymously(): void
    {
        $this->signIn();
        $seen = [];
        $this->session->container()->get(AuditRegistry::class)->addPage(new class ($seen) implements PageCheck {
            /** @param list<string> $seen */
            public function __construct(private array &$seen) {}

            public function id(): string
            {
                return 'test.who';
            }

            public function check(RenderedPage $page): array
            {
                if (preg_match('#<p class="who">([^<]*)</p>#', $page->body, $m) === 1) {
                    $this->seen[] = $m[1];

                    return [new Finding('test.who', Severity::Notice, 'Seen as ' . $m[1], $page->url, $page->locale)];
                }

                return [];
            }
        });

        $res = $this->send('POST', '/folio/seo-audit/run');

        $this->assertSame(303, $res->getStatusCode());
        $this->assertSame('/folio/seo-audit', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($seen, 'the posts were audited');
        $this->assertSame(['nobody'], array_values(array_unique($seen)), 'every page rendered as a visitor');
        // The admin is still signed in, and the report shows.
        $res = $this->send('GET', '/folio/seo-audit');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Seen as nobody', (string) $res->getBody());
        $this->assertStringContainsString('Complete:', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_an_incomplete_report_offers_continue_not_the_command_again(): void
    {
        $this->signIn();
        $this->slowAudits();
        $this->send('POST', '/folio/seo-audit/run');

        $body = (string) $this->send('GET', '/folio/seo-audit')->getBody();

        $this->assertStringContainsString('Incomplete: 1 of', $body);
        $this->assertStringContainsString('>Continue</button>', $body);
        $this->assertStringNotContainsString('Run the command again to continue', $body);
        $this->assertStringContainsString('On the server it runs with <code>preflow seo:audit</code>', $body);
    }

    #[RunInSeparateProcess]
    public function test_a_step_from_folio_audits_the_published_site_not_the_drafts(): void
    {
        $publisher = $this->publisher();
        $publisher->publish($this->working('article', 'a1', ['title' => 'Published title', 'slug' => 'first']), null);
        $publisher->publish($this->working('banner', 'banner', ['text' => 'Published banner']), null);
        // Unpublished changes on top: the admin sees them in Folio, a visitor doesn't.
        $this->working('article', 'a1', ['title' => 'Draft title', 'slug' => 'first', '_version' => 2]);
        $this->working('banner', 'banner', ['text' => 'Draft banner', '_version' => 2]);
        $this->signIn();
        $titles = $this->probe('h1 class="article"');
        $banners = $this->probe('p class="banner"');

        $this->assertSame(303, $this->send('POST', '/folio/seo-audit/run')->getStatusCode());

        $this->assertSame(['Published title'], array_values(array_unique($titles->texts)), 'the published snapshot, as visitors see it');
        $this->assertSame(['Published banner'], array_values(array_unique($banners->texts)), 'a record the page shows: published too');
    }

    #[RunInSeparateProcess]
    public function test_a_step_hides_the_admins_superglobals_from_the_pages(): void
    {
        $this->signIn();
        $seen = $this->probe('p class="globals"');
        // As PHP sets them for the admin's POST.
        $_COOKIE['admin_session'] = 'secret';
        $_POST['_csrf_token'] = $this->token;
        $_REQUEST = $_COOKIE + $_POST;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_COOKIE'] = 'admin_session=secret';

        $this->assertSame(303, $this->send('POST', '/folio/seo-audit/run')->getStatusCode());

        $this->assertNotEmpty($seen->texts);
        $this->assertSame(['||127.0.0.1'], array_values(array_unique($seen->texts)), 'no cookies, no form, a local address');
        // Put back for the admin's request.
        $this->assertSame('secret', $_COOKIE['admin_session']);
        $this->assertSame('203.0.113.9', $_SERVER['REMOTE_ADDR']);
        $this->assertSame('admin_session=secret', $_SERVER['HTTP_COOKIE']);
    }

    #[RunInSeparateProcess]
    public function test_a_step_has_a_20_second_budget(): void
    {
        $this->signIn();
        // Each page takes 10 s on the clock: a budget from 20 up to 29 s audits two pages, then stops
        // (the next one would take as long as the slowest so far); 19 s would audit one.
        $this->clockedAudits(10);

        $this->assertSame(200, $this->send('POST', '/folio/seo-audit/run')->getStatusCode());

        $report = AuditReport::load($this->file());
        $this->assertNotNull($report);
        $this->assertGreaterThan(2, $report->total);
        $this->assertSame(2, $report->done());
    }

    #[RunInSeparateProcess]
    public function test_fresh_starts_over(): void
    {
        $this->signIn();
        $this->slowAudits();
        $this->send('POST', '/folio/seo-audit/run');
        $this->send('POST', '/folio/seo-audit/run');
        $this->assertSame(2, AuditReport::load($this->file())?->done());

        $this->send('POST', '/folio/seo-audit/run', ['fresh' => '1']);

        $this->assertSame(1, AuditReport::load($this->file())?->done());
    }

    #[RunInSeparateProcess]
    public function test_a_run_held_elsewhere_shows_the_notice_and_try_again(): void
    {
        $this->signIn();
        $this->slowAudits();
        $this->send('POST', '/folio/seo-audit/run');
        $before = (string) file_get_contents($this->file());
        $lock = fopen(AuditRun::lockFile($this->file()), 'c+');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $res = $this->send('POST', '/folio/seo-audit/step', [], htmx: true);
            $partial = (string) $res->getBody();
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringContainsString('<div class="folio-notice is-warning" role="status"><p>Another audit is running on this site', $partial);
            $this->assertStringContainsString('>Try again</button>', $partial);
            $this->assertStringNotContainsString('hx-post', $partial, 'no polling');
            $this->assertStringContainsString('1 of ', $partial, 'how far the saved run got');
            $this->assertSame($before, file_get_contents($this->file()), 'nothing ran');

            // The screen says so too (the lock probe), and a plain Run as well.
            $this->assertStringContainsString('Another audit is running on this site', (string) $this->send('GET', '/folio/seo-audit')->getBody());
            $res = $this->send('POST', '/folio/seo-audit/run');
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringContainsString('Another audit is running on this site', (string) $res->getBody());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertStringNotContainsString('Another audit is running', (string) $this->send('GET', '/folio/seo-audit')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_failed_step_shows_a_generic_line_and_logs_the_message(): void
    {
        $this->signIn();
        // The report can't be written: a directory where the file goes. The failure is logged.
        mkdir($this->file(), 0777, true);
        $log = $this->dir . '/php-errors.log';
        ini_set('error_log', $log);

        $res = $this->send('POST', '/folio/seo-audit/run');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert"><p>The last step failed. The details are in the server log.</p>', $body);
        $this->assertStringNotContainsString("Can't write the SEO audit report", $body, 'the message (with its server path) stays in the log');
        $this->assertStringNotContainsString($this->dir, $body);
        $this->assertStringContainsString('>Run audit</button>', $body, 'nothing saved: run again');
        $this->assertStringNotContainsString('hx-post="/folio/seo-audit/step"', $body);

        $res = $this->send('POST', '/folio/seo-audit/step', [], htmx: true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('The last step failed. The details are in the server log.', (string) $res->getBody());
        $this->assertStringNotContainsString("Can't write", (string) $res->getBody());
        $this->assertStringContainsString("SEO audit: a step run from Folio failed: RuntimeException: Can't write the SEO audit report", (string) file_get_contents($log));
    }

    #[RunInSeparateProcess]
    public function test_a_step_that_fails_where_the_last_one_did_suggests_the_command(): void
    {
        $this->signIn();
        mkdir($this->file(), 0777, true);
        ini_set('error_log', $this->dir . '/php-errors.log');

        $body = (string) $this->send('POST', '/folio/seo-audit/run')->getBody();
        $this->assertStringContainsString('<input type="hidden" name="failed_at" value="0">', $body, 'Continue says where it stood');
        $this->assertStringNotContainsString('It failed at the same place', $body, 'the first failure');

        $body = (string) $this->send('POST', '/folio/seo-audit/run', ['failed_at' => '0'])->getBody();
        $this->assertStringContainsString('It failed at the same place as the last step. If it keeps failing, run the audit on the server: <code>preflow seo:audit</code>', $body);

        // A failure elsewhere than the last one (the run got further) says nothing more.
        $body = (string) $this->send('POST', '/folio/seo-audit/run', ['failed_at' => '3'])->getBody();
        $this->assertStringNotContainsString('It failed at the same place', $body);
    }

    #[RunInSeparateProcess]
    public function test_without_seo_audit_run_no_buttons_and_403(): void
    {
        $this->signIn(['folio.dashboard', 'seo.audit']);
        $incomplete = new AuditReport('2026-11-05T09:30:00+00:00', 'https://site.test');
        $incomplete->total = 10;
        $incomplete->cursor = 3;

        $res = $this->send('GET', '/folio/seo-audit');
        $body = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('/folio/seo-audit/run', $body);
        $this->assertStringNotContainsString('<button', preg_replace('#<(aside|header|nav)\b.*?</\1>#s', '', $body) ?? '', 'no run buttons on the screen');
        $this->assertStringContainsString('No audit has run on this site yet. Run it on the server with', $body);

        $this->assertSame(403, $this->send('POST', '/folio/seo-audit/run')->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/seo-audit/step', [], htmx: true)->getStatusCode());
        $this->assertFileDoesNotExist($this->file());

        // Read only: an incomplete report says how to continue on the server.
        $incomplete->save($this->file());
        $body = (string) $this->send('GET', '/folio/seo-audit')->getBody();
        $this->assertStringContainsString('Incomplete: 3 of 10 pages', $body);
        $this->assertStringContainsString('Run the command again to continue: <code>preflow seo:audit</code>', $body);
        $this->assertStringNotContainsString('/folio/seo-audit/run', $body);
    }

    #[RunInSeparateProcess]
    public function test_without_seo_audit_the_routes_are_403(): void
    {
        $this->signIn(['folio.dashboard', 'seo.audit.run']);

        $this->assertSame(403, $this->send('POST', '/folio/seo-audit/run')->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/seo-audit/step', [], htmx: true)->getStatusCode());
        $this->assertFileDoesNotExist($this->file());
    }

    #[RunInSeparateProcess]
    public function test_without_the_csrf_token_nothing_runs(): void
    {
        $this->signIn();
        $f = new Psr17Factory();

        foreach (['/folio/seo-audit/run', '/folio/seo-audit/step'] as $uri) {
            $res = $this->session->handle($f->createServerRequest('POST', $uri)->withHeader('Sec-Fetch-Site', 'same-origin')->withHeader('HX-Request', 'true')->withCookieParams([session_name() => session_id()])->withParsedBody([]));
            $this->assertSame(403, $res->getStatusCode(), $uri);
        }
        $this->assertFileDoesNotExist($this->file());
    }

    /**
     * What the audited pages show in their first <$tag>…< (e.g. 'h1 class="x"'), in the order audited.
     */
    private function probe(string $tag): \stdClass
    {
        $seen = new \stdClass();
        $seen->texts = [];
        $this->session->container()->get(AuditRegistry::class)->addPage(new class ($tag, $seen) implements PageCheck {
            public function __construct(private string $tag, private \stdClass $seen) {}

            public function id(): string
            {
                return 'test.probe';
            }

            public function check(RenderedPage $page): array
            {
                if (preg_match('#<' . preg_quote($this->tag, '#') . '>([^<]*)<#', $page->body, $m) === 1) {
                    $this->seen->texts[] = $m[1];
                }

                return [];
            }
        });

        return $seen;
    }

    /** The app's Audits on a clock that only moves while a page is audited: $seconds per page. */
    private function clockedAudits(int $seconds): void
    {
        $c = $this->session->container();
        $clock = new \DateTimeImmutable('2026-11-13T10:00:00+00:00');
        $registry = $c->get(AuditRegistry::class);
        $registry->addPage(new class ($clock, $seconds) implements PageCheck {
            public function __construct(private \DateTimeImmutable &$clock, private int $seconds) {}

            public function id(): string
            {
                return 'test.clock';
            }

            public function check(RenderedPage $page): array
            {
                $this->clock = $this->clock->modify('+' . $this->seconds . ' seconds');

                return [];
            }
        });
        $c->instance(Audits::class, new Audits(
            $registry,
            $c->get(PageRenderer::class),
            $c->get(PublicPages::class),
            $this->file(),
            static function () use (&$clock): \DateTimeImmutable {
                return $clock;
            },
        ));
    }

    /** The app's Audits on a clock where each reading is 100 s later: one page per step. */
    private function slowAudits(): void
    {
        $c = $this->session->container();
        $clock = new \DateTimeImmutable('2026-11-13T10:00:00+00:00');
        $c->instance(Audits::class, new Audits(
            $c->get(AuditRegistry::class),
            $c->get(PageRenderer::class),
            $c->get(PublicPages::class),
            $this->file(),
            static function () use (&$clock): \DateTimeImmutable {
                return $clock = $clock->modify('+100 seconds');
            },
        ));
    }

    /**
     * Auth with one admin, signed in on one in-process app.
     *
     * @param list<string>|null $permissions the admin role's permissions (all when null)
     */
    private function signIn(?array $permissions = null): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_seo_run_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => $permissions ?? ['*']]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class, \\Preflow\\Folio\\Tests\\Fixtures\\Audit\\GlobalsProbeProvider::class];\n");

        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $uri, array $body = [], bool $htmx = false): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method !== 'GET') {
            $req = $req->withHeader('Sec-Fetch-Site', 'same-origin');
            if (session_id() !== '') {
                $req = $req->withCookieParams([session_name() => session_id()]);
            }
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }
        if ($htmx) {
            $req = $req->withHeader('HX-Request', 'true');
        }

        return $this->session->handle($req);
    }
}
