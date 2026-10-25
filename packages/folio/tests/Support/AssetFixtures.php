<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Media\Image\VariantGenerator;
use Psr\Http\Message\UploadedFileInterface;

/** A temp asset library (JSON storage, en/de) and real image bytes made with GD. */
trait AssetFixtures
{
    private string $assetRoot;

    private function makeAssetRoot(): void
    {
        $this->assetRoot = sys_get_temp_dir() . '/assets_' . bin2hex(random_bytes(4));
        mkdir($this->assetRoot, 0777, true);
    }

    private function removeAssetRoot(): void
    {
        if (!is_dir($this->assetRoot)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->assetRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->assetRoot);
    }

    /** @param array<string, mixed> $config */
    private function library(array $config = [], ?VariantGenerator $variants = null): AssetLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new AssetLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', $config, $variants);
    }

    private function png(int $width = 1200, int $height = 800): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A JPEG whose EXIF says how to rotate it for display (e.g. 6 = a phone held upright). */
    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // APP1 "Exif": big-endian TIFF header, one IFD entry 0x0112 (Orientation, SHORT, 1).
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $payload = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private function upload(string $bytes, string $name, string $clientType = 'application/octet-stream'): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, $clientType);
    }
}
