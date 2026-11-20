<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Stream;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The whole-page preview (GET {admin}/preview/page/{type}/{id}?locale=&as=): the record's real
 * page (its route, the layout, navigation, SEO, components) as a visitor will see it once the
 * working copies are published, through core's Application::preview(). Folio only builds the
 * call: the record's address in the asked locale, and, with `as`, the role to view it as
 * (ViewAs: site.view_as, and only a role the editor may view as; any other `as` answers 403).
 *
 * The page renders in a frame of its own; the admin's request is not narrowed. The answer is the
 * page's own response (its Content-Security-Policy holds the nonce of its inline scripts),
 * without the Set-Cookie and hypermedia headers it may carry (never the admin's to send), never
 * cached or indexed, with the preview-editor bridge and the preview bar (what the page would send
 * and write) added as external files and markup only. A record that was never saved, or has no
 * address in the language, has no page: 404.
 *
 * @since 0.81.0
 */
final class PagePreviewController
{
    public function __construct(
        private readonly Application $app,
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly FrontendResolver $frontend,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ViewAs $viewAs,
        private readonly ?AdminAssetUrls $assetUrls = null,
        private readonly ?HypermediaProtocols $hypermedia = null,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->frontend->routeFor($type) === null) {
            return NotFound::response($request, FolioText::get('folio.errors.not_found'));
        }
        $typeDef = $this->registry->get($type);
        $record = $this->dm->findType($typeDef->key, (string) $request->getAttribute('id', ''));
        $query = $request->getQueryParams();
        $locale = is_string($query['locale'] ?? null) && $typeDef->locales->has($query['locale']) ? $query['locale'] : $typeDef->locales->default;
        $path = $record !== null ? $this->frontend->urlFor($record, $locale) : null;
        if ($path === null) {
            return NotFound::response($request, FolioText::get('folio.errors.not_found'));
        }

        try {
            $role = $this->viewAs->roleView(is_string($query['as'] ?? null) ? $query['as'] : '');
        } catch (\DomainException) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }

        // The editor's request (its admin surface, identity, session) is only where it starts from.
        $result = $this->app->preview(Contexts::current(), self::encodedPath($path), $locale, $role);

        return $this->answer($result);
    }

    private function answer(RenderResult $result): ResponseInterface
    {
        $response = $result->response;
        $strip = ['Set-Cookie', ...($this->hypermedia?->ownedHeaders() ?? []), ...($this->hypermedia?->nonceHeaders() ?? [])];
        foreach (array_keys($response->getHeaders()) as $name) {
            if (HypermediaProtocols::matches($name, $strip)) {
                $response = $response->withoutHeader($name);
            }
        }
        $response = $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Referrer-Policy', 'no-referrer');
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }

        $bar = $this->engine->render('@folio/admin/preview/bar.twig', [
            'mail' => $result->wouldSend(\Preflow\Core\Context\Bridge\NotifyChannel::NAME),
            'writes' => $result->wouldWrite(),
        ]);

        return $response->withBody(Stream::create(PreviewController::withEditorBridge((string) $response->getBody(), $this->assetUrls, $bar)));
    }

    /** The address as a request path: each segment percent-encoded ("/über-uns" → "/%C3%BCber-uns"). */
    private static function encodedPath(string $path): string
    {
        return implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }
}
