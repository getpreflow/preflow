<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\MediaLibrary;
use Preflow\Media\ImageTag;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Preview\VideoPreviewRenderer;
use Preflow\Media\Tests\Support\FixedLocale;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

#[RequiresFunction('imagewebp')]
final class ImageTagTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
    private FixedLocale $locale;
    private AssetCollector $css;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->lib = $this->library([], new VariantGenerator(new GdBackend(), [480, 768], 75));
        $this->locale = new FixedLocale('en');
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
        $this->assertStringContainsString('<source type="image/webp" srcset="' . $base . '-480.webp 480w, ' . $base . '-768.webp 768w" sizes="100vw">', $html);
        $this->assertStringContainsString('src="/media/' . $asset->path . '"', $html);
        $this->assertStringContainsString('width="1200" height="800"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        if (function_exists('imageavif')) {
            $this->assertLessThan(strpos($html, 'image/webp'), strpos($html, 'image/avif'));
        }
    }

    public function test_alt_follows_the_locale_provider(): void
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
        $legacyPdf = '2026/10/' . str_repeat('ab', 16) . '.pdf';
        $this->assertSame('<a href="/folio/_uploads/' . $legacyPdf . '">' . str_repeat('ab', 16) . '.pdf</a>', $this->tag()->render($legacyPdf));
        $this->assertSame('', $this->tag()->render(str_repeat('c', 32)));
        $this->assertSame('', $this->tag()->render('../../etc/passwd'));
        $this->assertSame('', $this->tag()->render(null));
    }

    /** @param array<string, array<string, mixed>> $presets */
    public function test_priority_sets_eager_fetchpriority_and_one_preload_link(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);
        $tag = $this->tag(['hero' => ['sizes' => '50vw', 'priority' => true]]);

        $html = $tag->render($asset->id, 'hero');
        $tag->render($asset->id, 'hero'); // the same image twice on a page

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
        $head = $this->css->renderHead();
        $this->assertSame(1, substr_count($head, 'rel="preload"'));
        $format = function_exists('imageavif') ? 'avif' : 'webp';
        preg_match('#<source type="image/' . $format . '" srcset="([^"]+)"#', $html, $m);
        $this->assertStringContainsString('<link rel="preload" as="image" type="image/' . $format . '" imagesrcset="' . $m[1] . '" imagesizes="50vw" fetchpriority="high">', $head);
    }

    public function test_without_priority_nothing_changes(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);

        $html = $this->tag()->render($asset->id);

        $this->assertStringNotContainsString('fetchpriority', $html);
        $this->assertStringNotContainsString('preload', $this->css->renderHead());
    }

    public function test_call_option_overrides_preset_priority(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);

        $html = $this->tag(['hero' => ['priority' => true]])->render($asset->id, 'hero', ['priority' => false]);

        $this->assertStringNotContainsString('fetchpriority', $html);
        $this->assertStringNotContainsString('preload', $this->css->renderHead());
    }

    public function test_srcset_lists_step_widths_up_to_the_original(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(700, 400), 'a.png'), null);
        $base = '/media/' . dirname($asset->path) . '/' . $asset->id;
        $presets = ['card' => ['widths' => [480, 960], 'step' => 160, 'sizes' => '50vw']];

        $html = $this->tag($presets)->render($asset->id, 'card');

        // 640 is made on first request; 700 is the original (narrower than 960).
        $this->assertStringContainsString('<source type="image/webp" srcset="' . $base . '-480.webp 480w, ' . $base . '-640.webp 640w, ' . $base . '-700.webp 700w" sizes="50vw">', $html);
    }

    public function test_preset_without_step_lists_its_own_widths(): void
    {
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);
        $base = '/media/' . dirname($asset->path) . '/' . $asset->id;

        $html = $this->tag(['thumb' => ['widths' => [480]]])->render($asset->id, 'thumb');

        $this->assertStringContainsString('srcset="' . $base . '-480.webp 480w"', $html);
    }

    public function test_a_box_preset_without_a_plan_renders_as_today(): void
    {
        $presets = ['cover-thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover']];
        $asset = $this->lib->upload($this->upload($this->png(1200, 800), 'a.png'), null);
        $today = $this->tag($presets)->render($asset->id, 'cover-thumb');

        // The library has no formats, so no plan: the same output with the formats passed to the tag.
        $tag = new ImageTag($this->lib, $this->locale, $presets, $this->css, '/folio/_uploads', \Preflow\Media\Image\ImageWidths::fromConfig($presets, [480, 768]), \Preflow\Media\Image\Formats::fromConfig($presets));

        $this->assertSame($today, $tag->render($asset->id, 'cover-thumb'));
        $this->assertStringContainsString('width="1200" height="800"', $today);
    }

    /** A library whose videos get a frame as their preview (ffmpeg; skipped without it). */
    private function videoLibrary(): MediaLibrary
    {
        if (PdfPreviewRenderer::findBinary('ffmpeg') === null) {
            $this->markTestSkipped('No ffmpeg on this machine.');
        }
        $renderers = new PreviewRenderers();
        $renderers->register(new VideoPreviewRenderer());

        return $this->lib = $this->library([], new VariantGenerator(new GdBackend(), [480, 768], 75), previews: $renderers);
    }

    private function clip(): \Preflow\Media\Asset
    {
        $bytes = (string) file_get_contents(__DIR__ . '/Fixtures/video/clip.mp4');

        return $this->lib->upload($this->upload($bytes, 'clip.mp4'), null);
    }

    public function test_video_renders_frame_image(): void
    {
        $this->videoLibrary();
        $clip = $this->clip();
        $this->lib->update($clip->id, ['alt' => ['en' => 'Test pattern']]);
        $base = '/media/' . substr($clip->path, 0, (int) strrpos($clip->path, '.')) . '-preview';

        $html = $this->tag()->render($clip->id);

        $this->assertStringStartsWith('<picture>', $html);
        $this->assertStringContainsString('<source type="image/webp" srcset="' . $base . '-480.webp 480w, ' . $base . '-768.webp 768w', $html);
        $this->assertStringContainsString('<img src="' . $base . '.png" width="1600" height="900" alt="Test pattern"', $html);
        $this->assertSame('/media/' . $clip->path, $this->tag()->url($clip->id)); // the video stays the video
    }

    public function test_video_poster_wins(): void
    {
        $this->videoLibrary();
        $clip = $this->clip();
        $poster = $this->lib->upload($this->upload($this->png(1200, 900), 'poster.png'), null);
        $this->lib->update($clip->id, ['poster' => $poster->id, 'alt' => ['en' => 'Clip']]);

        $html = $this->tag()->render($clip->id);

        $this->assertStringStartsWith('<picture>', $html);
        $this->assertStringContainsString('<img src="/media/' . $poster->path . '" width="1200" height="900" alt="Clip"', $html);
        $this->assertStringNotContainsString('preview', $html);
    }

    private function tag(array $presets = []): ImageTag
    {
        return new ImageTag($this->lib, $this->locale, $presets, $this->css, '/folio/_uploads', \Preflow\Media\Image\ImageWidths::fromConfig($presets, [480, 768]));
    }
}
