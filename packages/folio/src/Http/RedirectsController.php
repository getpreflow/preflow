<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Data\Locales;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\Folio\Redirects\LiveRecords;
use Preflow\Folio\Redirects\NotFoundEntries;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Redirects\RemovalTarget;
use Preflow\I18n\LocaleNames;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectPath;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/redirects (Settings → Redirects, permission redirects.manage): the redirect entries
 * (automatic, removed-page and hand-made ones) with their targets, the not-found list, the form
 * for a hand-made entry, and the page picker's _targets (any signed-in staff user: it lists
 * public pages only).
 *
 * Addresses are typed and shown as visitors see them ("/de/werkstatt"): on a site with locale
 * prefixes a first segment naming one of the other languages becomes the entry's language, else
 * the default language; a single-language site stores language-neutral entries (''). Paths are
 * kept in RedirectPath's one form.
 */
final class RedirectsController
{
    /** At most this many records in a _targets answer. */
    public const int MAX_TARGETS = 20;

    public function __construct(
        private readonly RedirectEntries $entries,
        private readonly NotFoundEntries $notFound,
        private readonly LiveRecords $live,
        private readonly FrontendResolver $resolver,
        private readonly TypeCatalog $catalog,
        private readonly DisplayLabels $labels,
        private readonly NonPublicPaths $nonPublic,
        private readonly Locales $locales,
        private readonly bool $prefixed,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
    ) {}

    /** GET {prefix}/redirects?q=…&record=type:id, or ?tab=not-found */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        $record = is_string($query['record'] ?? null) && str_contains($query['record'], ':') ? $query['record'] : '';
        $notFoundTab = ($query['tab'] ?? null) === 'not-found';

