<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Redirects\NotFoundEntries;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Settings → Redirects ({prefix}/redirects): the list of entries, the not-found tab, the add/edit
 * form and the page picker's _targets endpoint. A publishing "page" at "/{slug}" (translated
 * slugs), a non-publishing "news" at "/news/{slug}" shown when its status is "published", and a
 * "snippet" without a frontend route; en/de with prefixes. Without auth (no permission
 * middleware) except for the permission tests.
 */
final class RedirectsControllerTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $booted = null;
    private string $token = '';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        unlink($this->dir . '/config/models/post.json');
        $this->writeModel('page', ['label' => 'Pages', 'publishing' => true, 'frontend' => ['route' => '/{slug}'], 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ]]);
        $this->writeModel('news', ['label' => 'News', 'publishing' => false, 'frontend' => ['route' => '/news/{slug}'], 'fields' => [
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]]);
        $this->writeModel('snippet', ['label' => 'Snippets', 'publishing' => false, 'fields' => [
            'title' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]]);
        $this->write('resources/folio/frontend/page.twig', '<h1>{{ record.title }}</h1>');
        $this->write('resources/folio/frontend/news.twig', '<h1>{{ record.title }}</h1>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function app(): Application
    {
        if ($this->booted === null) {
            $this->booted = Application::create($this->dir);
            $this->booted->boot();
        }

        return $this->booted;
    }

    private function fetch(string $uri): ResponseInterface
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri)->withQueryParams($query));
    }

    /** @param array<string, mixed> $body */
    private function send(string $uri, array $body = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri)
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody($body + ($this->token !== '' ? ['_csrf_token' => $this->token] : []));
        if ($this->token !== '' && session_id() !== '') {
            $request = $request->withCookieParams([session_name() => session_id()]);
        }

        return $this->app()->handle($request);
    }

    private function entries(): RedirectEntries
    {
        return $this->app()->container()->get(RedirectEntries::class);
    }

    private function notFound(): NotFoundEntries
    {
        return $this->app()->container()->get(NotFoundEntries::class);
    }

    /** @param array<string, string> $slug */
    private function publishPage(string $id, array $title, array $slug): void
    {
        $this->publisher()->publish($this->working('page', $id, ['title' => $title, 'slug' => $slug, '_locales' => array_keys($slug)]), null);
    }

    /** Hit $path $times in $locale (as the middleware records it). */
    private function hitMissing(string $locale, string $path, int $times): string
    {
        for ($i = 0; $i < $times; $i++) {
            $this->notFound()->record($path, $locale, new \DateTimeImmutable('2026-11-0' . ($i + 1) . 'T10:00:00Z'));
        }

        return NotFoundEntries::id($locale, $path);
    }

    public function test_index_lists_entries_with_targets_and_broken_flags(): void
    {
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);
        $this->entries()->storeUnchecked(['path' => '/old-about', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1', 'hits' => 7, 'created_at' => '2026-10-01T10:00:00+00:00']);
        $this->entries()->storeUnchecked(['path' => '/gone', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'nope', 'created_at' => '2026-09-01T10:00:00+00:00']);
        $this->entries()->storeUnchecked(['path' => '/left', 'locale' => 'de', 'kind' => 'removed', 'source_type' => 'page', 'source_id' => 'p9', 'created_at' => '2026-08-01T10:00:00+00:00']);
        $this->entries()->storeUnchecked(['path' => '/shop', 'locale' => '', 'area' => true, 'mode' => 'prefix', 'target_url' => 'https://example.org/store', 'status' => 302, 'created_at' => '2026-10-02T10:00:00+00:00']);

        $res = $this->fetch('/folio/redirects');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#<h1>\s*Redirects\s*</h1>#', $body);
        // A record target: its label and its URL; the hits (that row's own Hits cell).
        $this->assertMatchesRegularExpression('#/old-about.*?About.*?/about.*?<td class="is-number">7</td>#s', $body);
        // A URL target, an area, temporary.
        $this->assertStringContainsString('https://example.org/store', $body);
        $this->assertMatchesRegularExpression('#/shop.*?302#s', $body);
        // Broken (the target record is gone) and untargeted entries, first.
        $this->assertStringContainsString('Broken', $body);
        $this->assertStringContainsString('No target', $body);
        $this->assertStringContainsString('/de/left', $body, 'the address as the visitor sees it');
        $gone = strpos($body, '/gone');
        $left = strpos($body, '/de/left');
        $shop = strpos($body, '/shop');
        $old = strpos($body, '/old-about');
        $this->assertLessThan($shop, $gone);
        $this->assertLessThan($shop, $left);
        $this->assertLessThan($old, $shop, 'then the newest first');
        $this->assertStringContainsString('href="/folio/redirects/new"', $body);
        $this->assertStringContainsString('href="/folio/redirects?tab=not-found"', $body);
        // Search on the address.
        $searched = (string) $this->fetch('/folio/redirects?q=old')->getBody();
        $this->assertStringContainsString('/old-about', $searched);
        $this->assertStringNotContainsString('/shop', $searched);
    }

    public function test_index_filters_by_record(): void
    {
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);
        $this->entries()->storeUnchecked(['path' => '/to-p1', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1']);
        $this->entries()->storeUnchecked(['path' => '/from-p2', 'locale' => 'en', 'kind' => 'removed', 'source_type' => 'page', 'source_id' => 'p2']);
        $this->entries()->storeUnchecked(['path' => '/other', 'locale' => 'en', 'target_url' => '/x']);

        $body = (string) $this->fetch('/folio/redirects?record=page:p2')->getBody();
        $this->assertStringContainsString('/from-p2', $body);
        $this->assertStringNotContainsString('/to-p1', $body);
        $this->assertStringNotContainsString('/other', $body);

        $body = (string) $this->fetch('/folio/redirects?record=page:p1')->getBody();
        $this->assertStringContainsString('/to-p1', $body);
        $this->assertStringNotContainsString('/from-p2', $body);
        $this->assertStringNotContainsString('/other', $body);
    }

    public function test_not_found_tab_hides_single_hits(): void
    {
        $id = $this->hitMissing('de', '/missing', 3);
        $this->hitMissing('en', '/once', 1);

        $body = (string) $this->fetch('/folio/redirects?tab=not-found')->getBody();

        $this->assertStringContainsString('/de/missing', $body);
        $this->assertMatchesRegularExpression('#/de/missing.*?Deutsch.*?<td class="is-number">3</td>#s', $body);
        $this->assertStringNotContainsString('/once', $body);
        $this->assertStringContainsString('href="/folio/redirects/new?not_found=' . $id . '"', $body);
        $this->assertStringContainsString('action="/folio/redirects/not-found/' . $id . '/dismiss"', $body);
        $this->assertStringContainsString('name="_csrf_token"', $body);
    }

    public function test_create_exact_entry_with_page_target(): void
    {
        $this->publishPage('p1', ['en' => 'About', 'de' => 'Über uns'], ['en' => 'about', 'de' => 'ueber-uns']);
        $form = (string) $this->fetch('/folio/redirects/new')->getBody();
        $this->assertStringContainsString('action="/folio/redirects"', $form);
        $this->assertStringContainsString('data-redirect-targets="/folio/redirects/_targets"', $form);

        $res = $this->send('/folio/redirects', ['address' => '/old-about/', 'target' => 'page:p1', 'status' => '301']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/redirects', $res->getHeaderLine('Location'));
        $entry = $this->entries()->at('en', '/old-about');
        $this->assertSame(['manual', 'page', 'p1', null, 301, false], [$entry['kind'] ?? null, $entry['target_type'] ?? null, $entry['target_id'] ?? null, $entry['target_url'] ?? null, $entry['status'] ?? null, $entry['area'] ?? null]);
        $answer = $this->fetch('/old-about?ref=x');
        $this->assertSame(301, $answer->getStatusCode());
        $this->assertSame('/about?ref=x', $answer->getHeaderLine('Location'));

        // Editing keeps the hits; deleting removes the entry.
        $this->assertSame(1, $this->entries()->at('en', '/old-about')['hits']);
        $edit = (string) $this->fetch('/folio/redirects/' . $entry['id'])->getBody();
        // The address after the form's "/", its language in the select.
        $this->assertStringContainsString('name="address" value="old-about"', $edit);
        $this->assertStringContainsString('<option value="en" selected>', $edit);
        $this->assertStringContainsString('value="page:p1"', $edit);
        $this->assertSame(302, $this->send('/folio/redirects/' . $entry['id'], ['address' => '/old-about', 'target' => '/elsewhere', 'status' => '302'])->getStatusCode());
        $edited = $this->entries()->at('en', '/old-about');
        $this->assertSame(['/elsewhere', 302, 1], [$edited['target_url'], $edited['status'], $edited['hits']]);
        $this->assertSame(302, $this->send('/folio/redirects/' . $entry['id'] . '/delete')->getStatusCode());
        $this->assertNull($this->entries()->at('en', '/old-about'));
        $this->assertSame(404, $this->fetch('/folio/redirects/' . $entry['id'])->getStatusCode());
    }

    public function test_address_prefill_only_takes_a_path(): void
    {
        // From the admin's 404 page ("→ add a redirect"): a path on this site is prefilled.
        // The field shows the path after its "/"; a language prefix goes into the language select.
        $old = (string) $this->fetch('/folio/redirects/new?address=%2Fold')->getBody();
        $this->assertStringContainsString('name="address" value="old"', $old);
        $this->assertStringContainsString('<option value="en" selected>', $old, 'no prefix: the default language, as before');
        $german = (string) $this->fetch('/folio/redirects/new?address=/de/alte-seite')->getBody();
        $this->assertStringContainsString('name="address" value="alte-seite"', $german);
        $this->assertStringContainsString('<option value="de" selected>', $german);
        // A URL, another host, a relative address or a backslash host never is.
        foreach (['https://x', '//x', 'x', '/\\x', ' /x', 'javascript:alert(1)'] as $refused) {
            $res = $this->fetch('/folio/redirects/new?address=' . rawurlencode($refused));
            $this->assertSame(200, $res->getStatusCode(), $refused);
            $this->assertStringContainsString('name="address" value=""', (string) $res->getBody(), $refused);
        }
        // A not-found row still wins.
        $id = $this->hitMissing('de', '/missing', 2);
        $fromRow = (string) $this->fetch('/folio/redirects/new?not_found=' . $id . '&address=%2Fold')->getBody();
        $this->assertStringContainsString('name="address" value="missing"', $fromRow);
        $this->assertStringContainsString('<option value="de" selected>', $fromRow);
    }

    public function test_create_area_entry_prefix_mode(): void
    {
        $res = $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => 'prefix', 'target' => '/journal', 'status' => '302']);

        $this->assertSame(302, $res->getStatusCode());
        $entry = $this->entries()->all()[0] ?? [];
        $this->assertSame(['/blog', 'en', true, 'prefix', '/journal', 302], [$entry['path'] ?? null, $entry['locale'] ?? null, $entry['area'] ?? null, $entry['mode'] ?? null, $entry['target_url'] ?? null, $entry['status'] ?? null]);
        $answer = $this->fetch('/blog/2024/hello?x=1');
        $this->assertSame(302, $answer->getStatusCode());
        $this->assertSame('/journal/2024/hello?x=1', $answer->getHeaderLine('Location'));

        // Prefix mode needs a URL or path, without a query or fragment.
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);
        foreach (['page:p1', '/journal?x=1', '/journal#top', ''] as $target) {
            $refused = $this->send('/folio/redirects', ['address' => '/diary', 'area' => '1', 'mode' => 'prefix', 'target' => $target, 'status' => '301']);
            $this->assertSame(422, $refused->getStatusCode(), $target);
        }
        $this->assertCount(1, $this->entries()->all());
    }

    public function test_whole_site_area_and_unsafe_targets_are_refused(): void
    {
        $refused = $this->send('/folio/redirects', ['address' => '/', 'area' => '1', 'mode' => 'one', 'target' => '/x', 'status' => '301']);
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertStringContainsString('An area can’t cover the whole site.', (string) $refused->getBody());

        foreach (['//evil.example', '/\\evil.example', '\\\\evil.example', '\\/evil.example', 'javascript:alert(1)', 'ftp://example.org', 'nowhere'] as $target) {
            $res = $this->send('/folio/redirects', ['address' => '/old', 'target' => $target, 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $target);
        }
        // A path typed without its "/" gets it (the form shows one in front); a scheme never does.
        foreach (['/folio/page', '/--component/x', 'mailto:x@example.org', 'https://example.org/x', '/a/../b', '?x=1'] as $address) {
            $res = $this->send('/folio/redirects', ['address' => $address, 'target' => '/x', 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $address);
        }
        $this->assertSame([], $this->entries()->all());
    }

    public function test_area_target_inside_the_area_is_refused(): void
    {
        foreach (['prefix', 'one'] as $mode) {
            foreach (['/blog/archive', '/blog'] as $target) {
                $res = $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => $mode, 'target' => $target, 'status' => '301']);
                $this->assertSame(422, $res->getStatusCode(), "{$mode} {$target}");
            }
        }
        $this->assertStringContainsString('The target lies inside the area itself', (string) $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => 'prefix', 'target' => '/blog/archive', 'status' => '301'])->getBody());
        // Another language's /blog is another address.
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => 'prefix', 'target' => '/de/blog/archiv', 'status' => '301'])->getStatusCode());
    }

    public function test_target_under_an_area_entry_is_refused(): void
    {
        $this->entries()->save(['path' => '/shop', 'locale' => 'en', 'area' => true, 'target_url' => '/store']);

        $res = $this->send('/folio/redirects', ['address' => '/old', 'target' => '/shop/shoes', 'status' => '301']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('That address redirects already; pick its target instead.', (string) $res->getBody());
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/old', 'target' => '/shopping', 'status' => '301'])->getStatusCode());
    }

    public function test_address_other_entries_send_to_is_refused(): void
    {
        $this->entries()->save(['path' => '/a', 'locale' => 'en', 'target_url' => '/b']);
        $this->entries()->save(['path' => '/c', 'locale' => 'de', 'target_url' => '/de/news/x']);

        foreach ([['/b', false], ['/de/news', true]] as [$address, $area]) {
            $res = $this->send('/folio/redirects', ['address' => $address, 'area' => $area ? '1' : '', 'target' => '/elsewhere', 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $address);
            $this->assertStringContainsString('Other redirects send visitors here', (string) $res->getBody());
        }
        // Editing an entry is refused the same way when its address becomes a target of others.
        $this->entries()->save(['path' => '/d', 'locale' => 'en', 'target_url' => '/e']);
        $d = $this->entries()->at('en', '/d');
        $this->assertSame(422, $this->send('/folio/redirects/' . $d['id'], ['address' => '/b', 'target' => '/e', 'status' => '301'])->getStatusCode());
        $this->assertNotNull($this->entries()->at('en', '/d'));
        // The entry's own target does not count against it.
        $this->assertSame(302, $this->send('/folio/redirects/' . $d['id'], ['address' => '/d', 'target' => '/f', 'status' => '301'])->getStatusCode());
    }

    public function test_addresses_a_keep_the_rest_area_sends_to_are_refused(): void
    {
        $this->entries()->save(['path' => '/blog', 'locale' => 'en', 'area' => true, 'mode' => 'prefix', 'target_url' => '/journal']);

        foreach ([['/journal/x', false], ['/journal/2020', true], ['/journal', true]] as [$address, $area]) {
            $res = $this->send('/folio/redirects', ['address' => $address, 'area' => $area ? '1' : '', 'target' => '/elsewhere', 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $address);
            $this->assertStringContainsString('Other redirects send visitors here', (string) $res->getBody());
        }
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/de/journal/x', 'target' => '/y', 'status' => '301'])->getStatusCode(), 'another language');
        // An area being saved that holds another area's target space.
        $this->entries()->save(['path' => '/news', 'locale' => 'en', 'area' => true, 'mode' => 'prefix', 'target_url' => '/press/all']);
        $this->assertSame(422, $this->send('/folio/redirects', ['address' => '/press', 'area' => '1', 'target' => '/y', 'status' => '301'])->getStatusCode());
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/journalism', 'target' => '/y', 'status' => '301'])->getStatusCode(), 'whole segments only');
    }

    public function test_keep_the_rest_target_with_entries_below_it_is_refused(): void
    {
        $this->entries()->save(['path' => '/journal/x', 'locale' => 'en', 'target_url' => '/z']);
        $this->entries()->save(['path' => '/press/sub', 'locale' => 'en', 'area' => true, 'target_url' => '/z']);

        foreach (['/journal', '/press'] as $target) {
            $res = $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => 'prefix', 'target' => $target, 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $target);
            $this->assertStringContainsString('That address redirects already; pick its target instead.', (string) $res->getBody());
        }
        // Another language, or one target for all: fine.
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => 'prefix', 'target' => '/de/journal', 'status' => '301'])->getStatusCode());
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/old', 'area' => '1', 'mode' => 'one', 'target' => '/journal', 'status' => '301'])->getStatusCode());
    }

    public function test_address_is_normalized_before_its_language_is_read(): void
    {
        // LocaleMiddleware strips every available locale's prefix, the default one too.
        $this->send('/folio/redirects', ['address' => '//de//alt/', 'target' => '/de/neu', 'status' => '301']);
        $this->send('/folio/redirects', ['address' => '/en/old', 'target' => '/new', 'status' => '301']);
        $this->send('/folio/redirects', ['address' => '/DE/x', 'target' => '/y', 'status' => '301']);

        $this->assertSame('/de/neu', $this->entries()->at('de', '/alt')['target_url'] ?? null);
        $this->assertSame('/new', $this->entries()->at('en', '/old')['target_url'] ?? null);
        $this->assertSame('/new', $this->fetch('/old')->getHeaderLine('Location'));
        $this->assertSame('/new', $this->fetch('/en/old')->getHeaderLine('Location'));
        // "DE" is no locale prefix to LocaleMiddleware (lowercase codes only): a path of the default language.
        $this->assertSame('/y', $this->entries()->at('en', '/DE/x')['target_url'] ?? null);
        $this->assertSame('/y', $this->fetch('/DE/x')->getHeaderLine('Location'));
    }

    public function test_single_language_site_with_prefixes_keeps_the_real_locale(): void
    {
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about-us']);
        $auto = $this->entries()->at('en', '/about');
        $this->assertSame('auto', $auto['kind'] ?? null);

        // Same locale as the automatic entry: no second entry at the same address.
        $res = $this->send('/folio/redirects', ['address' => '/about', 'target' => '/x', 'status' => '301']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('This address has a redirect already.', (string) $res->getBody());

        // Editing the automatic entry keeps its locale and kind.
        $this->assertSame(302, $this->send('/folio/redirects/' . $auto['id'], ['address' => '/about', 'target' => '/x'])->getStatusCode());
        $edited = $this->entries()->at('en', '/about');
        $this->assertSame(['en', 'auto', '/x', 301], [$edited['locale'], $edited['kind'], $edited['target_url'], $edited['status']]);
        $this->assertCount(1, $this->entries()->all());

        $this->send('/folio/redirects', ['address' => '/old', 'target' => '/new', 'status' => '301']);
        $this->assertNotNull($this->entries()->at('en', '/old'));
    }

    public function test_language_neutral_entries_without_prefixes(): void
    {
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'none'];\n");

        $this->send('/folio/redirects', ['address' => '/de/alt', 'target' => '/neu', 'status' => '301']);

        $this->assertSame('/neu', $this->entries()->at('', '/de/alt')['target_url'] ?? null, 'no prefixes: /de is part of the path');
    }

    public function test_area_entry_clears_the_not_found_rows_under_it(): void
    {
        $this->hitMissing('en', '/blog/one', 2);
        $this->hitMissing('en', '/blog/two/three', 2);
        $this->hitMissing('de', '/blog/one', 2);
        $this->hitMissing('en', '/blogger', 2);

        $this->send('/folio/redirects', ['address' => '/blog', 'area' => '1', 'mode' => 'prefix', 'target' => '/journal', 'status' => '301']);

        $this->assertSame(['de /blog/one', 'en /blogger'], array_map(static fn (array $r): string => $r['locale'] . ' ' . $r['path'], $this->notFound()->visible()) |> (static function (array $rows): array { sort($rows); return $rows; }));
    }

    public function test_typed_address_with_umlauts_matches_the_browser_form(): void
    {
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/de/über-uns', 'target' => '/de/wir', 'status' => '301'])->getStatusCode());

        $answer = $this->fetch('/de/%C3%BCber-uns');
        $this->assertSame(301, $answer->getStatusCode());
        $this->assertSame('/de/wir', $answer->getHeaderLine('Location'));
        // Shown as typed.
        $this->assertStringContainsString('/de/über-uns', (string) $this->fetch('/folio/redirects')->getBody());
    }

    public function test_locale_prefix_becomes_the_entry_language(): void
    {
        $this->send('/folio/redirects', ['address' => '/de/alt', 'target' => '/de/neu', 'status' => '301']);
        $this->send('/folio/redirects', ['address' => '/old', 'target' => '/new', 'status' => '301']);

        $this->assertSame('/de/neu', $this->entries()->at('de', '/alt')['target_url'] ?? null);
        $this->assertSame('/new', $this->entries()->at('en', '/old')['target_url'] ?? null);
        $this->assertSame(404, $this->fetch('/alt')->getStatusCode(), 'German only');
        $this->assertSame('/de/neu', $this->fetch('/de/alt')->getHeaderLine('Location'));
    }

    public function test_address_of_a_live_page_is_refused(): void
    {
        $this->publishPage('p1', ['en' => 'About', 'de' => 'Über uns'], ['en' => 'about', 'de' => 'ueber-uns']);
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);

        foreach (['/about', '/de/ueber-uns', '/news/hello'] as $address) {
            $res = $this->send('/folio/redirects', ['address' => $address, 'target' => '/elsewhere', 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $address);
            $this->assertStringContainsString('A page has this address; it always wins.', (string) $res->getBody());
            $this->assertStringContainsString('name="address" value="' . ltrim($address, '/') . '"', (string) $res->getBody(), 'the typed address stays (after the form\'s "/")');
        }
        $this->assertSame([], $this->entries()->all());
    }

    public function test_a_refused_address_hides_the_target_rules_that_need_it(): void
    {
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);

        $res = $this->send('/folio/redirects', ['address' => '/about', 'target' => '/about', 'status' => '301']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('A page has this address; it always wins.', (string) $res->getBody());
        $this->assertStringNotContainsString('The target is the old address itself.', (string) $res->getBody());
    }

    public function test_target_that_redirects_already_is_refused(): void
    {
        $this->entries()->save(['path' => '/a', 'locale' => 'en', 'target_url' => '/b']);
        $this->entries()->save(['path' => '/alt', 'locale' => 'de', 'target_url' => '/de/neu']);

        foreach (['/a', '/de/alt'] as $target) {
            $res = $this->send('/folio/redirects', ['address' => '/c', 'target' => $target, 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $target);
            $this->assertStringContainsString('That address redirects already; pick its target instead.', (string) $res->getBody());
        }
        // A target equal to the address.
        $this->assertSame(422, $this->send('/folio/redirects', ['address' => '/c', 'target' => '/c/', 'status' => '301'])->getStatusCode());
        $this->assertNull($this->entries()->at('en', '/c'));
    }

    public function test_typed_address_of_a_page_becomes_a_record_target(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key', 'url' => 'https://example.com'];\n");
        $this->publishPage('p1', ['en' => 'Team', 'de' => 'Team'], ['en' => 'b', 'de' => 'b-de']);
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);

        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/x', 'target' => '/b', 'status' => '301'])->getStatusCode());
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/y', 'target' => 'https://example.com/b', 'status' => '301'])->getStatusCode());
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/de/z', 'target' => '/de/news/hello', 'status' => '301'])->getStatusCode());

        $target = fn (string $locale, string $path): ?string => ($e = $this->entries()->at($locale, $path)) === null ? null
            : ($e['target_url'] ?? $e['target_type'] . ':' . $e['target_id']);
        $this->assertSame('page:p1', $target('en', '/x'));
        $this->assertSame('page:p1', $target('en', '/y'), "an absolute URL with the site's host");
        $this->assertSame('news:n1', $target('de', '/z'));

        // The page moves: the redirect follows it, no chain.
        $this->publishPage('p1', ['en' => 'Team', 'de' => 'Team'], ['en' => 'c', 'de' => 'b-de']);
        $answer = $this->fetch('/x');
        $this->assertSame(301, $answer->getStatusCode());
        $this->assertSame('/c', $answer->getHeaderLine('Location'));

        // Another language's page, a query, another host, a path no page holds: kept as typed.
        foreach (['/w1' => '/de/b-de', '/w2' => '/c?ref=x', '/w3' => 'https://example.org/c', '/w4' => '/nothing-here'] as $address => $typed) {
            $this->assertSame(302, $this->send('/folio/redirects', ['address' => $address, 'target' => $typed, 'status' => '301'])->getStatusCode(), $typed);
            $this->assertSame($typed, $target('en', $address));
        }
        // "Keep the rest" sends the rest of the path along: a URL stays a URL there.
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/old', 'area' => '1', 'mode' => 'prefix', 'target' => '/c', 'status' => '301'])->getStatusCode());
        $this->assertSame('/c', $this->entries()->all()[array_search('/old', array_column($this->entries()->all(), 'path'), true)]['target_url']);
    }

    public function test_record_target_off_the_site_is_refused(): void
    {
        $this->working('page', 'd1', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'draft'], '_locales' => ['en']]);

        foreach (['page:d1', 'page:nope'] as $posted) {
            $res = $this->send('/folio/redirects', ['address' => '/old', 'target' => $posted, 'status' => '301']);
            $this->assertSame(422, $res->getStatusCode(), $posted);
        }
        $this->assertSame([], $this->entries()->all());
    }

    public function test_screens_ask_for_migrate_when_the_tables_are_missing(): void
    {
        // A SQL site upgraded but not migrated: Folio's internal storage has no redirect tables.
        $container = $this->app()->container();
        $dm = $container->get(\Preflow\Data\DataManager::class);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $bare = new \Preflow\Data\DataManager(['json' => $dm->driver('json'), 'default' => new \Preflow\Data\Driver\SqliteDriver($pdo)], 'json', $dm->getTypeRegistry());
        $container->instance(RedirectEntries::class, new RedirectEntries($bare));
        $container->instance(NotFoundEntries::class, new NotFoundEntries($bare));

        $answers = [
            'list' => $this->fetch('/folio/redirects'),
            'not found tab' => $this->fetch('/folio/redirects?tab=not-found'),
            'new' => $this->fetch('/folio/redirects/new?not_found=x'),
            'edit' => $this->fetch('/folio/redirects/abc'),
            'save' => $this->send('/folio/redirects', ['address' => '/old', 'target' => '/new', 'status' => '301']),
            'update' => $this->send('/folio/redirects/abc', ['address' => '/old', 'target' => '/new', 'status' => '301']),
            'delete' => $this->send('/folio/redirects/abc/delete'),
            'dismiss' => $this->send('/folio/redirects/not-found/abc/dismiss'),
        ];
        foreach ($answers as $what => $res) {
            $this->assertSame(503, $res->getStatusCode(), $what);
            $this->assertStringContainsString('Redirects need their database tables. Run this command on the server: <code>preflow migrate</code>', (string) $res->getBody(), $what);
            $this->assertStringNotContainsString('folio-subtitle', (string) $res->getBody(), $what . ': no intro over a screen that cannot work');
        }
    }

    public function test_redirect_from_not_found_removes_the_row(): void
    {
        $id = $this->hitMissing('de', '/fehlt', 2);
        $this->hitMissing('de', '/andere', 2);

        $form = (string) $this->fetch('/folio/redirects/new?not_found=' . $id)->getBody();
        $this->assertStringContainsString('name="address" value="fehlt"', $form);
        $this->assertStringContainsString('<option value="de" selected>', $form);
        $this->assertStringContainsString('name="not_found" value="' . $id . '"', $form);

        $res = $this->send('/folio/redirects', ['address' => '/de/fehlt', 'target' => '/de/da', 'status' => '301', 'not_found' => $id]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(['/andere'], array_column($this->notFound()->visible(), 'path'));
        $this->assertNull($this->notFound()->find($id));
    }

    public function test_dismiss_hides_the_row(): void
    {
        $id = $this->hitMissing('en', '/missing', 2);

        $res = $this->send('/folio/redirects/not-found/' . $id . '/dismiss');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/redirects?tab=not-found', $res->getHeaderLine('Location'));
        $this->assertSame([], $this->notFound()->visible());
        $this->assertNotNull($this->notFound()->find($id)['dismissed_at'] ?? null);
    }

    public function test_targets_endpoint_lists_only_public_pages(): void
    {
        $this->publishPage('p1', ['en' => 'About us'], ['en' => 'about']);
        $this->working('page', 'p2', ['title' => ['en' => 'About draft'], 'slug' => ['en' => 'about-draft'], '_locales' => ['en']]);
        $this->working('news', 'n1', ['title' => 'About the news', 'slug' => 'about-news', 'status' => 'published']);
        $this->working('news', 'n2', ['title' => 'About the old news', 'slug' => 'old-news', 'status' => 'draft']);
        $this->working('snippet', 's1', ['title' => 'About snippet', 'status' => 'published']);
        $this->working('news', 'n3', ['title' => 'Elsewhere', 'slug' => 'elsewhere', 'status' => 'published']);

        $res = $this->fetch('/folio/redirects/_targets?q=about');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $items = json_decode((string) $res->getBody(), true)['items'] ?? null;
        $this->assertIsArray($items);
        $byValue = array_column($items, null, 'value');
        ksort($byValue);
        $this->assertSame(['news:n1', 'page:p1'], array_keys($byValue));
        $this->assertSame(['value' => 'page:p1', 'label' => 'About us', 'type' => 'Pages', 'url' => '/about'], $byValue['page:p1']);
        $this->assertSame('/news/about-news', $byValue['news:n1']['url']);

        for ($i = 0; $i < 25; $i++) {
            $this->working('news', 'm' . $i, ['title' => 'Many ' . $i, 'slug' => 'many-' . $i, 'status' => 'published']);
        }
        $this->assertCount(20, json_decode((string) $this->fetch('/folio/redirects/_targets?q=many')->getBody(), true)['items']);
    }

    public function test_routes_ask_redirects_manage_except_targets(): void
    {
        foreach (['index', 'create', 'store', 'edit', 'update', 'destroy', 'dismiss'] as $action) {
            $this->assertSame('redirects.manage', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\RedirectsController@' . $action), $action);
        }
        $this->assertSame(FolioPermissionMiddleware::SIGNED_IN, FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\RedirectsController@targets'));
    }

    #[RunInSeparateProcess]
    public function test_screen_needs_redirects_manage(): void
    {
        $this->signInAs('editor');

        $this->assertSame(403, $this->fetch('/folio/redirects')->getStatusCode());
        $this->assertSame(403, $this->fetch('/folio/redirects?tab=not-found')->getStatusCode());
        $this->assertSame(403, $this->fetch('/folio/redirects/new')->getStatusCode());
        $this->assertSame(403, $this->send('/folio/redirects', ['address' => '/old', 'target' => '/new', 'status' => '301'])->getStatusCode());
        $this->assertSame([], $this->entries()->all());
        $targets = $this->fetch('/folio/redirects/_targets?q=a');
        $this->assertSame(200, $targets->getStatusCode(), 'any signed-in staff user: it lists public pages only');
        $this->assertSame(['items' => []], json_decode((string) $targets->getBody(), true));
    }

    #[RunInSeparateProcess]
    public function test_nav_item_only_with_the_permission(): void
    {
        $this->signInAs('manager');
        $body = (string) $this->fetch('/folio/redirects')->getBody();
        $this->assertMatchesRegularExpression('#<span class="folio-nav-label">Settings</span>.*?<a href="/folio/redirects" class="active"#s', $body);

        $this->booted = null;
        $this->signInAs('editor');
        $this->assertStringNotContainsString('href="/folio/redirects"', (string) $this->fetch('/folio/page')->getBody());
    }

    public function test_list_rows_tabs_and_drawer_links(): void
    {
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);
        $this->entries()->storeUnchecked(['path' => '/old-about', 'locale' => '', 'target_type' => 'page', 'target_id' => 'p1', 'hits' => 2, 'created_at' => '2026-10-01T10:00:00+00:00']);

        // No not-found rows: no badge on the tab.
        $body = (string) $this->fetch('/folio/redirects')->getBody();
        $this->assertStringNotContainsString('folio-tab-badge', $body);
        // The header action and each row's Edit open the drawer (plain links without script).
        $this->assertMatchesRegularExpression('#<a class="btn btn-primary" href="/folio/redirects/new" data-folio-drawer><svg[^>]*><use href="[^"]*\#ui-plus"></use></svg>New redirect</a>#', $body);
        $this->assertStringContainsString('<th>From → to</th>', $body);
        // From → to in one cell: the address, then the target; the language under it; the kind and code.
        $this->assertMatchesRegularExpression('#<code>/old-about</code></a>.*?<span class="folio-redirect-to">About</span> <code class="folio-redirect-url">/about</code>.*?<span class="folio-redirect-meta">Any language · Pages</span>#s', $body);
        $this->assertMatchesRegularExpression('#<span class="folio-redirect-kind">Handmade</span></td>\s*<td class="folio-mono">301</td>#', $body);
        // The row menu: Edit (drawer) and Delete asking "Delete this redirect?" about that address, Keep / Delete.
        $id = $this->entries()->at('', '/old-about')['id'];
        $this->assertStringContainsString('<a class="folio-menu-item" role="menuitem" href="/folio/redirects/' . $id . '" data-folio-drawer>', $body);
        $this->assertStringContainsString('<form method="post" action="/folio/redirects/' . $id . '/delete" data-confirm="Delete this redirect?" data-confirm-item="/old-about" data-confirm-cancel="Keep" data-confirm-label="Delete" data-confirm-tone="danger" data-confirm-icon="ui-trash">', $body);

        // Two addresses on the not-found list: the badge counts them, on both tabs.
        $id1 = $this->hitMissing('en', '/missing-a', 2);
        $this->hitMissing('de', '/missing-b', 3);
        $this->hitMissing('en', '/once', 1);
        $badge = '#href="/folio/redirects\?tab=not-found"(?: aria-current="page")?>Not found <span class="folio-tab-badge"><span class="folio-sr">\(</span>2<span class="folio-sr">\)</span></span></a>#';
        $this->assertMatchesRegularExpression($badge, (string) $this->fetch('/folio/redirects')->getBody());
        $notFound = (string) $this->fetch('/folio/redirects?tab=not-found')->getBody();
        $this->assertMatchesRegularExpression($badge, $notFound);
        // "→ add a redirect" opens the drawer with the address.
        $this->assertStringContainsString('href="/folio/redirects/new?not_found=' . $id1 . '" data-folio-drawer>→ add a redirect</a>', $notFound);
    }

    public function test_search_finds_the_old_or_the_new_address(): void
    {
        $this->publishPage('p1', ['en' => 'Team'], ['en' => 'team']);
        $this->entries()->storeUnchecked(['path' => '/crew', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1']);
        $this->entries()->storeUnchecked(['path' => '/shop', 'locale' => 'en', 'target_url' => 'https://example.org/store']);
        $this->entries()->storeUnchecked(['path' => '/other', 'locale' => 'en', 'target_url' => '/x']);

        $byTarget = (string) $this->fetch('/folio/redirects?q=store')->getBody();
        $this->assertStringContainsString('/shop', $byTarget);
        $this->assertStringNotContainsString('/crew', $byTarget);
        $byLabel = (string) $this->fetch('/folio/redirects?q=team')->getBody();
        $this->assertStringContainsString('/crew', $byLabel);
        $this->assertStringNotContainsString('/other', $byLabel);
        $this->assertStringContainsString('placeholder="Old or new address"', $byLabel);
    }

    public function test_form_parts(): void
    {
        $this->publishPage('p1', ['en' => 'About'], ['en' => 'about']);
        $form = (string) $this->fetch('/folio/redirects/new')->getBody();

        $this->assertStringContainsString('<span class="folio-input-prefix" aria-hidden="true">/</span>', $form);
        $this->assertStringContainsString('A path such as /old-page. Queries are ignored.', $form);
        $this->assertMatchesRegularExpression('#<select id="folio-redirect-language" name="language">\s*<option value="">Any language</option><option value="en" selected>English</option><option value="de">Deutsch</option>#', $form, 'a blank form starts in the default language');
        // "Send visitors to": the segmented (shown by script), the page search (a combobox over _targets) and the
        // address input; both post "target", the page's only once script enables it. No datalist any more.
        $this->assertStringContainsString('<legend class="form-label">Send visitors to', $form);
        $this->assertMatchesRegularExpression('#data-redirect-kinds hidden>\s*<label class="folio-segment"><input type="radio" name="target_kind" value="page" checked><span>A page</span></label>\s*<label class="folio-segment"><input type="radio" name="target_kind" value="address"><span>An address</span></label>#', $form);
        $this->assertStringContainsString('<input type="hidden" name="target" value="" data-redirect-page-value disabled>', $form);
        $this->assertMatchesRegularExpression('#<input type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="folio-redirect-results"[^>]* data-redirect-targets="/folio/redirects/_targets"#', $form);
        $this->assertStringContainsString('role="listbox"', $form);
        $this->assertMatchesRegularExpression('#<input type="text" id="folio-redirect-target" name="target" value=""#', $form);
        $this->assertStringNotContainsString('<datalist', $form);
        // The answer as radio cards.
        $this->assertMatchesRegularExpression('#<label class="folio-radio-card"><input type="radio" name="status" value="301" checked><span class="folio-radio-card-text"><span class="folio-radio-card-label">301 · Moved for good</span><span class="folio-radio-card-help">Search engines move the ranking over.</span>#', $form);
        $this->assertMatchesRegularExpression('#<input type="radio" name="status" value="302"><span class="folio-radio-card-text"><span class="folio-radio-card-label">302 · For now</span><span class="folio-radio-card-help">The old address comes back later.</span>#', $form);
        $this->assertMatchesRegularExpression('#<a class="btn btn-ghost" href="/folio/redirects">Cancel</a>\s*<button type="submit" class="btn btn-primary"[^>]*>Save redirect</button>#', $form);
        $this->assertStringContainsString('class="folio-shell', $form, 'without _drawer=1: the page');

        // A page target: the page mode with the pick as a row (label, type and URL).
        $this->entries()->storeUnchecked(['path' => '/old', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1', 'status' => 302]);
        $edit = (string) $this->fetch('/folio/redirects/' . $this->entries()->at('en', '/old')['id'])->getBody();
        $this->assertStringContainsString('<input type="hidden" name="target" value="page:p1" data-redirect-page-value disabled>', $edit);
        $this->assertStringContainsString('<span class="folio-redirect-picked-label" data-redirect-picked-label>About</span>', $edit);
        $this->assertStringContainsString('<span class="folio-redirect-picked-meta" data-redirect-picked-meta>Pages · /about</span>', $edit);
        $this->assertStringContainsString('value="302" checked', $edit);
        // A URL target: the address mode.
        $this->entries()->storeUnchecked(['path' => '/away', 'locale' => 'en', 'target_url' => 'https://example.org/']);
        $away = (string) $this->fetch('/folio/redirects/' . $this->entries()->at('en', '/away')['id'])->getBody();
        $this->assertStringContainsString('name="target_kind" value="address" checked', $away);
        $this->assertStringContainsString('name="target" value="https://example.org/"', $away);
    }

    public function test_form_in_the_drawer(): void
    {
        $id = $this->hitMissing('en', '/gone', 2);
        $form = (string) $this->fetch('/folio/redirects/new?not_found=' . $id . '&_drawer=1')->getBody();

        $this->assertStringContainsString('<body class="folio-drawer-body">', $form);
        $this->assertStringNotContainsString('class="folio-shell', $form);
        $this->assertStringContainsString('<input type="hidden" name="_drawer" value="1">', $form);
        $this->assertStringContainsString('name="address" value="gone"', $form);
        $this->assertMatchesRegularExpression('#<div class="folio-drawer-actions">\s*<a class="btn btn-ghost" href="/folio/redirects" data-folio-drawer-cancel>Cancel</a>#', $form);

        // A refused form stays in the drawer.
        $refused = $this->send('/folio/redirects', ['address' => '', 'target' => '/x', 'status' => '301', '_drawer' => '1']);
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertStringContainsString('<body class="folio-drawer-body">', (string) $refused->getBody());
        $this->assertStringContainsString('Enter the old address.', (string) $refused->getBody());

        // A saved one tells the list behind it (drawer_saved: folio-drawer, type "redirect"), which reloads.
        $saved = $this->send('/folio/redirects', ['address' => 'gone', 'language' => 'en', 'target' => '/x', 'status' => '301', 'not_found' => $id, '_drawer' => '1']);
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertMatchesRegularExpression('#source: \'folio-drawer\', type: "redirect", id: ""#', (string) $saved->getBody());
        $this->assertSame('/x', $this->entries()->at('en', '/gone')['target_url'] ?? null);
        $this->assertNull($this->notFound()->find($id));
    }

    public function test_language_select_and_typed_address(): void
    {
        // The select decides the language of an address typed without a prefix; '' is any language.
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => 'alt', 'language' => 'de', 'target' => '/de/neu', 'status' => '301'])->getStatusCode());
        $this->assertSame('/de/neu', $this->entries()->at('de', '/alt')['target_url'] ?? null);
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => 'anywhere', 'language' => '', 'target' => '/x', 'status' => '301'])->getStatusCode());
        $this->assertSame('/x', $this->entries()->at('', '/anywhere')['target_url'] ?? null);
        $this->assertSame('/x', $this->fetch('/de/anywhere')->getHeaderLine('Location'), 'any language: also under /de');
        $this->assertSame('/x', $this->fetch('/anywhere')->getHeaderLine('Location'));
        // A prefix typed in front wins over the select.
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/de/vorher', 'language' => '', 'target' => '/y', 'status' => '301'])->getStatusCode());
        $this->assertSame('/y', $this->entries()->at('de', '/vorher')['target_url'] ?? null);
        // An unknown language is not one: the address alone decides, as without the select.
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => '/frueher', 'language' => 'xx', 'target' => '/y', 'status' => '301'])->getStatusCode());
        $this->assertNotNull($this->entries()->at('en', '/frueher'));
        // A query or fragment is left out (the lookup never sees one).
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => 'with-query?x=1#top', 'target' => '/y', 'status' => '301'])->getStatusCode());
        $this->assertNotNull($this->entries()->at('en', '/with-query'));

        // Editing an any-language entry keeps "Any language" in the select.
        $edit = (string) $this->fetch('/folio/redirects/' . $this->entries()->at('', '/anywhere')['id'])->getBody();
        $this->assertStringContainsString('name="address" value="anywhere"', $edit);
        $this->assertStringContainsString('<option value="" selected>Any language</option>', $edit);
    }

    public function test_language_is_read_only_where_the_select_is_shown(): void
    {
        // A prefixed site with one language: no select, so a posted language is ignored (the default language).
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->assertStringNotContainsString('name="language"', (string) $this->fetch('/folio/redirects/new')->getBody());

        $this->assertSame(302, $this->send('/folio/redirects', ['address' => 'old', 'language' => '', 'target' => '/x', 'status' => '301'])->getStatusCode());
        $this->assertNotNull($this->entries()->at('en', '/old'));
        $this->assertNull($this->entries()->at('', '/old'));
    }

    public function test_a_colon_in_a_path_is_no_scheme(): void
    {
        $this->assertSame(302, $this->send('/folio/redirects', ['address' => 'news:2024', 'target' => '/x', 'status' => '301'])->getStatusCode());
        $this->assertNotNull($this->entries()->at('en', (string) \Preflow\Routing\Redirect\RedirectPath::normalize('/news:2024')));
        foreach (['mailto:x@example.org', 'tel:+41', 'javascript:alert(1)', 'data:text/html,x', 'ftp://example.org/x', 'HTTPS:example.org'] as $scheme) {
            $this->assertSame(422, $this->send('/folio/redirects', ['address' => $scheme, 'target' => '/x', 'status' => '301'])->getStatusCode(), $scheme);
        }
    }

    public function test_a_broken_page_target_shows_its_pick_with_the_badge(): void
    {
        $this->entries()->storeUnchecked(['path' => '/gone', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'nope']);
        $edit = (string) $this->fetch('/folio/redirects/' . $this->entries()->at('en', '/gone')['id'])->getBody();

        $this->assertStringContainsString('name="target_kind" value="page" checked', $edit);
        $this->assertStringContainsString('<input type="hidden" name="target" value="page:nope" data-redirect-page-value disabled>', $edit);
        $this->assertMatchesRegularExpression('#<div class="folio-redirect-picked" data-redirect-picked aria-live="polite">.*?<span class="folio-redirect-picked-label" data-redirect-picked-label>page:nope</span>\s*<span class="folio-badge is-incomplete"[^>]*data-redirect-picked-broken>Broken</span>#s', $edit);
    }

    public function test_empty_list_action_opens_the_drawer(): void
    {
        $this->assertStringContainsString('<a class="btn folio-ink-action" href="/folio/redirects/new" data-folio-drawer>New redirect</a>', (string) $this->fetch('/folio/redirects')->getBody());
    }

    /** Signed in with auth and roles: "manager" may manage redirects, "editor" may not. */
    private function signInAs(string $role): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'manager@site.test', 'password_hash' => $hash, 'roles' => ['manager']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_redirects_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'manager' => ['folio.dashboard', 'page.view', 'redirects.manage'],
            'editor' => ['folio.dashboard', 'page.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $f = new Psr17Factory();
        $page = (string) $this->app()->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->app()->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => $role . '@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }
}
