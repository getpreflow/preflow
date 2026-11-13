<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Folio\Http\AssetController;
use Preflow\Folio\Tests\Support\PublishingScaffold;

final class FontsTest extends TestCase
{
    use PublishingScaffold;

    private const FONTS = ['InterVariable.woff2', 'JetBrainsMono-Regular.woff2', 'JetBrainsMono-Medium.woff2'];

    private function assets(): string
    {
        return dirname(__DIR__, 2) . '/assets';
    }

    /** @return array<string, string> the provider's font entries */
    private function map(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/FolioServiceProvider.php');
        preg_match_all("/'([A-Za-z\\-]+\\.woff2)' => '([^']+)'/", $src, $m);

        return array_combine($m[1], $m[2]);
    }

    private function controller(): AssetController
    {
        return new AssetController($this->assets(), new AdminAssets($this->map() + ['admin.css' => 'admin.css']));
    }

    public function test_provider_maps_all_three_fonts(): void
    {
        $this->assertEqualsCanonicalizing(self::FONTS, array_keys($this->map()));
    }

    public function test_each_font_is_served_as_woff2_with_immutable_cache(): void
    {
        foreach (self::FONTS as $f) {
            $res = $this->controller()->serve((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/' . $f)->withAttribute('file', $f));
            $this->assertSame(200, $res->getStatusCode(), $f);
            $this->assertSame('font/woff2', $res->getHeaderLine('Content-Type'), $f);
            $this->assertStringContainsString('immutable', $res->getHeaderLine('Cache-Control'));
            $this->assertStringStartsWith('wOF2', (string) $res->getBody());
        }
    }

    public function test_css_stays_text_with_charset(): void
    {
        $res = $this->controller()->serve((new Psr17Factory())->createServerRequest('GET', '/x')->withAttribute('file', 'admin.css'));
        $this->assertSame('text/css; charset=UTF-8', $res->getHeaderLine('Content-Type'));
    }

    public function test_font_face_urls_name_served_files_with_current_hash(): void
    {
        $css = (string) file_get_contents($this->assets() . '/admin.css');
        preg_match_all('/@font-face\s*\{[^}]*\}/', $css, $blocks);
        $this->assertCount(3, $blocks[0]);
        $map = $this->map();
        foreach ($blocks[0] as $block) {
            $this->assertMatchesRegularExpression('/font-display:\s*swap/', $block);
            $this->assertSame(1, preg_match('/url\("([^"?]+)\?v=([0-9a-f]{12})"\)/', $block, $u), $block);
            $this->assertArrayHasKey($u[1], $map);
            $this->assertSame(substr(hash_file('xxh3', $this->assets() . '/' . $map[$u[1]]), 0, 12), $u[2], $u[1] . ' changed; update the v in admin.css');
        }
    }

    public function test_font_face_families_match_the_stacks(): void
    {
        $css = (string) file_get_contents($this->assets() . '/admin.css');
        preg_match_all('/@font-face\s*\{[^}]*font-family:\s*"([^"]+)"/', $css, $f);
        preg_match('/--font-sans:\s*"([^"]+)"/', $css, $sans);
        preg_match('/--font-mono:\s*"([^"]+)"/', $css, $mono);
        $this->assertEqualsCanonicalizing(['Inter', 'JetBrains Mono', 'JetBrains Mono'], $f[1]);
        $this->assertContains($sans[1], $f[1]);
        $this->assertContains($mono[1], $f[1]);
    }

    public function test_mono_places_never_draw_a_faux_bold(): void
    {
        // JetBrains Mono ships 400 and 500 only: a <kbd> hint in a bold label must not be smeared bold.
        $css = (string) file_get_contents($this->assets() . '/admin.css');
        $this->assertStringContainsString(':where(code, kbd, samp, pre, .folio-mono) { font-family: var(--font-mono); font-synthesis-weight: none; }', $css);
    }

    public function test_licences_ship_with_the_fonts(): void
    {
        $this->assertFileExists($this->assets() . '/fonts/Inter-OFL.txt');
        $this->assertFileExists($this->assets() . '/fonts/JetBrainsMono-OFL.txt');
    }

    public function test_layouts_preload_inter(): void
    {
        foreach (['_layout', '_auth_layout', '_drawer_layout', 'login'] as $t) {
            $tpl = (string) file_get_contents(dirname(__DIR__, 2) . "/templates/admin/$t.twig");
            $this->assertStringContainsString("folio_asset('InterVariable.woff2')", $tpl, $t);
            $this->assertStringContainsString('as="font" type="font/woff2" crossorigin', $tpl, $t);
        }
    }

    /**
     * The rendered page preloads exactly the font URL the stylesheet asks for: the preload's
     * {prefix}/_assets/InterVariable.woff2?v=… equals admin.css's url() resolved against the
     * stylesheet's own URL. A mismatch would load the font twice.
     */
    public function test_rendered_preload_is_the_url_the_css_loads(): void
    {
        $this->scaffoldPublishing();
        try {
            $html = (string) $this->get('/folio')->getBody();
        } finally {
            $this->removeScaffold();
        }

        $this->assertSame(1, preg_match('#<link rel="preload" href="([^"]+)" as="font" type="font/woff2" crossorigin>#', $html, $preload), 'the dashboard preloads Inter');
        $this->assertSame(1, preg_match('#<link rel="stylesheet" href="([^"?]+/)admin\.css\?v=[^"]+">#', $html, $sheet));
        $css = (string) file_get_contents($this->assets() . '/admin.css');
        $this->assertSame(1, preg_match('/url\("(InterVariable\.woff2\?v=[0-9a-f]{12})"\)/', $css, $url));

        $this->assertSame($sheet[1] . $url[1], $preload[1]);
    }
}
