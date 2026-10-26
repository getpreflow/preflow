<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\Asset;
use Preflow\Media\Color\ColorExtractor;
use Preflow\Media\CropRect;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\Formats;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Crop, rotate and flip: the edit state on the record and the rendered master the pages get. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class EditTest extends TestCase
{
    use MediaFixtures;

    private const RED = [255, 0, 0];
    private const GREEN = [0, 255, 0];
    private const BLUE = [0, 0, 255];
    private const YELLOW = [255, 255, 0];

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function lib(?ColorManagement $color = null, bool $formats = true): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private', 'allow_svg' => true],
            new VariantGenerator(new GdBackend(), [480], 75, $color ?? new ColorManagement()),
            colors: new ColorExtractor(new ColorManagement()),
            formats: $formats ? Formats::fromConfig([
                'cover-thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover'],
                'cover-hero' => ['width' => 400, 'height' => 100, 'fit' => 'cover'],
                'fit-box' => ['width' => 300, 'height' => 300, 'fit' => 'contain'],
                'card' => ['widths' => [480]],
            ]) : null,
        );
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

    private static function stem(Asset $asset): string
    {
        return substr($asset->path, 0, (int) strrpos($asset->path, '.'));
    }

    /** @return list<string> file names in the asset's folder that start with its id */
    private function filesOf(MediaLibrary $lib, Asset $asset): array
    {
        $names = array_map('basename', glob($lib->absolute(dirname($asset->path)) . '/' . $asset->id . '*') ?: []);
        sort($names);

        return $names;
    }

    public function test_rotate_renders_a_master_and_keeps_the_original(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $stem = self::stem($asset);
        $original = (string) file_get_contents($lib->absolute($asset->path));
        $this->assertFileExists($lib->absolute($stem . '-480.webp'));

        $edited = $lib->rotate($asset->id, 1);

        $master = $stem . '-edit-' . Formats::key([1, 1, false, null]) . '.png';
        $this->assertSame(1, $edited->rotation);
        $this->assertSame($master, $edited->master?->path);
        $this->assertSame([600, 800], [$edited->master->width, $edited->master->height]);
        $this->assertSame([800, 600], [$edited->width, $edited->height]); // the original's
        $this->assertSame([600, 800], array_slice((array) getimagesize($lib->absolute($master)), 0, 2));
        $this->assertColor(self::BLUE, $lib->absolute($master), 5, 5);
        $this->assertColor(self::RED, $lib->absolute($master), 595, 5);

        $delivery = $lib->deliveryAsset($edited);
        $this->assertSame([$master, 'image/png', 600, 800], [$delivery->path, $delivery->mime, $delivery->width, $delivery->height]);
        $this->assertSame('/media/' . $master, $lib->url($edited));
        $this->assertSame('/media/' . $asset->path, $lib->originalUrl($edited));
        $this->assertSame($original, (string) file_get_contents($lib->absolute($asset->path)));

        $this->assertFileDoesNotExist($lib->absolute($stem . '-480.webp'));
        $this->assertFileExists($lib->absolute(substr($master, 0, -4) . '-480.webp'));
        $this->assertNotSame([], $edited->variants);
        foreach ($edited->variants as $v) {
            $this->assertStringStartsWith(substr($master, 0, -4) . '-', $v->path);
        }
        $this->assertStringStartsWith('/media/' . substr($master, 0, -4) . '-480.webp', (string) $lib->thumbnailUrl($edited));
        $this->assertEquals($edited, $lib->find($asset->id));
    }

    public function test_an_unedited_image_delivers_itself(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        $this->assertSame($asset, $lib->deliveryAsset($asset));
        $this->assertSame($lib->url($asset), $lib->originalUrl($asset));
        $this->assertSame('/media/' . $asset->path, $lib->url($asset));
        $this->assertTrue($lib->isEditable($asset));
    }

    public function test_set_crop_cuts_the_master_and_moves_the_focal_point(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $this->assertNotSame('#ffff00', $asset->color);

        $edited = $lib->setCrop($asset->id, CropRect::of(50, 50, 50, 50));

        $this->assertEquals(CropRect::of(50, 50, 50, 50), $edited->crop);
        $this->assertSame([400, 300], [$edited->master?->width, $edited->master?->height]);
        $this->assertColor(self::YELLOW, $lib->absolute($edited->master->path), 5, 5);
        $this->assertColor(self::YELLOW, $lib->absolute($edited->master->path), 395, 295);
        $this->assertSame('#ffff00', $edited->color);
        // The full image's centre (50/50) is the crop's top-left corner.
        $this->assertSame([0, 0], [$edited->focalX, $edited->focalY]);

        $cleared = $lib->setCrop($asset->id, null);
        $this->assertNull($cleared->crop);
        $this->assertNull($cleared->master);
        $this->assertSame([50, 50], [$cleared->focalX, $cleared->focalY]);
    }

    public function test_four_quarter_turns_bring_back_the_original(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $files = $this->filesOf($lib, $asset);

        for ($i = 0; $i < 4; $i++) {
            $last = $lib->rotate($asset->id, 1);
        }

        $this->assertFalse($last->isEdited());
        $this->assertNull($last->master);
        $this->assertEquals($asset->variants, $last->variants);
        $this->assertContains($asset->id . '-480.webp', $this->filesOf($lib, $asset));
        $this->assertSame([], array_filter($this->filesOf($lib, $asset), static fn (string $f) => str_contains($f, '-edit-')));
        $this->assertSame($files, $this->filesOf($lib, $asset));
        $this->assertSame($lib->url($asset), $lib->url($last));
    }

    public function test_each_change_gives_a_new_master(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        $rotated = $lib->rotate($asset->id, 1);
        $flipped = $lib->flip($asset->id, true);
        $cropped = $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 50));

        $paths = [$rotated->master?->path, $flipped->master?->path, $cropped->master?->path];
        $this->assertCount(3, array_unique(array_filter($paths)));
        $this->assertFileDoesNotExist($lib->absolute($rotated->master->path));
        $this->assertFileDoesNotExist($lib->absolute($flipped->master->path));
        $this->assertFileExists($lib->absolute($cropped->master->path));
        // Only the current master and its variants are left of the edits.
        $edits = array_values(array_filter($this->filesOf($lib, $asset), static fn (string $f) => str_contains($f, '-edit-')));
        foreach ($edits as $file) {
            $this->assertStringStartsWith(basename(substr($cropped->master->path, 0, -4)), $file);
        }
    }

    public function test_flip_twice_returns_to_the_start(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $lib->update($asset->id, ['focal_x' => 30, 'focal_y' => 20]);

        $once = $lib->flip($asset->id, true);
        $this->assertSame([0, true], [$once->rotation, $once->mirrored]);
        $this->assertSame([70, 20], [$once->focalX, $once->focalY]);
        $this->assertColor(self::GREEN, $lib->absolute($once->master->path), 5, 5);

        $twice = $lib->flip($asset->id, true);
        $this->assertSame([0, false], [$twice->rotation, $twice->mirrored]);
        $this->assertSame([30, 20], [$twice->focalX, $twice->focalY]);
        $this->assertNull($twice->master);
        $this->assertFalse($twice->isEdited());
    }

    public function test_flips_of_a_turned_image_act_on_what_is_shown(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $lib->rotate($asset->id, 1);

        $mirrored = $lib->flip($asset->id, true);
        $this->assertSame([3, true], [$mirrored->rotation, $mirrored->mirrored]);
        // Turned: blue top-left, red top-right; mirrored left-right: red top-left.
        $this->assertColor(self::RED, $lib->absolute($mirrored->master->path), 5, 5);

        $lib->flip($asset->id, true);
        $vertical = $lib->flip($asset->id, false);
        $this->assertSame([1, true], [$vertical->rotation, $vertical->mirrored]); // (6 - 1) mod 4
        // Turned, then upside down: yellow top-left.
        $this->assertColor(self::YELLOW, $lib->absolute($vertical->master->path), 5, 5);
    }

    public function test_rotate_turns_the_crop_and_the_focal_point(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $lib->update($asset->id, ['focal_x' => 30, 'focal_y' => 20]);

        $turned = $lib->rotate($asset->id, 1);
        $this->assertSame([80, 30], [$turned->focalX, $turned->focalY]);

        $cropped = $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 50)); // top-left of the turned image: blue
        $this->assertColor(self::BLUE, $lib->absolute($cropped->master->path), 5, 5);
        $back = $lib->rotate($asset->id, -1);
        $this->assertSame(0, $back->rotation);
        $this->assertEquals(CropRect::of(0, 50, 50, 50), $back->crop); // the same blue area of the original
        $this->assertColor(self::BLUE, $lib->absolute($back->master->path), 5, 5);
    }

    public function test_rotate_clears_and_flip_mirrors_the_format_crops(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        $lib->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(0, 0, 50, 50));
        $flipped = $lib->flip($asset->id, true);
        $this->assertEquals(['cover-thumb' => CropRect::of(50, 0, 50, 50)], $flipped->crops);

        $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 100));
        $this->assertEquals(CropRect::of(50, 0, 50, 100), $lib->flip($asset->id, true)->crop);

        $this->assertSame([], $lib->rotate($asset->id, 1)->crops);
    }

    public function test_rotate_by_whole_turns_changes_nothing(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $set = $lib->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(0, 0, 50, 50));

        foreach ([0, 4, -4, 8] as $turns) {
            $same = $lib->rotate($asset->id, $turns);
            $this->assertEquals($set, $same, "{$turns} quarter turns");
            $this->assertEquals($set, $lib->find($asset->id), "{$turns} quarter turns, stored");
        }
    }

    public function test_set_format_crop_keeps_the_master_and_removes_box_files(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $edited = $lib->rotate($asset->id, 1);
        $box = $lib->absolute(substr($edited->master->path, 0, -4) . '-cover-thumb-abcdef01-200.webp');
        file_put_contents($box, 'x');

        $set = $lib->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(10, 10, 40, 40));

        $this->assertEquals(['cover-thumb' => CropRect::of(10, 10, 40, 40)], $set->crops);
        $this->assertEquals($edited->master, $set->master);
        $this->assertFileExists($lib->absolute($edited->master->path));
        $this->assertFileDoesNotExist($box);
        $this->assertEquals($set, $lib->find($asset->id));

        $this->assertSame([], $lib->setFormatCrop($asset->id, 'cover-thumb', null)->crops);
    }

    public function test_set_format_crop_refuses_other_presets(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        foreach (['card', 'fit-box', 'nope'] as $preset) {
            try {
                $lib->setFormatCrop($asset->id, $preset, CropRect::of(0, 0, 50, 50));
                $this->fail("{$preset} must be refused.");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->lib(formats: false)->setFormatCrop($asset->id, 'cover-thumb', CropRect::of(0, 0, 50, 50));
    }

    public function test_only_public_jpeg_png_webp_and_avif_are_editable(): void
    {
        $lib = $this->lib();
        $gif = imagecreatetruecolor(20, 20);
        ob_start();
        imagegif($gif);
        $assets = [
            'private' => $lib->upload($this->upload($this->quadrants(), 'p.png'), null, true),
            'gif' => $lib->upload($this->upload((string) ob_get_clean(), 'a.gif'), null),
            'svg' => $lib->upload($this->upload('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>', 'a.svg', 'image/svg+xml'), null),
            'txt' => $lib->upload($this->upload("notes\n", 'a.txt'), null),
        ];
        $edits = [
            'setCrop' => static fn (string $id) => $lib->setCrop($id, CropRect::of(0, 0, 50, 50)),
            'setFormatCrop' => static fn (string $id) => $lib->setFormatCrop($id, 'cover-thumb', CropRect::of(0, 0, 50, 50)),
            'rotate' => static fn (string $id) => $lib->rotate($id, 1),
            'flip' => static fn (string $id) => $lib->flip($id, true),
        ];
        foreach ($assets as $kind => $asset) {
            $this->assertFalse($lib->isEditable($asset), $kind);
            foreach ($edits as $name => $edit) {
                try {
                    $edit($asset->id);
                    $this->fail("{$name} on a {$kind} must be refused.");
                } catch (\InvalidArgumentException) {
                }
            }
            $this->assertEquals($asset, $lib->find($asset->id));
            $this->assertNull($lib->orientedUrl($asset));
        }
        foreach ($edits as $name => $edit) {
            try {
                $edit(str_repeat('a', 32));
                $this->fail("{$name} on an unknown id must be refused.");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function test_cmyk_without_imagick_saves_the_crop_and_keeps_showing_the_original(): void
    {
        $lib = $this->lib(new ColorManagement(available: false));
        $asset = $lib->upload($this->upload((string) file_get_contents(__DIR__ . '/Fixtures/color/cmyk-profile.jpg'), 'print.jpg'), null);

        $edited = $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 100));

        $this->assertEquals(CropRect::of(0, 0, 50, 100), $edited->crop);
        $this->assertNull($edited->master);
        $this->assertSame(MediaLibrary::NOTE_NEEDS_IMAGICK, $edited->note);
        $this->assertSame([], $edited->variants);
        $this->assertSame('/media/' . $asset->path, $lib->url($edited));
        $this->assertSame([], array_filter($this->filesOf($lib, $asset), static fn (string $f) => str_contains($f, '-edit-')));
    }

    public function test_format_region(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);

        // A format crop, fitted to the box ratio around its centre: 400x300 px to 4:1 is 400x100 at y 100.
        $lib->setFormatCrop($asset->id, 'cover-hero', CropRect::of(0, 0, 50, 50));
        $asset = $lib->find($asset->id);
        $this->assertEquals(CropRect::of(0, 100 / 6, 50, 100 / 6), $lib->formatRegion($asset, 'cover-hero'));

        // Without one: the focal box inside the image crop (right half, focal moved to 0/50): 400x400 at (400, 100).
        $cropped = $lib->setCrop($asset->id, CropRect::of(50, 0, 50, 100));
        $this->assertSame([0, 50], [$cropped->focalX, $cropped->focalY]);
        $this->assertEquals(CropRect::of(50, 100 / 6, 50, 400 / 6), $lib->formatRegion($cropped, 'cover-thumb'));
        $this->assertEquals(CropRect::of(50, 0, 50, 100), $lib->formatRegion($cropped, 'fit-box'));
        $this->assertNull($lib->formatRegion($cropped, 'card'));
        $this->assertNull($lib->formatRegion($cropped, 'nope'));

        // Turned: 600x800, the 4:1 focal box is 600x150 at y 325.
        $lib->setCrop($asset->id, null);
        $turned = $lib->rotate($asset->id, 1);
        $this->assertEquals(CropRect::of(0, 325 / 8, 100, 150 / 8), $lib->formatRegion($turned, 'cover-hero'));

        $this->assertNull($this->lib(formats: false)->formatRegion($turned, 'cover-hero'));
    }

    public function test_oriented_image_for_the_editor(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $edited = $lib->rotate($asset->id, 1);

        $url = $lib->orientedUrl($edited);

        $rel = self::stem($asset) . '-oriented-' . Formats::key([1, 1, false, 'oriented']) . '.webp';
        $this->assertSame('/media/' . $rel, $url);
        $size = (array) getimagesize($lib->absolute($rel));
        $this->assertSame([600, 800, 'image/webp'], [$size[0], $size[1], $size['mime']]);
        $this->assertColor(self::BLUE, $lib->absolute($rel), 5, 5);
        $this->assertSame($url, $lib->orientedUrl($edited)); // made once

        // The next edit removes it.
        $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 50));
        $this->assertFileDoesNotExist($lib->absolute($rel));

        // The long side is capped at 1600 and never upscaled.
        $big = $lib->upload($this->upload($this->png(2000, 1000), 'big.png'), null);
        $bigRel = substr((string) $lib->orientedUrl($big), strlen('/media/'));
        $this->assertSame([1600, 800], array_slice((array) getimagesize($lib->absolute($bigRel)), 0, 2));
        $small = $lib->upload($this->upload($this->png(300, 200), 'small.png'), null);
        $smallRel = substr((string) $lib->orientedUrl($small), strlen('/media/'));
        $this->assertSame([300, 200], array_slice((array) getimagesize($lib->absolute($smallRel)), 0, 2));
    }

    public function test_a_focal_change_removes_box_files(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $box = $lib->absolute(self::stem($asset) . '-cover-thumb-abcdef01-200.webp');
        file_put_contents($box, 'x');

        $lib->update($asset->id, ['title' => 'Quadrants']);
        $this->assertFileExists($box);

        $lib->update($asset->id, ['focal_x' => 10]);
        $this->assertFileDoesNotExist($box);
    }

    public function test_regenerate_renders_the_master_again(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $edited = $lib->rotate($asset->id, 1);
        unlink($lib->absolute($edited->master->path));

        $again = $lib->regenerate($edited);

        $this->assertEquals($edited->master, $again->master);
        $this->assertFileExists($lib->absolute($again->master->path));
        $this->assertFileDoesNotExist($lib->absolute(self::stem($asset) . '-480.webp'));
        $this->assertFileExists($lib->absolute(substr($again->master->path, 0, -4) . '-480.webp'));
    }

    public function test_deleting_an_edited_asset_leaves_no_file(): void
    {
        $lib = $this->lib();
        $asset = $this->uploadQuadrants($lib);
        $lib->rotate($asset->id, 1);
        $edited = $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 50));
        $lib->orientedUrl($edited);
        file_put_contents($lib->absolute(substr($edited->master->path, 0, -4) . '-cover-thumb-abcdef01-200.webp'), 'x');
        $this->assertNotSame([], $this->filesOf($lib, $asset));

        $lib->delete($asset->id);

        $this->assertSame([], $this->filesOf($lib, $asset));
        $this->assertNull($lib->find($asset->id));
    }
}
