<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\CropRect;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/**
 * displayUrl(): the file to show at a size (an admin's large preview): the smallest WebP variant
 * at least $minWidth wide, else the widest, from the same files thumbnailUrl() uses (the delivered
 * image with its edits, a private file's gated URL), which keeps its 480 variant.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class DisplayUrlTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_the_smallest_variant_at_least_as_wide_else_the_widest(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(1200, 800), 'wide.png'), null);
        $stem = '/media/' . substr($asset->path, 0, (int) strrpos($asset->path, '.'));

        $this->assertSame($stem . '-480.webp', $lib->thumbnailUrl($asset), 'the thumbnail keeps its 480 variant');
        $this->assertSame($stem . '-960.webp', $lib->displayUrl($asset, 900));
        $this->assertSame($stem . '-480.webp', $lib->displayUrl($asset, 400));
        $this->assertSame($stem . '-320.webp', $lib->displayUrl($asset, 0));
        $this->assertSame($stem . '-960.webp', $lib->displayUrl($asset, 4000), 'none that wide: the widest');
    }

    public function test_an_edited_image_shows_its_delivered_file_and_a_private_one_its_gated_url(): void
    {
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(1200, 800), 'crop.png'), null);
        $edited = $lib->setCrop($asset->id, CropRect::of(0, 0, 50, 100));
        $master = (string) $edited->master?->path;

        $this->assertStringStartsWith('/media/' . substr($master, 0, (int) strrpos($master, '.')) . '-', (string) $lib->displayUrl($edited, 900));

        $private = $lib->upload($this->upload($this->png(1200, 800), 'secret.png'), null, private: true);
        $this->assertSame($lib->url($private), $lib->displayUrl($private, 900));
        $this->assertSame($lib->thumbnailUrl($private), $lib->displayUrl($private, 900));
    }

    public function test_other_files_have_none(): void
    {
        $lib = $this->lib();
        $text = $lib->upload($this->upload("notes\n", 'notes.txt'), null);

        $this->assertNull($lib->displayUrl($text, 900));
    }

    private function lib(): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new Locales(['en'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            new VariantGenerator(new GdBackend(), [320, 480, 960], 75, new ColorManagement()),
        );
    }
}
