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
}
