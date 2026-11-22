<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Stream;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Http\ContentSecurityPolicy;
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
 * address in the language, has no page: 404. A page that redirects (its login, a moved address)
 * is answered with a notice of where it would go (200, no Location), so the frame stays in the
 * preview.
 *
 * Since 0.81.0 the page's follow-up requests (component actions and renders, lazy parts, its own
 * htmx calls) render as the preview too: core's preview() issues them a signed preview token
 * bound to this request's session and editor, checked again at every use for the type's edit
 * permission and, with `as`, site.view_as (PreviewGate::VIEW_AS). A staff role view names the
 * editor's guard, so it passes no other guard of the staff population. When another record
 * answers at the address (two types without publishing with overlapping addresses), a notice says
 * so instead of showing it. A page whose own policy forbids frames (frame-ancestors 'none',
 * X-Frame-Options: DENY) is explained inside the frame, with a link that opens it in a tab of
 * its own. Relative addresses in the page resolve against its own address (<base href>).
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

        $as = $query['as'] ?? '';
        try {
            // A list or another shape (?as[]=…) is no choice either.
            $role = $this->viewAs->roleView(is_string($as) ? $as : throw new \DomainException('View as takes one value.'));
        } catch (\DomainException) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }

        // The address must lead to this record: two types without publishing whose addresses overlap
        // could otherwise show another type's unpublished record here (the preview reads drafts).
        $bare = $this->frontend->pathFor($record, $locale);
        $match = $bare === null ? null : $this->frontend->match($bare, $locale);
        if ($match === null || $match->route->type !== $typeDef->key || (string) $match->record->getId() !== (string) $record->getId()) {
            return $this->notice(FolioText::get('folio.preview.other_record', ['path' => $path]));
        }

        // The editor's request (its admin surface, identity, session) is only where it starts from.
        // The preview's follow-ups (component actions, lazy parts) carry a token checked for what
        // this request was: the type's edit permission (FolioPermissionMiddleware), and view as.
        $viewer = Contexts::current()->viewer;
        $guard = $viewer->primaryGuardName();
        $requires = [$typeDef->key . '.edit'];
        if ($role !== null) {
            $role = $role->by($guard);
            $requires[] = ViewAs::PERMISSION;
        }
        $result = $this->app->preview(Contexts::current(), self::encodedPath($path), $locale, $role, $request, $requires);

        return $this->answer($result, $request, $path);
    }

    private function answer(RenderResult $result, ServerRequestInterface $request, string $path): ResponseInterface
    {
        $response = $result->response;
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
            // A redirect (a gated page's login, a moved address) is shown, never followed: the frame
            // would leave the preview for the live site, under the editor's session.
            $response = new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render(
                '@folio/admin/preview/redirect.twig',
                ['target' => $response->getHeaderLine('Location')],
            ));
        }
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
        if (self::refusesFrames($response, $this->adminOrigin($request)) && strtolower($request->getHeaderLine('Sec-Fetch-Dest')) === 'iframe') {
            // The browser would leave the frame empty: say why, and offer the page in a tab of its
            // own, where its policy allows it (the same address, loaded as a document).
            $uri = $request->getUri();

            return $this->notice(
                FolioText::get('folio.preview.frame_refused'),
                $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''),
            );
        }

        $bar = $this->engine->render('@folio/admin/preview/bar.twig', [
            'mail' => $result->wouldSend(\Preflow\Core\Context\Bridge\NotifyChannel::NAME),
            'writes' => $result->wouldWrite(),
            // Without a key that can sign there is no preview token: the bar marks the page's interactive parts as switched off (the bridge stops them).
            'tokens' => $this->app->container()->get(\Preflow\Core\Context\Preview\PreviewToken::class)->enabled(),
        ]);

        // Relative addresses in the page resolve against the page's own address, not the admin's.
        $html = self::withBase((string) $response->getBody(), $path);

        return $response->withoutHeader('Content-Length')->withBody(Stream::create(PreviewController::withEditorBridge($html, $this->assetUrls, $bar)));
    }

    /**
     * A notice in place of the page (another record answers at the address; the site's policy
     * forbids frames), with an optional link that opens $link in a tab of its own. Admin markup
     * with a policy of its own: no script, styles from the admin's files, framed by the admin only.
     */
    private function notice(string $message, ?string $link = null): ResponseInterface
    {
        $html = $this->engine->render('@folio/admin/preview/notice.twig', ['message' => $message, 'link' => $link]);

        return new \Nyholm\Psr7\Response(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
        ], PreviewController::withEditorBridge($html, $this->assetUrls));
    }

    /**
     * Whether the page's own headers forbid showing it in the admin's frame. The preview answers
     * from the admin's address, so the frame and the admin share $origin: a frame-ancestors list
     * that allows neither 'self', '*', $origin nor its scheme refuses it ('none' included), and so
     * does X-Frame-Options other than SAMEORIGIN (DENY, ALLOW-FROM); SAMEORIGIN allows it.
     */
    private static function refusesFrames(ResponseInterface $response, string $origin): bool
    {
        $frameOptions = strtolower(trim($response->getHeaderLine('X-Frame-Options')));
        if ($frameOptions !== '' && $frameOptions !== 'sameorigin') {
            return true;
        }
        $origin = strtolower(rtrim($origin, '/'));
        $scheme = $origin === '' ? '' : (string) parse_url($origin, PHP_URL_SCHEME) . ':';
        foreach (ContentSecurityPolicy::parse(...$response->getHeader('Content-Security-Policy'))->policies() as $policy) {
            if (isset($policy['frame-ancestors'])) {
                $allowed = false;
                foreach ($policy['frame-ancestors'] as $source) {
                    $source = strtolower(rtrim($source, '/'));
                    if (in_array($source, ["'self'", '*'], true) || ($origin !== '' && ($source === $origin || $source === $scheme))) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The admin's origin: the request's when it names a host, else the site's (app.url). */
    private function adminOrigin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        if ($uri->getHost() !== '') {
            return $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        }

        return $this->app->site()->origin;
    }

    /**
     * $html with <base href> set to the page's own address, so its relative addresses (an image
     * "logo.png", a link "next") resolve as on the live site, not against the admin's preview
     * address. A page that sets its own <base> keeps it.
     */
    private static function withBase(string $html, string $path): string
    {
        if (preg_match('/<base\b/i', $html) === 1 || preg_match('/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $base = '<base href="' . htmlspecialchars(self::encodedPath($path), ENT_QUOTES, 'UTF-8') . '">';

        return substr_replace($html, $base, $head[0][1] + strlen($head[0][0]), 0);
    }

    /** The address as a request path: each segment percent-encoded ("/über-uns" → "/%C3%BCber-uns"). */
    private static function encodedPath(string $path): string
    {
        return implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }
}
