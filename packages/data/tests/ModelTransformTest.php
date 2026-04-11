<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

#[Entity(table: 'articles')]
final class ArticleWithTransforms extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';

    #[Field(transform: JsonTransformer::class)]
    public ?array $metadata = null;

    #[Field(transform: DateTimeTransformer::class)]
    public ?\DateTimeImmutable $publishedAt = null;
}

final class ModelTransformTest extends TestCase
{
    protected function setUp(): void
    {
        ModelMetadata::clearCache();
    }

    public function test_metadata_resolves_transformers(): void
    {
        $meta = ModelMetadata::for(ArticleWithTransforms::class);

        $this->assertArrayHasKey('metadata', $meta->transformers);
        $this->assertArrayHasKey('publishedAt', $meta->transformers);
        $this->assertInstanceOf(JsonTransformer::class, $meta->transformers['metadata']);
        $this->assertInstanceOf(DateTimeTransformer::class, $meta->transformers['publishedAt']);
        $this->assertArrayNotHasKey('title', $meta->transformers);
    }

    public function test_fill_applies_from_storage_transforms(): void
    {
        $article = new ArticleWithTransforms();
        $article->fill([
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => '{"tags":["php","preflow"]}',
            'publishedAt' => '2026-04-11 14:30:00',
        ]);

        $this->assertSame(['tags' => ['php', 'preflow']], $article->metadata);
        $this->assertInstanceOf(\DateTimeImmutable::class, $article->publishedAt);
        $this->assertSame('2026-04-11 14:30:00', $article->publishedAt->format('Y-m-d H:i:s'));
        $this->assertSame('Hello', $article->title);
    }

    public function test_to_array_applies_to_storage_transforms(): void
    {
        $article = new ArticleWithTransforms();
        $article->uuid = 'test-1';
        $article->title = 'Hello';
        $article->metadata = ['tags' => ['php']];
        $article->publishedAt = new \DateTimeImmutable('2026-04-11 14:30:00');

        $data = $article->toArray();

        $this->assertSame('{"tags":["php"]}', $data['metadata']);
        $this->assertSame('2026-04-11 14:30:00', $data['publishedAt']);
        $this->assertSame('Hello', $data['title']);
    }

    public function test_fill_handles_null_transformed_fields(): void
    {
        $article = new ArticleWithTransforms();
        $article->fill([
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => null,
            'publishedAt' => null,
        ]);

        $this->assertNull($article->metadata);
        $this->assertNull($article->publishedAt);
    }
}
