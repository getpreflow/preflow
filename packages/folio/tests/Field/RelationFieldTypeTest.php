<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\RelationFieldType;

final class RelationFieldTypeTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_rel_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Authors',
            'fields' => ['name' => ['type' => 'string', 'validate' => ['required']]],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);

        foreach (['a1' => 'Ann', 'a2' => 'Bob'] as $id => $name) {
            $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => $id, 'name' => $name]));
        }
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function type(): RelationFieldType
    {
        return new RelationFieldType($this->dm, $this->registry);
    }

    private function singleConfig(): array
    {
        return ['relation' => ['to' => 'author', 'multiple' => false]];
    }

    public function test_key(): void
    {
        $this->assertSame('relation', $this->type()->key());
    }

    public function test_render_editor_single_select_with_options(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'writer', label: 'Writer', value: 'a1', config: $this->singleConfig(),
        ));
        $this->assertStringContainsString('<select name="writer"', $html);
        $this->assertStringContainsString('— none —', $html);
        $this->assertStringContainsString('<option value="a1" selected>Ann</option>', $html);
        $this->assertStringContainsString('<option value="a2">Bob</option>', $html);
    }

    public function test_render_editor_multiple_uses_array_name_and_marks_selected(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'writers', value: ['a2'], config: ['relation' => ['to' => 'author', 'multiple' => true]],
        ));
        $this->assertStringContainsString('<select name="writers[]"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('<option value="a2" selected>Bob</option>', $html);
    }

    public function test_normalize_single_and_multiple(): void
    {
        $t = $this->type();
        $this->assertSame('a1', $t->normalizeInput('a1', $this->singleConfig()));
        $this->assertSame(['a1', 'a2'], $t->normalizeInput(['a1', 'a2'], ['relation' => ['to' => 'author', 'multiple' => true]]));
        $this->assertSame([], $t->normalizeInput('x', ['relation' => ['to' => 'author', 'multiple' => true]]));
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $this->assertSame('a1', $t->toStorage('a1'));
        $this->assertSame('a1', $t->fromStorage('a1'));
        $json = $t->toStorage(['a1', 'a2']);
        $this->assertSame(['a1', 'a2'], $t->fromStorage($json));
    }

    public function test_render_frontend_resolves_labels(): void
    {
        $out = $this->type()->renderFrontend('a1', $this->singleConfig());
        $this->assertStringContainsString('Ann', $out);
        $this->assertStringNotContainsString('a1', $out); // shows the label, not the raw id
    }

    public function test_unknown_target_renders_empty_select(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'x', config: ['relation' => ['to' => 'ghost', 'multiple' => false]],
        ));
        $this->assertStringContainsString('<select name="x"', $html);
        $this->assertStringNotContainsString('Ann', $html);
    }

    public function test_render_frontend_unknown_target_is_empty_not_error(): void
    {
        $out = $this->type()->renderFrontend('someid', ['relation' => ['to' => 'ghost', 'multiple' => false]]);
        $this->assertSame('', $out);
    }
}
