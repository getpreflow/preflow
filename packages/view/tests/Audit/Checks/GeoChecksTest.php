<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\GeoMarkdownThin;
use Preflow\View\Audit\Checks\GeoTypeSchemaMissing;
use Preflow\View\Audit\Checks\LlmsFullTooLarge;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Seo\SeoConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The GEO checks of the SEO audit (0.55): thin Markdown, page groups without their own JSON-LD, a too large llms-full.txt. */
final class GeoChecksTest extends TestCase
{
    use CheckFixtures;

    /** @var array<string, ResponseInterface> path => answer */
    private array $routes = [];

    /** @var list<string> paths rendered, in order */
    private array $rendered = [];

    private function renderer(): PageRenderer
    {
        $this->rendered = [];

        return new PageRenderer(function (ServerRequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();
            $this->rendered[] = $path;

            return $this->routes[$path] ?? new Response(404, ['Content-Type' => 'text/plain'], "Not Found\n");
        }, 'https://site.test', null, null, ['en', 'de'], 'en');
    }

    private static function markdown(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/markdown; charset=utf-8'], "# Title\n\nSource: https://site.test/x\nUpdated: 2026-11-05\n\n" . $body);
    }

    private static function words(int $n, string $word = 'word'): string
    {
        return trim(str_repeat($word . ' ', $n));
    }

    private function thin(bool $geo = true): GeoMarkdownThin
    {
        return new GeoMarkdownThin($this->registry(), $this->renderer(), new GeoConfig(enabled: $geo));
    }

    // geo.markdown-thin

    public function test_markdown_as_long_as_the_page_is_fine(): void
    {
        $this->routes['/about.md'] = self::markdown(self::words(90) . ' [a link](https://site.test/very/long/url/that/counts/nothing)');
        $page = $this->page($this->html(body: '<nav>' . self::words(500, 'menu') . '</nav><main><p>' . self::words(100) . '</p></main>'));

        $check = $this->thin();
        $this->assertSame('geo.markdown-thin', $check->id());
        $this->assertSame([], $check->check($page));
        $this->assertSame(['/about.md'], $this->rendered, 'the page\'s own Markdown view');
    }

    public function test_markdown_under_a_third_of_the_pages_text(): void
    {
        $this->routes['/about.md'] = self::markdown(self::words(30));
        $page = $this->page($this->html(body: '<main><p>' . self::words(100) . '</p><script>' . self::words(900, 'code') . '</script></main>'));

        $findings = $this->thin()->check($page);
        $this->assertSame(['geo.markdown-thin|warning|https://site.test/about|en'], $this->summary($findings));
        $this->assertStringContainsString('30 words', $findings[0]->message);
        $this->assertStringContainsString('100', $findings[0]->message);
        $this->assertTargetsNamePages($findings);
    }

    public function test_empty_markdown_is_reported_even_for_a_short_page(): void
    {
        $this->routes['/de/kontakt.md'] = self::markdown('');
        $page = $this->page($this->html(body: '<main><p>Schreiben Sie uns.</p></main>'), 'https://site.test/de/kontakt', 'de');

        $this->assertSame(['geo.markdown-thin|warning|https://site.test/de/kontakt|de'], $this->summary($this->thin()->check($page)));
    }

    public function test_a_short_page_with_some_markdown_is_fine(): void
    {
        // Under 50 words of page text, only an empty Markdown counts: short pages vary too much.
        $this->routes['/about.md'] = self::markdown(self::words(5));
        $this->assertSame([], $this->thin()->check($this->page($this->html(body: '<main><p>' . self::words(40) . '</p></main>'))));
    }

    public function test_pages_without_a_markdown_view_and_geo_off_find_nothing(): void
    {
        // No Markdown view (excluded, noindex): that is the page's choice, not a thin view.
        $page = $this->page($this->html(body: '<main><p>' . self::words(100) . '</p></main>'));
        $this->assertSame([], $this->thin()->check($page));

        $this->routes['/about.md'] = self::markdown('');
        $this->assertSame([], $this->thin(geo: false)->check($page));
        $this->assertSame([], $this->rendered, 'GEO off renders nothing');
        $this->assertSame([], $this->thin()->check(new \Preflow\View\Audit\RenderedPage('https://site.test/about', 'en', 301, [], '')));
    }

    // geo.type-schema-missing

    /** A page whose JSON-LD has nodes of $types. */
    private function typed(string $url, array $types, string $locale = 'en'): \Preflow\View\Audit\RenderedPage
    {
        $graph = array_map(static fn (string $type): array => ['@type' => $type], $types);
        $script = '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph]) . '</script>';

        return $this->page($this->html(head: '<title>T</title>' . $script), $url, $locale);
    }

