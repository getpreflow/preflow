<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

#[Entity(table: 'posts', storage: 'sqlite')]
class TestPost extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $title = '';

    #[Field]
    public string $body = '';

    #[Field(transform: 'json')]
    public array $metadata = [];

    #[Timestamps]
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}

class NoEntityModel extends Model
{
    public string $name = '';
}

final class ModelMetadataTest extends TestCase
{
    public function test_reads_table_name(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertSame('posts', $meta->table);
    }

    public function test_reads_storage(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertSame('sqlite', $meta->storage);
    }

    public function test_reads_id_field(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertSame('uuid', $meta->idField);
    }

    public function test_reads_fields(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertArrayHasKey('title', $meta->fields);
        $this->assertArrayHasKey('body', $meta->fields);
        $this->assertArrayHasKey('metadata', $meta->fields);
    }

    public function test_reads_searchable_fields(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertContains('title', $meta->searchableFields);
        $this->assertNotContains('body', $meta->searchableFields);
    }

    public function test_has_timestamps(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertTrue($meta->hasTimestamps);
    }

    public function test_caches_metadata(): void
    {
        $a = ModelMetadata::for(TestPost::class);
        $b = ModelMetadata::for(TestPost::class);

        $this->assertSame($a, $b);
    }

    public function test_throws_without_entity_attribute(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entity');

        ModelMetadata::for(NoEntityModel::class);
    }
}
