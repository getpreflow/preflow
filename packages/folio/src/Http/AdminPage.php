<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\Screen\PageAssets;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one place a ScreenView becomes an admin page. A screen (ScreenDispatcher) and a Folio
 * override (AdminController) are rendered here, so both get the same variables for the layout:
 * prefix, csrf, page_assets (the resolved stylesheets and scripts), extension_assets_base and,
 * for a screen, screen (label and path).
 *
 * @since 0.79.0
 */
final class AdminPage
{
    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ?PageAssets $assets = null,
    ) {}

    /**
     * @param string $owner names the caller in errors ("Screen Class::method", "Override Class")
     * @param string|null $extension the owning extension: its bare asset entries ('file.css') and
     *                               its assets base; null (an override) allows only '@folio/...'
     *                               and '@<extension>/...' entries
     * @param list<string> $assets entries listed before the view's own (a screen attribute's)
     * @param array{label: string, path: string}|null $screen
     * @throws ExtensionException
     */
    public function render(ScreenView $view, ServerRequestInterface $request, string $owner, ?string $extension = null, array $assets = [], ?array $screen = null): ResponseInterface
    {
        $entries = [...$assets, ...$view->assets];

        $vars = [
            'prefix' => $this->prefix,
            'page_assets' => $this->assets?->resolve($extension, $entries, $owner),
            'extension_assets_base' => $extension === null ? null : $this->assets?->base($extension),
            'csrf' => $request->getAttribute(CsrfToken::class)?->getValue() ?? '',
        ];
        if ($screen !== null) {
            $vars['screen'] = $screen;
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $view->render($this->engine, $vars, $owner));
    }
}
