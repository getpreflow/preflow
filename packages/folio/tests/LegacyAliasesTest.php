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

    /** @return iterable<string, array{string}> the names of 0.71, removed in 0.72 */
    public static function removedNames(): iterable
    {
        foreach (['PublishedModels', 'PublishState', 'PublishedCopies', 'ShadowTypes', 'Revision', 'Scheduler', 'VersionConflict', 'PreviewLinks', 'PreviewTarget', 'Publisher'] as $name) {
            yield $name => ["Preflow\\Folio\\Publishing\\$name"];
        }
        yield 'LiveContent' => ['Preflow\\Folio\\Content\\LiveContent'];
        yield 'RecordDeleter' => ['Preflow\\Folio\\Content\\RecordDeleter'];
        yield 'PublishCommand' => ['Preflow\\Folio\\Console\\PublishCommand'];
        yield 'PublishScheduledCommand' => ['Preflow\\Folio\\Console\\PublishScheduledCommand'];
    }

    #[DataProvider('removedNames')]
    public function test_the_publishing_names_of_0_71_are_gone(string $old): void
    {
        $this->assertFalse(class_exists($old), $old);
    }

    public function test_the_block_names_of_before_preflow_blocks_are_gone(): void
    {
        $this->assertFalse(class_exists('Preflow\\Folio\\Block\\Block'));
    }
}
