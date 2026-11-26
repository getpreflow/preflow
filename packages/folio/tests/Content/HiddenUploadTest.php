<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Tests\Fixtures\Blocks\MediaOfferBlock;
use Preflow\Folio\Tests\Support\TempRoots;

/**
 * A file posted for a field its show_when hides (0.91) is never written: which fields are hidden is
 * decided before the uploads are stored, for a record's field and for a block's. The editor disables
 * the input, so only a hand-made post carries one.
 */
final class HiddenUploadTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempRoots::path('hidden_upload_');
        mkdir($this->dir . '/models', 0777, true);
        mkdir($this->dir . '/uploads', 0777, true);
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'kind' => ['type' => 'string'],
                'cover' => ['type' => 'asset', 'show_when' => ['field' => 'kind', '=' => 'media']],
                'sections' => ['type' => 'blocks'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_a_hidden_record_field_writes_no_upload_and_keeps_its_stored_file(): void
    {
        $record = $this->build(['kind' => 'text'], ['cover' => $this->file()], ['cover' => '2026/10/a.png']);

        $this->assertSame([], $this->written());
        $this->assertSame('2026/10/a.png', $record->get('cover'));

        // Shown, the same post stores the file.
        $this->build(['kind' => 'media'], ['cover' => $this->file()], []);
        $this->assertNotSame([], $this->written());
    }

    public function test_a_hidden_block_field_writes_no_upload_and_keeps_its_stored_file(): void
    {
        $stored = ['sections' => [['_uid' => self::A, '_block' => 'media_offer', 'kind' => 'media', 'image' => '2026/10/b.png']]];
        $record = $this->build(
            ['kind' => 'text', 'sections' => ['_order' => [self::A], self::A => ['_block' => 'media_offer', 'kind' => 'text']]],
            ['sections' => [self::A => ['image' => $this->file()]]],
            $stored,
        );

        $this->assertSame([], $this->written());
        $this->assertSame('2026/10/b.png', $record->get('sections')[0]['image']);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, mixed> $existing
     */
    private function build(array $body, array $files, array $existing): \Preflow\Data\DynamicRecord
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/')->withParsedBody($body)->withUploadedFiles($files);

        return (new DraftRecordFactory($this->registry()))->build($this->type(), $request, ['uuid' => 'p1'] + $existing, 'en');
    }

    private function file(): \Psr\Http\Message\UploadedFileInterface
    {
        return (new Psr17Factory())->createUploadedFile((new Psr17Factory())->createStream('png-bytes'), 9, UPLOAD_ERR_OK, 'x.png', 'image/png');
    }

    /** @return list<string> */
    private function written(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir . '/uploads', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[] = $f->getPathname();
        }

        return $files;
    }

    private function type(): TypeDefinition
    {
        return (new TypeRegistry($this->dir . '/models', Locales::single('en')))->get('page');
    }

    private function registry(): FieldTypeRegistry
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());
        $registry->register(new AssetFieldType($this->dir . '/uploads', '/folio/_uploads'));
        $registry->register(new BlocksFieldType(new BlockRegistry([MediaOfferBlock::class]), $registry));

        return $registry;
    }
}
