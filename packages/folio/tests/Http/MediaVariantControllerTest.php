<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Folio\Assets\Asset;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Assets\Image\GdBackend;
use Preflow\Folio\Assets\ImageWidths;
use Preflow\Folio\Assets\VariantGenerator;
use Preflow\Folio\Http\MediaVariantController;
use Preflow\Folio\Tests\Support\AssetFixtures;

#[RequiresFunction('imagewebp')]
final class MediaVariantControllerTest extends TestCase
{
    use AssetFixtures;

    private AssetLibrary $lib;
    private ImageWidths $widths;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->widths = ImageWidths::fromConfig(['card' => ['widths' => [480, 960], 'step' => 160]], null);
        $this->lib = $this->library([], new VariantGenerator(new GdBackend(), $this->widths->eager(), 75));
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function serve(string $path): \Psr\Http\Message\ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/media/' . $path)->withAttribute('path', $path);

        return (new MediaVariantController($this->lib, $this->widths))->serve($request);
    }

    private function asset(int $width = 1000, int $height = 600): Asset
    {
        return $this->lib->upload($this->upload($this->png($width, $height), 'a.png'), null);
    }

    private function base(Asset $asset): string
    {
        return substr($asset->path, 0, (int) strrpos($asset->path, '.'));
    }

    private function file(string $rel): string
    {
        return $this->assetRoot . '/media/' . $rel;
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

    public function test_missing_allowed_variant_is_generated_and_served(): void
    {
        $asset = $this->asset();
        $rel = $this->base($asset) . '-640.webp';
        $this->assertFileDoesNotExist($this->file($rel)); // a step width: not made at upload

        $res = $this->serve($rel);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/webp', $res->getHeaderLine('Content-Type'));
        $this->assertSame('public, max-age=31536000', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('RIFF', substr((string) $res->getBody(), 0, 4));
        $this->assertFileExists($this->file($rel));
        $this->assertSame([640, 384], array_slice(getimagesize($this->file($rel)), 0, 2));

        $mtime = filemtime($this->file($rel));
        clearstatcache();
        $this->serve($rel);
        $this->assertSame($mtime, filemtime($this->file($rel))); // reused, not re-encoded
        $this->assertSame([], glob($this->file($rel) . '.*') ?: []); // no lock or temp file left
    }

    public function test_disallowed_requests_are_404_and_write_nothing(): void
    {
        $asset = $this->asset();
        $base = $this->base($asset);
        $before = $this->mediaFiles();

        foreach ([
            $base . '-700.webp',                                  // not a width any preset uses
            dirname($base) . '/' . str_repeat('a', 32) . '-640.webp', // unknown asset
            $base . '-640.png',                                   // not a variant format
            $base . '-640.gif',
            '../' . $base . '-640.webp',                          // path tricks
            $base . '-0640.webp',
            'notes.txt',                                          // any other missing media file
        ] as $path) {
            try {
                $this->serve($path);
                $this->fail("expected a 404 for {$path}");
            } catch (NotFoundHttpException) {
            }
        }

        $this->assertSame($before, $this->mediaFiles());
    }

    public function test_width_above_the_original_is_404(): void
    {
        $asset = $this->asset(500, 300);

        $this->expectException(NotFoundHttpException::class);
        $this->serve($this->base($asset) . '-640.webp');
    }

    public function test_the_original_width_is_allowed_when_narrower_than_the_largest(): void
    {
        $asset = $this->asset(700, 400); // srcset lists 700w for such an image
        unlink($this->file($this->base($asset) . '-700.webp')); // made at upload; ask for it again

        $this->assertSame(200, $this->serve($this->base($asset) . '-700.webp')->getStatusCode());
    }

    public function test_a_second_request_waits_for_the_one_generating(): void
    {
        $asset = $this->asset();
        $rel = $this->base($asset) . '-800.webp';
        @mkdir(dirname($this->file($rel)), 0777, true);
        // Another process holds the variant's lock for a moment, as a concurrent first request would.
        $holder = proc_open([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); usleep(700000); flock($h, LOCK_UN);', $this->file($rel) . '.lock'], [1 => ['pipe', 'w']], $pipes);
        $this->assertSame("locked\n", fgets($pipes[1]));

        $started = microtime(true);
        $res = $this->serve($rel);
        $waited = microtime(true) - $started;
        proc_close($holder);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertGreaterThan(0.4, $waited);
        $this->assertFileExists($this->file($rel));
    }

    public function test_delete_removes_variants_made_on_demand(): void
    {
        $asset = $this->asset();
        $rel = $this->base($asset) . '-640.webp';
        $this->serve($rel);

        $this->lib->delete($asset->id);

        $this->assertFileDoesNotExist($this->file($rel));
        $this->assertSame([], glob($this->file($this->base($asset)) . '-*') ?: []);
    }

    public function test_warm_generates_every_allowed_width(): void
    {
        $asset = $this->asset();

        $made = $this->lib->warm($asset, $this->widths->allowed());

        foreach ([480, 640, 800, 960] as $w) {
            $this->assertFileExists($this->file($this->base($asset) . "-{$w}.webp"));
        }
        $this->assertGreaterThanOrEqual(2, $made); // 640 and 800 were new
    }
}
