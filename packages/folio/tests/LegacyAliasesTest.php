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

    /** @return iterable<string, array{string, string}> preflow/rbac's admin classes, carried by Folio since 0.69 */
    public static function rbacAliases(): iterable
    {
        foreach (['Extension', 'NewPermissions', 'NewPermissionsScreen', 'Fields\\PermissionsFieldType', 'Fields\\RolesFieldType', 'Types\\RoleRules', 'Types\\StaffAdmin', 'Types\\StaffRules'] as $name) {
            yield $name => ["Preflow\\Rbac\\$name", "Preflow\\Folio\\Rbac\\$name"];
        }
    }

    #[DataProvider('rbacAliases')]
    public function test_the_old_rbac_name_is_the_folio_class(string $old, string $new): void
    {
        $this->assertSame($new, (new \ReflectionClass($old))->getName());
    }
}
