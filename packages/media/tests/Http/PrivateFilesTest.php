<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\ImageTag;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Tests\Support\AllowPrivateFiles;
use Preflow\Media\Tests\Support\MediaApp;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Serving private files at {private_url}/{id} (moved from Folio's PrivateAssetsTest): the
 * headers, the streamed body and the 404s, behind a site's own PrivateFileAccess that
 * allows everyone. Without one the core denies (MediaRoutesTest); Folio's rules are
 * tested in Folio.
 */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class PrivateFilesTest extends TestCase
{
    use MediaApp;

    protected function setUp(): void
    {
        $this->scaffoldMediaApp([MediaServiceProvider::class, AllowPrivateFiles::class]);
        $this->writeFile('config/media.php', "<?php\nreturn ['private_url' => '/tools/files'];\n");
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'application/octet-stream');
    }

    private function png(int $width = 1200, int $height = 800): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    private function uploadPrivate(UploadedFileInterface $file): Asset
    {
        return $this->media()->upload($file, null, true);
    }

    /** @return list<string> */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }

        return $out;
    }

    public function test_private_asset_not_under_public_media(): void
    {
        $img = $this->uploadPrivate($this->file($this->png(), 'Chart.png'));
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $base = substr($img->path, 0, (int) strrpos($img->path, '.'));

        // Nothing under the public media URL serves or makes it.
        $this->assertSame(404, $this->fetch('/media/' . $img->path)->getStatusCode());
        $this->assertSame(404, $this->fetch('/media/' . $base . '-480.webp')->getStatusCode());
        $this->assertSame([], $this->filesUnder($this->dir . '/public'));

        // The only URL is the private route: library, media_url, media_image, thumbnails.
        $this->assertSame('/tools/files/' . $img->id, $this->media()->url($img));
        $this->assertSame('/tools/files/' . $img->id, $this->media()->thumbnailUrl($img));
        $tag = $this->mediaApp()->container()->get(ImageTag::class);
        $this->assertSame('/tools/files/' . $pdf->id, $tag->url($pdf->id));
        $html = $tag->render($img->id, null, ['priority' => true]);
        $this->assertStringContainsString('src="/tools/files/' . $img->id . '"', $html);
        $this->assertStringNotContainsString('srcset', $html);
        $this->assertStringNotContainsString('/media/', $html);
        $this->assertStringContainsString('href="/tools/files/' . $pdf->id . '"', $tag->render($pdf->id));
    }

    public function test_downloads_pdf_as_attachment_no_store(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Members Guide.pdf'));
        $img = $this->uploadPrivate($this->file($this->png(40, 30), 'Chart.png'));

        $res = $this->fetch('/tools/files/' . $pdf->id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($this->pdf(), (string) $res->getBody());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('attachment; filename="Members Guide.pdf"', $res->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));

        $image = $this->fetch('/tools/files/' . $img->id);
        $this->assertSame(200, $image->getStatusCode());
        $this->assertSame('image/png', $image->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('inline', $image->getHeaderLine('Content-Disposition'));
        $this->assertSame('private, no-store', $image->getHeaderLine('Cache-Control'));
    }

    public function test_traversal_rejected(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $public = $this->media()->upload($this->file($this->png(40, 30), 'Open.png'), null);

        // A record whose path points outside the private dir.
        $data = $pdf->toData();
        $data['path'] = '../../config/app.php';
        $this->mediaApp()->container()->get(\Preflow\Data\DataManager::class)
            ->saveType(\Preflow\Data\DynamicRecord::fromArray($this->media()->definition(), $data), false);

        $escaped = $this->fetch('/tools/files/' . $pdf->id);
        $this->assertSame(404, $escaped->getStatusCode());
        $this->assertStringNotContainsString('media-integration-test-key', (string) $escaped->getBody());
        $this->assertSame('private, no-store', $escaped->getHeaderLine('Cache-Control'));

        $this->assertNotSame(200, $this->fetch('/tools/files/..%2F..%2Fconfig%2Fapp.php')->getStatusCode());
        $this->assertNotSame(200, $this->fetch('/tools/files/../../config/app.php')->getStatusCode());
        $this->assertSame(404, $this->fetch('/tools/files/' . str_repeat('0', 32))->getStatusCode());
        // A public asset is not served by the private route.
        $this->assertSame(404, $this->fetch('/tools/files/' . $public->id)->getStatusCode());
    }

    public function test_private_download_is_streamed_from_disk(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));

        $body = $this->fetch('/tools/files/' . $pdf->id)->getBody();
        $this->assertSame(realpath($this->dir . '/storage/private-media/' . $pdf->path), $body->getMetadata('uri'));
        $this->assertSame($this->pdf(), (string) $body);
    }
}
