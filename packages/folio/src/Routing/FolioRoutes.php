<?php

declare(strict_types=1);

namespace Preflow\Folio\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteEntry;

final class FolioRoutes
{
    private const BLOCKS = 'Preflow\\Folio\\Http\\BlockEditorController';
    private const ADMIN = 'Preflow\\Folio\\Http\\AdminController';
    private const PREVIEW = 'Preflow\\Folio\\Http\\PreviewController';
    private const PUBLISHING = 'Preflow\\Folio\\Http\\PublishingController';
    private const ASSETS = 'Preflow\\Folio\\Http\\AssetLibraryController';
    private const EXTENSIONS = 'Preflow\\Folio\\Http\\ExtensionsController';
    private const WORKING_MW = 'Preflow\\Folio\\Http\\WorkingCopyMiddleware';
    private const AUTH = 'Preflow\\Folio\\Http\\AuthController';
    private const PASSWORDS = 'Preflow\\Folio\\Http\\PasswordController';
    private const ACCOUNT = 'Preflow\\Folio\\Http\\AccountController';
    private const AUTH_MW = 'Preflow\\Folio\\Http\\AdminAuthMiddleware';
    private const PERMISSION_MW = 'Preflow\\Folio\\Http\\FolioPermissionMiddleware';

    /**
     * @param bool $passwords the forgot-password and set-password pages and the own account page (record users behind the default guard)
     * @return RouteEntry[]
     */
    public static function admin(string $prefix, bool $authEnabled = false, bool $passwords = false): array
    {
        $prefix = '/' . trim($prefix, '/');

        $defs = [
            ['GET', $prefix, 'index'],
        ];
        if ($authEnabled) {
            $defs[] = ['GET',  $prefix . '/login',  'loginForm', self::AUTH];
            $defs[] = ['POST', $prefix . '/login',  'login',     self::AUTH];
            $defs[] = ['POST', $prefix . '/logout', 'logout',    self::AUTH];
            if ($passwords) {
                $defs[] = ['GET',  $prefix . '/forgot',           'forgotForm',   self::PASSWORDS];
                $defs[] = ['POST', $prefix . '/forgot',           'forgot',       self::PASSWORDS];
                $defs[] = ['GET',  $prefix . '/password/{token}', 'passwordForm', self::PASSWORDS];
                $defs[] = ['POST', $prefix . '/password',         'password',     self::PASSWORDS];
                // The signed-in account's own page (signed in, no permission): before {type}, which it would match.
                $defs[] = ['GET',  $prefix . '/_account',          'show',     self::ACCOUNT];
                $defs[] = ['POST', $prefix . '/_account/password', 'password', self::ACCOUNT];
            }
        }
        $defs = array_merge($defs, [
            ['GET',  $prefix . '/_blocks/{key}/new',   'newBlock', self::BLOCKS],
            ['POST', $prefix . '/_blocks/duplicate',   'duplicate', self::BLOCKS],
            ['GET',  $prefix . '/assets',              'index', self::ASSETS],
            ['POST', $prefix . '/assets',              'upload', self::ASSETS],
            ['GET',  $prefix . '/assets/{id}',         'panel', self::ASSETS],
            ['POST', $prefix . '/assets/{id}',         'save', self::ASSETS],
            ['POST', $prefix . '/assets/{id}/delete',  'destroy', self::ASSETS],
            ['GET',  $prefix . '/extensions',          'index', self::EXTENSIONS],
            ['POST', $prefix . '/extensions/{name}',   'toggle', self::EXTENSIONS],
            ['GET',  $prefix . '/_relation/{type}',    'relationOptions'], // before the generic {type} routes
            ['GET',  $prefix . '/{type}',              'list'],
            ['GET',  $prefix . '/{type}/new',          'createForm'],
            ['POST', $prefix . '/{type}/preview',      'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}',              'store'],
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['GET',  $prefix . '/{type}/{id}/label',   'recordLabel'],
            ['POST', $prefix . '/{type}/{id}/preview', 'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}/{id}/unpublish', 'unpublish', self::PUBLISHING],
            ['POST', $prefix . '/{type}/{id}/discard', 'discard', self::PUBLISHING],
            ['POST', $prefix . '/{type}/{id}/autosave', 'autosave', self::PUBLISHING],
            ['POST', $prefix . '/{type}/{id}/revisions/{rev}/restore', 'restore', self::PUBLISHING],
            ['POST', $prefix . '/{type}/{id}/share', 'share', self::PUBLISHING],
            ['POST', $prefix . '/{type}/{id}/share/revoke', 'revoke', self::PUBLISHING],
            ['POST', $prefix . '/{type}/{id}',         'update'],
            ['POST', $prefix . '/{type}/{id}/delete',  'destroy'],
            ['POST', $prefix . '/{type}/{id}/move',    'reorder'],
            ['POST', $prefix . '/{type}/{id}/_action/{name}', 'recordAction'],
        ]);

        $entries = [];
        foreach ($defs as $def) {
            [$method, $pattern, $action] = $def;
            $handlerClass = $def[3] ?? self::ADMIN;
            // Signed-in staff, then the route's permission (FolioPermissionMiddleware::rule).
            $middleware = ($authEnabled && $handlerClass !== self::AUTH && $handlerClass !== self::PASSWORDS) ? [self::AUTH_MW, self::PERMISSION_MW] : [];
            $middleware[] = self::WORKING_MW; // the admin shows drafts of referenced records
            $c = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $handlerClass . '@' . $action,
                method: $method,
                mode: RouteMode::Action,
                middleware: $middleware,
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

    /**
     * Missing image variants under the media URL (made on first request). Placed
     * before the frontend catch-all; existing files never reach PHP.
     */
    public static function media(string $url): RouteEntry
    {
        $pattern = rtrim($url, '/') . '/{...path}';
        $c = PatternCompiler::compile($pattern);

        return new RouteEntry(
            pattern: $pattern,
            handler: 'Preflow\\Folio\\Http\\MediaVariantController@serve',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $c['paramNames'],
            regex: $c['regex'],
            isCatchAll: $c['isCatchAll'],
        );
    }

    /**
     * Private assets at {url}/{id} (PrivateAssetController checks the permission).
     * Only a 32-hex id matches, so other pages under the URL still reach the
     * frontend; placed before the frontend catch-all. It needs the session:
     * session.paths must include the URL's prefix.
     */
    public static function privateAssets(string $url): RouteEntry
    {
        $pattern = rtrim($url, '/') . '/{id}';
        $c = PatternCompiler::compile($pattern);

        return new RouteEntry(
            pattern: $pattern,
            handler: 'Preflow\\Folio\\Http\\PrivateAssetController@serve',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $c['paramNames'],
            regex: '#^' . preg_quote(rtrim($url, '/'), '#') . '/(?P<id>[a-f0-9]{32})$#',
            isCatchAll: $c['isCatchAll'],
        );
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
            // Also match "/" (empty path): the home record is served at the root.
            regex: '#^/(?P<path>.*)$#',
            isCatchAll: $c['isCatchAll'],
        );
    }
}
