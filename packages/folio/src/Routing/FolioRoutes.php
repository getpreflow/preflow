<?php

declare(strict_types=1);

namespace Preflow\Folio\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteEntry;

final class FolioRoutes
{
    private const ADMIN = 'Preflow\\Folio\\Http\\AdminController';
    private const PREVIEW = 'Preflow\\Folio\\Http\\PreviewController';

    /** @return RouteEntry[] */
    public static function admin(string $prefix): array
    {
        $prefix = '/' . trim($prefix, '/');

        $defs = [
            ['GET',  $prefix,                          'index'],
            ['GET',  $prefix . '/{type}',              'list'],
            ['GET',  $prefix . '/{type}/new',          'createForm'],
            ['POST', $prefix . '/{type}/preview',      'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}',              'store'],
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['GET',  $prefix . '/{type}/{id}/label',   'recordLabel'],
            ['POST', $prefix . '/{type}/{id}/preview', 'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}/{id}',         'update'],
            ['POST', $prefix . '/{type}/{id}/delete',  'destroy'],
        ];

        $entries = [];
        foreach ($defs as $def) {
            [$method, $pattern, $action] = $def;
            $handlerClass = $def[3] ?? self::ADMIN;
            $c = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $handlerClass . '@' . $action,
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

        // Package-owned upload serving. Catch-all (paths contain slashes), placed
        // before the frontend catch-all so it wins for {prefix}/_uploads/*.
        $uploadPattern = $prefix . '/_uploads/{...path}';
        $uc = PatternCompiler::compile($uploadPattern);
        $entries[] = new RouteEntry(
            pattern: $uploadPattern,
            handler: 'Preflow\\Folio\\Http\\UploadController@serve',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $uc['paramNames'],
            regex: $uc['regex'],
            isCatchAll: $uc['isCatchAll'],
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
