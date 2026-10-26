<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Http\VariantController;
use Preflow\Media\Tests\Support\MediaFixtures;

#[RequiresFunction('imagewebp')]
final class MediaVariantControllerTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
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

        return (new VariantController($this->lib, $this->widths))->serve($request);
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

    public function test_versioned_names_follow_the_current_path_stem(): void
    {
        $asset = $this->asset();
        $old = $this->base($asset);
        $new = $old . '-v3';
        rename($this->file($old . '.png'), $this->file($new . '.png'));
        $save = new \ReflectionMethod($this->lib, 'save');
        $save->invoke($this->lib, [...$asset->toData(), 'path' => $new . '.png', 'version' => 3]);

        $res = $this->serve($new . '-480.webp');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertFileExists($this->file($new . '-480.webp'));

        foreach ([
            $old . '-v2-480.webp',   // not the current version
            $old . '-480.webp',      // the previous stem
            $old . '-v0-480.webp',
            $old . '-v01-480.webp',
            $old . '-v1-480.webp',
        ] as $path) {
            try {
                $this->serve($path);
                $this->fail("expected a 404 for {$path}");
            } catch (NotFoundHttpException) {
            }
        }
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

    public function test_no_recorded_variant_in_the_format_means_no_generation(): void
    {
        $asset = $this->library()->upload($this->upload($this->png(1000, 600), 'a.png'), null); // no backend at upload: no variants
        $this->assertSame([], $asset->variants);

        try {
            $this->serve($this->base($asset) . '-640.webp');
            $this->fail('expected a 404');
        } catch (NotFoundHttpException) {
        }
        $this->assertFileDoesNotExist($this->file($this->base($asset) . '-640.webp'));
    }

    public function test_failed_encode_redirects_to_the_nearest_existing_variant(): void
    {
        $asset = $this->asset();
        $failing = new class implements \Preflow\Media\Image\ImageBackend {
            public function supports(string $format): bool { return true; }
            public function writeEdited(string $src, string $dest, int $quarterTurns, bool $mirror, ?array $crop, string $format, int $quality, int $maxLongSide = 0): bool
            {
                return false;
            }

            public function writeVariants(string $src, array $jobs, int $quality): array { return array_fill(0, count($jobs), false); }
        };
        $this->lib = $this->library([], new VariantGenerator($failing, $this->widths->eager(), 75));

        $res = $this->serve($this->base($asset) . '-800.webp');

        $this->assertSame(302, $res->getStatusCode()); // never a broken image inside a srcset
        $this->assertSame('/media/' . $this->base($asset) . '-480.webp', $res->getHeaderLine('Location'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertFileDoesNotExist($this->file($this->base($asset) . '-800.webp'));
    }

    public function test_a_waiting_request_reuses_the_file_the_other_one_wrote(): void
    {
        $asset = $this->asset();
        $rel = $this->base($asset) . '-800.webp';
        $source = $this->file($this->base($asset) . '-480.webp'); // any valid bytes stand in for the other encode
        $holder = proc_open([PHP_BINARY, '-r', '$h = fopen($argv[1] . ".lock", "c"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); usleep(400000); copy($argv[2], $argv[1]); flock($h, LOCK_UN);', $this->file($rel), $source], [1 => ['pipe', 'w']], $pipes);
        $this->assertSame("locked\n", fgets($pipes[1]));

        $res = $this->serve($rel);
        proc_close($holder);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(file_get_contents($source), (string) $res->getBody()); // the other's file, not a second encode
    }

    public function test_gif_and_svg_assets_get_no_variants(): void
    {
        $gif = imagecreatetruecolor(800, 400);
        ob_start();
        imagegif($gif);
        $assets = [
            $this->lib->upload($this->upload((string) ob_get_clean(), 'a.gif'), null),
            $this->library(['allow_svg' => true], new VariantGenerator(new GdBackend(), $this->widths->eager(), 75))
                ->upload($this->upload('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="400"/>', 'a.svg'), null),
        ];

        foreach ($assets as $asset) {
            try {
                $this->serve($this->base($asset) . '-480.webp');
                $this->fail('expected a 404 for ' . $asset->mime);
            } catch (NotFoundHttpException) {
            }
            $this->assertFileDoesNotExist($this->file($this->base($asset) . '-480.webp'));
        }
    }

    public function test_warm_on_upload_makes_the_step_widths_at_once(): void
    {
        $this->lib = $this->library(['warm_on_upload' => true, 'warm_widths' => $this->widths->allowed()], new VariantGenerator(new GdBackend(), $this->widths->eager(), 75));

        $asset = $this->asset();

        $this->assertFileExists($this->file($this->base($asset) . '-640.webp'));
        $this->assertFileExists($this->file($this->base($asset) . '-800.webp'));
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
