<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyAliasesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function aliases(): iterable
    {
        foreach (['Asset', 'Variant', 'AssetUploadException'] as $name) {
            yield $name => ["Preflow\\Folio\\Assets\\$name", "Preflow\\Media\\$name"];
        }
        yield 'AssetLibrary' => ['Preflow\\Folio\\Assets\\AssetLibrary', 'Preflow\\Media\\MediaLibrary'];
        yield 'ImageTag' => ['Preflow\\Folio\\Assets\\ImageTag', 'Preflow\\Media\\ImageTag'];
        foreach (['VariantGenerator', 'ImageWidths'] as $name) {
            yield $name => ["Preflow\\Folio\\Assets\\$name", "Preflow\\Media\\Image\\$name"];
        }
        foreach (['ImageBackend', 'ImageBackends', 'GdBackend', 'ImagickBackend', 'Orientation'] as $name) {
            yield "Image\\$name" => ["Preflow\\Folio\\Assets\\Image\\$name", "Preflow\\Media\\Image\\$name"];
        }
    }

    #[DataProvider('aliases')]
    public function test_the_old_name_is_the_media_class(string $old, string $new): void
    {
        $this->assertSame($new, (new \ReflectionClass($old))->getName());
    }

    /** @return iterable<string, array{string, string}> the publishing classes, in preflow/publishing since 0.71 */
    public static function publishingAliases(): iterable
    {
        foreach (['PublishedModels', 'PublishState', 'PublishedCopies', 'ShadowTypes', 'Revision', 'Scheduler', 'VersionConflict', 'PreviewLinks', 'PreviewTarget'] as $name) {
            yield $name => ["Preflow\\Folio\\Publishing\\$name", "Preflow\\Publishing\\$name"];
        }
        yield 'LiveContent' => ['Preflow\\Folio\\Content\\LiveContent', 'Preflow\\Publishing\\LiveContent'];
    }

    #[DataProvider('publishingAliases')]
    public function test_the_old_name_is_the_publishing_class(string $old, string $new): void
    {
        $this->assertSame($new, (new \ReflectionClass($old))->getName());
    }

    public function test_the_old_publisher_is_a_publisher_of_the_new_name(): void
    {
        $this->assertTrue(is_subclass_of('Preflow\\Folio\\Publishing\\Publisher', \Preflow\Publishing\Publisher::class));
    }
}
