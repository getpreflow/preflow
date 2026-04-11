<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Transform\JsonTransformer;

final class TypeDefinitionTest extends TestCase
{
    public function test_stores_key_table_storage(): void
    {
        $type = new TypeDefinition(key: 'tournament', table: 'tournaments', storage: 'mysql', fields: []);
        $this->assertSame('tournament', $type->key);
        $this->assertSame('tournaments', $type->table);
        $this->assertSame('mysql', $type->storage);
    }

    public function test_id_field_defaults_to_uuid(): void
    {
        $type = new TypeDefinition(key: 'x', table: 'x', storage: 'default', fields: []);
        $this->assertSame('uuid', $type->idField);
    }

    public function test_stores_fields(): void
    {
        $field = new TypeFieldDefinition(name: 'title', type: 'string', searchable: true);
        $type = new TypeDefinition(key: 'post', table: 'posts', storage: 'default', fields: ['title' => $field]);
        $this->assertArrayHasKey('title', $type->fields);
        $this->assertSame('string', $type->fields['title']->type);
        $this->assertTrue($type->fields['title']->searchable);
    }

    public function test_searchable_fields_populated(): void
    {
        $type = new TypeDefinition(
            key: 'post', table: 'posts', storage: 'default',
            fields: [
                'title' => new TypeFieldDefinition(name: 'title', searchable: true),
                'status' => new TypeFieldDefinition(name: 'status'),
            ],
            searchableFields: ['title'],
        );
        $this->assertSame(['title'], $type->searchableFields);
    }

    public function test_transformers_populated(): void
    {
        $transformer = new JsonTransformer();
        $type = new TypeDefinition(key: 'post', table: 'posts', storage: 'default', fields: [], transformers: ['metadata' => $transformer]);
        $this->assertArrayHasKey('metadata', $type->transformers);
        $this->assertInstanceOf(JsonTransformer::class, $type->transformers['metadata']);
    }

    public function test_field_definition_defaults(): void
    {
        $field = new TypeFieldDefinition(name: 'title');
        $this->assertSame('title', $field->name);
        $this->assertSame('string', $field->type);
        $this->assertFalse($field->searchable);
        $this->assertNull($field->transform);
    }
}
