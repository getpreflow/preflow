<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Assets\AssetUploadException;
use Preflow\Folio\Tests\Support\AssetFixtures;

#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class AssetLibraryTest extends TestCase
{
    use AssetFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_upload_creates_asset_with_dimensions(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(), 'Sea View.PNG', 'image/png'), 'u@x');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $asset->id);
        $this->assertSame(1200, $asset->width);
        $this->assertSame(800, $asset->height);
        $this->assertSame('image/png', $asset->mime);
        $this->assertSame('Sea View.PNG', $asset->originalName);
        $this->assertSame('image', $asset->kind());
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/' . $asset->id . '\.png$#', $asset->path);
        $this->assertFileExists($this->assetRoot . '/media/' . $asset->path);
        $this->assertSame('/media/' . $asset->path, $library->url($asset->path));
        $this->assertEquals($asset, $library->find($asset->id));
        $this->assertSame('u@x', $asset->createdBy);
    }

    public function test_extension_follows_sniffed_mime(): void
    {
        $library = $this->library();

        $asset = $library->upload($this->upload($this->png(10, 10), 'shell.php', 'application/x-php'), null);
        $this->assertSame('image/png', $asset->mime);
        $this->assertStringEndsWith('.png', $asset->path);

        $text = $library->upload($this->upload("just some notes\n", 'notes.jpg', 'image/jpeg'), null);
        $this->assertSame('text/plain', $text->mime);
        $this->assertStringEndsWith('.txt', $text->path);
        $this->assertSame('document', $text->kind());

        $this->expectException(AssetUploadException::class);
        $library->upload($this->upload('<?php echo 1;', 'x.jpg', 'image/jpeg'), null);
    }

    public function test_office_documents_are_accepted(): void
    {
        $file = $this->assetRoot . '/report.docx';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w/>');
        $zip->close();

        $asset = $this->library()->importFile($file, 'report.docx', null);

        $this->assertStringEndsWith('.docx', $asset->path);
        $this->assertSame('document', $asset->kind());
    }

    public function test_svg_refused_by_default(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

        try {
            $this->library()->upload($this->upload($svg, 'logo.svg', 'image/svg+xml'), null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame('SVG uploads are disabled.', $e->getMessage());
        }

        $asset = $this->library(['allow_svg' => true])->upload($this->upload($svg, 'logo.svg', 'image/svg+xml'), null);
        $this->assertStringEndsWith('.svg', $asset->path);
    }

    public function test_size_limit(): void
    {
        $this->expectException(AssetUploadException::class);
        $this->expectExceptionMessage('larger than the 10 B limit');

        $this->library(['max_bytes' => 10])->upload($this->upload($this->png(20, 20), 'a.png', 'image/png'), null);
    }

    public function test_failed_upload_is_refused(): void
    {
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $broken = $f->createUploadedFile($f->createStream(''), 0, UPLOAD_ERR_PARTIAL, 'a.png', 'image/png');

        $this->expectException(AssetUploadException::class);
        $this->library()->upload($broken, null);
    }

    public function test_update_metadata_clamps_focal_point(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(20, 20), 'a.png', 'image/png'), null);
        $poster = $library->upload($this->upload($this->png(20, 20), 'p.png', 'image/png'), null);

        $library->update($asset->id, ['alt' => ['en' => 'Sea'], 'title' => 'Sea', 'focal_x' => 150, 'focal_y' => -3]);
        $updated = $library->update($asset->id, ['alt' => ['de' => 'Meer'], 'poster' => $poster->id]);

        $this->assertSame(100, $updated->focalX);
        $this->assertSame(0, $updated->focalY);
        $this->assertSame(['en' => 'Sea', 'de' => 'Meer'], $updated->alt);
        $this->assertSame('Meer', $updated->altFor('de', 'en'));
        $this->assertSame('Sea', $updated->altFor('fr', 'en'));
        $this->assertSame('Sea', $updated->title);
        $this->assertSame($poster->id, $updated->posterId);

        $this->assertNull($library->update($asset->id, ['poster' => 'not-an-asset'])->posterId);
    }

    public function test_delete_removes_files(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(20, 20), 'a.png', 'image/png'), null);

        $library->delete($asset->id);

        $this->assertNull($library->find($asset->id));
        $this->assertFileDoesNotExist($this->assetRoot . '/media/' . $asset->path);
    }

    public function test_is_asset_id(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(20, 20), 'a.png', 'image/png'), null);

        $this->assertTrue($library->isAssetId($asset->id));
        $this->assertFalse($library->isAssetId(str_repeat('a', 32)));
        $this->assertFalse($library->isAssetId('2026/10/' . $asset->id . '.png'));
        $this->assertFalse($library->isAssetId('../../etc/passwd'));
    }

    public function test_all_is_newest_first(): void
    {
        $now = 1000;
        $library = new \Preflow\Folio\Assets\AssetLibrary(
            new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json'),
            new \Preflow\Data\Locales(['en'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            [],
            null,
            function () use (&$now): \DateTimeImmutable { return new \DateTimeImmutable('@' . ($now += 60)); },
        );
        $a = $library->upload($this->upload($this->png(5, 5), 'a.png', 'image/png'), null);
        $b = $library->upload($this->upload($this->png(5, 5), 'b.png', 'image/png'), null);

        $this->assertSame([$b->id, $a->id], array_map(static fn ($x) => $x->id, $library->all()));
    }
}
