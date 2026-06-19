<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Folio\Routing\FolioRoutes;

final class FolioRoutesTest extends TestCase
{
    public function test_admin_routes_use_prefix_and_action_mode(): void
    {
        $entries = FolioRoutes::admin('/folio');

        $patterns = array_map(fn ($e) => $e->method . ' ' . $e->pattern, $entries);
        $this->assertContains('GET /folio', $patterns);
        $this->assertContains('GET /folio/{type}', $patterns);
        $this->assertContains('GET /folio/{type}/new', $patterns);
        $this->assertContains('POST /folio/{type}', $patterns);
        $this->assertContains('GET /folio/{type}/{id}/edit', $patterns);
        $this->assertContains('POST /folio/{type}/{id}', $patterns);
        $this->assertContains('POST /folio/{type}/{id}/delete', $patterns);

        foreach ($entries as $e) {
            $this->assertSame(RouteMode::Action, $e->mode);
            if (str_contains($e->handler, 'AdminController')) {
                $this->assertStringStartsWith('Preflow\\Folio\\Http\\AdminController@', $e->handler);
            }
        }
    }

    public function test_admin_prefix_is_configurable(): void
    {
        $entries = FolioRoutes::admin('/cms');
        $this->assertSame('/cms', $entries[0]->pattern);
    }

    public function test_admin_includes_asset_route(): void
    {
        $entries = FolioRoutes::admin('/folio');

        $match = null;
        foreach ($entries as $e) {
            if ($e->pattern === '/folio/_assets/{file}') {
                $match = $e;
                break;
            }
        }

        $this->assertNotNull($match, 'asset route should be registered');
        $this->assertSame('GET', $match->method);
        $this->assertSame('Preflow\\Folio\\Http\\AssetController@serve', $match->handler);
        $this->assertContains('file', $match->paramNames);
        // Prefix-configurability: dashboard stays first.
        $this->assertSame('/folio', $entries[0]->pattern);
    }

    public function test_frontend_is_lowest_priority_catch_all(): void
    {
        $entry = FolioRoutes::frontend();
        $this->assertTrue($entry->isCatchAll);
        $this->assertSame('GET', $entry->method);
        $this->assertSame('Preflow\\Folio\\Http\\FrontendController@show', $entry->handler);
        $this->assertSame(['path'], $entry->paramNames);
    }
}
