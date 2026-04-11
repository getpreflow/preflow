<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

final class DynamicRecordTest extends TestCase
{
    private TypeDefinition $type;

    protected function setUp(): void
    {
        $this->type = new TypeDefinition(
            key: 'article',
            table: 'articles',
            storage: 'default',
            fields: [
                'title' => new TypeFieldDefinition(name: 'title'),
                'metadata' => new TypeFieldDefinition(name: 'metadata', type: 'json', transform: JsonTransformer::class),
                'published_at' => new TypeFieldDefinition(name: 'published_at', type: 'datetime', transform: DateTimeTransformer::class),
            ],
            transformers: [
                'metadata' => new JsonTransformer(),
                'published_at' => new DateTimeTransformer(),
            ],
        );
    }

    public function test_get_and_set(): void
    {
        $record = new DynamicRecord($this->type);
        $record->set('title', 'Hello');
        $this->assertSame('Hello', $record->get('title'));
    }

    public function test_get_returns_null_for_missing_field(): void
    {
        $record = new DynamicRecord($this->type);
        $this->assertNull($record->get('nonexistent'));
    }

    public function test_get_id(): void
    {
        $record = new DynamicRecord($this->type, ['uuid' => 'abc-123']);
        $this->assertSame('abc-123', $record->getId());
    }

    public function test_get_type(): void
    {
        $record = new DynamicRecord($this->type);
        $this->assertSame($this->type, $record->getType());
    }

    public function test_to_array_applies_to_storage_transforms(): void
    {
        $record = new DynamicRecord($this->type, [
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => ['tags' => ['php']],
            'published_at' => new \DateTimeImmutable('2026-04-11 14:30:00'),
        ]);

        $data = $record->toArray();

        $this->assertSame('{"tags":["php"]}', $data['metadata']);
        $this->assertSame('2026-04-11 14:30:00', $data['published_at']);
        $this->assertSame('Hello', $data['title']);
    }

    public function test_from_array_applies_from_storage_transforms(): void
    {
        $record = DynamicRecord::fromArray($this->type, [
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => '{"tags":["php"]}',
            'published_at' => '2026-04-11 14:30:00',
        ]);

        $this->assertSame(['tags' => ['php']], $record->get('metadata'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $record->get('published_at'));
        $this->assertSame('Hello', $record->get('title'));
    }

    public function test_from_array_handles_null_transforms(): void
    {
        $record = DynamicRecord::fromArray($this->type, [
            'uuid' => 'test-1',
            'metadata' => null,
            'published_at' => null,
        ]);

        $this->assertNull($record->get('metadata'));
        $this->assertNull($record->get('published_at'));
    }
}