    private function schemaCheck(bool $seo = true): GeoTypeSchemaMissing
    {
        return new GeoTypeSchemaMissing($this->registry(), new SeoConfig(enabled: $seo));
    }

    public function test_a_group_of_pages_with_only_generic_nodes_is_reported_once(): void
    {
        $check = $this->schemaCheck();
        $this->assertSame('geo.type-schema-missing', $check->id());
        foreach (['chess', 'go', 'catan'] as $game) {
            $check->collect($this->typed("https://site.test/de/spiele/{$game}", ['WebPage', 'BreadcrumbList'], 'de'));
        }
        // Top-level pages are no group; two pages are too few.
        $check->collect($this->typed('https://site.test/about', ['WebPage']));
        $check->collect($this->typed('https://site.test/contact', ['WebPage']));
        $check->collect($this->typed('https://site.test/news/a', ['WebPage']));
        $check->collect($this->typed('https://site.test/news/b', ['WebPage']));

        $findings = $check->finish();
        $this->assertSame(['geo.type-schema-missing|notice|https://site.test/de/spiele/chess|de'], $this->summary($findings));
        $this->assertStringContainsString('3 pages under /de/spiele/', $findings[0]->message);
    }

    public function test_one_page_with_its_own_node_covers_its_group(): void
    {
        $check = $this->schemaCheck();
        $check->collect($this->typed('https://site.test/games/chess', ['WebPage', 'Game']));
        $check->collect($this->typed('https://site.test/games/go', ['WebPage']));
        $check->collect($this->typed('https://site.test/games/catan', ['WebPage']));

        $this->assertSame([], $check->finish());

        // @type as a list, nested in another node.
        $listed = $this->schemaCheck();
        $script = '<script type="application/ld+json">' . json_encode(['@type' => 'WebPage', 'mainEntity' => ['@type' => ['Product', 'Thing']]]) . '</script>';
        $listed->collect($this->page($this->html(head: $script), 'https://site.test/shop/a'));
        $listed->collect($this->typed('https://site.test/shop/b', ['WebPage']));
        $listed->collect($this->page($this->html(head: '<script type="application/ld+json">not json</script>'), 'https://site.test/shop/c'));
        $this->assertSame([], $listed->finish());
    }

    public function test_the_state_survives_a_continued_run_and_seo_off_finds_nothing(): void
    {
        $check = $this->schemaCheck();
        $check->collect($this->typed('https://site.test/games/chess', ['WebPage']));
        $check->collect($this->typed('https://site.test/games/go', ['WebPage']));
        $state = json_decode(json_encode($check->state()), true);

        $later = $this->schemaCheck();
        $later->restore($state);
        $later->collect($this->typed('https://site.test/games/catan', ['WebPage']));
        $this->assertCount(1, $later->finish());

        $off = $this->schemaCheck(seo: false);
        foreach (['a', 'b', 'c'] as $slug) {
            $off->collect($this->typed("https://site.test/games/{$slug}", ['WebPage']));
        }
        $this->assertSame([], $off->finish());
    }

    // llms.full-too-large

    public function test_llms_full_over_the_limit_per_locale(): void
    {
        $this->routes['/llms-full.txt'] = new Response(200, ['Content-Type' => 'text/plain'], str_repeat('x', 2048));
        $this->routes['/de/llms-full.txt'] = new Response(200, ['Content-Type' => 'text/plain'], str_repeat('x', 100));
        $check = new LlmsFullTooLarge(new GeoConfig(enabled: true, fullMaxMb: 0.001));

        $this->assertSame('llms.full-too-large', $check->id());
        $findings = $check->check($this->renderer());
        $this->assertSame(['llms.full-too-large|warning|https://site.test/llms-full.txt|en'], $this->summary($findings));
        $this->assertStringContainsString('2 KB', $findings[0]->message);
    }

    public function test_llms_full_off_or_geo_off_renders_nothing(): void
    {
        $this->routes['/llms-full.txt'] = new Response(200, ['Content-Type' => 'text/plain'], str_repeat('x', 2048));

        $this->assertSame([], (new LlmsFullTooLarge(new GeoConfig(enabled: true, full: false, fullMaxMb: 0.001)))->check($this->renderer()));
        $this->assertSame([], (new LlmsFullTooLarge(new GeoConfig(enabled: false, fullMaxMb: 0.001)))->check($this->renderer()));
        $this->assertSame([], $this->rendered);
        $this->assertSame(5.0, GeoConfig::fromArray([])->fullMaxMb);
        $this->assertSame(12.5, GeoConfig::fromArray(['full_max_mb' => 12.5])->fullMaxMb);
    }
}