        return $this->html('@folio/admin/redirects/index.twig', [
            'prefix' => $this->prefix,
            'tab' => $notFoundTab ? 'not-found' : 'redirects',
            'q' => $q,
            'record' => $record,
            'rows' => $notFoundTab ? [] : $this->rows($q, $record),
            'missing' => $notFoundTab ? $this->missing() : [],
            'csrf' => self::csrf($request),
        ]);
    }

    /** GET {prefix}/redirects/new (?not_found={id} starts from that address) */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $values = self::blank();
        $id = $request->getQueryParams()['not_found'] ?? null;
        $row = is_string($id) ? $this->notFound->find($id) : null;
        if ($row !== null) {
            $values['address'] = $this->display($this->visible($row['locale'], $row['path']));
            $values['not_found'] = $row['id'];
        }

        return $this->form($request, null, $values);
    }

    /** POST {prefix}/redirects */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        return $this->save($request, null);
    }

    /** GET {prefix}/redirects/{id} */
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $entry = $this->entry($request);
        if ($entry === null) {
            return self::unknown();
        }

        return $this->form($request, $entry, [
            'address' => $this->display($this->visible($entry['locale'], $entry['path'])),
            'area' => $entry['area'],
            'mode' => $entry['mode'],
            'target' => $entry['target_type'] !== null && $entry['target_id'] !== null
                ? $entry['target_type'] . ':' . $entry['target_id']
                : (string) $entry['target_url'],
            'status' => $entry['status'],
            'not_found' => '',
        ]);
    }

    /** POST {prefix}/redirects/{id} */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $entry = $this->entry($request);

        return $entry === null ? self::unknown() : $this->save($request, $entry);
    }

    /** POST {prefix}/redirects/{id}/delete */
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $entry = $this->entry($request);
        if ($entry === null) {
            return self::unknown();
        }
        $this->entries->delete($entry['id']);
        self::flash($request, FolioText::get('folio.redirects.deleted'));

        return new Response(302, ['Location' => $this->prefix . '/redirects']);
    }

    /** POST {prefix}/redirects/not-found/{id}/dismiss */
    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->notFound->find((string) $request->getAttribute('id', ''));
        if ($row === null) {
            return self::unknown();
        }
        $this->notFound->dismiss($row['id'], new \DateTimeImmutable());
        self::flash($request, FolioText::get('folio.redirects.dismissed', ['address' => $this->display($this->visible($row['locale'], $row['path']))]));

        return new Response(302, ['Location' => $this->prefix . '/redirects?tab=not-found']);
    }

    /**
     * GET {prefix}/redirects/_targets?q=…: the page picker. Records the public site shows (types
     * with a frontend route) whose label contains q, at most MAX_TARGETS:
     * {items: [{value: "type:id", label, type (the type's label), url}]}.
     */
    public function targets(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams()['q'] ?? '';
        $q = is_string($q) ? mb_substr(trim($q), 0, 200) : '';
        $labeler = new RecordLabeler();
        $items = [];
        foreach ($this->catalog->all() as $listing) {
            if ($this->resolver->routeFor($listing->key) === null) {
                continue;
            }
            foreach ($this->live->of($listing->key) as $record) {
                $label = $labeler->label($record);
                if ($q !== '' && mb_stripos($label, $q) === false) {
                    continue;
                }
                $url = $this->resolver->urlFor($record, $record->getType()->locales->default);
                if ($url === null) {
                    continue;
                }
                $items[] = ['value' => $listing->key . ':' . $record->getId(), 'label' => $label, 'type' => $this->labels->type($listing->key), 'url' => $url];
                if (count($items) >= self::MAX_TARGETS) {
                    break 2;
                }
            }
        }

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode(
            ['items' => $items],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Checks the posted form and stores the entry ($entry: the one being edited). A refused form
     * comes back with the reason on its field (422).
     *
     * @param array<string, mixed>|null $entry
     */
    private function save(ServerRequestInterface $request, ?array $entry): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $text = static fn (string $name): string => is_string($body[$name] ?? null) ? trim($body[$name]) : '';
        $values = [
            'address' => $text('address'),
            'area' => ($body['area'] ?? null) === '1',
            'mode' => $text('mode') === AreaMode::Prefix->value ? AreaMode::Prefix->value : AreaMode::One->value,
            'target' => $text('target'),
            'status' => $text('status') === '302' ? 302 : 301,
            'not_found' => $text('not_found'),
        ];
        $host = $request->getUri()->getHost();
        [$locale, $path, $error] = $this->checkAddress($values['address'], $values['area'], $entry, $host);
        $errors = $error !== null ? ['address' => $error] : [];
        $mode = $values['area'] ? $values['mode'] : AreaMode::One->value;
        [$target, $error] = $this->checkTarget($values['target'], $values['area'], $mode, $locale, $path, $entry['id'] ?? null, $host);
        if ($error !== null) {
            $errors['target'] = $error;
        }
        if ($errors !== [] || $path === null || $target === null) {
            return $this->form($request, $entry, $values, $errors);
        }

        $row = [
            'path' => $path,
            'locale' => $locale,
            'area' => $values['area'],
            'mode' => $mode,
            'target_type' => $target->type,
            'target_id' => $target->id,
            'target_url' => $target->url,
            // Automatic and removed-page entries always answer 301.
            'status' => ($entry['kind'] ?? 'manual') === 'manual' ? $values['status'] : 301,
            'kind' => $entry['kind'] ?? 'manual',
            'source_type' => $entry['source_type'] ?? null,
            'source_id' => $entry['source_id'] ?? null,
        ];
        if ($entry !== null) {
            // Also when the address changed (a new id): the entry keeps its counts and who made it.
            foreach (['hits', 'created_at', 'created_by', 'last_hit_at'] as $kept) {
                $row[$kept] = $entry[$kept];
            }
        } else {
            $user = $request->getAttribute(Authenticatable::class);
            $row['created_by'] = $user instanceof Authenticatable ? (string) $user->getAuthId() : null;
        }
        $this->entries->save($row);
        $id = Redirect::id($values['area'] ? 'area' : 'exact', $locale, $path);
        if ($entry !== null && $entry['id'] !== $id) {
            $this->entries->delete($entry['id']);
        }

        // An address that gets a redirect leaves the not-found list, and so does the row it was made from.
        if ($values['area']) {
            $this->notFound->forgetUnder($locale, $path);
        } else {
            $this->notFound->forget($locale, $path);
        }
        $from = $values['not_found'] !== '' ? $this->notFound->find($values['not_found']) : null;
        if ($from !== null) {
            $this->notFound->forget($from['locale'], $from['path']);
        }
        self::flash($request, FolioText::get('folio.redirects.saved'));

        return new Response(302, ['Location' => $this->prefix . '/redirects']);
    }

    /**
     * The entry's locale and path for a typed address, or why it is refused. The address is
     * normalized first, then its locale prefix split off (as LocaleMiddleware does).
     *
     * @param array<string, mixed>|null $entry the entry being edited
     * @return array{string, ?string, ?string} locale, path (null when refused), error
     */
    private function checkAddress(string $address, bool $area, ?array $entry, string $host): array
    {
        if ($address === '') {
            return ['', null, FolioText::get('folio.redirects.errors.address_required')];
        }
        // A query or fragment never reaches the lookup: such an address could never match.
        $full = str_contains($address, '?') || str_contains($address, '#') ? null : RedirectPath::normalize($address);
        if ($full === null) {
            return ['', null, FolioText::get('folio.redirects.errors.address_invalid')];
        }
        [$locale, $path] = $this->split($full);
        if ($this->nonPublic->matches($path) || $this->nonPublic->matches($full)) {
            return [$locale, null, FolioText::get('folio.redirects.errors.address_not_public')];
        }
        // RedirectPath::under() never matches '/', and an area over the whole site would hide every 404.
        if ($area && $path === '/') {
            return [$locale, null, FolioText::get('folio.redirects.errors.whole_site')];
        }
        // An exact entry at a page's address would never answer; an area's root may be a page (it covers what is below).
        if (!$area && $this->pageAt($this->lookupLocale($locale), $path)) {
            return [$locale, null, FolioText::get('folio.redirects.errors.page_wins')];
        }
        $taken = $this->entries->find(Redirect::id($area ? 'area' : 'exact', $locale, $path));
        if ($taken !== null && $taken['id'] !== ($entry['id'] ?? null)) {
            return [$locale, null, FolioText::get('folio.redirects.errors.taken')];
        }
        // No chains: other entries that send visitors here would send them on again.
        if ($this->targetedBy($locale, $path, $area, $entry['id'] ?? null, $host)) {
            return [$locale, null, FolioText::get('folio.redirects.errors.targeted')];
        }

        return [$locale, $path, null];
    }

    /**
     * Whether another entry's URL target lies at this address (exact) or under it (area), in its
     * language, where no page answers.
     */
    private function targetedBy(string $locale, string $path, bool $area, ?string $own, string $host): bool
    {
        foreach ($this->entries->all() as $other) {
            if ($other['id'] === $own || $other['target_url'] === null) {
                continue;
            }
            $at = $this->siteAddress($other['target_url'], $host);
            if ($at === null || ($locale !== '' && $at[0] !== $locale)) {
                continue;
            }
            $hit = $area ? RedirectPath::under($at[1], $path) !== null : $at[1] === $path;
            if ($hit && !$this->pageAt($this->lookupLocale($at[0]), $at[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parsed target, or why it is refused: a live record ("type:id", not for "keep the rest"),
     * an http(s) URL, or a path on this site; never "//host" or "\\host" in any mix of slashes,
     * never the address itself, an address inside the area being saved, or an address another
     * entry redirects (exact or by an area: no chains); for "keep the rest" no ? or # (the rest of
     * the path is appended after the target).
     *
     * @return array{?RemovalTarget, ?string}
     */
    private function checkTarget(string $posted, bool $area, string $mode, string $locale, ?string $path, ?string $own, string $host): array
    {
        if ($posted === '') {
            return [null, FolioText::get('folio.redirects.errors.target_required')];
        }
        $target = preg_match('#^[/\\\\]{2}#', $posted) === 1 ? null : RemovalTarget::parse($posted, $this->resolver);
        if ($target === null) {
            return [null, FolioText::get('folio.redirects.errors.target_invalid')];
        }
        if ($target->type !== null && $target->id !== null) {
            if ($mode === AreaMode::Prefix->value) {
                return [null, FolioText::get('folio.redirects.errors.prefix_needs_url')];
            }

            return $this->live->find($target->type, $target->id) === null
                ? [null, FolioText::get('folio.redirects.errors.target_not_live')]
                : [$target, null];
        }
        $url = (string) $target->url;
        if ($mode === AreaMode::Prefix->value && (str_contains($url, '?') || str_contains($url, '#'))) {
            return [null, FolioText::get('folio.redirects.errors.prefix_no_query')];
        }
        $at = $this->siteAddress($url, $host);
        if ($at === null || $path === null) {
            return [$target, null];
        }
        [$targetLocale, $targetPath] = $at;
        $sameLanguage = $locale === '' || $targetLocale === $locale;
        if ($sameLanguage && $targetPath === $path) {
            return [null, FolioText::get('folio.redirects.errors.target_is_address')];
        }
        // /blog → /blog/archive would run /blog/x → /blog/archive/x → /blog/archive/archive/x …
        if ($area && $sameLanguage && RedirectPath::under($targetPath, $path) !== null) {
            return [null, FolioText::get('folio.redirects.errors.target_in_area')];
        }
        if ($this->redirectsAt($this->lookupLocale($targetLocale), $targetPath, $own)) {
            return [null, FolioText::get('folio.redirects.errors.target_redirects')];
        }

        return [$target, null];
    }

    /**
     * Whether a request for the address would be redirected, as the middleware looks it up (an
     * exact entry, else an area above it), leaving out the entry being edited. A page there
     * answers first: no redirect.
     */
    private function redirectsAt(string $locale, string $path, ?string $own): bool
    {
        $exact = $this->entries->exact($path, $locale);
        $redirected = $exact !== null && $exact->id !== $own;
        if (!$redirected) {
            foreach ($this->entries->areas($locale) as $area) {
                if ($area->id !== $own && RedirectPath::under($path, $area->path) !== null) {
                    $redirected = true;
                    break;
                }
            }
        }

        return $redirected && !$this->pageAt($locale, $path);
    }

    /**
     * The entry locale and path a URL on this site lands on (a path, or an absolute URL with this
     * host), as split() reads an address; null for another host or a path normalize() rejects.
     *
     * @return array{string, string}|null
     */
    private function siteAddress(string $url, string $host): ?array
    {
        $local = self::localPath($url, $host);
        $normalized = $local === null ? null : RedirectPath::normalize($local);

        return $normalized === null ? null : $this->split($normalized);
    }

    /** The path of a URL on this site (a path, or an absolute URL with this host), without query and fragment; else null. */
    private static function localPath(string $url, string $host): ?string
    {
        if (str_starts_with($url, '/')) {
            return (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || $host === '' || strcasecmp((string) ($parts['host'] ?? ''), $host) !== 0) {
            return null;
        }

        return (string) ($parts['path'] ?? '/') ?: '/';
    }

    /**
     * Whether a page holds the address. Tried with the path decoded too: FrontendResolver::match()
     * compares route values unencoded, so "/%C3%BCber-uns" alone would miss the page "über-uns".
     */
    private function pageAt(string $locale, string $path): bool
    {
        return $this->resolver->match($path, $locale) !== null
            || ($path !== rawurldecode($path) && $this->resolver->match(rawurldecode($path), $locale) !== null);
    }

    /**
     * The entry's locale and the path without its locale prefix, for a normalized address. With
     * locale prefixes, as LocaleMiddleware reads them: a first segment of two lowercase letters
     * naming one of the site's languages (the default's too) is the language, else the default
     * language; the locale is always a real one, as RecordAddresses stores it. Without prefixes
     * the entry is language-neutral ('').
     *
     * @return array{string, string}
     */
    private function split(string $normalized): array
    {
        if (!$this->prefixed) {
            return ['', $normalized];
        }
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $normalized, $m) === 1 && $this->locales->has($m[1])) {
            return [$m[1], ($m[2] ?? '') === '' ? '/' : $m[2]];
        }

        return [$this->locales->default, $normalized];
    }

    /** The locale a request for an address of $locale has ('' on a site without locale prefixes: its default). */
    private function lookupLocale(string $locale): string
    {
        return $locale !== '' ? $locale : $this->locales->default;
    }

    /** The address as visitors see it: the locale prefix of a language other than the default, then the path. */
    private function visible(string $locale, string $path): string
    {
        if ($locale === '' || $locale === $this->locales->default || !$this->prefixed) {
            return $path;
        }

        return '/' . $locale . ($path === '/' ? '' : $path);
    }

    /**
     * A path in RedirectPath's form as typed: each segment decoded ("/über-uns"), except for the
     * characters that would change it when typed back ("%", "/", "?", "#").
     */
    private function display(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => str_replace(['%', '/', '?', '#'], ['%25', '%2F', '%3F', '%23'], rawurldecode($segment)),
            explode('/', $path),
        ));
    }

    /**
     * The entries for the list: untargeted and broken ones first, then the newest.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $q, string $record): array
    {
        [$recordType, $recordId] = $record !== '' ? explode(':', $record, 2) : ['', ''];
        $labeler = new RecordLabeler();
        $rows = [];
        foreach ($this->entries->all() as $entry) {
            if ($record !== '' && !(($entry['target_type'] === $recordType && $entry['target_id'] === $recordId)
                || ($entry['source_type'] === $recordType && $entry['source_id'] === $recordId))) {
                continue;
            }
            $address = $this->display($this->visible($entry['locale'], $entry['path']));
            if ($q !== '' && mb_stripos($address, $q) === false) {
                continue;
            }
            $target = ['state' => 'none', 'label' => null, 'type' => null, 'url' => null];
            if ($entry['target_url'] !== null) {
                $target = ['state' => 'ok', 'label' => null, 'type' => null, 'url' => $entry['target_url']];
            } elseif ($entry['target_type'] !== null && $entry['target_id'] !== null) {
                $live = $this->live->find($entry['target_type'], $entry['target_id']);
                $target = $live === null
                    ? ['state' => 'broken', 'label' => null, 'type' => $this->typeLabel($entry['target_type']), 'url' => null]
                    : [
                        'state' => 'ok',
                        'label' => $labeler->label($live),
                        'type' => $this->typeLabel($entry['target_type']),
                        'url' => $this->resolver->urlFor($live, $this->lookupLocale($entry['locale']))
                            ?? $this->resolver->urlFor($live, $live->getType()->locales->default),
                    ];
            }
            $rows[] = $entry + [
                'address' => $address,
                'language' => $this->language($entry['locale']),
                'target' => $target,
                'edit' => $this->prefix . '/redirects/' . $entry['id'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [(int) ($a['target']['state'] === 'ok'), (string) $b['created_at']]
            <=> [(int) ($b['target']['state'] === 'ok'), (string) $a['created_at']]);

        return $rows;
    }

    /** @return list<array<string, mixed>> the not-found list's visible rows, as shown */
    private function missing(): array
    {
        return array_map(fn (array $row): array => $row + [
            'address' => $this->display($this->visible($row['locale'], $row['path'])),
            'language' => $this->language($row['locale']),
            'redirect' => $this->prefix . '/redirects/new?not_found=' . rawurlencode($row['id']),
        ], $this->notFound->visible());
    }

    private function language(string $locale): string
    {
        return $locale === '' ? FolioText::get('folio.redirects.any_language') : LocaleNames::native($locale);
    }

    private function typeLabel(string $type): string
    {
        return $this->catalog->has($type) ? $this->labels->type($type) : $type;
    }

    /**
     * @param array<string, mixed>|null $entry
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, ?array $entry, array $values, array $errors = []): ResponseInterface
    {
        $target = RemovalTarget::parse($values['target'], $this->resolver);
        $picked = $target?->type !== null && $target->id !== null ? $this->live->find($target->type, $target->id) : null;

        return $this->html('@folio/admin/redirects/form.twig', [
            'prefix' => $this->prefix,
            'entry' => $entry,
            'action' => $this->prefix . '/redirects' . ($entry !== null ? '/' . $entry['id'] : ''),
            'values' => $values,
            'errors' => $errors,
            'picked' => $picked !== null ? (new RecordLabeler())->label($picked) : null,
            'manual' => ($entry['kind'] ?? 'manual') === 'manual',
            'csrf' => self::csrf($request),
        ], $errors === [] ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return ['address' => '', 'area' => false, 'mode' => AreaMode::One->value, 'target' => '', 'status' => 301, 'not_found' => ''];
    }

    /** @return array<string, mixed>|null */
    private function entry(ServerRequestInterface $request): ?array
    {
        return $this->entries->find((string) $request->getAttribute('id', ''));
    }

    /** @param array<string, mixed> $context */
    private function html(string $template, array $context, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render($template, $context));
    }

    private static function csrf(ServerRequestInterface $request): string
    {
        return $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
    }

    private static function unknown(): ResponseInterface
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], FolioText::get('folio.redirects.unknown'));
    }

    private static function flash(ServerRequestInterface $request, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash('success', $message);
        }
    }
}
