<?php

declare(strict_types=1);

namespace Preflow\Folio\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteEntry;

final class FolioRoutes
{
    private const ADMIN = 'Preflow\\Folio\\Http\\AdminController';

    /** @return RouteEntry[] */
    public static function admin(string $prefix): array
    {
        $prefix = '/' . trim($prefix, '/');

        $defs = [
            ['GET',  $prefix,                          'index'],
            ['GET',  $prefix . '/{type}',              'list'],
            ['GET',  $prefix . '/{type}/new',          'createForm'],
            ['POST', $prefix . '/{type}',              'store'],
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['POST', $prefix . '/{type}/{id}',         'update'],
            ['POST', $prefix . '/{type}/{id}/delete',  'destroy'],
        ];

        $entries = [];
        foreach ($defs as [$method, $pattern, $action]) {
            $c = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: self::ADMIN . '@' . $action,
                method: $method,
                mode: RouteMode::Action,
                middleware: [],
                paramNames: $c['paramNames'],
                regex: $c['regex'],
                isCatchAll: $c['isCatchAll'],
            );
        }

        // Package-owned admin assets (CSS/JS, incl. vendored editor bundles).
        // Appended last; single-segment {file} param, allowlist-guarded in the
        // controller. entries[0] stays the dashboard (prefix-configurability).
        $assetPattern = $prefix . '/_assets/{file}';
        $ac = PatternCompiler::compile($assetPattern);
        $entries[] = new RouteEntry(
            pattern: $assetPattern,
            handler: 'Preflow\\Folio\\Http\\AssetController@serve',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $ac['paramNames'],
            regex: $ac['regex'],
            isCatchAll: $ac['isCatchAll'],
        );

        return $entries;
    }

    public static function frontend(): RouteEntry
    {
        $c = PatternCompiler::compile('/{...path}');
        return new RouteEntry(
            pattern: '/{...path}',
            handler: 'Preflow\\Folio\\Http\\FrontendController@show',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $c['paramNames'],
            regex: $c['regex'],
            isCatchAll: $c['isCatchAll'],
        );
    }
}
