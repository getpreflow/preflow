<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\CropRect;
use Preflow\Media\Derived\DerivedMaker;
use Preflow\Media\Derived\DerivedMakers;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Making a copy again from its original in place: remake(), canRemake(), isStale() and the makers. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class RemakeTest extends TestCase
{
    use MediaApp;
    use MediaFixtures;

    private MediaLibrary $lib;
    private StampMaker $stamp;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->stamp = new StampMaker($this->assetRoot . '/made');
        $makers = new DerivedMakers();
        $makers->register($this->stamp);
        $this->lib = $this->library(
            ['versions_dir' => $this->assetRoot . '/versions'],
            new VariantGenerator(new GdBackend(), [480], 75),
            makers: $makers,
        );
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function jpeg(int $width = 60, int $height = 40, int $red = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 80, 40));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function fileWith(string $bytes): string
    {
        $dir = $this->assetRoot . '/in';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function original(): Asset
    {
        return $this->lib->upload($this->upload($this->jpeg(), 'photo.jpg'), 'ed');
    }

    /** @param array<string, mixed> $params */
    private function stamped(Asset $original, array $params = ['line' => 'a'], string $maker = 'stamp'): Asset
    {
        return $this->lib->derive($original->id, $this->fileWith($this->jpeg(60, 40, 10)), 'ed', $maker, $params);
    }

    private function bytesOf(Asset $asset): string
    {
        return (string) file_get_contents((string) $this->lib->file($asset));
    }

    public function test_remake_keeps_id_and_versions_old_file(): void
    {
        $copy = $this->stamped($this->original());

        $remade = $this->lib->remake($copy->id, 'ed');

        $this->assertSame($copy->id, $remade->id);
        $this->assertSame(2, $remade->version);
        $this->assertCount(1, $this->lib->versions($copy->id));
        $this->assertStringContainsString('stamp:{"line":"a"}', $this->bytesOf($remade));
        $this->assertSame(['line' => 'a'], $remade->makerParams);
        // The library owns the maker's file and its own input: both are gone.
        $this->assertFileDoesNotExist((string) $this->stamp->lastOutput);
        $this->assertFileDoesNotExist((string) $this->stamp->lastInput);
    }

    public function test_remake_with_new_params_stores_them(): void
    {
        $copy = $this->stamped($this->original());

        $remade = $this->lib->remake($copy->id, null, ['line' => 'b']);

        $this->assertSame(['line' => 'b'], $remade->makerParams);
        $this->assertSame(['line' => 'b'], $this->lib->find($copy->id)?->makerParams);
        $this->assertSame(['line' => 'b'], $this->stamp->lastParams);
        $this->assertStringContainsString('stamp:{"line":"b"}', $this->bytesOf($remade));
    }

    public function test_remake_gives_maker_the_oriented_original(): void
    {
        $original = $this->lib->rotate($this->original()->id, 1);
        $copy = $this->stamped($original);

        $this->lib->remake($copy->id, null);

        $this->assertSame([60, 40], array_slice((array) getimagesize((string) $this->lib->file($original)), 0, 2));
        $this->assertSame([40, 60], $this->stamp->lastSize);
    }

    public function test_remake_gives_maker_the_raw_file_of_an_unedited_original(): void
    {
        $original = $this->original();
        $copy = $this->stamped($original);

        $this->lib->remake($copy->id, null);

        $this->assertSame($this->bytesOf($original), $this->stamp->lastBytes);
    }

    public function test_remake_copy_maker_uses_raw_file_and_keeps_copy_edits(): void
    {
        $original = $this->original();
        $copy = $this->lib->makeCopy($original->id, 'ed');
        $copy = $this->lib->setCrop($copy->id, CropRect::of(10, 10, 50, 50));
        $original = $this->lib->replace($original->id, $this->fileWith($this->jpeg(80, 50, 30)), 'ed');

        $remade = $this->lib->remake($copy->id, 'ed');

        $this->assertSame($this->bytesOf($original), $this->bytesOf($remade));
        $this->assertSame($copy->crop?->toArray(), $remade->crop?->toArray());
        $this->assertSame([80, 50], [$remade->width, $remade->height]);
        $this->assertSame(2, $remade->derivedVersion);
        $this->assertSame(2, $remade->version);
        $this->assertNull($this->stamp->lastInput); // no maker is called for a plain copy
    }

    public function test_remake_refuses_unknown_maker(): void
    {
        $copy = $this->stamped($this->original(), maker: 'nobody');
        $bytes = $this->bytesOf($copy);

        try {
            $this->lib->remake($copy->id, null);
            $this->fail('remake() should refuse a maker nobody registered');
        } catch (\InvalidArgumentException) {
        }

        $after = $this->lib->find($copy->id);
        $this->assertSame(1, $after?->version);
        $this->assertSame($bytes, $this->bytesOf($after));
        $this->assertFalse($this->lib->canRemake($after));
    }

    public function test_remake_refuses_without_live_original(): void
    {
        $original = $this->original();
        $copy = $this->stamped($original);
        $bytes = $this->bytesOf($copy);
        $this->lib->discard($original->id);

        try {
            $this->lib->remake($copy->id, null);
            $this->fail('remake() should refuse a copy whose original is gone');
        } catch (\InvalidArgumentException) {
        }

        $after = $this->lib->find($copy->id);
        $this->assertSame(1, $after?->version);
        $this->assertSame($bytes, $this->bytesOf($after));
        $this->assertFalse($this->lib->canRemake($after));
        $this->assertFalse($this->lib->isStale($after));
        $this->assertNull($this->stamp->lastInput);
    }

    public function test_remake_refuses_a_copy_whose_original_is_binned(): void
    {
        $makers = new DerivedMakers();
        $makers->register($this->stamp);
        $this->lib = $this->library(
            ['versions_dir' => $this->assetRoot . '/versions', 'bin_dir' => $this->assetRoot . '/bin'],
            new VariantGenerator(new GdBackend(), [480], 75),
            makers: $makers,
        );
        $original = $this->original();
        $copy = $this->stamped($original);
        $bytes = $this->bytesOf($copy);
        $this->lib->delete($original->id); // no usage sources here: the original bins although it has a copy
        $this->assertNotNull($this->lib->findBinned($original->id));

        try {
            $this->lib->remake($copy->id, null);
            $this->fail('remake() should refuse a copy whose original is in the bin');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('in the bin', $e->getMessage());
        }

        $after = $this->lib->find($copy->id);
        $this->assertSame(1, $after?->version);
        $this->assertSame($bytes, $this->bytesOf($after));
        $this->assertFalse($this->lib->canRemake($after));
        $this->assertFalse($this->lib->isStale($after));
        $this->assertNull($this->stamp->lastInput);
    }

    public function test_remake_refuses_an_original(): void
    {
        $original = $this->original();

        $this->expectException(\InvalidArgumentException::class);
        $this->lib->remake($original->id, null);
    }

    public function test_is_stale_after_original_replaced(): void
    {
        $original = $this->original();
        $copy = $this->stamped($original);
        $this->assertFalse($this->lib->isStale($copy));
        $this->assertTrue($this->lib->canRemake($copy));
        $this->assertFalse($this->lib->isStale($original));

        $this->lib->replace($original->id, $this->fileWith($this->jpeg(60, 40, 90)), 'ed');
        $copy = $this->lib->find($copy->id);
        $this->assertNotNull($copy);
        $this->assertTrue($this->lib->isStale($copy));

        $remade = $this->lib->remake($copy->id, 'ed');
        $this->assertSame(2, $remade->derivedVersion);
        $this->assertFalse($this->lib->isStale($remade));
    }

    public function test_register_copy_name_is_refused(): void
    {
        $makers = new DerivedMakers();

        $this->expectException(\InvalidArgumentException::class);
        $makers->register(new StampMaker($this->assetRoot . '/made', MediaLibrary::MAKER_COPY));
    }

    public function test_makers_by_name(): void
    {
        $makers = new DerivedMakers();
        $makers->register($this->stamp);

        $this->assertTrue($makers->has('stamp'));
        $this->assertSame($this->stamp, $makers->get('stamp'));
        $this->assertFalse($makers->has('nobody'));
        $this->assertNull($makers->get('nobody'));
    }

    public function test_the_provider_shares_one_makers_registry_with_the_library(): void
    {
        $this->scaffoldMediaApp();
        try {
            $container = $this->mediaApp()->container();
            $makers = $container->get(DerivedMakers::class);

            $this->assertSame($makers, $container->get(DerivedMakers::class));
            $this->assertSame($makers, (new \ReflectionProperty(MediaLibrary::class, 'makers'))->getValue($this->media()));
        } finally {
            $this->removeMediaApp();
        }
    }
}

/** A fake maker: the input as a JPEG with a comment marker naming its params; it remembers what it got. */
final class StampMaker implements DerivedMaker
{
    public ?string $lastInput = null;
    public ?string $lastOutput = null;
    public ?string $lastBytes = null;
    /** @var ?array{int, int} */
    public ?array $lastSize = null;
    /** @var ?array<string, mixed> */
    public ?array $lastParams = null;

    public function __construct(private readonly string $dir, private readonly string $name = 'stamp')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function make(Asset $original, string $file, array $params): string
    {
        $this->lastInput = $file;
        $this->lastBytes = (string) file_get_contents($file);
        $size = (array) getimagesize($file);
        $this->lastSize = [(int) $size[0], (int) $size[1]];
        $this->lastParams = $params;

        $image = imagecreatefromstring($this->lastBytes) ?: throw new \RuntimeException('not an image');
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $marker = $this->name . ':' . json_encode($params);
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($marker) + 2) . $marker . substr($jpeg, 2);

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->lastOutput = $this->dir . '/' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($this->lastOutput, $jpeg);

        return $this->lastOutput;
    }
}
