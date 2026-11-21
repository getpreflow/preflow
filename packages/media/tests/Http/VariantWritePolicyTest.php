<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Context\WritesRefused;
use Preflow\Media\Asset;
use Preflow\Media\Http\VariantController;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Testing\ContextFactory;
use Preflow\View\VariantPending;
use Psr\Http\Message\ResponseInterface;

/** A media variant made on request is a 'file' write of the write policy. */
#[RequiresFunction('imagewebp')]
final class VariantWritePolicyTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
    private ImageWidths $widths;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->makeAssetRoot();
        $this->widths = ImageWidths::fromConfig(['card' => ['widths' => [480, 960], 'step' => 160]], null);
        $this->lib = $this->library([], new VariantGenerator(new GdBackend(), $this->widths->eager(), 75));
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeAssetRoot();
    }

    private function serve(string $path): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/media/' . $path)->withAttribute('path', $path);

        return (new VariantController($this->lib, $this->widths))->serve($request);
    }

    private function base(Asset $asset): string
    {
        return substr($asset->path, 0, (int) strrpos($asset->path, '.'));
    }

    public function test_no_variant_file_is_written_when_writes_are_skipped(): void
    {
        $asset = $this->asset();
        $before = $this->mediaFiles();
        $frame = null;

        [$made, $pending] = Contexts::run(ContextFactory::forTest()->skippingWrites(), function () use ($asset, &$frame): array {
            $frame = Contexts::frame();

            return [
                $this->serve($this->base($asset) . '-480.webp'), // made at upload: served, nothing to write
                $this->serve($this->base($asset) . '-640.webp'), // allowed, not made yet
            ];
        });

        $this->assertSame(200, $made->getStatusCode());
        $this->assertSame(404, $pending->getStatusCode());
        $this->assertSame('pending', $pending->getHeaderLine(VariantPending::HEADER));
        $this->assertSame($before, $this->mediaFiles(), 'no variant file written');
        $this->assertEquals([new SkippedWrite('media', 'variants', $this->base($asset) . '-640.webp', 'file')], $frame->skippedWrites());

        // Refused: the request fails, nothing is written.
        try {
            Contexts::run(ContextFactory::forTest()->refusingWrites(), fn () => $this->serve($this->base($asset) . '-640.webp'));
            $this->fail('refused');
        } catch (WritesRefused) {
        }
        $this->assertSame($before, $this->mediaFiles());

        // A visitor's request makes it.
        $visit = Contexts::run(ContextFactory::forTest(), fn () => $this->serve($this->base($asset) . '-640.webp'));
        $this->assertSame(200, $visit->getStatusCode());
        $this->assertNotSame($before, $this->mediaFiles());
    }

    /** @return list<string> every file under media */
    private function mediaFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->assetRoot . '/media', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($this->assetRoot . '/media/'));
        }
        sort($out);

        return $out;
    }

    private function asset(): Asset
    {
        return $this->lib->upload($this->upload($this->png(1000, 600), 'a.png'), null);
    }
}
