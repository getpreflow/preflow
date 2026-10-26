<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\Asset;
use Preflow\Media\CropRect;
use Preflow\Media\Http\VariantController;
use Preflow\Media\Image\BoxPlan;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\Formats;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\Geometry;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\ImageTag;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Tests\Support\FixedLocale;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Psr\Http\Message\ResponseInterface;

/** Box presets (cover, contain): their plan, their files made on first request, and media_image() with them. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class BoxPresetTest extends TestCase
{
    use MediaFixtures;

    private const RED = [255, 0, 0];
    private const GREEN = [0, 255, 0];
    private const BLUE = [0, 0, 255];
    private const YELLOW = [255, 255, 0];

    private const array PRESETS = [
        'cover-thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover'],
        'cover-hero' => ['width' => 400, 'height' => 100, 'fit' => 'cover'],
        'fit-box' => ['width' => 300, 'height' => 300, 'fit' => 'contain'],
        'card' => ['widths' => [480]],
    ];

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @param array<string, mixed> $config */
    private function lib(array $config = [], bool $variants = true, ?PreviewRenderers $previews = null): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            $config + ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            $variants ? new VariantGenerator(new GdBackend(), [480], 75) : null,
            previews: $previews,
            formats: Formats::fromConfig(self::PRESETS),
        );
    }

    private function tag(MediaLibrary $lib): ImageTag
    {
        return new ImageTag($lib, new FixedLocale('en'), self::PRESETS, new AssetCollector(new NonceGenerator()), '', ImageWidths::fromConfig(self::PRESETS, [480]), Formats::fromConfig(self::PRESETS));
    }

    private function serve(MediaLibrary $lib, string $path): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/media/' . $path)->withAttribute('path', $path);

        return (new VariantController($lib, ImageWidths::fromConfig(self::PRESETS, [480]), Formats::fromConfig(self::PRESETS)))->serve($request);
    }

    /** 800x600 PNG: red top-left, green top-right, blue bottom-left, yellow bottom-right. */
    private function quadrants(): string
    {
        $im = imagecreatetruecolor(800, 600);
        foreach ([[0, 0, self::RED], [400, 0, self::GREEN], [0, 300, self::BLUE], [400, 300, self::YELLOW]] as [$x, $y, $c]) {
            imagefilledrectangle($im, $x, $y, $x + 399, $y + 299, (int) imagecolorallocate($im, ...$c));
        }
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function uploadQuadrants(MediaLibrary $lib): Asset
    {
        return $lib->upload($this->upload($this->quadrants(), 'q.png'), null);
    }

    /** @param array{int, int, int} $expected */
    private function assertColor(array $expected, string $file, int $x, int $y): void
    {
        $im = imagecreatefromstring((string) file_get_contents($file));
        imagepalettetotruecolor($im);
        $rgb = imagecolorat($im, $x, $y) & 0xFFFFFF;
        $got = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
        foreach ($expected as $i => $v) {
            $this->assertEqualsWithDelta($v, $got[$i], 40, "pixel $x,$y is " . implode(',', $got) . ' not ' . implode(',', $expected));
        }
    }

    /** @param list<array{int, int, int}> $allowed every sampled pixel is one of these */
    private function assertOnly(array $allowed, string $file): void
    {
        $im = imagecreatefromstring((string) file_get_contents($file));
        imagepalettetotruecolor($im);
        [$w, $h] = [imagesx($im), imagesy($im)];
        for ($x = 2; $x < $w; $x += (int) max(1, intdiv($w, 10))) {
            for ($y = 2; $y < $h; $y += (int) max(1, intdiv($h, 10))) {
                $rgb = imagecolorat($im, $x, $y) & 0xFFFFFF;
                $got = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                $match = array_filter($allowed, static fn (array $c): bool => abs($c[0] - $got[0]) <= 40 && abs($c[1] - $got[1]) <= 40 && abs($c[2] - $got[2]) <= 40);
                $this->assertNotSame([], $match, "pixel $x,$y is " . implode(',', $got));
            }
        }
    }

    private static function stem(string $path): string
    {
        return substr($path, 0, (int) strrpos($path, '.'));
    }

    /** The box file of a preset at a width, made through the controller as a browser would. */
    private function boxFileVia(MediaLibrary $lib, Asset $asset, string $preset, int $width): string
    {
        $plan = $lib->boxPlan($lib->find($asset->id), $preset);
        $this->assertNotNull($plan);
        $rel = $plan->base . '-' . $width . '.webp';
        $res = $this->serve($lib, $rel);
        $this->assertSame(200, $res->getStatusCode(), $rel);
        $this->assertSame('image/webp', $res->getHeaderLine('Content-Type'));

        return $lib->absolute($rel);
    }

    /** @return list<string> every file under the media dir */
    private function mediaFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->assetRoot . '/media', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($this->assetRoot . '/media/'));
        }
        sort($out);

        return $out;
    }

    private function assertNotFound(MediaLibrary $lib, string $path): void
    {
        $before = $this->mediaFiles();
        try {
            $this->serve($lib, $path);
            $this->fail("{$path} was served");
        } catch (NotFoundHttpException) {
            $this->assertSame($before, $this->mediaFiles(), "{$path} wrote a file");
        }
    }

    private static function keyIn(string $html, string $preset): string
    {
        return preg_match('#-' . preg_quote($preset, '#') . '-([0-9a-f]{8})-\d+\.webp#', $html, $m) === 1 ? $m[1] : '';
    }

    public function test_the_plan_of_a_cover_preset(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $stem = self::stem($asset->path);

        $plan = $lib->boxPlan($asset, 'cover-thumb');

        $this->assertInstanceOf(BoxPlan::class, $plan);
        $this->assertSame('cover-thumb', $plan->format->name);
        $this->assertSame($asset->path, $plan->source->path);
        $this->assertSame(['x' => 100, 'y' => 0, 'w' => 600, 'h' => 600], $plan->crop);
        $this->assertSame([200, 200, [200, 400]], [$plan->width, $plan->height, $plan->widths]);
        $this->assertSame($stem . '-cover-thumb-' . Formats::key([$stem, 'cover-thumb', 200, 200, 'cover', [], 100, 0, 600, 600]), $plan->base);

        $this->assertNull($lib->boxPlan($asset, 'card'));
        $this->assertNull($lib->boxPlan($asset, 'nope'));
        $this->assertNull($this->lib(variants: false)->boxPlan($asset, 'cover-thumb'));
        $this->assertNull($this->lib(['max_pixels' => 1000])->boxPlan($asset, 'cover-thumb'));
        $private = $lib->upload($this->upload($this->quadrants(), 'p.png'), null, true);
        $this->assertNull($lib->boxPlan($private, 'cover-thumb'));
        $text = $lib->upload($this->upload('hello', 'a.txt'), null);
        $this->assertNull($lib->boxPlan($text, 'cover-thumb'));
    }

    public function test_media_image_with_a_cover_preset(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $plan = $lib->boxPlan($asset, 'cover-thumb');
        $base = '/media/' . $plan->base;

        $html = $this->tag($lib)->render($asset->id, 'cover-thumb', ['sizes' => '200px', 'class' => 'thumb']);

        $this->assertStringContainsString('<source type="image/webp" srcset="' . $base . '-200.webp 200w, ' . $base . '-400.webp 400w" sizes="200px">', $html);
        $this->assertStringContainsString('<img src="' . $base . '-200.webp" width="200" height="200"', $html);
        $this->assertStringContainsString('class="thumb"', $html);
        $this->assertStringNotContainsString('folio-focal', $this->tag($lib)->render($lib->update($asset->id, ['focal_x' => 100])->id, 'cover-thumb'));
        if (function_exists('imageavif')) {
            $this->assertStringContainsString('<source type="image/avif" srcset="' . $base . '-200.avif 200w', $html);
        }

        $file = $this->boxFileVia($lib, $asset, 'cover-thumb', 200);
        $this->assertSame([200, 200], array_slice((array) getimagesize($file), 0, 2));
    }

    public function test_the_cut_follows_the_focal_point(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        $lib->update($asset->id, ['focal_x' => 100, 'focal_y' => 50]);
        $this->assertSame(Geometry::focalBox(800, 600, 1.0, 100, 50), $lib->boxPlan($lib->find($asset->id), 'cover-thumb')->crop);
        $file = $this->boxFileVia($lib, $asset, 'cover-thumb', 200);
        $this->assertColor(self::RED, $file, 55, 50);
        $this->assertColor(self::GREEN, $file, 80, 50);

        $lib->update($asset->id, ['focal_x' => 0]);
        $file = $this->boxFileVia($lib, $asset, 'cover-thumb', 200);
        $this->assertColor(self::RED, $file, 120, 50);
        $this->assertColor(self::GREEN, $file, 145, 50);
    }

    public function test_a_wide_cover_takes_a_strip_around_the_focal_point(): void
    {
        $lib = $this->lib();
        $asset = $lib->update($this->uploadQuadrants($lib)->id, ['focal_x' => 50, 'focal_y' => 90]);

        $file = $this->boxFileVia($lib, $asset, 'cover-hero', 400);

        $this->assertSame([400, 100], array_slice((array) getimagesize($file), 0, 2));
        $this->assertColor(self::BLUE, $file, 50, 50);
        $this->assertColor(self::YELLOW, $file, 350, 50);
    }

    public function test_the_image_crop_cuts_every_box(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $cropped = $lib->setCrop($asset->id, CropRect::of(50, 0, 50, 100));
        $masterStem = self::stem($cropped->master->path);

        $this->assertStringStartsWith($masterStem . '-cover-thumb-', $lib->boxPlan($cropped, 'cover-thumb')->base);
        $this->assertOnly([self::GREEN, self::YELLOW], $this->boxFileVia($lib, $cropped, 'cover-thumb', 200));
        $this->assertOnly([self::GREEN, self::YELLOW], $this->boxFileVia($lib, $cropped, 'cover-hero', 400));
    }

    public function test_a_format_crop_changes_only_its_preset(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $hero = $lib->boxPlan($asset, 'cover-hero')->base;

        $set = $lib->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(0, 0, 50, 50));

        $this->assertSame(['x' => 50, 'y' => 0, 'w' => 300, 'h' => 300], $lib->boxPlan($set, 'cover-thumb')->crop);
        $this->assertOnly([self::RED], $this->boxFileVia($lib, $set, 'cover-thumb', 200));
        $this->assertSame($hero, $lib->boxPlan($set, 'cover-hero')->base);
    }

    public function test_a_format_crop_is_taken_inside_the_image_crop(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $lib->setCrop($asset->id, CropRect::of(50, 0, 50, 100)); // the right half: 400x600 master

        // 0-75 % x 0-50 % of the original is 0-600 x 0-300; inside the crop it is 0-200 x 0-300, fitted 1:1: 200x200 at y 50.
        $set = $lib->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(0, 0, 75, 50));

        $this->assertSame(['x' => 0, 'y' => 50, 'w' => 200, 'h' => 200], $lib->boxPlan($set, 'cover-thumb')->crop);
        $this->assertOnly([self::GREEN], $this->boxFileVia($lib, $set, 'cover-thumb', 200));
    }

    public function test_a_contain_preset_fits_the_whole_picture(): void
    {
        $lib = $this->lib();
        $asset = $lib->update($this->uploadQuadrants($lib)->id, ['focal_x' => 10, 'focal_y' => 10]);

        $html = $this->tag($lib)->render($asset->id, 'fit-box');
        $file = $this->boxFileVia($lib, $asset, 'fit-box', 300);

        $this->assertStringContainsString('width="300" height="225"', $html);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 800, 'h' => 600], $lib->boxPlan($asset, 'fit-box')->crop);
        $this->assertSame([300, 225], array_slice((array) getimagesize($file), 0, 2));
        $this->assertColor(self::RED, $file, 20, 20);
        $this->assertColor(self::GREEN, $file, 280, 20);
        $this->assertColor(self::BLUE, $file, 20, 200);
        $this->assertColor(self::YELLOW, $file, 280, 200);
    }

    public function test_every_change_gives_new_urls_and_old_ones_are_gone(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $changes = [
            'focal' => fn () => $lib->update($asset->id, ['focal_x' => 20, 'focal_y' => 20]),
            'crop' => fn () => $lib->setCrop($asset->id, CropRect::of(0, 0, 75, 100)),
            'format crop' => fn () => $lib->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(0, 0, 50, 50)),
            'rotate' => fn () => $lib->rotate($asset->id, 1),
            'replace' => fn () => $lib->replace($asset->id, $this->upload($this->quadrants(), 'q2.png'), null),
        ];
        foreach ($changes as $name => $change) {
            $before = $lib->find($asset->id);
            $thumb = $lib->boxPlan($before, 'cover-thumb');
            $hero = $lib->boxPlan($before, 'cover-hero');
            $old = $this->boxFileVia($lib, $before, 'cover-thumb', 200);

            $change();

            $after = $lib->find($asset->id);
            $this->assertNotSame($thumb->base, $lib->boxPlan($after, 'cover-thumb')->base, $name);
            if ($name === 'format crop') {
                $this->assertSame($hero->base, $lib->boxPlan($after, 'cover-hero')->base, 'a format crop changes only its preset');
            } else {
                $this->assertNotSame($hero->base, $lib->boxPlan($after, 'cover-hero')->base, $name);
            }
            $this->assertFileDoesNotExist($old, $name);
            $this->assertNotFound($lib, $thumb->base . '-200.webp');
        }
    }

    public function test_the_endpoint_makes_nothing_outside_the_plan(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $stem = self::stem($asset->path);
        $plan = $lib->boxPlan($asset, 'cover-thumb');
        $key = substr($plan->base, -8);
        $private = $lib->upload($this->upload($this->quadrants(), 'p.png'), null, true);
        $edited = $lib->rotate($asset->id, 1);
        $oldMaster = self::stem($edited->master->path);
        $lib->rotate($asset->id, 1);

        foreach ([
            $stem . '-nope-' . $key . '-200.webp',                     // an unknown preset
            $stem . '-card-' . $key . '-200.webp',                     // not a box
            $plan->base . '-300.webp',                                 // a width outside the plan
            $stem . '-cover-thumb-00000000-200.webp',                  // a wrong key
            self::stem($private->path) . '-cover-thumb-' . $key . '-200.webp', // a private asset
            $oldMaster . '-480.webp',                                  // an earlier master's variant
            $oldMaster . '-cover-thumb-' . $key . '-200.webp',
        ] as $path) {
            $this->assertNotFound($lib, $path);
        }
    }

    public function test_the_current_masters_variants_are_made_on_request(): void
    {
        $lib = $this->lib();
        $edited = $lib->rotate($this->uploadQuadrants($lib)->id, 1);
        $rel = self::stem($edited->master->path) . '-480.webp';
        unlink($lib->absolute($rel));

        $this->assertSame(200, $this->serve($lib, $rel)->getStatusCode());
        $this->assertFileExists($lib->absolute($rel));
    }

    public function test_a_small_image_is_never_upscaled(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(150, 150), 's.png'), null);
        $plan = $lib->boxPlan($asset, 'cover-thumb');

        $html = $this->tag($lib)->render($asset->id, 'cover-thumb');

        $this->assertSame([150], $plan->widths);
        $this->assertStringContainsString('srcset="/media/' . $plan->base . '-150.webp 150w"', $html);
        $this->assertStringContainsString('<img src="/media/' . $plan->base . '-150.webp" width="200" height="200"', $html);
        $this->assertNotFound($lib, $plan->base . '-200.webp');
        $this->assertSame([150, 150], array_slice((array) getimagesize($this->boxFileVia($lib, $asset, 'cover-thumb', 150)), 0, 2));
    }

    public function test_a_width_preset_on_an_edited_asset_uses_the_master(): void
    {
        $lib = $this->lib();
        $edited = $lib->rotate($this->uploadQuadrants($lib)->id, 1);
        $master = '/media/' . self::stem($edited->master->path);

        $html = $this->tag($lib)->render($edited->id, 'card');

        $this->assertStringContainsString('srcset="' . $master . '-480.webp 480w', $html);
        $this->assertStringContainsString('<img src="/media/' . $edited->master->path . '" width="600" height="800"', $html);
        $this->assertStringNotContainsString('/media/' . self::stem($edited->path) . '-480', $html);
    }

    public function test_original_url(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $tag = $this->tag($lib);

        $this->assertSame($tag->url($asset->id), $tag->originalUrl($asset->id));
        $lib->rotate($asset->id, 1);
        $this->assertSame('/media/' . $asset->path, $tag->originalUrl($asset->id));
        $this->assertNotSame($tag->url($asset->id), $tag->originalUrl($asset->id));
        $this->assertSame('', $tag->originalUrl(null));
        $this->assertSame('', $tag->originalUrl(str_repeat('0', 32)));
    }

    public function test_a_pdf_preview_gets_boxes(): void
    {
        if ((new PdfPreviewRenderer())->engine() === null) {
            $this->markTestSkipped('No PDF renderer (Ghostscript or pdftocairo) on this machine.');
        }
        $renderers = new PreviewRenderers();
        $renderers->register(new PdfPreviewRenderer(new ColorManagement()));
        $lib = $this->lib(previews: $renderers);
        $pdf = $lib->upload($this->upload((string) file_get_contents(__DIR__ . '/Fixtures/pdf/rgb.pdf'), 'a.pdf'), null);
        $plan = $lib->boxPlan($pdf, 'cover-thumb');

        $html = $this->tag($lib)->render($pdf->id, 'cover-thumb');

        $this->assertMatchesRegularExpression('#^' . preg_quote(self::stem($pdf->path), '#') . '-preview-cover-thumb-[0-9a-f]{8}$#', $plan->base);
        $this->assertStringContainsString('/media/' . $plan->base . '-200.webp 200w', $html);
        $this->assertStringContainsString('width="200" height="200"', $html);
        $this->assertSame([200, 200], array_slice((array) getimagesize($this->boxFileVia($lib, $pdf, 'cover-thumb', 200)), 0, 2));
    }

    public function test_warm_makes_the_box_files(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        $made = $lib->warm($asset, [480]);

        foreach (['cover-thumb' => [200, 400], 'cover-hero' => [400, 800], 'fit-box' => [300, 600]] as $preset => $widths) {
            $plan = $lib->boxPlan($asset, $preset);
            $this->assertSame($widths, $plan->widths, $preset);
            foreach ($widths as $w) {
                $this->assertFileExists($lib->absolute($plan->base . '-' . $w . '.webp'));
            }
        }
        $this->assertGreaterThanOrEqual(6, $made);
        $this->assertSame(0, $lib->warm($asset, [480]));
    }
}
