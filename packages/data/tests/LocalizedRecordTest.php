<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;

final class LocalizedRecordTest extends TestCase
{
    private string $models;

    protected function setUp(): void
    {
        $this->models = sys_get_temp_dir() . '/lr_models_' . bin2hex(random_bytes(4));
        mkdir($this->models, 0777, true);
        file_put_contents($this->models . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'status' => ['type' => 'string'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->models . '/page.json');
        @rmdir($this->models);
    }

    private function record(Locales $locales, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray((new TypeRegistry($this->models, $locales))->get('page'), $data);
    }

    public function test_translatable_flag_is_parsed_and_not_config(): void
    {
        $type = (new TypeRegistry($this->models, new Locales(['en', 'de'], 'en')))->get('page');

        $this->assertTrue($type->fields['title']->translatable);
        $this->assertArrayNotHasKey('translatable', $type->fields['title']->config);
        $this->assertSame(['title'], $type->translatableFields());
    }

    public function test_single_locale_type_has_no_translatable_fields(): void
    {
        $type = (new TypeRegistry($this->models))->get('page');

        $this->assertSame([], $type->translatableFields());
        $this->assertFalse($type->locales->isMultilingual());
    }

    public function test_localized_values_and_fallback_flags(): void
    {
        $record = $this->record(new Locales(['en', 'de'], 'en'), [
            'uuid' => '1', 'title' => ['en' => 'About', 'de' => ''], 'status' => 'published',
        ]);

        $de = $record->localized('de');

        $this->assertSame('de', $de->locale());
        $this->assertSame('About', $de->get('title'));
        $this->assertTrue($de->isFallback('title'));
        $this->assertFalse($de->isFallback('status'));
        $this->assertSame(['uuid' => '1', 'title' => 'About', 'status' => 'published'], $de->toArray());
        $this->assertSame($record, $de->record());
    }

    public function test_localized_record_shared_field_reads_map_default(): void
    {
        $record = $this->record(new Locales(['en', 'de'], 'en'), [
            'uuid' => '1', 'title' => 'T', 'status' => ['en' => 'published', 'de' => 'x'],
        ]);

        $this->assertSame('published', $record->localized('de')->get('status'));
    }

    public function test_available_locales_defaults_and_filters(): void
    {
        $locales = new Locales(['en', 'de'], 'en');

        $this->assertSame(['en'], $this->record($locales, ['uuid' => '1'])->availableLocales());
        $this->assertSame(['en', 'de'], $this->record($locales, ['uuid' => '1', '_locales' => ['de', 'xx']])->availableLocales());
    }
}
