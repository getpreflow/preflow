<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Preview;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Preview\VideoPreviewRenderer;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Video previews: one frame by ffmpeg, made on upload; a missing or failing ffmpeg leaves the upload alone. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class VideoPreviewRendererTest extends TestCase
{
    use MediaFixtures;

    private const string FIXTURES = __DIR__ . '/../Fixtures/video';

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function requireFfmpeg(): void
    {
        if (PdfPreviewRenderer::findBinary('ffmpeg') === null) {
            $this->markTestSkipped('No ffmpeg on this machine.');
        }
    }

    private function lib(?VideoPreviewRenderer $renderer = null): MediaLibrary
    {
        $renderers = new PreviewRenderers();
        $renderers->register($renderer ?? new VideoPreviewRenderer());

        return $this->library([], new VariantGenerator(new GdBackend(), [480, 960], 75), previews: $renderers);
    }

    private function video(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    public function test_renders_png_frame_within_long_side(): void
    {
        $this->requireFfmpeg();
        $renderer = new VideoPreviewRenderer();

        $png = $renderer->render(self::FIXTURES . '/clip.mp4', 320);

        $this->assertNotNull($png);
        try {
            $this->assertSame([320, 180, IMAGETYPE_PNG], array_slice((array) getimagesize($png), 0, 3));
        } finally {
            @unlink($png);
        }
        foreach (['video/mp4', 'video/webm', 'video/quicktime'] as $mime) {
            $this->assertTrue($renderer->supports($mime), $mime);
        }
        $this->assertFalse($renderer->supports('application/pdf'));
        $this->assertFalse($renderer->supports('video/x-msvideo'));
    }

    public function test_short_video_takes_frame_at_ten_percent(): void
    {
        $this->assertSame(0.2, VideoPreviewRenderer::seekFor(2.0));
        $this->assertSame(1.0, VideoPreviewRenderer::seekFor(30.0));
        $this->assertSame(1.0, VideoPreviewRenderer::seekFor(10.0));
        $this->assertSame(1.0, VideoPreviewRenderer::seekFor(null));
        $this->assertSame(20, VideoPreviewRenderer::TIME_LIMIT);
    }

    public function test_unreadable_video_gives_no_preview(): void
    {
        $this->requireFfmpeg();

        $this->assertNull((new VideoPreviewRenderer())->render(self::FIXTURES . '/broken.mp4', 320));

        $library = $this->lib();
        $asset = $library->upload($this->upload($this->video('broken.mp4'), 'broken.mp4'), null);
        $this->assertSame('video', $asset->kind());
        $this->assertNull($asset->preview);
        $this->assertFileExists((string) $library->file($asset));
        $this->assertNull($library->thumbnailUrl($asset));
    }

    public function test_missing_ffmpeg_gives_null(): void
    {
        $renderer = new VideoPreviewRenderer('/nonexistent/ffmpeg');

        $this->assertFalse($renderer->supports('video/mp4'));
        $this->assertNull($renderer->render(self::FIXTURES . '/clip.mp4', 320));

        $asset = $this->lib($renderer)->upload($this->upload($this->video('clip.mp4'), 'clip.mp4'), null);
        $this->assertSame('video', $asset->kind());
        $this->assertNull($asset->preview);
    }

    public function test_without_ffprobe_the_frame_at_one_second_or_the_first_is_taken(): void
    {
        $this->requireFfmpeg();
        // Without ffprobe the seek is 1 s; a clip of 2 s has a frame there.
        $png = (new VideoPreviewRenderer(ffprobe: '/nonexistent/ffprobe'))->render(self::FIXTURES . '/clip.mp4', 160);

        $this->assertNotNull($png);
        @unlink($png);
    }

    public function test_a_hanging_ffmpeg_is_stopped_at_the_time_limit(): void
    {
        $stub = $this->assetRoot . '/ffmpeg';
        file_put_contents($stub, "#!/bin/sh\nexec sleep 20\n");
        chmod($stub, 0755);
        $started = microtime(true);

        $png = (new VideoPreviewRenderer($stub, '/nonexistent/ffprobe', timeLimit: 1))->render(self::FIXTURES . '/clip.mp4', 160);

        $this->assertNull($png);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function test_upload_makes_the_preview_and_a_poster_wins_the_thumbnail(): void
    {
        $this->requireFfmpeg();
        $library = $this->lib();

        $asset = $library->upload($this->upload($this->video('clip.mp4'), 'clip.mp4'), null);

        $stem = substr($asset->path, 0, (int) strrpos($asset->path, '.'));
        $this->assertSame($stem . '-preview.png', $asset->preview?->path);
        $this->assertSame([1600, 900], [$asset->preview->width, $asset->preview->height]);
        $this->assertFileExists($this->assetRoot . '/media/' . $asset->preview->path);
        $this->assertSame('/media/' . $stem . '-preview-480.webp', $library->thumbnailUrl($asset));
        $this->assertSame('/media/' . $asset->path, $library->url($asset)); // the video stays the video

        $poster = $library->upload($this->upload($this->png(1200, 900), 'poster.png'), null);
        $withPoster = $library->update($asset->id, ['poster' => $poster->id]);
        $this->assertSame($library->thumbnailUrl($poster), $library->thumbnailUrl($withPoster));

        // Clearing the poster brings the frame back.
        $cleared = $library->update($asset->id, ['poster' => '']);
        $this->assertSame('/media/' . $stem . '-preview-480.webp', $library->thumbnailUrl($cleared));
    }
}
