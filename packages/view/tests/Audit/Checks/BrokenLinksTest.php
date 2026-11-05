<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\AuditRun;
use Preflow\View\Audit\Checks\BrokenLinks;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** link.broken: internal link and image targets, status-checked once per run through the renderer. */
final class BrokenLinksTest extends TestCase
{
    use CheckFixtures;

    /** @var list<string> paths rendered, in order */
    private array $rendered = [];
    private string $public;
    private string $homeLinks = '';
    private ?string $robots = "User-agent: *\nAllow: /\nAllow: /folio/media/\nDisallow: /folio/\nDisallow: /private\n\n# AI training crawlers\nUser-agent: GPTBot\nDisallow: /\n";

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/audit_links_' . bin2hex(random_bytes(4));
        @mkdir($this->public . '/img', 0777, true);
        file_put_contents($this->public . '/img/logo.png', 'png');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->public, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->public);
    }

    public function test_reports_broken_targets_once_with_the_first_page_that_links_them(): void
    {
        $check = $this->check();
        $check->restore([]);

        $this->assertSame('link.broken', $check->id());
        $check->collect($this->page($this->html(body: implode('', [
            '<h1>Home</h1>',
            '<a href="/ok">a</a><a href="/ok#part">b</a><a href="https://site.test/ok">c</a><a href="ok">d</a>',
            '<a href="/missing">missing</a><a href="/missing">again</a>',
            '<a href="/search?q=spiele">search</a><a href="/private/notes">private</a><a href="/folio/edit">admin</a>',
            '<a href="https://elsewhere.test/x">ext</a><a href="mailto:a@site.test">mail</a><a href="tel:+41">tel</a><a href="#top">top</a><a href="javascript:void(0)">js</a>',
            '<img src="/media/x-480.webp" alt=""><img srcset="/media/gone.webp 1x, /media/x-480.webp 2x" src="data:image/gif;base64,R0lGOD" alt="">',
            '<a href="/old">old</a><a href="/moved">moved</a><a href="/loop">loop</a><a href="/boom">boom</a><a href="/out">out</a>',
            '<img src="/img/logo.png" alt="Logo"><img src="/folio/media/a.jpg" alt="A">',
        ])), 'https://site.test/', 'en'));
        $check->collect($this->page($this->html(body: '<a href="/missing">m</a><a href="/">home</a><a href="../about">about</a><a href="/de/kaputt">k</a>'), 'https://site.test/de/%C3%BCber', 'de'));

        $state = json_decode((string) json_encode($check->state()), true);
        $resumed = $this->check();
        $resumed->restore($state);
        $resumed->collect($this->page($this->html(body: '<a href="/missing">m</a><a href="/ok">ok</a><a href="/boom">b</a>'), 'https://site.test/team', 'en'));

        $findings = $resumed->finish();

        $this->assertEqualsCanonicalizing([
            '/robots.txt', '/ok', '/missing', '/media/x-480.webp', '/media/gone.webp',
            '/old', '/older', '/new', '/moved', '/loop', '/boom', '/out', '/folio/media/a.jpg',
            '/about', '/de/kaputt',
        ], $this->rendered);
        $this->assertSame(array_unique($this->rendered), $this->rendered, 'each target is rendered once per run');

        $byPath = $this->byPath($findings);
        $this->assertSame(['/missing', '/media/gone.webp', '/old', '/loop', '/boom', '/de/kaputt'], array_keys($byPath));
        $this->assertSame('link.broken|critical|https://site.test/|en', $this->summary([$byPath['/missing']])[0]);
        $this->assertStringContainsString('404', $byPath['/missing']->message);
        $this->assertStringContainsString('3 pages', $byPath['/missing']->message);
        $this->assertSame('link.broken|critical|https://site.test/|en', $this->summary([$byPath['/media/gone.webp']])[0]);
        $this->assertSame('link.broken|notice|https://site.test/|en', $this->summary([$byPath['/old']])[0]);
        $this->assertStringContainsString('/new', $byPath['/old']->message);
        $this->assertSame('link.broken|critical|https://site.test/|en', $this->summary([$byPath['/loop']])[0]);
        $this->assertSame('link.broken|critical|https://site.test/|en', $this->summary([$byPath['/boom']])[0]);
        $this->assertStringContainsString('500', $byPath['/boom']->message);
        $this->assertStringContainsString('2 pages', $byPath['/boom']->message);
        $this->assertSame('link.broken|critical|https://site.test/de/%C3%BCber|de', $this->summary([$byPath['/de/kaputt']])[0]);
        $this->assertTargetsNamePages($findings);
    }

    public function test_a_finish_renders_nothing(): void
    {
        $check = $this->check();
        $check->restore([]);
        $check->collect($this->page($this->html(body: '<a href="/missing">m</a>'), 'https://site.test/', 'en'));
        $before = count($this->rendered);

        $check->finish();

        $this->assertSame($before, count($this->rendered));
    }

    public function test_without_robots_txt_every_internal_path_is_checked_and_a_fresh_start_forgets_targets(): void
    {
        $this->robots = null;
        $check = $this->check();
        $check->restore([]);
        $check->collect($this->page($this->html(body: '<a href="/private/notes">p</a>'), 'https://site.test/', 'en'));
        $this->assertSame(['/robots.txt', '/private/notes'], $this->rendered);

        $check->restore([]);
        $check->collect($this->page($this->html(body: '<a href="/private/notes">p</a>'), 'https://site.test/', 'en'));
        $this->assertSame(['/robots.txt', '/private/notes', '/robots.txt', '/private/notes'], $this->rendered);
        $this->assertSame(['link.broken|critical|https://site.test/|en'], $this->summary($check->finish()), 'counted once after the fresh start');
        $this->assertStringContainsString('linked from 1 page', $check->finish()[0]->message);
    }

    public function test_a_static_robots_txt_in_public_is_read_without_rendering(): void
    {
        file_put_contents($this->public . '/robots.txt', "User-agent: *\nDisallow: /private\n");
        try {
            $check = $this->check();
            $check->restore([]);
            $check->collect($this->page($this->html(body: '<a href="/private/notes">p</a><a href="/ok">ok</a>'), 'https://site.test/', 'en'));
        } finally {
            @unlink($this->public . '/robots.txt');
        }

        $this->assertSame(['/ok'], $this->rendered);
    }

    public function test_srcset_data_uri_candidates_are_skipped(): void
    {
        $check = $this->check();
        $check->restore([]);
        $check->collect($this->page($this->html(body: '<img srcset="data:image/png;base64,AAAA,BBBB 1x, /ok 2x,/new 3x" alt="">'), 'https://site.test/', 'en'));

        $this->assertSame(['/robots.txt', '/ok', '/new'], $this->rendered);
        $this->assertSame([], $check->finish());
    }

    public function test_public_files_and_directories_are_fine_also_at_the_end_of_a_redirect(): void
    {
        @mkdir($this->public . '/docs', 0777, true);
        file_put_contents($this->public . '/docs/index.html', '<html></html>');
        $check = $this->check();
        $check->restore([]);
        $check->collect($this->page($this->html(body: '<a href="/docs/">d</a><a href="/docs">d</a><a href="/favicon.ico">f</a><a href="/handbook">h</a>'), 'https://site.test/', 'en'));

        $this->assertSame(['/robots.txt', '/favicon.ico', '/handbook'], $this->rendered);
        $this->assertSame([], $check->finish());
    }

    public function test_queued_public_pages_are_left_to_their_own_audit(): void
    {
        $check = $this->check();
        $check->restore([]);
        $check->queued(['/queued', '/de/%C3%BCber']);
        $check->collect($this->page($this->html(body: '<a href="/queued">q</a><a href="/de/über">ü</a><a href="/to-queued">t</a>'), 'https://site.test/', 'en'));

        $this->assertSame(['/robots.txt', '/to-queued'], $this->rendered);
        $this->assertSame([], $check->finish(), 'a queued page answers for itself (status.error), not as link.broken');
    }

    public function test_a_full_run_renders_each_public_page_once_and_a_broken_one_shows_as_its_status(): void
    {
        $registry = $this->registry();
        $renderer = $this->renderer();
        $registry->addRun(new BrokenLinks($registry, $renderer, $this->public));
        $pages = new PublicPages();
        $pages->source(static fn (string $locale): array => $locale === 'en'
            ? [new PublicPage('Home', '/', order: 0), new PublicPage('A', '/a'), new PublicPage('B', '/b'), new PublicPage('Gone', '/gone')]
            : [new PublicPage('Über', '/de/%C3%BCber')]);
        // The home page links every public page; the German one is linked decoded.
        $this->homeLinks = '<a href="/a">a</a><a href="/b">b</a><a href="/gone">g</a><a href="/de/über">ü</a>';

        $report = (new AuditRun($registry, $renderer, $pages, $this->public . '/storage/seo-audit.json', static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-05T10:00:00+00:00')))
            ->run(600, null, null, false, null);

        $this->assertTrue($report->complete());
        $this->assertEqualsCanonicalizing(['/', '/a', '/b', '/gone', '/de/%C3%BCber', '/robots.txt'], $this->rendered);
        $this->assertSame(['status.error|critical|https://site.test/gone|en'], $this->summary($report->findings));
    }

    private function check(): BrokenLinks
    {
        return new BrokenLinks($this->registry(), $this->renderer(), $this->public);
    }

    private function renderer(): PageRenderer
    {
        return new PageRenderer(function (ServerRequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();
            $this->rendered[] = $path;
            $html = ['Content-Type' => 'text/html; charset=UTF-8'];

            return match ($path) {
                '/robots.txt' => $this->robots !== null ? new Response(200, ['Content-Type' => 'text/plain'], $this->robots) : new Response(404, $html, 'Not found'),
                '/ok', '/new', '/about', '/folio/media/a.jpg' => new Response(200, $html, '<html><body>ok</body></html>'),
                '/old' => new Response(301, ['Location' => '/older']),
                '/older' => new Response(301, ['Location' => 'https://site.test/new']),
                '/moved' => new Response(301, ['Location' => '/ok']),
                '/favicon.ico' => new Response(301, ['Location' => '/img/logo.png']),
                '/handbook' => new Response(301, ['Location' => '/docs/']),
                '/to-queued' => new Response(301, ['Location' => '/queued']),
                '/' => new Response(200, $html, '<html><head><title>Home</title></head><body>' . $this->homeLinks . '</body></html>'),
                '/a', '/b', '/de/%C3%BCber' => new Response(200, $html, '<html><head><title>' . $path . '</title></head><body><a href="/">home</a></body></html>'),
                '/loop' => new Response(302, ['Location' => '/loop']),
                '/out' => new Response(301, ['Location' => 'https://elsewhere.test/']),
                '/boom' => new Response(500, $html, 'Error'),
                '/media/x-480.webp' => new Response(404, ['X-Preflow-Variant' => 'pending']),
                default => new Response(404, $html, 'Not found'),
            };
        }, 'https://site.test', null, null, ['en', 'de'], 'en');
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, Finding> the linked path each message names first => finding
     */
    private function byPath(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            $this->assertSame(1, preg_match('~(/[a-z/.\-0-9]+)~', $finding->message, $m), $finding->message);
            $out[$m[1]] = $finding;
        }

        return $out;
    }
}
