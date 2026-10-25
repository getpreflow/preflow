<?php

declare(strict_types=1);

namespace Preflow\Media\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Media\Http\PrivateFileController;
use Preflow\Media\Http\VariantController;
use Preflow\Media\MediaConfig;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteEntry;

/**
 * The media core's routes, added by MediaServiceProvider::boot() after the app's own
 * routes (and, on a Folio site, before Folio's frontend catch-all):
 *
 * - {private_url}/{id}: private files (PrivateFileController asks PrivateFileAccess). Only
 *   a 32-hex id matches, so other pages under the URL still reach the frontend. It needs
 *   the session: session.paths must include the URL's prefix.
 * - {private_url}/{id}/v/{n}: an earlier file of an asset, behind the same gate.
 * - {url}/{...path}: missing image variants, made on first request; existing files never
 *   reach PHP.
 */
final class MediaRoutes
{
    public const string PRIVATE_HANDLER = PrivateFileController::class . '@serve';
    public const string VERSION_HANDLER = PrivateFileController::class . '@serveVersion';
    public const string VARIANT_HANDLER = VariantController::class . '@serve';

    /** Adds the routes, each once per collection: a second call adds nothing. */
    public static function register(RouteCollection $routes, MediaConfig $config): void
    {
        $private = rtrim($config->privateUrl, '/');
        $media = rtrim($config->url, '/');
        $entries = [
            new RouteEntry(
                pattern: $private . '/{id}',
                handler: self::PRIVATE_HANDLER,
                method: 'GET',
                mode: RouteMode::Action,
                middleware: [],
                paramNames: ['id'],
                regex: '#^' . preg_quote($private, '#') . '/(?P<id>[a-f0-9]{32})$#',
                isCatchAll: false,
            ),
            new RouteEntry(
                pattern: $private . '/{id}/v/{n}',
                handler: self::VERSION_HANDLER,
                method: 'GET',
                mode: RouteMode::Action,
                middleware: [],
                paramNames: ['id', 'n'],
                regex: '#^' . preg_quote($private, '#') . '/(?P<id>[a-f0-9]{32})/v/(?P<n>[1-9][0-9]{0,5})$#',
                isCatchAll: false,
            ),
            new RouteEntry(
                pattern: $media . '/{...path}',
                handler: self::VARIANT_HANDLER,
                method: 'GET',
                mode: RouteMode::Action,
                middleware: [],
                paramNames: ['path'],
                regex: '#^' . preg_quote($media, '#') . '/(?P<path>.+)$#',
                isCatchAll: true,
            ),
        ];
        $present = [];
        foreach ($routes->all() as $entry) {
            $present[$entry->method . ' ' . $entry->pattern . ' ' . $entry->handler] = true;
        }
        foreach ($entries as $entry) {
            if (!isset($present[$entry->method . ' ' . $entry->pattern . ' ' . $entry->handler])) {
                $routes->add($entry);
            }
        }
    }
}
