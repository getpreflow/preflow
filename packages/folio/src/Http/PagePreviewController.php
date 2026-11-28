<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Stream;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Http\NonPublicPaths;
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
 * Since 0.83.0 the same preview takes any address of the site, also one that is no record's page
 * (an app's own route, such as /account): GET {admin}/preview/address/page?path=&as= renders it,
 * GET {admin}/preview/address is the screen with the address field, the View as choice and the
 * frame (the record's editor links to it). Both ask site.view_as_address (FolioPermissionMiddleware)
 * and site.view_as (ViewAs::addresses()), also for a visitor's view: the address preview reads the
 * working copies of every type, as any preview does. The frame, the bar, the outbox and the token
 * are the record preview's (one rendering path, render()); the token requires both permissions,
 * also for a visitor's view.
 * Only a path of the site's own pages is taken (sitePath()): no scheme, host, protocol-relative
 * "//", backslash, control character, query or fragment; never the admin's or another non-public
 * prefix (NonPublicPaths). Dot segments and doubled slashes are resolved; the language is the one
 * the path's prefix names, else the site's default. Anything else answers 400.
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
        /** The prefixes that are no public page (the admin's, APIs'): never an address to preview. @since 0.83.0 */
        private readonly ?NonPublicPaths $nonPublic = null,
        /** The admin's language for the marks' labels while the page renders (since 0.94.0). */
        private readonly ?\Preflow\Folio\Content\PreviewLabelLanguage $labelLanguage = null,
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

        // The preview's follow-ups carry a token checked for what this request was: the type's edit
        // permission (FolioPermissionMiddleware), and view as.
        return $this->render($request, $path, $locale, $role, [$typeDef->key . '.edit']);
    }

    /**
     * GET {admin}/preview/address?path=&as= (site.view_as_address, then site.view_as): the address
     * field, the View as choice and, for an address of the site, the frame that shows it
     * (sitePath()). An address that is none shows why beside the field, and no frame.
     *
     * @since 0.83.0
     */
    public function addressScreen(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->viewAs->addresses()) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        $query = $request->getQueryParams();
        $typed = $query['path'] ?? null;
        $as = is_string($query['as'] ?? null) ? $query['as'] : '';
        $path = null;
        $error = null;
        if ($typed !== null && $typed !== '') {
            try {
                $path = $this->sitePath($typed);
            } catch (\InvalidArgumentException) {
                $error = FolioText::get('folio.preview.address_refused', ['address' => is_string($typed) ? $typed : '']);
            }
        }
        $frame = $path === null ? null : $this->prefix . '/preview/address/page?path=' . rawurlencode($path) . ($as !== '' ? '&as=' . rawurlencode($as) : '');

        return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'], $this->engine->render('@folio/admin/preview/address.twig', [
            'prefix' => $this->prefix,
            'path' => $path !== null ? rawurldecode($path) : (is_string($typed) ? $typed : ''),
            'as' => $as,
            'view_as' => $this->viewAs->groups(),
            'error' => $error,
            'frame' => $frame,
        ]));
    }

    /**
     * GET {admin}/preview/address/page?path=&as= (site.view_as_address, then site.view_as): the
     * page at a path of the site as the role (or a visitor), as the record preview shows a record's.
     *
     * @since 0.83.0
     */
    public function address(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $as = $query['as'] ?? '';
        try {
            $role = $this->viewAs->addresses()
                ? $this->viewAs->roleView(is_string($as) ? $as : throw new \DomainException('View as takes one value.'))
                : throw new \DomainException('This editor may not preview addresses.');
        } catch (\DomainException) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        $typed = $query['path'] ?? null;
        try {
            $path = $this->sitePath($typed);
        } catch (\InvalidArgumentException) {
            return $this->notice(FolioText::get('folio.preview.address_refused', ['address' => is_string($typed) ? $typed : '']), null, 400);
        }
        $site = $this->app->site();
        $locale = $site->urls?->localeOf($path) ?? $site->defaultLocale;

        // Both permissions, also for a visitor's view: taking either away ends it at its next request.
        return $this->render($request, $path, $locale, $role, [ViewAs::ADDRESS_PERMISSION, ViewAs::PERMISSION]);
    }

    /**
     * The preview of $path in $locale as $role (null: a visitor), answered for the frame. The
     * editor's request (its admin surface, identity, session) is only where it starts from: the
     * page's follow-ups (component actions, lazy parts) carry a token that requires $requires, and
     * with a role site.view_as, checked again at every use. A staff role view names the editor's
     * guard, so it passes no other guard of the staff population.
     *
     * @param list<string> $requires
     */
    private function render(ServerRequestInterface $request, string $path, string $locale, ?RoleView $role, array $requires): ResponseInterface
    {
        if ($role !== null) {
            $role = $role->by(Contexts::current()->viewer->primaryGuardName());
            $requires[] = ViewAs::PERMISSION;
        }
        // The marks' labels in the editor's language (FolioText::locale() on the admin surface, before the preview's context).
        $admin = FolioText::locale();
        $preview = fn () => $this->app->preview(Contexts::current(), self::encodedPath($path), $locale, $role, $request, array_values(array_unique($requires)));
        $result = $this->labelLanguage !== null ? $this->labelLanguage->during($admin, $preview) : $preview();

        return $this->answer($result, $request, $path);
    }

    /**
     * $typed as a path of the site's own pages, each segment percent-encoded once ("/über-uns" is
     * "/%C3%BCber-uns", as the request asks for it): '/' first (added to a path typed without it,
     * as the field shows it), dot and empty segments resolved ('/a//b/../c' is '/a/c', also when
     * encoded), a trailing slash only where the site's locale root has one. What it leads to is
     * checked decoded, so an encoded segment ("/%66olio") is never a way into the admin. A segment
     * is decoded once and must then be one plain segment: an encoded slash or backslash ("%2F",
     * "%5c"), a second layer of encoding ("%252F") or invalid UTF-8 is refused, so the path means the
     * same to a router or front server that decodes it. Core's SafeReturnPath does not fit here: it
     * accepts or refuses a redirect target as given, this normalises what an editor typed.
     *
     * @throws \InvalidArgumentException for anything else: not a string, empty, a scheme ("https:",
     *         "javascript:"), a host ("//host", "\\host"), a backslash or control character (also
     *         encoded), an encoded slash, double encoding, invalid UTF-8, a query or fragment, a path
     *         above the root, or one under the admin's or another non-public prefix
     */
    private function sitePath(mixed $typed): string
    {
        $typed = is_string($typed) ? trim($typed) : '';
        if ($typed === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $typed . rawurldecode($typed)) === 1 || strpbrk($typed, '?#') !== false
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $typed) === 1 || str_starts_with($typed, '//')) {
            throw new \InvalidArgumentException('Not a path of this site.');
        }
        $names = [];
        foreach (explode('/', $typed) as $segment) {
            $name = rawurldecode($segment);
            // A segment is one segment: no encoded slash or backslash in it (a router or front server that
            // decodes them would read another path), no second layer of encoding, valid UTF-8 only.
            if (strpbrk($name, '/\\') !== false || preg_match('/%[0-9A-Fa-f]{2}/', $name) === 1 || preg_match('//u', $name) !== 1) {
                throw new \InvalidArgumentException('Not one path segment.');
            }
            if ($name === '' || $name === '.') {
                continue;
            }
            if ($name === '..') {
                if ($names === []) {
                    throw new \InvalidArgumentException('A path above the root.');
                }
                array_pop($names);
                continue;
            }
            $names[] = $name;
        }
        $plain = '/' . implode('/', $names);
        $path = '/' . implode('/', array_map(rawurlencode(...), $names));
        $urls = $this->app->site()->urls;
        if ($path !== '/' && str_ends_with($typed, '/') && $urls?->isHome($path . '/') === true) {
            $path .= '/';
        }
        $admin = rtrim($this->prefix, '/');
        foreach ([$plain, $urls?->strip($plain) ?? $plain] as $candidate) {
            if (($admin !== '' && ($candidate === $admin || str_starts_with($candidate, $admin . '/'))) || $this->nonPublic?->matches($candidate) === true) {
                throw new \InvalidArgumentException('Not a public page.');
            }
        }

        return $path;
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
    private function notice(string $message, ?string $link = null, int $status = 200): ResponseInterface
    {
        $html = $this->engine->render('@folio/admin/preview/notice.twig', ['message' => $message, 'link' => $link]);

        return new \Nyholm\Psr7\Response($status, [
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
