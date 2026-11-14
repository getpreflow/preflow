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
use Preflow\Folio\Redirects\SiteTarget;
use Preflow\Folio\Redirects\VisibleAddress;
use Preflow\I18n\LocaleNames;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectErrors;
use Preflow\Routing\Redirect\RedirectPath;
use Preflow\Routing\Redirect\RedirectRejected;
use Preflow\Routing\Redirect\RedirectRules;
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
        private readonly SiteTarget $site,
        private readonly RedirectRules $rules,
    ) {}

    /** GET {prefix}/redirects?q=…&record=type:id (q: the old or the new address), or ?tab=not-found */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            $query = $request->getQueryParams();
            $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';
            $record = is_string($query['record'] ?? null) && str_contains($query['record'], ':') ? $query['record'] : '';
            $notFoundTab = ($query['tab'] ?? null) === 'not-found';

            $missing = $notFoundTab ? $this->missing() : [];

            return $this->html('@folio/admin/redirects/index.twig', [
                'prefix' => $this->prefix,
                'tab' => $notFoundTab ? 'not-found' : 'redirects',
                'q' => $q,
                'record' => $record,
                'rows' => $notFoundTab ? [] : $this->rows($q, $record),
                'missing' => $missing,
                // The Not found tab's badge (hidden at 0), on both tabs.
                'not_found_count' => $notFoundTab ? count($missing) : count($this->notFound->visible()),
                'csrf' => self::csrf($request),
            ]);
        });
    }

    /**
     * GET {prefix}/redirects/new (?not_found={id} starts from that address; ?address=/path, from the
     * admin's 404 page, prefills a path on this site: anything else, a URL or "//host" included, is
     * left out). Saving validates the address as always. With _drawer=1 (the list's drawer) the form
     * comes in the drawer layout, and so does every form after it; without, it is a page.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            $values = self::blank();
            $query = $request->getQueryParams();
            $id = $query['not_found'] ?? null;
            $row = is_string($id) ? $this->notFound->find($id) : null;
            if ($row !== null) {
                $values = $this->addressValues($row['locale'], $row['path']) + $values;
                $values['not_found'] = $row['id'];
            } elseif (is_string($query['address'] ?? null) && self::isPath($query['address'])) {
                $values = $this->typedAddressValues($query['address']) + $values;
            }

            return $this->form($request, null, $values);
        });
    }

    /** POST {prefix}/redirects */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            return $this->save($request, null);
        });
    }

    /** GET {prefix}/redirects/{id} */
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            $entry = $this->entry($request);
            if ($entry === null) {
                return self::unknown($request);
            }

            return $this->form($request, $entry, $this->addressValues($entry['locale'], $entry['path']) + [
                'area' => $entry['area'],
                'mode' => $entry['mode'],
                'target' => $entry['target_type'] !== null && $entry['target_id'] !== null
                    ? $entry['target_type'] . ':' . $entry['target_id']
                    : (string) $entry['target_url'],
                'status' => $entry['status'],
                'not_found' => '',
                'target_kind' => '',
            ]);
        });
    }

    /** POST {prefix}/redirects/{id} */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            $entry = $this->entry($request);

            return $entry === null ? self::unknown($request) : $this->save($request, $entry);
        });
    }

    /** POST {prefix}/redirects/{id}/delete */
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            $entry = $this->entry($request);
            if ($entry === null) {
                return self::unknown($request);
            }
            $this->entries->delete($entry['id']);
            self::flash($request, FolioText::get('folio.redirects.deleted'));

            return new Response(302, ['Location' => $this->prefix . '/redirects']);
        });
    }

    /** POST {prefix}/redirects/not-found/{id}/dismiss */
    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded(function () use ($request): ResponseInterface {
            $row = $this->notFound->find((string) $request->getAttribute('id', ''));
            if ($row === null) {
                return self::unknown($request);
            }
            $this->notFound->dismiss($row['id'], new \DateTimeImmutable());
            self::flash($request, FolioText::get('folio.redirects.dismissed', ['address' => $this->visible($row['locale'], $row['path'])]));

            return new Response(302, ['Location' => $this->prefix . '/redirects?tab=not-found']);
        });
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
     * Runs a screen's action; a storage failure (a SQL site that has not run `preflow migrate`:
     * no redirect tables) answers with a notice saying so instead of an error page.
     *
     * @param \Closure(): ResponseInterface $action
     */
    private function guarded(\Closure $action): ResponseInterface
    {
        try {
            return $action();
        } catch (\RuntimeException $e) {
            if (!self::storageFailure($e)) {
                throw $e;
            }

            return $this->html('@folio/admin/redirects/index.twig', ['prefix' => $this->prefix, 'unavailable' => true], 503);
        }
    }

    /** Whether the failure came from the database (a missing table): a PDOException anywhere in the chain. */
    private static function storageFailure(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks the posted form and stores the entry ($entry: the one being edited). A refused form
     * comes back with the reason on its field (422). The typed address and target are read here;
     * the rules (no chains, a page wins, …) are RedirectRules', which every writer is held to.
     *
     * @param array<string, mixed>|null $entry
     */
    private function save(ServerRequestInterface $request, ?array $entry): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $text = static fn (string $name): string => is_string($body[$name] ?? null) ? trim($body[$name]) : '';
        // The form's language select (sites with locale prefixes): '' any language, else one of the site's.
        $language = $this->prefixed && is_string($body['language'] ?? null)
            && ($body['language'] === '' || $this->locales->has($body['language'])) ? $body['language'] : null;
        $values = [
            'address' => $text('address'),
            'language' => $language ?? '',
            'area' => ($body['area'] ?? null) === '1',
            'mode' => $text('mode') === AreaMode::Prefix->value ? AreaMode::Prefix->value : AreaMode::One->value,
            'target' => $text('target'),
            'status' => $text('status') === '302' ? 302 : 301,
            'not_found' => $text('not_found'),
            'target_kind' => $text('target_kind'),
        ];
        $host = $request->getUri()->getHost();
        [$locale, $path, $addressError] = $this->readAddress($values['address'], $language);
        $mode = $values['area'] ? $values['mode'] : AreaMode::One->value;
        [$target, $targetError] = $this->readTarget($values['target']);

        $draft = [
            'path' => $path ?? '',
            'locale' => $locale,
            'area' => $values['area'],
            'mode' => $mode,
            'target_type' => $target?->type,
            'target_id' => $target?->id,
            'target_url' => $target?->url,
        ];
        $errors = [];
        if ($addressError !== null) {
            $errors['address'] = $addressError;
        } else {
            $this->refusals($errors, $this->rules->address($draft, $entry, $host));
        }
        // A refused address leaves the target rules that need it out (as before: "/about" a page, target "/about": page_wins only).
        if ($errors !== []) {
            $draft['path'] = '';
        }
        if ($targetError !== null) {
            $errors['target'] = $targetError;
        } else {
            $this->refusals($errors, $this->rules->target($draft, $entry, $host));
        }
        if ($errors !== [] || $path === null || $target === null) {
            return $this->form($request, $entry, $values, $errors);
        }

        $target = $this->pageTarget($target, $values['area'], $mode, $locale, $path, $host);
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
        try {
            // Also forgets the address on the not-found list.
            $this->entries->save($row, $entry, $host);
        } catch (RedirectRejected $e) {
            $this->refusals($errors, $e->errors);

            return $this->form($request, $entry, $values, $errors);
        }
        $id = Redirect::id($values['area'] ? 'area' : 'exact', $locale, $path);
        if ($entry !== null && $entry['id'] !== $id) {
            $this->entries->delete($entry['id']);
        }

        // The row the entry was made from leaves the not-found list too.
        $from = $values['not_found'] !== '' ? $this->notFound->find($values['not_found']) : null;
        if ($from !== null) {
            $this->notFound->forget($from['locale'], $from['path']);
        }
        self::flash($request, FolioText::get('folio.redirects.saved'));
        if (self::inDrawer($request)) {
            // The list behind the drawer reloads (admin.js) and shows the flash.
            return $this->html('@folio/admin/drawer_saved.twig', ['type' => 'redirect', 'id' => '']);
        }

        return new Response(302, ['Location' => $this->prefix . '/redirects']);
    }

    /**
     * Adds the rules' refusals to the form's errors, in this screen's texts; a field that already
     * has one keeps it.
     *
     * @param array<string, string> $errors
     */
    private function refusals(array &$errors, RedirectErrors $refused): void
    {
        foreach ($refused->all() as $field => $key) {
            $errors[$field] ??= FolioText::get('folio.redirects.errors.' . $key);
        }
    }

    /**
     * The entry's locale and path for a typed address, or why it is refused. The form shows a "/"
     * in front of the field, so a path typed without it gets it (never a scheme or a backslash
     * host); a query or fragment is left out, since the lookup never sees one. The address is
     * normalized, then its locale prefix split off (as LocaleMiddleware does). Without a prefix
     * the form's language select ($language: '' any language) decides, where the form has one;
     * else the default language.
     *
     * @return array{string, ?string, ?string} locale, path (null when refused), error
     */
    private function readAddress(string $address, ?string $language = null): array
    {
        if ($address === '') {
            return ['', null, FolioText::get('folio.redirects.errors.address_required')];
        }
        $bare = (string) preg_replace('/[?#].*$/s', '', $address);
        if ($bare !== '' && !str_starts_with($bare, '/') && !str_starts_with($bare, '\\') && preg_match('/^[a-z][a-z0-9+.-]*:/i', $bare) !== 1) {
            $bare = '/' . $bare;
        }
        $full = RedirectPath::normalize($bare);
        if ($full === null) {
            return ['', null, FolioText::get('folio.redirects.errors.address_invalid')];
        }
        [$locale, $path] = $this->site->split($full);
        if ($language !== null && $path === $full) {
            $locale = $language;
        }
        if ($this->nonPublic->matches($path) || $this->nonPublic->matches($full)) {
            return [$locale, null, FolioText::get('folio.redirects.errors.address_not_public')];
        }

        return [$locale, $path, null];
    }

    /**
     * The parsed target (a record "type:id", an http(s) URL, or a path on this site; never
     * "//host" or "\\host" in any mix of slashes), or why it is not one.
     *
     * @return array{?RemovalTarget, ?string}
     */
    private function readTarget(string $posted): array
    {
        if ($posted === '') {
            return [null, FolioText::get('folio.redirects.errors.target_required')];
        }
        $target = preg_match('#^[/\\\\]{2}#', $posted) === 1 ? null : RemovalTarget::parse($posted, $this->resolver);

        return $target === null ? [null, FolioText::get('folio.redirects.errors.target_invalid')] : [$target, null];
    }

    /**
     * A page's address becomes the page itself: the redirect follows it when it moves, as
     * SiteTarget resolves it. Not for "keep the rest" (the rest of the path goes after the URL),
     * another language's address (a record answers in the visitor's language) or a URL with a
     * query or fragment.
     */
    private function pageTarget(RemovalTarget $target, bool $area, string $mode, string $locale, string $path, string $host): RemovalTarget
    {
        $url = $target->url;
        if ($url === null || $mode === AreaMode::Prefix->value || str_contains($url, '?') || str_contains($url, '#')) {
            return $target;
        }
        $at = $this->site->address($url, $host);
        if ($at === null || ($locale !== '' && $at[0] !== $locale)) {
            return $target;
        }
        $page = $this->site->page($at[0], $at[1]);

        return $page === null ? $target : RemovalTarget::record($page->route->type, (string) $page->record->getId());
    }

    /** The locale a request for an address of $locale has ('' on a site without locale prefixes: its default). */
    private function lookupLocale(string $locale): string
    {
        return $this->site->lookupLocale($locale);
    }

    /**
     * The form's address and language for a stored one: on a site with locale prefixes the
     * address without its prefix and the language in the select; elsewhere as visitors see it.
     *
     * @return array{address: string, language: string}
     */
    private function addressValues(string $locale, string $path): array
    {
        return $this->languages() === []
            ? ['address' => $this->visible($locale, $path), 'language' => '']
            : ['address' => (new VisibleAddress($this->locales->default, false))->of('', $path), 'language' => $locale];
    }

    /**
     * The form's address and language for a typed path (the 404 page's prefill): a locale prefix
     * goes into the select; without one it is any language.
     *
     * @return array{address: string, language: string}
     */
    private function typedAddressValues(string $typed): array
    {
        $full = RedirectPath::normalize($typed);
        if ($this->languages() === [] || $full === null) {
            return ['address' => $typed, 'language' => ''];
        }
        [$locale, $path] = $this->site->split($full);

        return $path === $full ? ['address' => $typed, 'language' => ''] : $this->addressValues($locale, $path);
    }

    /**
     * The language select's options (code => name, '' any language), on a site whose addresses
     * carry the language; none elsewhere (every entry there is for any language).
     *
     * @return array<string, string>
     */
    private function languages(): array
    {
        if (!$this->prefixed || count($this->locales->available) < 2) {
            return [];
        }
        $options = ['' => FolioText::get('folio.redirects.any_language')];
        foreach ($this->locales->available as $locale) {
            $options[$locale] = LocaleNames::native($locale);
        }

        return $options;
    }

    /** The address as visitors see and type it ("/de/über-uns"). */
    private function visible(string $locale, string $path): string
    {
        return (new VisibleAddress($this->locales->default, $this->prefixed))->of($locale, $path);
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
            $address = $this->visible($entry['locale'], $entry['path']);
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
            // The search finds the old address or the target (its URL, or the page's label).
            if ($q !== '' && array_filter([$address, $target['url'], $target['label']], static fn (?string $text): bool => $text !== null && mb_stripos($text, $q) !== false) === []) {
                continue;
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
            'address' => $this->visible($row['locale'], $row['path']),
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
        $page = $target?->type !== null && $target->id !== null;
        // "Send visitors to": a page (a picked or posted "type:id", or nothing yet) or an address.
        $kind = in_array($values['target_kind'] ?? '', ['page', 'address'], true)
            ? $values['target_kind']
            : ($values['target'] === '' || $page ? 'page' : 'address');
        $drawer = self::inDrawer($request);

        return $this->html('@folio/admin/redirects/form.twig', [
            'prefix' => $this->prefix,
            'layout' => $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig',
            'editor_assets' => ['admin.js'],
            'drawer' => $drawer,
            'entry' => $entry,
            'action' => $this->prefix . '/redirects' . ($entry !== null ? '/' . $entry['id'] : ''),
            'values' => $values,
            'errors' => $errors,
            'languages' => $this->languages(),
            'target_kind' => $kind,
            'picked' => $picked !== null ? (new RecordLabeler())->label($picked) : null,
            'picked_item' => $picked !== null ? [
                'value' => $values['target'],
                'label' => (new RecordLabeler())->label($picked),
                'type' => $this->typeLabel((string) $target?->type),
                'url' => $this->resolver->urlFor($picked, $picked->getType()->locales->default) ?? '',
            ] : null,
            'manual' => ($entry['kind'] ?? 'manual') === 'manual',
            'csrf' => self::csrf($request),
        ], $errors === [] ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return ['address' => '', 'language' => '', 'area' => false, 'mode' => AreaMode::One->value, 'target' => '', 'status' => 301, 'not_found' => '', 'target_kind' => ''];
    }

    /** Whether the form is in the list's drawer: _drawer=1 in the query (opened) or the posted form. */
    private static function inDrawer(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();

        return ($request->getQueryParams()['_drawer'] ?? null) === '1' || (is_array($body) && ($body['_drawer'] ?? null) === '1');
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

    /** A path on this site: "/…", never "//host" or "/\host", a scheme or a relative "x". */
    private static function isPath(string $address): bool
    {
        return preg_match('~^/(?![/\\\\])[^\\\\\\s]*$~', $address) === 1 && RedirectPath::normalize($address) !== null;
    }

    private static function unknown(ServerRequestInterface $request): ResponseInterface
    {
        return NotFound::response($request, FolioText::get('folio.redirects.unknown'));
    }

    private static function flash(ServerRequestInterface $request, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash('success', $message);
        }
    }
}
