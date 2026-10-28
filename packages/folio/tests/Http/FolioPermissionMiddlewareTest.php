<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Routing\FolioRoutes;

final class FolioPermissionMiddlewareTest extends TestCase
{
    public function test_every_folio_route_has_a_permission(): void
    {
        foreach (FolioRoutes::admin('/folio', true) as $route) {
            if (!in_array('Preflow\\Folio\\Http\\AdminAuthMiddleware', $route->middleware, true)) {
                continue; // public: login, admin assets, uploads
            }
            $this->assertNotNull(FolioPermissionMiddleware::rule($route->handler), "{$route->method} {$route->pattern} ({$route->handler}) has no permission");
            $this->assertContains(FolioPermissionMiddleware::class, $route->middleware, "{$route->pattern} is not checked");
        }
    }

    public function test_without_auth_no_permission_middleware_is_attached(): void
    {
        foreach (FolioRoutes::admin('/folio', false) as $route) {
            $this->assertNotContains(FolioPermissionMiddleware::class, $route->middleware);
        }
    }

    /** @return iterable<array{string, string}> */
    public static function mapping(): iterable
    {
        yield ['Preflow\\Folio\\Http\\AdminController@index', 'folio.dashboard'];
        yield ['Preflow\\Folio\\Http\\AdminController@list', '{type}.view'];
        yield ['Preflow\\Folio\\Http\\AdminController@editForm', '{type}.view'];
        yield ['Preflow\\Folio\\Http\\AdminController@recordLabel', '{type}.view'];
        yield ['Preflow\\Folio\\Http\\AdminController@relationOptions', '{type}.view'];
        yield ['Preflow\\Folio\\Http\\AdminController@createForm', '{type}.create'];
        yield ['Preflow\\Folio\\Http\\AdminController@store', '{type}.create'];
        yield ['Preflow\\Folio\\Http\\AdminController@update', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\AdminController@reorder', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\AdminController@destroy', '{type}.delete'];
        yield ['Preflow\\Folio\\Http\\AdminController@deletePlan', '{type}.delete'];
        yield ['Preflow\\Folio\\Http\\PublishingController@autosave', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\PublishingController@discard', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\PublishingController@restore', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\PublishingController@share', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\PublishingController@revoke', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\PublishingController@unpublish', '{type}.publish'];
        yield ['Preflow\\Folio\\Http\\PreviewController@preview', '{type}.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@index', 'assets.view'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@panel', 'assets.view'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@upload', 'assets.upload'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@save', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@destroy', 'assets.delete'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@bin', 'assets.delete'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@restoreFromBin', 'assets.delete'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@replace', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@editor', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@edit', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@makeCopy', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@remake', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@restoreVersion', 'assets.edit'];
        yield ['Preflow\\Folio\\Http\\AssetLibraryController@deleteVersion', 'assets.delete'];
        yield ['Preflow\\Folio\\Http\\BlockEditorController@newBlock', '*.edit|create'];
        yield ['Preflow\\Folio\\Http\\BlockEditorController@duplicate', '*.edit|create'];
    }

    #[DataProvider('mapping')]
    public function test_handler_maps_to_its_permission(string $handler, string $permission): void
    {
        $this->assertSame($permission, FolioPermissionMiddleware::rule($handler));
    }

    public function test_auth_handlers_need_no_permission(): void
    {
        $this->assertNull(FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\AuthController@login'));
    }
}
