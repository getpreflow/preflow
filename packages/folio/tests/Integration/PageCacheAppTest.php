<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * The page cache on a Folio site in web mode: what staff publish is what the next anonymous
 * visitor sees, with no stale step.
 */
final class PageCacheAppTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        putenv('APP_DEBUG');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-page-cache-test-key-0123', 'url' => 'https://site.test'];\n");
        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'always'];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><body><h1>{{ record.title }}</h1></body></html>');
        // The category list: a page of the site listing the published posts.
        $this->write('app/pages/category.twig', '<ul>{% for p in folio_list("post") %}<li>{{ p.title }}</li>{% endfor %}</ul>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_the_approval_mail_case(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Awaiting approval'], 'slug' => ['en' => 'project']]), null);

        // Anonymous visitors: both pages are cached.
        foreach (['/project', '/category'] as $path) {
            $this->assertSame('miss', $this->get($path)->getHeaderLine('X-Page-Cache'), $path);
            $hit = $this->get($path);
            $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'), $path);
            $this->assertStringContainsString('Awaiting approval', (string) $hit->getBody(), $path);
        }

        // Staff approve: the project is published with its new title.
        $published = $this->post('/folio/post/p1', ['title' => 'Approved project', 'slug' => 'project', '_locale' => 'en', '_action' => 'publish']);
        $this->assertSame(302, $published->getStatusCode(), (string) $published->getBody());

        // The owner follows the mail: the new state at once, on both pages, never a stale copy.
        foreach (['/project' => '<h1>Approved project</h1>', '/category' => '<li>Approved project</li>'] as $path => $expected) {
            $response = $this->get($path);
            $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'), $path);
            $this->assertStringContainsString($expected, (string) $response->getBody(), $path);
            $this->assertStringNotContainsString('Awaiting approval', (string) $response->getBody(), $path);
            $this->assertSame('hit', $this->get($path)->getHeaderLine('X-Page-Cache'), "{$path} is cached again");
        }
    }

    #[RunInSeparateProcess]
    public function test_job_detail_with_other_jobs_next_visit(): void
    {
        // The owner's example (forum-holzkarriere): the job itself fresh, "other jobs of this
        // company" next visit, on the job's own Folio address. Rebuilds run behind each response.
        $this->afterResponse = true;
        $this->write('resources/folio/frontend/post.twig', '<html><body><h1>{{ record.title }}</h1>'
            . '{% freshness \'next-visit\' %}<ul class="others">{% for p in folio_list("post") %}<li>{{ p.title }}</li>{% endfor %}</ul>{% endfreshness %}'
            . '</body></html>');
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Carpenter'], 'slug' => ['en' => 'carpenter']]), null);
        $this->publisher()->publish($this->working('post', 'p2', ['title' => ['en' => 'Joiner'], 'slug' => ['en' => 'joiner']]), null);
        $first = $this->get('/carpenter');
        $this->assertSame('miss', $first->getHeaderLine('X-Page-Cache'), $first->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertStringContainsString('<h1>Carpenter</h1>', (string) $first->getBody());
        $this->assertSame('hit', $this->get('/carpenter')->getHeaderLine('X-Page-Cache'));

        // The company publishes a new job.
        $this->publishNow('p3', ['title' => ['en' => 'Sawyer'], 'slug' => ['en' => 'sawyer']]);

        // The detail page is still cached, but stale: the next visitor gets the old list at once.
        $this->assertTrue($this->stored('/carpenter')?->stale, 'kept and marked stale, not forgotten');
        $next = $this->get('/carpenter');
        $this->assertSame('stale', $next->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('<li>Joiner</li>', (string) $next->getBody());
        $this->assertStringNotContainsString('Sawyer', (string) $next->getBody());

        // It was rebuilt behind that response: the visit after gets the new list from the cache.
        $after = $this->get('/carpenter');
        $this->assertSame('hit', $after->getHeaderLine('X-Page-Cache'), 'rebuilt and cached again');
        $this->assertStringContainsString('<li>Sawyer</li>', (string) $after->getBody());

        // The job itself stays fresh: changing it refreshes the page at once.
        $this->afterResponse = false; // to see the forget before the rebuild
        $published = $this->post('/folio/post/p1', ['title' => 'Master carpenter', 'slug' => 'carpenter', '_locale' => 'en', '_action' => 'publish']);
        $this->assertSame(302, $published->getStatusCode(), (string) $published->getBody());
        $this->assertNull($this->stored('/carpenter'), 'forgotten');
        $response = $this->get('/carpenter');
        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('<h1>Master carpenter</h1>', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_publish_rebuilds_its_pages_behind_the_response(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Awaiting approval'], 'slug' => ['en' => 'project']]), null);
        $this->cacheAll(['/project', '/category']);

        $this->afterResponse = true;
        $published = $this->post('/folio/post/p1', ['title' => 'Approved project', 'slug' => 'project', '_locale' => 'en', '_action' => 'publish']);
        $this->assertSame(302, $published->getStatusCode(), (string) $published->getBody());

        // Rebuilt right after the staff request: the owner's first visit is already a hit.
        foreach (['/project' => '<h1>Approved project</h1>', '/category' => '<li>Approved project</li>'] as $path => $expected) {
            $response = $this->get($path);
            $this->assertSame('hit', $response->getHeaderLine('X-Page-Cache'), $path);
            $this->assertStringContainsString($expected, (string) $response->getBody(), $path);
        }
    }

    #[RunInSeparateProcess]
    public function test_folio_refresh_page_and_everything(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Carpenter'], 'slug' => ['en' => 'carpenter']]), null);
        $this->cacheAll(['/carpenter', '/category']);
        $before = $this->stored('/carpenter')?->createdAt;
        $category = $this->stored('/category')?->createdAt;

        // Settings → Cache shows the page cache with both actions.
        $screen = (string) $this->get('/folio/cache')->getBody();
        $this->assertStringContainsString('Page cache (finished pages for visitors)', $screen);
        $this->assertStringContainsString('<form method="post" action="/folio/cache/pages/refresh"', $screen);
        $this->assertStringContainsString('<form method="post" action="/folio/cache/pages/refresh-all">', $screen);
        $this->assertStringContainsString('Refresh a page', $screen);
        $this->assertStringContainsString('Refresh everything', $screen);

        // One page, by its path: forgotten, and rebuilt behind the response.
        $this->afterResponse = true;
        usleep(2000);
        $response = $this->post('/folio/cache/pages/refresh', ['url' => '/carpenter']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/cache', $response->getHeaderLine('Location'));
        $this->assertGreaterThan($before, $this->stored('/carpenter')?->createdAt, 'rebuilt');
        $this->assertSame($category, $this->stored('/category')?->createdAt, 'the other page is left alone');
        $this->assertSame('hit', $this->get('/carpenter')->getHeaderLine('X-Page-Cache'));

        // Everything: both rebuilt.
        $before = $this->stored('/carpenter')?->createdAt;
        usleep(2000);
        $this->assertSame(302, $this->post('/folio/cache/pages/refresh-all')->getStatusCode());
        $this->assertGreaterThan($before, $this->stored('/carpenter')?->createdAt);
        $this->assertGreaterThan($category, $this->stored('/category')?->createdAt);

        // Another site's address changes nothing.
        $before = $this->stored('/carpenter')?->createdAt;
        $this->assertSame(302, $this->post('/folio/cache/pages/refresh', ['url' => 'https://elsewhere.test/carpenter'])->getStatusCode());
        $this->assertSame($before, $this->stored('/carpenter')?->createdAt);
    }

    #[RunInSeparateProcess]
    public function test_a_detail_page_depends_on_its_record_and_address_not_the_whole_type(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Carpenter'], 'slug' => ['en' => 'carpenter']]), null);
        $this->publisher()->publish($this->working('post', 'p2', ['title' => ['en' => 'Joiner'], 'slug' => ['en' => 'joiner']]), null);
        foreach (['/carpenter', '/joiner', '/category'] as $path) {
            $this->get($path);
            $this->assertSame('hit', $this->get($path)->getHeaderLine('X-Page-Cache'), $path);
        }

        // Another record of the type: only the listing page goes.
        $this->publishNow('p3', ['title' => ['en' => 'Sawyer'], 'slug' => ['en' => 'sawyer']]);
        $this->assertFalse($this->stored('/carpenter')?->stale, 'the detail page is left alone');
        $this->assertSame('hit', $this->get('/carpenter')->getHeaderLine('X-Page-Cache'));
        $this->assertNull($this->stored('/category'), 'the listing follows its own (fresh) dependency');

        // A record takes over an address (a slug change): the page at it goes, though the record
        // it showed did not change; the page at the address it left goes too.
        $this->publishNow('p3', ['title' => ['en' => 'Sawyer'], 'slug' => ['en' => 'carpenter']]);
        $this->assertNull($this->stored('/carpenter'), 'the address taken over');
        $this->assertNotNull($this->stored('/joiner'), 'another address is left alone');

        // Unpublished: the page at its address goes.
        $this->get('/joiner');
        $app = $this->app();
        $app->container()->get(\Preflow\Folio\Publishing\Publisher::class)->unpublish('post', 'p2');
        $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
        $this->assertNull($this->stored('/joiner'));
        $this->assertNotSame('hit', $this->get('/joiner')->getHeaderLine('X-Page-Cache'), 'never served from the cache');
    }

    #[RunInSeparateProcess]
    public function test_the_found_record_a_second_locale_and_delete(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Carpenter', 'de' => 'Schreiner'], 'slug' => ['en' => 'carpenter', 'de' => 'schreiner'], '_locales' => ['en', 'de']]), null);
        $this->publisher()->publish($this->working('post', 'p2', ['title' => ['en' => 'Joiner'], 'slug' => ['en' => 'joiner']]), null);
        $this->cacheAll(['/carpenter', '/de/schreiner', '/joiner']);
        $this->assertStringContainsString('<h1>Schreiner</h1>', $this->storedBody('/de/schreiner', 'de'));

        // The shown record written around the Publisher (one record): its page goes, by its record tag alone.
        $app = $this->app();
        $app->container()->get(\Preflow\Data\DataManager::class)->reportWrite($this->storage, 'post__published', 'p1');
        $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
        $this->assertNull($this->stored('/carpenter'), 'the found record is a dependency');
        $this->assertNull($this->stored('/de/schreiner', 'de'));
        $this->assertNotNull($this->stored('/joiner'), 'another record\'s page stays');

        // A record that takes over the address in the second locale only.
        $this->cacheAll(['/carpenter', '/de/schreiner']);
        $this->publishNow('p3', ['title' => ['en' => 'Sawyer', 'de' => 'Säger'], 'slug' => ['en' => 'sawyer', 'de' => 'schreiner'], '_locales' => ['en', 'de']]);
        $this->assertNull($this->stored('/de/schreiner', 'de'), 'its German address taken over');
        $this->assertNotNull($this->stored('/carpenter'), 'its English address is not touched');

        // Deleted (RecordDeleter and member deletion go through Publisher::forget()).
        $app = $this->app();
        $app->container()->get(\Preflow\Folio\Publishing\Publisher::class)->forget('post', 'p2');
        $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
        $this->assertNull($this->stored('/joiner'));
        $this->assertNotNull($this->stored('/carpenter'));
    }

    #[RunInSeparateProcess]
    public function test_every_route_a_path_tries_is_a_dependency_and_the_home_address(): void
    {
        // Two routes match /jobs/…: the job route (tried first) and the pages' catch-all.
        $this->writeModel('job', [
            'label' => 'Jobs', 'publishing' => true, 'frontend' => ['route' => '/jobs/{slug}'],
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']], 'slug' => ['type' => 'string', 'validate' => ['required']]],
        ]);
        $this->writeModel('page', [
            'label' => 'Pages', 'publishing' => true, 'frontend' => ['route' => '/{...path}', 'home' => 'home'],
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']], 'path' => ['type' => 'string', 'validate' => ['required']]],
        ]);
        $this->write('resources/folio/frontend/job.twig', '<h1>Job: {{ record.title }}</h1>');
        $this->write('resources/folio/frontend/page.twig', '<h1>Page: {{ record.title }}</h1>');
        $publish = function (string $type, string $id, array $data): void {
            $app = $this->app();
            $dm = $app->container()->get(\Preflow\Data\DataManager::class);
            $record = \Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
            $dm->saveType($record, false);
            $app->container()->get(\Preflow\Folio\Publishing\Publisher::class)->publish($record, null);
            $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
        };
        $publish('page', 'home', ['title' => 'Welcome', 'path' => 'home']);
        $publish('page', 'about-jobs', ['title' => 'About carpentry jobs', 'path' => 'jobs/carpenter']);
        $publish('page', 'sawyer-info', ['title' => 'About sawing', 'path' => 'jobs/sawyer']);
        $this->cacheAll(['/', '/jobs/carpenter', '/jobs/sawyer']);
        $this->assertStringContainsString('Page: Welcome', $this->storedBody('/'));
        $this->assertStringContainsString('Page: About carpentry jobs', $this->storedBody('/jobs/carpenter'));

        // A job published at /jobs/carpenter takes over the page's address.
        $publish('job', 'j1', ['title' => 'Carpenter', 'slug' => 'carpenter']);
        $this->assertNull($this->stored('/jobs/carpenter'), 'another type took over the path');
        $this->assertStringContainsString('Job: Carpenter', (string) $this->get('/jobs/carpenter')->getBody());
        $this->assertNotNull($this->stored('/jobs/sawyer'), 'one job is no bulk write');
        $this->assertNotNull($this->stored('/'));

        // An import of jobs (a table write around the Publisher): every path the job route tries goes.
        $app = $this->app();
        $app->container()->get(\Preflow\Data\DataManager::class)->reportWrite($this->storage, 'job__published');
        $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
        $this->assertNull($this->stored('/jobs/sawyer'), 'the job route found nothing there, but it could now');
        $this->assertNotNull($this->stored('/'), 'the home page tries no job route');

        // The home record: another page record taking the home value takes over "/".
        $publish('page', 'new-home', ['title' => 'New welcome', 'path' => 'home']);
        $this->assertNull($this->stored('/'), 'the home address');
    }

    /** @param list<string> $paths visits each (miss) and checks it is a hit now */
    private function cacheAll(array $paths): void
    {
        foreach ($paths as $path) {
            $miss = $this->get($path);
            $this->assertSame(200, $miss->getStatusCode(), $path . ': ' . substr((string) $miss->getBody(), 0, 300));
            $this->assertSame('hit', $this->get($path)->getHeaderLine('X-Page-Cache'), $path . ': ' . $miss->getHeaderLine('X-Page-Cache-Skip'));
        }
    }

    /**
     * Publishes post $id with $data through one web app and flushes its change log, as the
     * end of a staff request does.
     *
     * @param array<string, mixed> $data
     */
    private function publishNow(string $id, array $data): void
    {
        $app = $this->app();
        $app->container()->get(\Preflow\Folio\Publishing\Publisher::class)->publish($this->working('post', $id, $data), null);
        $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
    }

    #[RunInSeparateProcess]
    public function test_a_hit_reads_nothing_with_seo_and_geo_on_and_follows_the_site_settings(): void
    {
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Pub', 'site_fields' => ['description' => 'tagline']];\n");
        $this->write('config/geo.php', "<?php\nreturn ['enabled' => true, 'types' => ['post']];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ record.title }}</title></head><body><h1>{{ record.title }}</h1></body></html>');
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Project'], 'slug' => ['en' => 'project']]), null);
        $this->site('Games every Friday');

        $miss = $this->get('/project');
        $this->assertSame('miss', $miss->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('<meta property="og:description" content="Games every Friday">', (string) $miss->getBody());

        // The hit: the same head tags, and not one read through the data layer.
        $app = $this->app();
        $reads = new class implements \Preflow\Data\Observe\DataObserver {
            /** @var list<string> */
            public array $seen = [];
            public function read(string $storage, string $type, ?string $id): void { $this->seen[] = "read {$storage}:{$type}#{$id}"; }
            public function rawRead(string $storage, string $sql): void { $this->seen[] = "raw {$sql}"; }
            public function wrote(string $storage, string $type, ?string $id): void { $this->seen[] = "wrote {$type}"; }
            public function rawWrote(string $storage, string $sql): void { $this->seen[] = "raw write {$sql}"; }
        };
        $app->container()->get(\Preflow\Data\DataManager::class)->observe($reads);
        $hit = $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/project'));
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertSame([], $reads->seen, 'a hit makes no query');
        $this->assertStringContainsString('type="text/markdown"', $hit->getHeaderLine('Link'), 'GEO ran on the hit too');
        $strip = static fn (ResponseInterface $r): string => (string) preg_replace('/nonce="[^"]+"|Nonce":"[^"]+"/', 'NONCE', (string) $r->getBody());
        $this->assertSame($strip($miss), $strip($hit));

        // The site settings change: the page read them while it rendered, so it is refreshed.
        $this->site('Games every Saturday');
        $fresh = $this->get('/project');
        $this->assertSame('miss', $fresh->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('content="Games every Saturday"', (string) $fresh->getBody());
    }

    // -----------------------------------------------------------------------
    // Live parts (a guest album as on holz-objekte: the cookie holds the item numbers)
    // -----------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function test_two_visitors_never_see_each_others_live_parts(): void
    {
        $this->album('<html><head><script nonce="{{ csp_nonce() }}">var a = 1;</script></head><body><h1>{{ record.title }}</h1>'
            . '{% live with {number: record.status} %}<p class="album">{{ album_count() }} in your album{% if in_album(number) %}, this one too{% endif %}</p>{% endlive %}'
            . '<form><input name="_token" value="{{ guest_token() }}"></form>'
            . '<footer>{{ record.title }} footer</footer></body></html>');

        $a = $this->visit('/project', ['album' => '1_2']);
        $this->assertSame('miss', $a->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $a->getHeaderLine('X-Page-Cache-Skip'), 'stored');
        $this->assertStringContainsString('<p class="album">2 in your album, this one too</p>', (string) $a->getBody(), 'the first visitor sees their own album');
        $this->assertStringNotContainsString('<!--pc:', (string) $a->getBody());

        $b = $this->visit('/project', ['album' => '3']);
        $this->assertSame('hit', $b->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('<p class="album">1 in your album</p>', (string) $b->getBody());
        $this->assertStringNotContainsString('this one too', (string) $b->getBody());
        $this->assertStringContainsString('<footer>Project footer</footer>', (string) $b->getBody());

        $again = $this->visit('/project', ['album' => '1_2']);
        $this->assertSame('hit', $again->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('<p class="album">2 in your album, this one too</p>', (string) $again->getBody());
        $this->assertStringContainsString('<p class="album">0 in your album</p>', (string) $this->visit('/project')->getBody(), 'no album, no count');

        // Nor their nonces: each is its own, the one its CSP names.
        $nonces = [];
        foreach ([$a, $b, $again] as $response) {
            $this->assertSame(1, preg_match('/<script nonce="([^"]+)">/', (string) $response->getBody(), $m));
            $this->assertStringContainsString("'nonce-{$m[1]}'", $response->getHeaderLine('Content-Security-Policy'));
            $nonces[] = $m[1];
        }
        $this->assertCount(3, array_unique($nonces));

        // Nor their form tokens: each visitor's own, though it sits outside the live part.
        $tokens = [];
        foreach ([$a, $b, $again] as $response) {
            $this->assertSame(1, preg_match('/name="_token" value="(guest-token-[0-9a-f]{16})"/', (string) $response->getBody(), $m));
            $tokens[] = $m[1];
        }
        $this->assertNotSame($tokens[0], $tokens[1], 'A and B');
        $this->assertSame($tokens[0], $tokens[2], 'A gets their own again');

        // What is stored is the placeholder, never somebody's album.
        $stored = $this->storedBody('/project');
        $this->assertStringContainsString('<!--pc:l:', $stored);
        $this->assertStringNotContainsString('in your album', $stored);
        $this->assertStringNotContainsString('guest-token-', $stored);
    }

    #[RunInSeparateProcess]
    public function test_a_forged_marker_in_user_text_is_not_rendered(): void
    {
        $log = $this->captureLog();
        $this->write('app/pages/secret.twig', '<p>{% live %}TOP SECRET{% endlive %}</p>{% block plain %}PLAIN BLOCK{{ w|default("") }}{% endblock %}');
        $this->write('storage/upload.txt', '{% live %}UPLOADED {{ 7 * 7 }}{% endlive %}');
        $recipe = ['t' => 'secret.twig', 'b' => '__live_1', 'w' => []];
        $forged = (new \Preflow\Core\PageCache\LivePlaceholder('a key the visitor guessed'))->make('twig', $recipe);
        $edited = (string) preg_replace('/:[0-9a-f]{32}-->/', ':' . str_repeat('0', 32) . '-->', $forged);
        // Signed with the app's own key (say the key leaked), but naming nothing declared live:
        $appKey = new \Preflow\Core\PageCache\LivePlaceholder('folio-page-cache-test-key-0123');
        $undeclared = [
            $appKey->make('twig', ['t' => 'nowhere.twig', 'b' => '__live_1', 'w' => []]),
            $appKey->make('twig', ['t' => 'secret.twig', 'b' => 'plain', 'w' => ['w' => '<script>x</script>']]),
            $appKey->make('twig', ['t' => $this->dir . '/storage/upload.txt', 'b' => '__live_1', 'w' => []]),
            $appKey->make('twig', ['t' => $this->dir . '/storage/upload.txt (string template 0)', 'b' => '__live_1', 'w' => []]),
            $appKey->make('twig', ['c' => \stdClass::class, 'b' => '__live_1', 'w' => []]),
            $appKey->make('component', ['c' => \Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock::class, 'p' => []]),
        ];
        $text = "<p>Hi</p>{$forged}{$edited}" . implode('', $undeclared);
        // Rich text a visitor sent in: printed raw.
        $this->album('<html><body><div class="text">{{ record.body|raw }}</div></body></html>', body: $text);

        $miss = $this->visit('/project');
        $hit = $this->visit('/project');
        $this->assertSame(['miss', 'hit'], [$miss->getHeaderLine('X-Page-Cache'), $hit->getHeaderLine('X-Page-Cache')]);
        foreach ([$miss, $hit] as $response) {
            $body = (string) $response->getBody();
            foreach (['TOP SECRET', 'PLAIN BLOCK', 'UPLOADED', '<script>x', \Preflow\Core\PageCache\LiveParts::ERROR] as $never) {
                $this->assertStringNotContainsString($never, $body);
            }
            $this->assertStringContainsString($text, $body, 'left as the comments they are');
        }
        $this->assertStringContainsString('page cache: 8 live marker(s) left as comments', (string) file_get_contents($log));

        // The control: the declared live block, signed with the app's key, is a live part.
        $live = $this->app()->container()->get(\Preflow\Core\PageCache\LiveParts::class);
        $this->assertSame('<b>TOP SECRET</b>', $live->fill('<b>' . $appKey->make('twig', $recipe) . '</b>'));
    }

    #[RunInSeparateProcess]
    public function test_with_the_default_key_nothing_is_filled_or_stored(): void
    {
        $log = $this->captureLog();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'url' => 'https://site.test'];\n");
        $this->write('app/pages/secret.twig', '<p>{% live %}TOP SECRET{% endlive %}</p>');
        $public = new \Preflow\Core\PageCache\LivePlaceholder(\Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY);
        $marker = $public->make('twig', ['t' => 'secret.twig', 'b' => '__live_1', 'w' => []]);
        $this->album('<html><body>{% live %}<p class="album">{{ album_count() }} in your album</p>{% endlive %}<div>{{ record.body|raw }}</div></body></html>', body: $marker);

        foreach (['1_2' => '2', '3' => '1'] as $album => $count) {
            $response = $this->visit('/project', ['album' => (string) $album]);
            $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
            $this->assertSame('app-key', $response->getHeaderLine('X-Page-Cache-Skip'));
            $body = (string) $response->getBody();
            $this->assertStringContainsString("<p class=\"album\">{$count} in your album</p>", $body, 'live parts render in place');
            $this->assertStringNotContainsString('TOP SECRET', $body, 'a marker signed with the public key is never filled');
            $this->assertStringContainsString($marker, $body);
        }
        $this->assertNull($this->stored('/project'));
    }

    #[RunInSeparateProcess]
    public function test_a_throwing_live_block_renders_its_boundary_and_the_page_is_served(): void
    {
        $log = $this->captureLog();
        $this->album('<html><body><h1>{{ record.title }}</h1>{% live %}<p>{{ live_boom() }}</p>{% endlive %}'
            . '{% live with {n: 1} %}<p class="album">{{ album_count() }} in your album</p>{% endlive %}</body></html>');

        foreach (['miss', 'hit'] as $expected) {
            $response = $this->visit('/project', ['album' => '4_5_6']);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($expected, $response->getHeaderLine('X-Page-Cache'));
            $body = (string) $response->getBody();
            $this->assertStringContainsString('<h1>Project</h1>' . \Preflow\Core\PageCache\LiveParts::ERROR . '<p class="album">', $body, 'its boundary, the rest of the page');
            $this->assertStringContainsString('<p class="album">3 in your album</p>', $body, 'the other live part still renders');
        }
        $this->assertMatchesRegularExpression('/page cache: a live part \(twig\) failed: .*the album service is down/', (string) file_get_contents($log));
    }

    #[RunInSeparateProcess]
    public function test_a_personal_value_outside_live_parts_is_not_stored_and_logged(): void
    {
        $log = $this->captureLog();
        // The developer forgot the live block: the album shows in the cached part of the page.
        $this->album('<html><body><h1>{{ record.title }}</h1><p class="album">{{ album_numbers() }}</p></body></html>');

        $first = $this->visit('/project', ['album' => '1_2']);
        $this->assertSame('miss', $first->getHeaderLine('X-Page-Cache'));
        $this->assertSame('differs', $first->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertStringContainsString('<p class="album">1,2</p>', (string) $first->getBody(), 'the visitor still gets their page');
        $this->assertNull($this->stored('/project'), 'not stored');
        $this->assertStringContainsString('page cache: https://site.test/project differs per visitor outside live parts', (string) file_get_contents($log));

        $other = $this->visit('/project', ['album' => '3']);
        $this->assertSame('miss', $other->getHeaderLine('X-Page-Cache'), 'never served from the cache');
        $this->assertStringContainsString('<p class="album">3</p>', (string) $other->getBody());
        $this->assertStringNotContainsString('1,2', (string) $other->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_cookie_read_from_the_superglobal_outside_live_parts_is_not_stored(): void
    {
        $log = $this->captureLog();
        $this->album('<html><body><h1>{{ record.title }}</h1><p class="album">{{ raw_album() }}</p></body></html>');

        $_COOKIE = ['album' => '1_2'];
        $first = $this->visit('/project', ['album' => '1_2']);
        $this->assertSame('miss', $first->getHeaderLine('X-Page-Cache'));
        $this->assertSame('differs', $first->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertStringContainsString('<p class="album">1_2</p>', (string) $first->getBody());
        $this->assertNull($this->stored('/project'), 'not stored');
        $this->assertSame(['album' => '1_2'], $_COOKIE, 'put back');
        $this->assertStringContainsString('page cache: https://site.test/project differs per visitor outside live parts', (string) file_get_contents($log));
        $_COOKIE = [];
    }

    /** The post page with $template, published, and the guest album functions. */
    private function album(string $template, string $body = ''): void
    {
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\" . \Preflow\Folio\Tests\Fixtures\Live\GuestAlbumProvider::class . "::class];\n");
        $this->write('resources/folio/frontend/post.twig', $template);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Project'], 'slug' => ['en' => 'project'], 'status' => '2', 'body' => ['en' => $body]]), null);
    }

    /** @param array<string, string> $cookies */
    private function visit(string $uri, array $cookies = []): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $uri)->withCookieParams($cookies));
    }

    private function stored(string $uri, string $locale = 'en'): ?\Preflow\Core\PageCache\StoredPage
    {
        $app = $this->app();
        $key = \Preflow\Core\PageCache\PageKey::fromRequest(
            (new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $uri)->withAttribute('locale', $locale),
            $app->container()->get(\Preflow\Core\PageCache\PageCacheConfig::class),
            ['locale' => $locale],
        );

        return $key === null ? null : $app->container()->get(\Preflow\Core\PageCache\PageStore::class)->get($key);
    }

    private function storedBody(string $uri, string $locale = 'en'): string
    {
        return ($this->stored($uri, $locale) ?? throw new \RuntimeException("{$uri} is not stored"))->body;
    }

    /** error_log() of this (separate) process goes to a file; returns its path. */
    private function captureLog(): string
    {
        $log = $this->dir . '/php-error.log';
        touch($log);
        ini_set('error_log', $log);

        return $log;
    }

    /** Saves and publishes the site singleton through a web app, whose change log then invalidates. */
    private function site(string $tagline): void
    {
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $record = \Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('site'), ['uuid' => 'site', 'tagline' => ['en' => $tagline]]);
        $dm->saveType($record, false);
        $app->container()->get(\Preflow\Folio\Publishing\Publisher::class)->publish($record, null);
        $app->container()->get(\Preflow\Core\PageCache\ChangeLog::class)->flush();
    }

    /** Whether each app runs its after-response work (rebuilds) at the end of handle(), as run() does. */
    private bool $afterResponse = false;

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->runAfterResponseInHandle($this->afterResponse);
        $app->boot();

        return $app;
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $uri));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body = []): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'https://site.test' . $uri)
            ->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body));
    }
}
