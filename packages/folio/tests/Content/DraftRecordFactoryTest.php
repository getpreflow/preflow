<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\StringFieldType;

final class DraftRecordFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/drf_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        mkdir($this->dir . '/uploads', 0777, true);
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'status' => ['type' => 'string'],
                'cover' => ['type' => 'asset', 'translatable' => true],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/models/page.json');
        @rmdir($this->dir . '/models');
        @rmdir($this->dir . '/uploads');
        @rmdir($this->dir);
    }

    public function test_saving_de_leaves_en_untouched(): void
    {
        $record = $this->build(['title' => 'D', 'status' => 's'], ['title' => ['en' => 'E']], 'de');

        $this->assertSame(['en' => 'E', 'de' => 'D'], $record->get('title'));
    }

    public function test_saving_default_keeps_other_locales(): void
    {
        $record = $this->build(['title' => 'E2'], ['title' => ['en' => 'E', 'de' => 'D']], 'en');

        $this->assertSame(['en' => 'E2', 'de' => 'D'], $record->get('title'));
    }

    public function test_legacy_scalar_becomes_map(): void
    {
        $record = $this->build(['title' => 'D'], ['title' => 'Old english'], 'de');

        $this->assertSame(['en' => 'Old english', 'de' => 'D'], $record->get('title'));
    }

    public function test_shared_field_is_plain_value(): void
    {
        $this->assertSame('published', $this->build(['title' => 'X', 'status' => 'published'], [], 'de')->get('status'));
    }

    public function test_upload_fields_are_always_shared(): void
    {
        $record = $this->build(['title' => 'X'], ['cover' => '2026/10/a.png'], 'de');

        $this->assertSame('2026/10/a.png', $record->get('cover'));
    }

    public function test_locales_checkbox_handling(): void
    {
        $checked = $this->build(['title' => 'X', '_locales_submitted' => '1', '_locales' => ['de', 'xx']], [], 'en');
        $kept = $this->build(['title' => 'X'], ['_locales' => ['en', 'de']], 'en');
        $none = $this->build(['title' => 'X', '_locales_submitted' => '1'], ['_locales' => ['en', 'de']], 'en');

        $this->assertSame(['en', 'de'], $checked->get('_locales'));
        $this->assertSame(['en', 'de'], $kept->get('_locales'));
        $this->assertSame(['en'], $none->get('_locales'));
    }

    public function test_system_keys_are_carried_over(): void
    {
        $this->assertSame(30, $this->build(['title' => 'X'], ['_order' => 30], 'en')->get('_order'));
    }

    public function test_unknown_locale_uses_default(): void
    {
        $record = $this->build(['title' => 'X'], ['title' => ['en' => 'E']], 'xx');

        $this->assertSame(['en' => 'X'], $record->get('title'));
    }

    public function test_single_locale_stores_scalars(): void
    {
        $record = $this->build(['title' => 'Plain'], [], 'en', Locales::single('en'));

        $this->assertSame('Plain', $record->get('title'));
        $this->assertNull($record->get('_locales'));
    }

    public function test_preview_mode_writes_no_uploads(): void
    {
        $file = (new Psr17Factory())->createUploadedFile(
            (new Psr17Factory())->createStream('png-bytes'), 9, UPLOAD_ERR_OK, 'x.png', 'image/png',
        );
        $request = (new Psr17Factory())->createServerRequest('POST', '/')
            ->withParsedBody(['title' => 'X'])->withUploadedFiles(['cover' => $file]);

        $record = $this->factory()->build($this->type(), $request, [], 'en', writeUploads: false);

        $this->assertSame([], glob($this->dir . '/uploads/*') ?: []);
        $this->assertSame('', (string) $record->get('cover'));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $existing
     */
    private function build(array $body, array $existing, string $locale, ?Locales $locales = null): \Preflow\Data\DynamicRecord
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/')->withParsedBody($body);

        return $this->factory()->build($this->type($locales), $request, ['uuid' => 'p1'] + $existing, $locale);
    }

    private function type(?Locales $locales = null): TypeDefinition
    {
        return (new TypeRegistry($this->dir . '/models', $locales ?? new Locales(['en', 'de'], 'en')))->get('page');
    }

    private function factory(): DraftRecordFactory
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());
        $registry->register(new AssetFieldType($this->dir . '/uploads', '/folio/_uploads'));

        return new DraftRecordFactory($registry);
    }
}
