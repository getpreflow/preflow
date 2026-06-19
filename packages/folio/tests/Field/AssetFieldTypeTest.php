<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\AssetFieldType;

final class AssetFieldTypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_asset_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        // best-effort recursive cleanup
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function type(): AssetFieldType
    {
        return new AssetFieldType($this->dir, '/folio/_uploads');
    }

    private function upload(string $name, string $mime = 'image/png', string $data = 'X')
    {
        $f = new Psr17Factory();
        return $f->createUploadedFile($f->createStream($data), strlen($data), UPLOAD_ERR_OK, $name, $mime);
    }

    public function test_key(): void
    {
        $this->assertSame('asset', $this->type()->key());
    }

    public function test_store_single_moves_file_and_returns_path(): void
    {
        $result = $this->type()->storeUploads([$this->upload('pic.png')], [], ['asset' => ['multiple' => false, 'accept' => 'image/*']]);
        $this->assertIsString($result);
        $this->assertStringEndsWith('.png', $result);
        $this->assertFileExists($this->dir . '/' . $result);
    }

    public function test_store_rejects_disallowed_extension(): void
    {
        $result = $this->type()->storeUploads([$this->upload('evil.php', 'image/png')], [], ['asset' => ['multiple' => false, 'accept' => 'image/*']]);
        $this->assertSame('', $result); // .php not in the image allowlist -> not stored
    }

    public function test_store_multiple_returns_kept_plus_new(): void
    {
        $result = $this->type()->storeUploads(
            [$this->upload('a.png'), $this->upload('b.png')],
            ['2026/06/existing.png'],
            ['asset' => ['multiple' => true, 'accept' => 'image/*']],
        );
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains('2026/06/existing.png', $result);
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $this->assertSame('a/b.png', $t->toStorage('a/b.png'));
        $this->assertSame('a/b.png', $t->fromStorage('a/b.png'));
        $json = $t->toStorage(['x.png', 'y.png']);
        $this->assertSame(['x.png', 'y.png'], $t->fromStorage($json));
    }

    public function test_render_editor_emits_file_input_and_existing_remove(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'cover', label: 'Cover', value: '2026/06/x.png',
            config: ['asset' => ['multiple' => false, 'accept' => 'image/*']],
        ));
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('name="cover"', $html);
        $this->assertStringContainsString('accept="image/*"', $html);
        $this->assertStringContainsString('name="cover_remove[]"', $html);
        $this->assertStringContainsString('src="/folio/_uploads/2026/06/x.png"', $html);
    }

    public function test_render_editor_multiple_uses_array_name(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'gallery', config: ['asset' => ['multiple' => true]],
        ));
        $this->assertStringContainsString('name="gallery[]"', $html);
        $this->assertStringContainsString('multiple', $html);
    }

    public function test_render_frontend_image(): void
    {
        $out = $this->type()->renderFrontend('2026/06/x.png', []);
        $this->assertStringContainsString('<img src="/folio/_uploads/2026/06/x.png"', $out);
    }
}
