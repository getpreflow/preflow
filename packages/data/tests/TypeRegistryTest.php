<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

final class TypeRegistryTest extends TestCase
{
    private string $tmpDir;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_type_registry_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->registry = new TypeRegistry($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.json'));
        rmdir($this->tmpDir);
    }

    private function writeSchema(string $name, array $schema): void
    {
        file_put_contents(
            $this->tmpDir . '/' . $name . '.json',
            json_encode($schema, JSON_PRETTY_PRINT),
        );
    }

    public function test_loads_type_from_json_file(): void
    {
        $this->writeSchema('tournament', [
            'key' => 'tournament',
            'table' => 'tournaments',
            'storage' => 'mysql',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true],
                'max_players' => ['type' => 'integer'],
            ],
        ]);

        $type = $this->registry->get('tournament');

        $this->assertInstanceOf(TypeDefinition::class, $type);
        $this->assertSame('tournament', $type->key);
        $this->assertSame('tournaments', $type->table);
        $this->assertSame('mysql', $type->storage);
        $this->assertCount(2, $type->fields);
        $this->assertSame(['title'], $type->searchableFields);
    }

    public function test_resolves_transformers(): void
    {
        $this->writeSchema('article', [
            'key' => 'article',
            'table' => 'articles',
            'fields' => [
                'metadata' => ['type' => 'json', 'transform' => 'Preflow\\Data\\Transform\\JsonTransformer'],
                'published_at' => ['type' => 'datetime', 'transform' => 'Preflow\\Data\\Transform\\DateTimeTransformer'],
            ],
        ]);

        $type = $this->registry->get('article');

        $this->assertCount(2, $type->transformers);
        $this->assertInstanceOf(JsonTransformer::class, $type->transformers['metadata']);
        $this->assertInstanceOf(DateTimeTransformer::class, $type->transformers['published_at']);
    }

    public function test_storage_defaults_to_default(): void
    {
        $this->writeSchema('simple', [
            'key' => 'simple',
            'table' => 'simples',
            'fields' => ['name' => ['type' => 'string']],
        ]);

        $type = $this->registry->get('simple');
        $this->assertSame('default', $type->storage);
    }

    public function test_has_returns_true_for_existing_type(): void
    {
        $this->writeSchema('exists', ['key' => 'exists', 'table' => 'exists', 'fields' => []]);
        $this->assertTrue($this->registry->has('exists'));
    }

    public function test_has_returns_false_for_missing_type(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function test_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown type: missing');
        $this->registry->get('missing');
    }

    public function test_caches_loaded_types(): void
    {
        $this->writeSchema('cached', ['key' => 'cached', 'table' => 'cached', 'fields' => ['name' => ['type' => 'string']]]);
        $first = $this->registry->get('cached');
        $second = $this->registry->get('cached');
        $this->assertSame($first, $second);
    }

    public function test_define_then_get(): void
    {
        $def = $this->registry->define([
            'key' => 'ext', 'table' => 'ext_table',
            'fields' => ['title' => ['type' => 'string', 'searchable' => true]],
        ]);

        $this->assertTrue($this->registry->has('ext'));
        $this->assertSame($def, $this->registry->get('ext'));
        $this->assertSame('ext_table', $def->table);
        $this->assertSame(['title'], $def->searchableFields);
    }

    public function test_define_duplicate_of_file_throws(): void
    {
        $this->writeSchema('post', ['key' => 'post', 'table' => 'posts', 'fields' => []]);

        $this->expectException(\RuntimeException::class);
        $this->registry->define(['key' => 'post', 'table' => 'x', 'fields' => []]);
    }

    public function test_define_duplicate_of_definition_throws(): void
    {
        $this->registry->define(['key' => 'ext', 'table' => 'ext', 'fields' => []]);

        $this->expectException(\RuntimeException::class);
        $this->registry->define(['key' => 'ext', 'table' => 'ext', 'fields' => []]);
    }

    public function test_extends_file_is_not_a_standalone_type(): void
    {
        // A site file with "extends": true only patches an extension's type (Folio merges it).
        $this->writeSchema('member', ['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]);

        $this->assertFalse($this->registry->has('member'));
        $def = $this->registry->define(['key' => 'member', 'table' => 'member', 'fields' => ['email' => ['type' => 'string']]]);
        $this->assertSame($def, $this->registry->get('member'));
    }

    public function test_extends_file_without_definition_is_unknown(): void
    {
        $this->writeSchema('member', ['key' => 'member', 'extends' => true, 'fields' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown type: member');
        $this->registry->get('member');
    }

    public function test_keys_lists_files_and_defined_types_without_extends_files(): void
    {
        $this->writeSchema('zebra', ['key' => 'zebra', 'table' => 'z', 'fields' => []]);
        $this->writeSchema('patch', ['key' => 'patch', 'extends' => true, 'fields' => []]);
        $this->registry->define(['key' => 'alpha', 'table' => 'a', 'fields' => []]);

        $this->assertSame(['alpha', 'zebra'], $this->registry->keys());
    }

    public function test_searchable_is_null_when_unset_false_or_true_when_said(): void
    {
        $this->writeSchema('thing', [
            'key' => 'thing',
            'table' => 'things',
            'fields' => [
                'a' => ['type' => 'string'],
                'b' => ['type' => 'string', 'searchable' => false],
                'c' => ['type' => 'string', 'searchable' => true],
            ],
        ]);

        $fields = $this->registry->get('thing')->fields;

        $this->assertNull($fields['a']->searchable);
        $this->assertFalse($fields['b']->searchable);
        $this->assertTrue($fields['c']->searchable);
        $this->assertSame(['c'], $this->registry->get('thing')->searchableFields);
    }
}
