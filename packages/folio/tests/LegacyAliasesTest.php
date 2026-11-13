<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyAliasesTest extends TestCase
{
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

    /** @return iterable<string, array{string}> the asset names of 0.34 and the record users of 0.38, removed in 0.72 */
    public static function longStaleNames(): iterable
    {
        foreach (['Asset', 'Variant', 'AssetUploadException', 'AssetLibrary', 'ImageTag', 'VariantGenerator', 'ImageWidths'] as $name) {
            yield $name => ["Preflow\\Folio\\Assets\\$name"];
        }
        foreach (['ImageBackend', 'ImageBackends', 'GdBackend', 'ImagickBackend', 'Orientation'] as $name) {
            yield "Image\\$name" => ["Preflow\\Folio\\Assets\\Image\\$name"];
        }
        yield 'RecordUser' => ['Preflow\\Folio\\Users\\RecordUser'];
        yield 'RecordUserProvider' => ['Preflow\\Folio\\Users\\RecordUserProvider'];
    }

    #[DataProvider('longStaleNames')]
    public function test_the_asset_and_record_user_names_of_before_0_38_are_gone(string $old): void
    {
        $this->assertFalse(class_exists($old), $old);
    }

    public function test_asset_references_stays_in_folio(): void
    {
        $this->assertTrue(class_exists('Preflow\\Folio\\Assets\\AssetReferences'), 'a Folio class, not an alias');
    }

    public function test_the_block_names_of_before_preflow_blocks_are_gone(): void
    {
        $this->assertFalse(class_exists('Preflow\\Folio\\Block\\Block'));
    }
}
