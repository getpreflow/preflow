<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Tests\Support\AllowPrivateFiles;
use Preflow\Media\Tests\Support\MediaApp;
use Psr\Http\Message\UploadedFileInterface;

/** Earlier files of an asset at {private_url}/{id}/v/{n}, gated by PrivateFileAccess. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class VersionFileTest extends TestCase
{
    use MediaApp;

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'application/octet-stream');
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array{0: string, 1: string} the asset id and the bytes of version 1 */
    private function replacedAsset(): array
    {
        $v1 = $this->png(30, 20);
        $asset = $this->media()->upload($this->file($v1, 'First.png'), null);
        $this->media()->replace($asset->id, $this->file($this->png(40, 20), 'Second.png'), null);

        return [$asset->id, $v1];
    }

    public function test_the_core_default_refuses(): void
    {
        $this->scaffoldMediaApp();
        [$id, ] = $this->replacedAsset();

        foreach ([$id, str_repeat('0', 32)] as $candidate) {
            $res = $this->fetch('/files/' . $candidate . '/v/1');
            $this->assertSame(403, $res->getStatusCode());
            $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        }
    }

    public function test_an_allowed_visitor_gets_the_version(): void
    {
        $this->scaffoldMediaApp([MediaServiceProvider::class, AllowPrivateFiles::class]);
        [$id, $v1] = $this->replacedAsset();

        $res = $this->fetch('/files/' . $id . '/v/1');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($v1, (string) $res->getBody());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline; filename="First.png"', $res->getHeaderLine('Content-Disposition'));
        $this->assertFileExists($this->dir . '/storage/media-versions/' . $id . '/v1.png');

        foreach (['/files/' . $id . '/v/2', '/files/' . $id . '/v/7', '/files/' . str_repeat('0', 32) . '/v/1'] as $uri) {
            $missing = $this->fetch($uri);
            $this->assertSame(404, $missing->getStatusCode(), $uri);
            $this->assertSame('private, no-store', $missing->getHeaderLine('Cache-Control'));
        }
        $this->assertNotSame(200, $this->fetch('/files/' . $id . '/v/0')->getStatusCode());
        $this->assertNotSame(200, $this->fetch('/files/' . $id . '/v/..%2F..%2Fconfig')->getStatusCode());
    }

    public function test_version_files_are_never_under_the_media_url(): void
    {
        $this->scaffoldMediaApp([MediaServiceProvider::class, AllowPrivateFiles::class]);
        [$id, ] = $this->replacedAsset();
        $asset = $this->media()->find($id);
        $old = str_replace('-v2.png', '.png', $asset->path);

        $this->assertFileDoesNotExist($this->dir . '/public/media/' . $old);
        $this->assertSame(404, $this->fetch('/media/' . $old)->getStatusCode());
    }

    public function test_a_tampered_version_path_is_not_served(): void
    {
        $this->scaffoldMediaApp([MediaServiceProvider::class, AllowPrivateFiles::class]);
        [$id, ] = $this->replacedAsset();
        $data = $this->media()->find($id)->toData();
        $data['versions'][0]['path'] = '../../config/app.php';
        $this->mediaApp()->container()->get(\Preflow\Data\DataManager::class)
            ->saveType(\Preflow\Data\DynamicRecord::fromArray($this->media()->definition(), $data), false);

        $res = $this->fetch('/files/' . $id . '/v/1');

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringNotContainsString('media-integration-test-key', (string) $res->getBody());
    }
}
