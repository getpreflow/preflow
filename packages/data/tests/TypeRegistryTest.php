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
}
