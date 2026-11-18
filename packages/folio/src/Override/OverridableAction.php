<?php

declare(strict_types=1);

namespace Preflow\Folio\Override;

use Preflow\Folio\Extension\Screen\ScreenView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A class in App\Folio\Overrides that replaces a core admin action (today the dashboard,
 * Content\Index).
 */
interface OverridableAction
{
    /**
     * A response is sent as it is. A ScreenView (since 0.79.0) is rendered inside the admin
     * layout exactly as a screen's is: the template gets prefix, csrf, page_assets and
     * extension_assets_base next to its own data. An override has no extension of its own, so
     * the view's assets must be '@folio/<name>' or '@<extension>/<path>', never a bare 'file.css'.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface|ScreenView;
}
