<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Assets\ImageTag;
use Preflow\Folio\Assets\Image\GdBackend;
use Preflow\Folio\Assets\VariantGenerator;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

#[RequiresFunction('imagewebp')]
final class ImageTagTest extends TestCase
{
    use AssetFixtures;

    private AssetLibrary $lib;
    private ContentLocale $locale;
    private AssetCollector $css;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->lib = $this->library([], new VariantGenerator(new GdBackend(), [480, 768], 75));
        $this->locale = new ContentLocale(new Locales(['en', 'de'], 'en'));
        $this->css = new AssetCollector(new NonceGenerator());
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_picture_with_srcset_and_dimensions(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);
        $base = '/media/' . dirname($asset->path) . '/' . $asset->id;

        $html = $this->tag()->render($asset->id);

        $this->assertStringStartsWith('<picture>', $html);
        $this->assertStringContainsString('<source type="image/webp" srcset="' . $base . '-480.webp 480w, ' . $base . '-768.webp 768w, ' . $base . '-1200.webp 1200w" sizes="100vw">', $html);
        $this->assertStringContainsString('src="/media/' . $asset->path . '"', $html);
        $this->assertStringContainsString('width="1200" height="800"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        if (function_exists('imageavif')) {
            $this->assertLessThan(strpos($html, 'image/webp'), strpos($html, 'image/avif'));
        }
    }

    public function test_alt_follows_content_locale(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(100, 50), 'a.png'), null);
        $this->lib->update($asset->id, ['alt' => ['en' => 'Sea', 'de' => 'Meer']]);
        $other = $this->lib->upload($this->upload($this->png(100, 50), 'b.png'), null);
        $this->lib->update($other->id, ['alt' => ['en' => 'Sky']]);

        $this->locale->set('de');
        $this->assertStringContainsString('alt="Meer"', $this->tag()->render($asset->id));
        $this->assertStringContainsString('alt="Sky"', $this->tag()->render($other->id));
        $this->assertStringContainsString('alt="Override &amp; more"', $this->tag()->render($asset->id, null, ['alt' => 'Override & more']));
    }

    public function test_focal_point_class_not_style(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(100, 50), 'a.png'), null);
        $this->lib->update($asset->id, ['focal_x' => 31, 'focal_y' => 68]);
        $tag = $this->tag();

        $html = $tag->render($asset->id, null, ['class' => 'hero-img']);
        $tag->render($asset->id);

        $this->assertStringContainsString('class="hero-img folio-focal folio-fx-30 folio-fy-70"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $css = $this->css->renderCss();
        $this->assertSame(1, substr_count($css, '.folio-focal{'));
        $this->assertStringContainsString('.folio-fx-30{--folio-fx:30%}', $css);
        $this->assertStringContainsString('.folio-fy-70{--folio-fy:70%}', $css);
    }

    public function test_centred_focal_point_adds_nothing(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(100, 50), 'a.png'), null);

        $this->assertStringNotContainsString('folio-focal', $this->tag()->render($asset->id));
        $this->assertSame('', $this->css->renderCss());
    }

    public function test_preset_options_apply(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);

        $html = $this->tag(['hero' => ['sizes' => '50vw', 'loading' => 'eager']])->render($asset->id, 'hero', ['class' => 'x']);

        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('class="x"', $html);
    }

    public function test_lists_documents_legacy_paths_and_unknown(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(100, 50), 'a.png'), null);
        $doc = $this->lib->upload($this->upload("notes\n", 'Notes.txt'), null);
        $legacy = '2026/10/' . str_repeat('ab', 16) . '.png';

        $this->assertStringContainsString($asset->id, $this->tag()->render([$asset->id, $doc->id]));
        $this->assertStringContainsString($asset->id, $this->tag()->render(json_encode([$asset->id])));
        $this->assertSame('<a href="/media/' . $doc->path . '">Notes.txt</a>', $this->tag()->render($doc->id));
        $this->assertSame('<img src="/folio/_uploads/' . $legacy . '" alt="" loading="lazy">', $this->tag()->render($legacy));
        $this->assertSame('', $this->tag()->render(str_repeat('c', 32)));
        $this->assertSame('', $this->tag()->render('../../etc/passwd'));
        $this->assertSame('', $this->tag()->render(null));
    }

    /** @param array<string, array<string, mixed>> $presets */
    private function tag(array $presets = []): ImageTag
    {
        return new ImageTag($this->lib, $this->locale, $presets, $this->css, '/folio/_uploads');
    }
}
