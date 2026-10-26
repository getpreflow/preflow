<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Image;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Media\CropRect;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageBackend;
use Preflow\Media\Image\ImagickBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Tests\Support\MediaFixtures;

#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class EditRenderTest extends TestCase
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

    /** @return array<string, array{string}> */
    public static function backends(): array
    {
        return ['gd' => ['gd'], 'imagick' => ['imagick']];
    }

    private function backend(string $name): ImageBackend
    {
        if ($name === 'imagick') {
            if (!class_exists(\Imagick::class)) {
                $this->markTestSkipped('Imagick is not installed.');
            }

            return new ImagickBackend();
        }

        return new GdBackend();
    }

    /** 800x600: red top-left, green top-right, blue bottom-left, yellow bottom-right. */
    private function quadrants(string $ext = 'png'): string
    {
        $im = imagecreatetruecolor(800, 600);
        foreach ([[0, 0, self::RED], [400, 0, self::GREEN], [0, 300, self::BLUE], [400, 300, self::YELLOW]] as [$x, $y, $c]) {
            imagefilledrectangle($im, $x, $y, $x + 399, $y + 299, (int) imagecolorallocate($im, ...$c));
        }
        $file = $this->assetRoot . '/q.' . $ext;
        $ext === 'png' ? imagepng($im, $file) : imagejpeg($im, $file, 95);

        return $file;
    }

    /** @param array{int, int, int} $expected */
    private function assertColor(array $expected, string $file, int $x, int $y): void
    {
        $im = imagecreatefromstring((string) file_get_contents($file));
        imagepalettetotruecolor($im); // Imagick writes small PNGs with a palette
        $rgb = imagecolorat($im, $x, $y) & 0xFFFFFF;
        $got = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
        foreach ($expected as $i => $v) {
            $this->assertEqualsWithDelta($v, $got[$i], 40, "pixel $x,$y is " . implode(',', $got) . ' not ' . implode(',', $expected));
        }
    }

    #[DataProvider('backends')]
    public function test_quarter_turn(string $b): void
    {
        $dest = $this->assetRoot . '/o.png';
        $this->assertTrue($this->backend($b)->writeEdited($this->quadrants(), $dest, 1, false, null, 'png', 92));

        $this->assertSame([600, 800], array_slice(getimagesize($dest), 0, 2));
        $this->assertColor(self::BLUE, $dest, 5, 5);
        $this->assertColor(self::RED, $dest, 595, 5);
    }

    #[DataProvider('backends')]
    public function test_mirror(string $b): void
    {
        $dest = $this->assetRoot . '/o.png';
        $this->assertTrue($this->backend($b)->writeEdited($this->quadrants(), $dest, 0, true, null, 'png', 92));

        $this->assertColor(self::GREEN, $dest, 5, 5);
    }

    #[DataProvider('backends')]
    public function test_mirror_then_turn(string $b): void
    {
        $dest = $this->assetRoot . '/o.png';
        $this->assertTrue($this->backend($b)->writeEdited($this->quadrants(), $dest, 1, true, null, 'png', 92));

        $this->assertSame([600, 800], array_slice(getimagesize($dest), 0, 2));
        $this->assertColor(self::YELLOW, $dest, 5, 5);
    }

    #[DataProvider('backends')]
    public function test_crop(string $b): void
    {
        $dest = $this->assetRoot . '/o.png';
        $this->assertTrue($this->backend($b)->writeEdited($this->quadrants(), $dest, 0, false, ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 300], 'png', 92));

        $this->assertSame([400, 300], array_slice(getimagesize($dest), 0, 2));
        $this->assertColor(self::RED, $dest, 5, 5);
        $this->assertColor(self::RED, $dest, 395, 295);
    }

    #[DataProvider('backends')]
    public function test_max_long_side_and_formats(string $b): void
    {
        foreach (['jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'png' => 'image/png'] as $format => $mime) {
            $dest = $this->assetRoot . '/o.' . $format;
            $this->assertTrue($this->backend($b)->writeEdited($this->quadrants(), $dest, 0, false, null, $format, 92, 400));
            $size = getimagesize($dest);
            $this->assertSame($mime, $size['mime']);
            $this->assertSame([400, 300], [$size[0], $size[1]]);
        }
    }

    #[DataProvider('backends')]
    public function test_exif_oriented_jpeg_is_upright_before_turning(string $b): void
    {
        // Stored 600x800 with orientation 6: upright it is 800x600 (red top-left ... blue bottom-left).
        $upright = imagecreatefrompng($this->quadrants());
        $stored = imagerotate($upright, 90, 0); // counter-clockwise: undone by orientation 6 (rotate 90 cw)
        ob_start();
        imagejpeg($stored, null, 95);
        $jpeg = (string) ob_get_clean();
        $src = $this->assetRoot . '/exif.jpg';
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', 6) . "\x00\x00\x00\x00\x00\x00";
        $payload = "Exif\x00\x00" . $tiff;
        file_put_contents($src, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2));

        $dest = $this->assetRoot . '/o.png';
        $this->assertTrue($this->backend($b)->writeEdited($src, $dest, 1, false, null, 'png', 92));

        $this->assertSame([600, 800], array_slice(getimagesize($dest), 0, 2));
        $this->assertColor(self::BLUE, $dest, 5, 5);
    }

    #[DataProvider('backends')]
    public function test_a_job_with_a_crop_scales_the_region(string $b): void
    {
        $dest = $this->assetRoot . '/o.webp';
        $r = $this->backend($b)->writeVariants($this->quadrants(), [['dest' => $dest, 'width' => 200, 'format' => 'webp', 'crop' => ['x' => 400, 'y' => 300, 'w' => 400, 'h' => 300]]], 80);

        $this->assertSame([true], $r);
        $this->assertSame([200, 150], array_slice(getimagesize($dest), 0, 2));
        $this->assertColor(self::YELLOW, $dest, 5, 5);
        $this->assertColor(self::YELLOW, $dest, 195, 145);
    }

    #[DataProvider('backends')]
    public function test_a_job_without_a_crop_keeps_its_dimensions(string $b): void
    {
        $dest = $this->assetRoot . '/o.webp';
        $r = $this->backend($b)->writeVariants($this->quadrants(), [['dest' => $dest, 'width' => 200, 'format' => 'webp']], 80);

        $this->assertSame([true], $r);
        $this->assertSame([200, 150], array_slice(getimagesize($dest), 0, 2));
    }

    public function test_render_edited_writes_and_reports_the_size(): void
    {
        $this->quadrants();
        $gen = new VariantGenerator(new GdBackend(), [480]);

        $size = $gen->renderEdited($this->assetRoot, 'q.png', 'image/png', 1, false, null, 'e.jpg');
        $this->assertSame([600, 800], $size);
        $info = getimagesize($this->assetRoot . '/e.jpg');
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame([600, 800], [$info[0], $info[1]]);
        $this->assertSame([], glob($this->assetRoot . '/*.tmp-*'));

        $this->assertSame([300, 400], $gen->renderEdited($this->assetRoot, 'q.png', 'image/png', 1, false, null, 'f.webp', 400));
        $this->assertSame(400, getimagesize($this->assetRoot . '/f.webp')[1]);
    }

    public function test_render_edited_turns_the_percent_crop_into_pixels_of_the_turned_image(): void
    {
        $this->quadrants();
        $gen = new VariantGenerator(new GdBackend(), [480]);

        // Turned image is 600x800; the top 50% is 600x400, and its top-left is blue.
        $size = $gen->renderEdited($this->assetRoot, 'q.png', 'image/png', 1, false, CropRect::of(0, 0, 100, 50), 'e.png');
        $this->assertSame([600, 400], $size);
    }

    #[RequiresPhpExtension('imagick')]
    public function test_render_edited_without_colour_management_refuses_cmyk(): void
    {
        $dir = $this->assetRoot;
        copy(__DIR__ . '/../Fixtures/color/cmyk-profile.jpg', $dir . '/c.jpg');
        $gen = new VariantGenerator(new GdBackend(), [480], 75, new ColorManagement(available: false));

        $this->assertNull($gen->renderEdited($dir, 'c.jpg', 'image/jpeg', 0, false, null, 'e.jpg'));
        $this->assertFileDoesNotExist($dir . '/e.jpg');
        $this->assertSame([], glob($dir . '/*.tmp-*'));
    }

    public function test_generate_box_writes_the_cropped_variant(): void
    {
        $this->quadrants();
        $gen = new VariantGenerator(new GdBackend(), [480]);

        $this->assertTrue($gen->generateBox($this->assetRoot, 'q.png', 'image/png', ['x' => 400, 'y' => 300, 'w' => 400, 'h' => 300], 200, 'webp', 'q-cover-1a2b3c4d-200.webp'));
        $this->assertSame([200, 150], array_slice(getimagesize($this->assetRoot . '/q-cover-1a2b3c4d-200.webp'), 0, 2));
        $this->assertSame([], glob($this->assetRoot . '/*.tmp-*'));
    }

    public function test_remove_boxes_deletes_only_box_files(): void
    {
        $asset = $this->library([], new VariantGenerator(new GdBackend(), [480]))->upload($this->upload($this->png(800, 400), 'a.png'), null);
        $dir = $this->assetRoot . '/media';
        $stem = $dir . '/' . preg_replace('/\.[a-z0-9]+$/', '', $asset->path);
        $box = $stem . '-cover-thumb-1a2b3c4d-400.webp';
        $keep = [$stem . '-v3-cover-thumb-1a2b3c4d-400.webp', $stem . '-edit-9f8e7d6c-cover-thumb-1a2b3c4d-400.webp', $stem . '-480.webp', $stem . '-edit-1a2b3c4d.jpg', $stem . '-edit-1a2b3c4d-480.webp', $stem . '-preview-1a2b3c4d-400.webp'];
        foreach ([$box, ...$keep] as $f) {
            file_put_contents($f, 'x');
        }

        (new VariantGenerator(new GdBackend(), [480]))->removeBoxes($asset, $dir);

        $this->assertFileDoesNotExist($box);
        foreach ($keep as $f) {
            $this->assertFileExists($f);
        }
    }
}
