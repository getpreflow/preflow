<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\Highlighter;
use Preflow\Folio\FolioText;
use Preflow\Folio\Content\ListQuery;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\ListView;
use Preflow\Folio\Content\ListCells;
use Preflow\Folio\Content\ListExport;
use Preflow\Folio\Content\DeleteSummary;
use Preflow\Folio\Content\RecordId;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RelationLabels;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\VersionConflict;
use Preflow\Publishing\PublishFailed;
use Preflow\Publishing\DeleteRefused;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\WriteKind;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Badge;
use Preflow\Folio\Extension\Link;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Seo\SeoSection;
use Preflow\Folio\View\FormOwner;
use Preflow\I18n\Completeness;
use Preflow\Validation\ValidationException;
use Preflow\Folio\Override\ActionResolver;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    private readonly DraftRecordFactory $drafts;
    private readonly DisplayLabels $labels;
    private readonly TranslationStatus $translations;

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly ActionResolver $overrides,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly string $prefix,
        private readonly RecordLabeler $labeler,
        private readonly FrontendResolver $frontend,
        /** Every save and list move goes through it (since 0.71). */
        private readonly RecordWriter $writer,
        /** Every delete goes through it. */
        private readonly RecordDeleter $deleter,
        ?DraftRecordFactory $drafts = null,
        private readonly ?Publisher $publisher = null,
        private readonly ?\Preflow\Publishing\PreviewLinks $previewLinks = null,
        private readonly ?\Preflow\Auth\Access\Access $access = null,
        private readonly ?Customizations $customizations = null,
        private readonly ?\Preflow\Folio\Users\AuthorNames $authors = null,
        private ?ListExport $export = null,
        private readonly ?\Preflow\Folio\Extension\Screen\PageAssets $pageAssets = null,
        ?DisplayLabels $labels = null,
        ?TranslationStatus $translations = null,
        /** Asked whether machine translation is available (since 0.66; before, the AI connection). */
        private readonly ?\Preflow\I18n\Machine\MachineTranslator $translator = null,
        private readonly ?\Preflow\I18n\Machine\JobRunner $jobs = null,
        private readonly ?\Preflow\I18n\Machine\ReviewIndex $reviewIndex = null,
        private readonly ?\Preflow\I18n\Machine\TranslationWorker $worker = null,
        private readonly ?\Preflow\View\Seo\SeoConfig $seo = null,
        private readonly ?\Preflow\Folio\Redirects\RecordAddresses $addresses = null,
        private readonly ?\Preflow\Folio\Redirects\OldAddresses $oldAddresses = null,
        // The side panel's search result: the page's title and description as search engines get them (SEO on).
        private readonly ?\Preflow\Folio\Seo\FolioPageMeta $pageMeta = null,
        // config app.url: the host the search result shows ("example.com › about"); null shows the path alone.
        private readonly ?string $siteUrl = null,
    ) {
        $this->labels = $labels ?? new DisplayLabels($catalog, $registry);
        $this->translations = $translations ?? new TranslationStatus($fieldTypes, $this->labels);
        $this->drafts = $drafts ?? new DraftRecordFactory($fieldTypes);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (($override = $this->overrides->resolve('Content', 'Index')) !== null) {
            return $override->handle($request);
        }

        // The review section refreshing itself while a job is queued or running: the section alone,
        // without counting every type's records again each time.
        // Folio's own htmx (@folio/htmx.js, not the app's driver) polls the review partial.
        if ($request->getHeaderLine('HX-Request') === 'true' && ($request->getQueryParams()['review'] ?? '') === '1') {
            $review = $this->reviewSection();

            return $this->html($review === null ? '' : $this->engine->render('@folio/admin/partials/dashboard-review.twig', [
                'prefix' => $this->prefix,
                'review' => $review,
            ]));
        }

        $cards = [];
        foreach ($this->catalog->all() as $listing) {
            $cards[] = [
                'key' => $listing->key,
                'label' => $this->labels->type($listing->key),
                'count' => count($this->dm->queryType($listing->key)->get()->items()),
            ];
        }

        $review = $this->reviewSection();

        return $this->html($this->engine->render('@folio/admin/dashboard.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'cards' => $cards,
            'review' => $review,
            'editor_assets' => ($review['polling'] ?? false) ? ['htmx.js'] : [],
            // #[DashboardNotice] HTML of the types the user may view, printed raw above the cards.
            'dashboard_notices' => $this->customizations?->dashboardNotices(fn (string $type): bool => $this->can($type . '.view')) ?? [],
        ]));
    }

    /** Rows shown in the dashboard's review list; the rest is counted ("+N more"). */
    private const REVIEW_ROWS = 50;

    /**
     * The dashboard's "Waiting for review" section, or null when AI is off or the user lacks ai.translate:
     * records with unchecked machine translations (only types the user may view and that still exist),
     * grouped by type, and the jobs that are queued, running or stopped (also only of types the user may view).
     * A job's records being translated right now come with their progress (JobRunner stores it per
     * item); while a shown job is queued or running and has changed in the last 10 minutes, the
     * section refreshes itself ("polling").
     *
     * @return array{groups: list<array{label: string, family: ?\Preflow\Data\TypeFamily, icon: string, rows: list<array{title: string, url: string, languages: list<string>}>}>, more: int, jobs: list<array<string, mixed>>, no_worker: bool, polling: bool}|null
     */
    private function reviewSection(): ?array
    {
        if (!($this->translator?->available() ?? false) || !$this->can('ai.translate') || $this->reviewIndex === null) {
            return null;
        }

        $groups = [];
        $shown = 0;
        $more = 0;
        foreach ($this->reviewIndex->entries() as $entry) {
            if (!$this->catalog->has($entry->collection) || !$this->can($entry->collection . '.view')) {
                continue;
            }
            if ($shown >= self::REVIEW_ROWS) {
                $more++;
                continue;
            }
            $shown++;
            $counts = $entry->counts;
            ksort($counts);
            $languages = [];
            foreach ($counts as $locale => $count) {
                $languages[] = self::languageName((string) $locale) . ' (' . $count . ')';
            }
            $locale = (string) array_key_first($counts);
            $listing = $this->catalog->get($entry->collection);
            $groups[$entry->collection]['label'] = $this->labels->type($entry->collection);
            $groups[$entry->collection]['family'] = $listing?->family;
            $groups[$entry->collection]['icon'] = $listing?->glyph() ?? 'ui-file';
            $groups[$entry->collection]['rows'][] = [
                'title' => $entry->title,
                'languages' => $languages,
                'url' => $this->prefix . '/' . $entry->collection . '/' . rawurlencode($entry->id) . '/edit' . ($locale === '' ? '' : '?locale=' . rawurlencode($locale)),
            ];
        }

        $format = new AdminFormat();
        $jobs = [];
        $queued = $polling = false;
        foreach ($this->jobs?->active(50) ?? [] as $job) {
            // Like the review rows: a job shows its records' type and progress, so only to who may view the type.
            if (!$this->can($job->collection . '.view')) {
                continue;
            }
            if (count($jobs) >= 10) {
                break;
            }
            $queued = $queued || $job->state === 'queued';
            $polling = $polling || (($job->state === 'queued' || $job->state === 'running') && !self::stale($job));
            $jobs[] = [
                'type' => $this->catalog->has($job->collection) ? $this->labels->type($job->collection) : $job->collection,
                'languages' => implode(', ', array_map(self::languageName(...), $job->locales)),
                'done' => count($job->items) - count($job->pending()),
                'total' => count($job->items),
                'cost' => $job->costUnknown ? '—' : AiTranslateController::cost($job->costUsd),
                'state' => $job->state,
                'message' => $job->state === 'stopped' ? $job->message : null,
                'by' => $job->queuedBy,
                'since' => $format->date($job->queuedAt, 'datetime'),
                'current' => $job->state === 'running' || $job->state === 'queued' ? $this->itemsInProgress($job) : [],
            ];
        }

        return [
            'groups' => array_values($groups),
            'more' => $more,
            'jobs' => $jobs,
            'no_worker' => $queued && !WorkerWatch::runs($this->worker),
            'polling' => $polling,
        ];
    }

    /** Seconds without any change after which a queued or running job no longer keeps the dashboard polling. */
    private const STALE_JOB_SECONDS = 600;

    /**
     * Whether a queued or running job has not changed for STALE_JOB_SECONDS (its driver went away,
     * or no worker runs): polling it would only repeat the same answer. A job without a stamp
     * (saved before 0.48.1) counts as stale, so it never keeps a page polling forever.
     */
    private static function stale(\Preflow\I18n\Machine\Job $job): bool
    {
        if ($job->updatedAt === null) {
            return true;
        }
        try {
            return new \DateTimeImmutable($job->updatedAt) < new \DateTimeImmutable('-' . self::STALE_JOB_SECONDS . ' seconds');
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * The records of a running job being translated right now, with how far their language has
     * come: what JobRunner stores on a pending item while a driver works on it.
     *
     * @return list<array{title: string, language: string, done: int, total: int}>
     */
    private function itemsInProgress(\Preflow\I18n\Machine\Job $job): array
    {
        $current = [];
        foreach ($job->items as $id => $item) {
            $progress = $item['progress'] ?? null;
            if ($item['status'] !== 'pending' || !is_array($progress) || (int) ($progress['total'] ?? 0) <= 0) {
                continue;
            }
            $record = $this->catalog->has($job->collection) ? $this->dm->findType($job->collection, (string) $id) : null;
            $current[] = [
                'title' => $record === null ? (string) $id : $this->labeler->label($record),
                'language' => self::languageName((string) ($progress['locale'] ?? '')),
                'done' => min((int) ($progress['done'] ?? 0), (int) $progress['total']),
                'total' => (int) $progress['total'],
            ];
        }

        return $current;
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        if ($this->isSingleton($type)) {
            // A singleton has no list: its one record (id = type key) is edited directly.
            $typeDef = $this->registry->get($type);
            $record = $this->dm->findType($type, $type);
            return $this->form(
                $type,
                ($record?->toArray() ?? []) + self::defaults($typeDef), // declared defaults for keys not stored yet (first save, a setting added later)
                $this->prefix . '/' . $type . '/' . $type,
                $this->labelFor($type),
                [],
                $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
                locale: $this->requestLocale($request, $typeDef),
                editUrl: $this->prefix . '/' . $type,
                notice: self::restoredNotice($request) ?? $this->confirmedNotice($request, $type),
            );
        }

        $typeDef = $this->registry->get($type);
        $listing = $this->catalog->get($type);
        $listConfig = $listing?->list ?? [];
        $orderable = ($listConfig['orderable'] ?? false) === true;
        $columns = $this->listColumns($typeDef, $listConfig);
        $extraColumns = $this->customized($type)['columns'];

        $multilingual = $typeDef->translatableFields() !== [];
        $states = $this->publishes($type) ? $this->publisher->states($type) : null;
        // Records per state over the whole type: the status links' counts (ListQuery::STATUSES).
        $statusCounts = null;
        if ($states !== null) {
            $statusCounts = array_fill_keys(ListQuery::STATUSES, 0);
            foreach ($states as $state) {
                $statusCounts[$state->value] = ($statusCounts[$state->value] ?? 0) + 1;
            }
        }
        $stateOf = $states === null ? null : static fn (DynamicRecord $record): PublishState => $states[(string) $record->getId()] ?? PublishState::Draft;

        $query = ListQuery::fromRequest($request, $typeDef, $listConfig, $typeDef->locales->default, $states !== null);
        $result = $query->fetch(
            $this->dm->queryType($type),
            $multilingual ? fn (DynamicRecord $record): bool => $this->hasUnchecked($typeDef, $record) : null,
            $stateOf === null ? null : static fn (DynamicRecord $record): string => $stateOf($record)->value,
        );
        $records = array_values($result->items);
        $relations = new RelationLabels($this->dm, $this->labeler);
        // Relation columns (only when "list.columns" names them): the page's labels in one query per target.
        $relationColumns = array_values(array_filter(array_keys($columns), static fn (string $name) => $typeDef->fields[$name]->type === 'relation'));
        $relationLabels = $relationColumns === [] ? [] : $relations->forRecords($typeDef, $relationColumns, $records);
        $menu = $this->rowMenu($type, $typeDef, $records, $states);
        $route = $this->frontend->routeFor($type);
        $uncheckedUrl = $this->prefix . '/' . $type . '?unchecked=1';

        $rows = [];
        foreach ($records as $record) {
            $id = (string) $record->getId();
            $cells = [];
            $marked = [];
            foreach ($columns as $name => $label) {
                $text = ListCells::text($this->fieldTypes, $typeDef, $name, $record, $relationLabels, $this->labels);
                // cells: plain text (Twig escapes it); marked: escaped HTML with the search words in <mark>.
                $cells[$name] = $text;
                $marked[$name] = $text === '' ? '' : Highlighter::html($text, $query->q);
            }
            $home = $route !== null ? $this->homeValue($route, $record) : null;
            $rows[] = [
                'id' => $record->getId(),
                'cells' => $cells,
                'marked' => $marked,
                // Extension columns (#[ListColumn]): plain text, a Badge or a Link; all escaped by the template.
                'extra' => array_map(function (array $column) use ($record): string|array {
                    $value = ($column['callable'])($record);

                    if ($value instanceof Badge) {
                        return ['label' => $value->label, 'tone' => $value->tone()];
                    }
                    if ($value instanceof Link) {
                        $href = $value->href($this->prefix);

                        return $href === null ? $value->label : ['label' => $value->label, 'href' => $href];
                    }

                    return (string) $value;
                }, $extraColumns),
                'languages' => $multilingual ? $this->languageStates($typeDef, $record) : [],
                'unchecked_url' => $uncheckedUrl,
                'state' => $stateOf !== null ? $stateOf($record) : null,
                // The frontend route's home record: "The home page · slug “home”" under its title.
                'home' => $home !== null ? FolioText::get('folio.list.home_page', ['field' => $route->paramNames[0], 'value' => $home]) : null,
                // Its address in the default language, as the router sees it ("/", "/about").
                'address' => $route !== null ? $this->frontend->pathFor($record, $typeDef->locales->default) : null,
                'menu' => $menu[$id] ?? [],
            ];
        }

        $partial = ($request->getQueryParams()['_partial'] ?? null) === '1';
        $list = ListView::build(
            $query,
            $typeDef,
            $listConfig,
            $columns,
            $result,
            $this->prefix . '/' . $type,
            $relations,
            fn (string $target): bool => $this->can($target . '.view'),
            withFilterMenu: !$partial, // the partial (each keystroke) never shows the menu: skip its option queries
            labels: $this->labels,
            statusCounts: $statusCounts,
        );
        // Every record of the type, whatever the view narrows to: the header's count.
        $typeTotal = $statusCounts !== null
            ? array_sum($statusCounts)
            : ($list['filtered'] ? $this->dm->queryType($type)->paginate(1, 1)->total : $list['total']);
        // "No … yet" only when the type has no records at all; a search without hits says so instead.
        $hasRecords = $rows !== [] || $typeTotal > 0;

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->html($this->engine->render($partial ? '@folio/admin/_list_results.twig' : '@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            // The header's type tile: the type's family tint and icon.
            'family' => $listing?->family,
            'glyph' => $listing?->glyph() ?? 'ui-file',
            'type_total' => $typeTotal,
            'empty_text' => $this->labels->emptyState($type),
            'types' => $this->catalog->all(),
            'columns' => $columns,
            // field => ListCells::kind(): number cells are mono and right-aligned.
            'column_kinds' => array_combine(array_keys($columns), array_map(static fn (string $name): string => ListCells::kind($typeDef, $name), array_keys($columns))),
            'extra_columns' => array_column($extraColumns, 'label'),
            'rows' => $rows,
            'list' => $list,
            'has_records' => $hasRecords,
            // Manual positions only mean something in the list's own order, unfiltered.
            'orderable' => $orderable && !$list['sorted'] && !$list['filtered'],
            'multilingual' => $multilingual,
            'publishing' => $states !== null,
            // A frontend type: each row's address (mono) and its type tile.
            'frontend' => $route !== null,
            'csrf' => $csrf,
            'bulk' => $this->bulkBar($type, $typeDef, $listConfig),
            // What this user may do with the rows (the row menu's Edit, moves and Delete).
            'may' => ['create' => $this->can($type . '.create'), 'edit' => $this->can($type . '.edit'), 'delete' => $this->can($type . '.delete')],
            // The row "…" menu's items that do not depend on the row.
            'row_menu' => [
                'duplicate' => $this->duplicable($type) && $this->can($type . '.create') && $this->can($type . '.view'),
                'actions' => $this->menuActions($type),
            ],
            // "Translate all…" (header) and "Translate…" (bulk bar): the AI run screen.
            'ai_run_url' => $this->aiRunUrl($type, $typeDef),
            // The Export button (header: the whole filtered list; bulk bar: the ticked rows), only with OpenSpout installed.
            'export_url' => $this->exporter()->available() ? $this->prefix . '/' . $type . '/_export' : null,
            // The view to return to after a bulk action (search, filters, status, sort, page).
            'list_query' => http_build_query($query->query(), '', '&', PHP_QUERY_RFC3986),
            // #[ListNotice] HTML, printed raw above the list; the full page only, not each live-search refresh.
            'list_notices' => $partial ? [] : ($this->customizations?->listNotices($type) ?? []),
        ]));
    }

    /**
     * The row "…" menu's items that depend on the row, per record id: "View on site" (a record the
     * site shows: a publishing type's live snapshot, another frontend type's record, at its address
     * in the default language), "Unpublish" (live records, for those who may publish; with the count
     * of addresses it takes off the site, for the "Send its old links to…" prompt) and "Copy preview
     * link" (publishing types with a frontend, for those who may edit, as the editor offers it).
     *
     * @param list<DynamicRecord>                 $records
     * @param array<string, PublishState>|null    $states
     * @return array<string, array{view_url: ?string, unpublish_url: ?string, unpublish_addresses: int, share_url: ?string}>
     */
    private function rowMenu(string $type, TypeDefinition $typeDef, array $records, ?array $states): array
    {
        $route = $this->frontend->routeFor($type);
        $ids = array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $records);
        $default = $typeDef->locales->default;
        $live = [];
        if ($states !== null && $route !== null && $ids !== []) {
            foreach ($this->publisher->query($type)->byIds($ids)->get()->items() as $snapshot) {
                // The snapshot as a record of the type itself, so its address is the type's route.
                $live[(string) $snapshot->getId()] = DynamicRecord::fromArray($typeDef, $snapshot->toArray());
            }
        }
        $mayPublish = $states !== null && $this->can($type . '.publish');
        $addresses = $mayPublish ? ($this->addresses?->counts($type, $ids) ?? []) : [];
        $share = $states !== null && $route !== null && $this->previewLinks?->enabled() === true && $this->can($type . '.edit');

        $menu = [];
        foreach ($records as $record) {
            $id = (string) $record->getId();
            $base = $this->prefix . '/' . $type . '/' . rawurlencode($id);
            $isLive = $states !== null && ($states[$id] ?? PublishState::Draft) !== PublishState::Draft;
            $shown = $states === null ? $record : ($live[$id] ?? null);
            $menu[$id] = [
                'view_url' => $route !== null && $shown !== null ? $this->frontend->urlFor($shown, $default) : null,
                'unpublish_url' => $mayPublish && $isLive ? $base . '/unpublish' : null,
                'unpublish_addresses' => $addresses[$id] ?? 0,
                'share_url' => $share ? $base . '/share' : null,
            ];
        }

        return $menu;
    }

    /**
     * Extension record actions shown in the editor's header (toolbar: true) that this user may run:
     * the row menu offers them too. Those with toolbar: false stay editor actions.
     *
     * @return list<array{name: string, label: string, icon: ?string, confirm: ?string}>
     */
    private function menuActions(string $type): array
    {
        if (!$this->can($type . '.edit')) {
            return [];
        }
        $actions = [];
        foreach ($this->customized($type)['actions'] as $action) {
            if ($action['toolbar'] && $this->can($action['permission'])) {
                $actions[] = ['name' => $action['name'], 'label' => $action['label'], 'icon' => $action['icon'], 'confirm' => $action['confirm']];
            }
        }

        return $actions;
    }

    /**
     * The home record's value of the route's first parameter (its default-language value equals
     * the route's "home"), else null.
     */
    private function homeValue(\Preflow\Folio\Content\FrontendRoute $route, DynamicRecord $record): ?string
    {
        if ($route->home === null || $route->paramNames === []) {
            return null;
        }
        $first = $route->paramNames[0];
        $locales = $record->getType()->locales;
        $value = in_array($first, $record->getType()->translatableFields(), true)
            ? LocaleValue::pick($record->get($first), $locales, $locales->default)
            : LocaleValue::default($record->get($first), $locales);

        return $value === $route->home ? $route->home : null;
    }

    /**
     * The copy's title (the field the list shows first, a text field): "<title> (copy)" in each
     * language the record has a title in, with the suffix in that language (folio.list.copy_title);
     * a title that already ends in the suffix counts on ("Home (copy)" → "Home (copy 2)"), so the
     * list never shows two rows that read the same.
     *
     * @return array<string, mixed> RecordWriter::duplicate() overrides
     */
    private function copyOverrides(DynamicRecord $source): array
    {
        $def = $source->getType();
        $name = array_key_first($this->listColumns($def, $this->catalog->get($def->key)?->list ?? []));
        if (!is_string($name) || !in_array($def->fields[$name]->type ?? '', ['string', 'text'], true)) {
            return [];
        }
        $value = $source->get($name);
        if (in_array($name, $def->translatableFields(), true)) {
            $titles = [];
            foreach (LocaleValue::map($value, $def->locales) as $locale => $title) {
                // A language the site no longer has stays as it is (duplicate() takes the site's languages only).
                if (is_string($title) && trim($title) !== '' && $def->locales->has((string) $locale)) {
                    $titles[$locale] = self::copyTitle($title, (string) $locale);
                }
            }

            return $titles === [] ? [] : [$name => $titles];
        }

        return is_string($value) && trim($value) !== '' ? [$name => self::copyTitle($value, $def->locales->default)] : [];
    }

    /** "<title> (copy)" in $locale; "<title> (copy 2)", "(copy 3)"… for a title that is a copy already. */
    private static function copyTitle(string $title, string $locale): string
    {
        $template = FolioText::translator()->get('folio.list.copy_title', ['title' => ':title'], $locale);
        [$before, $after] = explode(':title', $template, 2) + [1 => ''];
        if (str_ends_with($after, ')') && $after !== ')') {
            $open = substr($after, 0, -1); // " (copy"
            if (preg_match('/^(.*)' . preg_quote($open, '/') . '(?: (\d+))?\)$/su', $title, $m) === 1) {
                return $m[1] . $open . ' ' . (($m[2] ?? '') !== '' ? (int) $m[2] + 1 : 2) . ')';
            }
        }

        return $before . $title . $after;
    }

    /**
     * Whether "Duplicate" makes sense for the type: not for a singleton (one record only) and not
     * for a user type, whose copy would carry the account's password hash and sign-in.
     */
    private function duplicable(string $type): bool
    {
        $listing = $this->catalog->get($type);

        return $listing !== null && !$listing->singleton && $listing->users === null;
    }

    /**
     * The list as an .xlsx download (GET {type}/_export, the list's own search / filters / sort, optional
     * ids[]): the ticked records, else everything the list matches, not just its page. The file is
     * written to a temp file that is unlinked as soon as it is open for streaming.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type) || !$this->exporter()->available()) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        $typeDef = $this->registry->get($type);
        $listConfig = $this->catalog->get($type)?->list ?? [];
        $columns = $this->listColumns($typeDef, $listConfig);
        $exporter = $this->exporter();

        $params = $request->getQueryParams();
        if (array_key_exists('ids', $params)) {
            $records = $this->recordsByIds($type, self::idList($params['ids']));
            if ($records instanceof ResponseInterface) {
                return $records;
            }
        } else {
            // The list's own view: its status and "not checked" narrowing too, as the list shows it.
            $query = ListQuery::fromRequest($request, $typeDef, $listConfig, $typeDef->locales->default, $this->publishes($type));
            // The states only when a status is asked for (they read every snapshot of the type).
            $states = $query->status !== null ? $this->publisher->states($type) : null;
            $records = $exporter->records($type, $query, $query->narrowing(
                $typeDef->translatableFields() !== [] ? fn (DynamicRecord $record): bool => $this->hasUnchecked($typeDef, $record) : null,
                $states === null ? null : static fn (DynamicRecord $record): string => ($states[(string) $record->getId()] ?? PublishState::Draft)->value,
            ));
        }

        $path = tempnam(sys_get_temp_dir(), 'folio-export-');
        if ($path === false) {
            return new Response(500, [], FolioText::get('folio.errors.export_failed'));
        }
        try {
            $exporter->write($typeDef, $columns, $records, $path);
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return new Response(500, [], FolioText::get('folio.errors.export_failed'));
            }
            $size = (int) filesize($path);
        } finally {
            @unlink($path); // an open handle keeps reading on
        }

        return new Response(200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . (preg_replace('/[^A-Za-z0-9_-]+/', '-', $type) ?: 'export') . '-' . date('Y-m-d') . '.xlsx"',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'no-store',
        ], \Nyholm\Psr7\Stream::create($handle));
    }

    private function exporter(): ListExport
    {
        return $this->export ??= new ListExport($this->dm, $this->fieldTypes, new RelationLabels($this->dm, $this->labeler), labels: $this->labels);
    }

    public function recordLabel(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        $payload = json_encode(
            ['id' => $id, 'label' => $this->labeler->label($record)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], (string) $payload);
    }

    /**
     * Search endpoint of the relation picker and the list's relation filter: one page of the target
     * type's records as {items: [{id, label}], more}. "label" and "order" name fields of the target
     * (the relation's labelField / orderBy); only names a relation to the target declares are used,
     * never a hidden field, and anything else is ignored. Hidden fields are not searched either.
     */
    public function relationOptions(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $params = $request->getQueryParams();
        $text = static fn (string $key): ?string => is_string($params[$key] ?? null) && $params[$key] !== '' ? $params[$key] : null;
        $page = is_scalar($params['page'] ?? null) ? (int) $params['page'] : 1;

        $picker = $this->fieldTypes->has('relation') ? $this->fieldTypes->get('relation') : null;
        if (!$picker instanceof \Preflow\Folio\Field\Types\RelationFieldType) {
            $picker = new \Preflow\Folio\Field\Types\RelationFieldType($this->dm, $this->registry);
        }
        $payload = json_encode(
            $picker->page($type, $text('label'), $text('order'), mb_substr($text('q') ?? '', 0, 200), $page, $text('meta')),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], $payload);
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);
        $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
        $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

        $locale = $this->requestLocale($request, $this->registry->get($type));

        // The fields' declared defaults, so a new record's form starts from them (a boolean defaulting to true is checked).
        return $this->form($type, self::defaults($this->registry->get($type)), $action, FolioText::get('folio.form.new', ['label' => $this->singularFor($type)]), [], $csrf, 200, $layout, $locale, $this->prefix . '/' . $type . '/new');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $typeDef = $this->registry->get($type);
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);

        if (($refused = $this->refusePublish($request, $type)) !== null) {
            return $refused;
        }
        $locale = $this->requestLocale($request, $typeDef);
        $record = $this->drafts->build($typeDef, $request, [], $locale, $this->can('assets.upload'));
        if ($this->drafts->uploadErrors() !== []) {
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), FolioText::get('folio.form.new', ['label' => $this->singularFor($type)]), $this->drafts->uploadErrors(), $csrf, 422, $layout, $locale);
        }
        // The writer gives the new record its id and, in an orderable list, the last place.
        $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
        $failed = $this->saveForm($request, $record, $this->saveOptions($request, $type, publish: true), true, function (array $values, array $errors, int $status, bool $saved, ?string $notice, bool $reload) use ($type, $typeDef, $drawer, $csrf, $layout, $locale): ResponseInterface {
            if (!$saved) {
                // Nothing stored: the form creates again (the writer assigns a fresh id and place then).
                unset($values[$typeDef->idField], $values['_order']);

                return $this->form($type, $values, $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), FolioText::get('folio.form.new', ['label' => $this->singularFor($type)]), $errors, $csrf, $status, $layout, $locale);
            }
            // The draft is stored: a re-rendered form must update it, not create another.
            $base = $this->prefix . '/' . $type . '/' . $values[$typeDef->idField];

            return $this->form($type, $values, $base . ($drawer ? '?_drawer=1' : ''), FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), $errors, $csrf, $status, $layout, $locale, $base . '/edit',
                notice: $notice,
                reloadUrl: $reload ? $base . '/edit' : null,
            );
        });
        if ($failed !== null) {
            return $failed;
        }

        if ($drawer) {
            // The drawer's save hands the new record back to the editor below (drawer_saved.twig); a
            // _folio_next does not apply here: "Save and leave" is the full-page editor's.
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/drawer_saved.twig', [
                'type' => $type,
                'id' => $record->getId(),
            ]));
        }

        return new Response(302, ['Location' => self::leaveTo($request) ?? $this->prefix . '/' . $type . $this->localeQuery($typeDef, $locale)]);
    }

    public function editForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->form(
            $type,
            $record->toArray(),
            $this->prefix . '/' . $type . '/' . $id,
            FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]),
            [],
            $csrf,
            locale: $this->requestLocale($request, $this->registry->get($type)),
            editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit',
            notice: self::restoredNotice($request) ?? $this->confirmedNotice($request, $type),
        );
    }

    /**
     * "Still correct": the translations in a language (all, or those under the path) are marked as
     * made from the current default texts. Saves through the RecordWriter (the type's save hooks,
     * the schedule rule) with the version check; nothing is published.
     *
     * From the editor form (it posts _locale and the fields, the button names the path in
     * _confirm_path) the submitted values are saved together with the stamp, with the form's
     * current _version, so nothing typed is lost. Without form fields (an API-style post: locale,
     * optional path, _version) only the stamp is saved.
     */
    public function confirmTranslation(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        $typeDef = $this->registry->get($type);
        $current = $this->dm->findType($type, $id);
        $body = (array) $request->getParsedBody();
        $fromForm = is_string($body['_locale'] ?? null);
        $locale = $body['_locale'] ?? $body['locale'] ?? '';
        $locale = is_string($locale) ? $locale : '';
        if ($current === null || !$typeDef->locales->has($locale) || $locale === $typeDef->locales->default) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $path = $body['_confirm_path'] ?? $body['path'] ?? null;
        $path = is_string($path) && $path !== '' ? $path : null;
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $action = $this->prefix . '/' . $type . '/' . $id;
        $editUrl = $this->isSingleton($type) ? $this->prefix . '/' . $type : $action . '/edit';
        $heading = FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]);
        $done = new Response(302, ['Location' => $editUrl . '?locale=' . rawurlencode($locale) . '&confirmed=1']);

        if ($fromForm) {
            $built = $this->drafts->build($typeDef, $request, $current->toArray(), $locale, $this->can('assets.upload'));
            if ($this->drafts->uploadErrors() !== []) {
                return $this->form($type, $built->toArray(), $action, $heading, $this->drafts->uploadErrors(), $csrf, 422, locale: $locale, editUrl: $editUrl);
            }
            $record = $this->translations->confirm($typeDef, $built, $locale, $path);
            $failed = $this->saveForm($request, $record, $this->saveOptions($request, $type, publish: false), false,
                fn (array $values, array $errors, int $status, bool $saved, ?string $notice, bool $reload): ResponseInterface => $this->form($type, $values, $action, $heading, $errors, $csrf, $status, locale: $locale, editUrl: $editUrl, notice: $notice, reloadUrl: $reload ? $editUrl : null),
            );

            return $failed ?? $done;
        }

        $confirmed = $this->translations->confirm($typeDef, $current, $locale, $path);
        try {
            $outcome = $this->writer->save($confirmed, $this->saveOptions($request, $type, publish: false));
        } catch (VersionConflict $e) {
            $values = $current->toArray();
            $values['_version'] = $e->current;

            return $this->form($type, $values, $action, $heading, [], $csrf, 409, locale: $locale, editUrl: $editUrl,
                notice: FolioText::get('folio.form.conflict'), reloadUrl: $editUrl . '?locale=' . rawurlencode($locale));
        } catch (ValidationException $e) {
            // A #[BeforeSave] hook, the rules or the storage refused: nothing saved.
            return $this->form($type, $confirmed->toArray(), $action, $heading, $e->errors(), $csrf, 422, locale: $locale, editUrl: $editUrl);
        }
        $this->leftSite($request, $outcome->addressesLeft ? [$outcome->record] : []);

        return $done;
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $typeDef = $this->registry->get($type);
        $current = $this->dm->findType($type, $id);
        if ($current === null && !($this->isSingleton($type) && $id === $type)) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $existing = $current?->toArray() ?? [$typeDef->idField => $id]; // a singleton is created on first save

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        if (($refused = $this->refusePublish($request, $type)) !== null) {
            return $refused;
        }
        $locale = $this->requestLocale($request, $typeDef);
        $record = $this->drafts->build($typeDef, $request, $existing, $locale, $this->can('assets.upload'));
        if ($this->drafts->uploadErrors() !== []) {
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . '/' . $id, FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), $this->drafts->uploadErrors(), $csrf, 422, locale: $locale, editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit');
        }
        $action = $this->prefix . '/' . $type . '/' . $id;
        $failed = $this->saveForm($request, $record, $this->saveOptions($request, $type, publish: true), false,
            fn (array $values, array $errors, int $status, bool $saved, ?string $notice, bool $reload): ResponseInterface => $this->form($type, $values, $action, FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), $errors, $csrf, $status,
                locale: $locale,
                editUrl: $action . '/edit',
                notice: $notice,
                reloadUrl: $reload ? $action . '/edit' : null,
            ),
        );
        if ($failed !== null) {
            return $failed;
        }

        return new Response(302, ['Location' => self::leaveTo($request) ?? $this->prefix . '/' . $type . $this->localeQuery($typeDef, $locale)]);
    }

    /**
     * "Save and leave" (admin.js's unsaved-changes dialog) posts the link the editor was leaving for as
     * _folio_next: once the save succeeded, the answer goes there instead of the list. Only a path on
     * this site (SafeReturnPath); anything else is ignored. A refused save shows the form as always.
     */
    private static function leaveTo(ServerRequestInterface $request): ?string
    {
        $next = ((array) $request->getParsedBody())['_folio_next'] ?? null;

        return is_string($next) ? \Preflow\Core\Http\SafeReturnPath::check($next, ['/']) : null;
    }

    /**
     * Move a record one step up or down in an orderable list (POST direction=up|down), through the
     * RecordWriter: records without a position yet are numbered in their current order first, the
     * two records' save hooks run (a #[BeforeSave] refusal moves nothing and is flashed). At either
     * end of the list nothing moves.
     */
    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type) || ($this->catalog->get($type)?->list['orderable'] ?? false) !== true) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        if ($this->dm->findType($type, $id) === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $direction = (string) (((array) $request->getParsedBody())['direction'] ?? '');
        if ($direction === 'up' || $direction === 'down') {
            try {
                $this->writer->reorder($type, $id, $direction);
            } catch (ValidationException $e) {
                self::flash($request, 'error', self::errorText($e));
            } catch (\InvalidArgumentException) {
                // RecordTypes, not the catalog, says what is orderable: a site's own may disagree.
                return new Response(404, [], FolioText::get('folio.errors.not_found'));
            }
        }

        // Back to the page the move was made on.
        $page = (int) (((array) $request->getParsedBody())['page'] ?? 1);

        return new Response(302, ['Location' => $this->prefix . '/' . $type . ($page > 1 ? '?page=' . $page : '')]);
    }

    /**
     * "Duplicate" (POST {type}/{id}/duplicate): a copy of the record through
     * RecordWriter::duplicate(), a draft with a free address, then the copy's editor with a
     * "This is a copy of …" note. Needs the type's create and view permissions; never for a singleton or a
     * user type (duplicable()). A refusal (a hook, the rules) goes back to the list with its reason.
     */
    public function duplicate(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type) || !$this->duplicable($type)) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        // The route asks create (FolioPermissionMiddleware); a copy also shows the record, so view too.
        if (!$this->can($type . '.create') || !$this->can($type . '.view')) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        $source = $this->dm->findType($type, $id);
        if ($source === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        try {
            $outcome = $this->writer->duplicate($type, $id, new SaveOptions(by: self::userId($request), mayPublish: $this->can($type . '.publish')), $this->copyOverrides($source));
        } catch (ValidationException $e) {
            self::flash($request, 'error', self::errorText($e));

            return new Response(302, ['Location' => $this->prefix . '/' . $type]);
        } catch (Refused $e) {
            self::flash($request, 'error', $e->getMessage());

            return new Response(302, ['Location' => $this->prefix . '/' . $type]);
        } catch (\InvalidArgumentException) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        self::flash($request, 'success', FolioText::get('folio.list.duplicated', ['title' => $this->labeler->label($source)]));

        return new Response(302, ['Location' => $this->prefix . '/' . $type . '/' . rawurlencode((string) $outcome->record->getId()) . '/edit']);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if ($this->isSingleton($type)) {
            return new Response(404, [], FolioText::get('folio.errors.singleton_delete'));
        }
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        // Through the delete plan: cascades, detaches and restrictions of declared relations;
        // a restriction or any hook's refusal leaves everything in place.
        try {
            $this->deleter->delete($type, [$id], $this->removalTarget($request));
        } catch (DeleteRefused $e) {
            self::flash($request, 'error', $this->deleteSummary()->refusal($e));
        } catch (Refused $e) {
            self::flash($request, 'error', $e->getMessage());
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }

    /**
     * What deleting the posted ids[] would do, for the confirmation dialog:
     * {restricted, lines, confirm}. Only a summary: the delete computes its own plan.
     */
    public function deletePlan(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        $ids = self::postedIds($request);
        if ($ids === null || $ids === []) {
            return new Response(422, [], FolioText::get($ids === null ? 'folio.errors.too_many_records' : 'folio.errors.no_records'));
        }

        $payload = json_encode($this->deleteSummary()->summary($this->deleter->plan($type, $ids)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], $payload);
    }

    /** The delete confirmation and the reasons a delete is refused, in the editor's words. */
    private function deleteSummary(): DeleteSummary
    {
        return new DeleteSummary($this->labels, $this->addresses);
    }

    /** "Send its old links to…" of a delete (the posted _redirect_to); null: they answer 404. */
    private function removalTarget(ServerRequestInterface $request): ?\Preflow\Folio\Redirects\RemovalTarget
    {
        return \Preflow\Folio\Redirects\RemovalTarget::parse(((array) $request->getParsedBody())['_redirect_to'] ?? null, $this->frontend);
    }

    /**
     * Bulk delete (POST {type}/_bulk/delete, ids[]; the route asks {type}.delete): through the
     * delete plan, all or nothing. Back to the list's view with "Delete: done (N)" or the reason.
     */
    public function bulkDelete(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        if (($records = $this->postedRecords($request, $type)) instanceof ResponseInterface) {
            return $records;
        }

        try {
            $this->deleter->delete($type, array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $records), $this->removalTarget($request));
            self::flash($request, 'success', FolioText::get('folio.common.done_count', ['label' => FolioText::get('folio.common.delete'), 'count' => count($records)]));
        } catch (DeleteRefused $e) {
            self::flash($request, 'error', $this->deleteSummary()->refusal($e));
        } catch (Refused $e) {
            self::flash($request, 'error', $e->getMessage());
        }

        return new Response(302, ['Location' => $this->bulkReturn($request, $type)]);
    }

    /**
     * Any other bulk action (POST {type}/_bulk/{name}, ids[]; the route asks {type}.edit):
     * "set-{field}" for a select or boolean field the model's "list.bulk" names (value: one of
     * its options, '1'/'0' for a boolean), saved per record through the RecordWriter (as a form save); a failing
     * record is reported, the others are saved. Else the type's #[BulkAction] of that name,
     * for those with its permission. Ids of other types or without a record are ignored.
     */
    public function bulkAction(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $name = (string) $request->getAttribute('name', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        $typeDef = $this->registry->get($type);

        if (str_starts_with($name, 'set-')) {
            $field = $this->bulkFields($type, $typeDef)[substr($name, 4)] ?? null;
            if ($field === null) {
                return new Response(404, [], FolioText::get('folio.errors.unknown_action'));
            }
            $value = ((array) $request->getParsedBody())['value'] ?? null;
            if (!is_string($value) || !isset($field['options'][$value])) {
                return new Response(422, [], FolioText::get('folio.errors.not_a_value', ['label' => $field['label']]));
            }
            if (($records = $this->postedRecords($request, $type)) instanceof ResponseInterface) {
                return $records;
            }

            return $this->bulkSet($request, $type, $field, $value, $records);
        }

        $action = $this->customizations?->bulkAction($type, $name);
        if ($action === null) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_action'));
        }
        if (!$this->can($action['permission'])) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        if (($records = $this->postedRecords($request, $type)) instanceof ResponseInterface) {
            return $records;
        }

        $response = ($action['callable'])($records, $request);
        if ($response instanceof ResponseInterface) {
            return $response;
        }
        if ($response !== null) {
            throw new \Preflow\Folio\Extension\ExtensionException("Bulk action \"{$action['name']}\" of type \"{$type}\" must return a response or null, not " . get_debug_type($response) . '.');
        }
        self::flash($request, 'success', FolioText::get('folio.common.done_count', ['label' => $action['label'], 'count' => count($records)]));

        return new Response(302, ['Location' => $this->bulkReturn($request, $type)]);
    }

    /**
     * @param array{name: string, label: string, options: array<string, string>} $field
     * @param list<DynamicRecord> $records
     */
    private function bulkSet(ServerRequestInterface $request, string $type, array $field, string $value, array $records): ResponseInterface
    {
        $fieldDef = $this->registry->get($type)->fields[$field['name']];
        $fieldType = $this->fieldTypes->get($fieldDef->type);
        $stored = $fieldType->toStorage($fieldType->normalizeInput($value, $fieldDef->config));

        // Not from the post: a bulk set is no form (no loaded version, no schedule of its own).
        $options = new SaveOptions(by: self::userId($request), mayPublish: $this->can($type . '.publish'), kind: WriteKind::Bulk);
        $done = 0;
        $failed = [];
        $left = [];
        foreach ($records as $record) {
            try {
                $outcome = $this->writer->setField($record, $field['name'], $stored, $options);
                $done++;
                if ($outcome->addressesLeft) {
                    $left[] = $outcome->record;
                }
            } catch (ValidationException $e) {
                $failed[] = $this->labeler->label($record) . ' — ' . self::errorText($e);
            }
        }

        $this->leftSite($request, $left);
        $message = FolioText::get('folio.list.set_done', ['label' => $field['label'], 'count' => $done]);
        if ($failed !== []) {
            self::flash($request, 'error', FolioText::get('folio.list.failed', ['message' => $message, 'count' => count($failed), 'records' => implode('; ', $failed)]));
        } else {
            self::flash($request, 'success', $message);
        }

        return new Response(302, ['Location' => $this->bulkReturn($request, $type)]);
    }

    /**
     * The type's "set" bulk fields: those "list.bulk" names that are select or boolean
     * fields (not translatable), with the values they may be set to.
     *
     * @return array<string, array{name: string, label: string, options: array<string, string>}>
     */
    private function bulkFields(string $type, TypeDefinition $typeDef): array
    {
        $fields = [];
        foreach ((array) ($this->catalog->get($type)?->list['bulk'] ?? []) as $name) {
            $fieldDef = is_string($name) ? ($typeDef->fields[$name] ?? null) : null;
            if ($fieldDef === null || !in_array($fieldDef->type, ['select', 'boolean'], true) || $fieldDef->translatable
                || $name === $typeDef->idField || ($fieldDef->config['readonly'] ?? false) === true) {
                continue;
            }
            $fields[$name] = [
                'name' => $name,
                'label' => $this->labels->field($type, $name),
                'options' => $fieldDef->type === 'select' ? $this->labels->options($type, $name) : ['1' => FolioText::get('folio.common.yes'), '0' => FolioText::get('folio.common.no')],
            ];
        }

        return $fields;
    }

    /**
     * The list's bulk bar: set fields and extension actions for those who may edit, delete
     * for those who may delete; null when none is left (no tick column either).
     *
     * @param array<string, mixed> $listConfig
     * @return array{fields: list<array{label: string, url: string, options: array<string, string>}>, actions: list<array{label: string, url: string, confirm: ?string}>, translate: ?string, delete: ?array{url: string, plan: string}}|null
     */
    private function bulkBar(string $type, TypeDefinition $typeDef, array $listConfig): ?array
    {
        $translate = $this->aiRunUrl($type, $typeDef);
        $base = $this->prefix . '/' . $type . '/_bulk/';
        $fields = [];
        $actions = [];
        if ($this->can($type . '.edit')) {
            foreach ($this->bulkFields($type, $typeDef) as $name => $field) {
                $fields[] = ['label' => $field['label'], 'url' => $base . 'set-' . $name, 'options' => $field['options']];
            }
            foreach ($this->customizations?->bulkActions($type) ?? [] as $action) {
                if ($this->can($action['permission'])) {
                    $actions[] = ['label' => $action['label'], 'url' => $base . $action['name'], 'confirm' => $action['confirm']];
                }
            }
        }
        $delete = $this->can($type . '.delete') ? ['url' => $base . 'delete', 'plan' => $this->prefix . '/' . $type . '/_delete-plan'] : null;

        return $fields === [] && $actions === [] && $translate === null && $delete === null
            ? null
            : ['fields' => $fields, 'actions' => $actions, 'translate' => $translate, 'delete' => $delete];
    }

    /**
     * The AI run screen of a type ({type}/_ai/run), or null when it is not offered: the same
     * conditions as the edit form's Translate button (AI on, something to translate into another
     * language, ai.translate and the type's edit permission).
     */
    private function aiRunUrl(string $type, TypeDefinition $typeDef): ?string
    {
        if (!($this->translator?->available() ?? false) || !$this->translations->translatable($typeDef)
            || !$this->can('ai.translate') || !$this->can($type . '.edit')) {
            return null;
        }

        return $this->prefix . '/' . $type . '/_ai/run';
    }

    /**
     * The posted ids[] (strings, each once); null when there are more than RecordDeleter::MAX_IDS.
     *
     * @return list<string>|null
     */
    private static function postedIds(ServerRequestInterface $request): ?array
    {
        return self::idList(((array) $request->getParsedBody())['ids'] ?? null);
    }

    /**
     * An ids[] value as a list of strings, each once, without ids that are not usable
     * (RecordId::usable: a path or invalid UTF-8); null when there are more than RecordDeleter::MAX_IDS.
     *
     * @return list<string>|null
     */
    private static function idList(mixed $ids): ?array
    {
        $ids = is_array($ids) ? array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && RecordId::usable($id)))) : [];

        return count($ids) > RecordDeleter::MAX_IDS ? null : $ids;
    }

    /**
     * The stored records of $type the posted ids[] name, in the order posted, each read
     * directly (never from a query cache: bulk paths change and save whole records);
     * a 422 when there are none or too many ids.
     *
     * @return list<DynamicRecord>|ResponseInterface
     */
    private function postedRecords(ServerRequestInterface $request, string $type): array|ResponseInterface
    {
        return $this->recordsByIds($type, self::postedIds($request), fresh: true);
    }

    /**
     * The stored records of $type for these ids, in the order given (one query, or with
     * $fresh one direct read per id); a 422 when there are none or too many ids. Ids that
     * name no record are dropped.
     *
     * @param list<string>|null $ids null: too many
     * @return list<DynamicRecord>|ResponseInterface
     */
    private function recordsByIds(string $type, ?array $ids, bool $fresh = false): array|ResponseInterface
    {
        if ($ids === null) {
            return new Response(422, [], FolioText::get('folio.errors.too_many_records'));
        }
        $found = [];
        if ($fresh) {
            foreach ($ids as $id) {
                $record = $this->dm->findType($type, $id);
                if ($record !== null) {
                    $found[$id] = $record;
                }
            }
        } elseif ($ids !== []) {
            $idField = $this->registry->get($type)->idField;
            foreach ($this->dm->queryType($type)->whereAny([['field' => $idField, 'operator' => 'IN', 'value' => $ids]])->get()->items() as $record) {
                $found[(string) $record->getId()] = $record;
            }
        }
        $records = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $records[] = $found[$id];
            }
        }

        return $records === [] ? new Response(422, [], FolioText::get('folio.errors.no_records')) : $records;
    }

    /**
     * The list URL to return to: the posted "_query" read as list parameters only (search,
     * filters, sort, page) and rebuilt, so nothing but the list's own view is ever followed.
     */
    private function bulkReturn(ServerRequestInterface $request, string $type): string
    {
        return ListQuery::fromPosted($request, $this->registry->get($type), $this->catalog->get($type)?->list ?? [], $this->publishes($type))
            ->url($this->prefix . '/' . $type);
    }

    /**
     * An extension's #[RecordAction] (POST {type}/{id}/_action/{name}), for those with its
     * permission (the route asks {type}.edit). A null result returns to the form with "{label}: done".
     */
    public function recordAction(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        $action = $this->catalog->has($type) ? $this->customizations?->action($type, (string) $request->getAttribute('name', '')) : null;
        if ($action === null) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_action'));
        }
        if (!$this->can($action['permission'])) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        $response = ($action['callable'])($record, $request);
        if ($response instanceof ResponseInterface) {
            return $response;
        }
        if ($response !== null) {
            throw new \Preflow\Folio\Extension\ExtensionException("Record action \"{$action['name']}\" of type \"{$type}\" must return a response or null, not " . get_debug_type($response) . '.');
        }
        self::flash($request, 'success', FolioText::get('folio.common.done', ['label' => $action['label']]));

        return new Response(302, ['Location' => $this->prefix . '/' . $type . ($this->isSingleton($type) ? '' : '/' . $id . '/edit')]);
    }

    /** @param array<string, mixed> $values @param array<string, list<string>> $errors */
    private function form(string $type, array $values, string $action, string $heading, array $errors, string $csrf = '', int $status = 200, string $layout = '@folio/admin/_layout.twig', ?string $locale = null, ?string $editUrl = null, ?string $notice = null, ?string $reloadUrl = null): ResponseInterface
    {
        $typeDef = $this->registry->get($type);
        $locales = $typeDef->locales;
        $locale = $locale !== null && $locales->has($locale) ? $locale : $locales->default;
        $translatable = array_flip($typeDef->translatableFields());
        $multilingual = $translatable !== [];
        $fields = [];
        $editorAssets = [];
        $multipart = false;
        $isNew = strtok($action, '?') === $this->prefix . '/' . $type;
        $current = $multilingual && !$isNew ? DynamicRecord::fromArray($typeDef, $values) : null;
        // Translations of this language made from a default text that has changed since, per field.
        $outdated = [];
        if ($current !== null && $locale !== $locales->default) {
            foreach ($this->translations->outdated($typeDef, $current, $locale) as $text) {
                $field = explode('.', $text->path, 2)[0];
                $outdated[$field] = ['count' => ($outdated[$field]['count'] ?? 0) + 1, 'plain' => $text->path === $field];
            }
        }
        // Machine translations of this language nobody has checked yet, per field.
        $unchecked = [];
        if ($current !== null && $locale !== $locales->default) {
            foreach ($this->translations->unchecked($typeDef, $current, $locale) as $text) {
                $field = explode('.', $text->path, 2)[0];
                $unchecked[$field] = ['count' => ($unchecked[$field]['count'] ?? 0) + 1, 'plain' => $text->path === $field];
            }
        }
        // The fields the public address is built from: admin.js keeps them to a-z, digits and hyphens.
        $addressFields = $this->frontend->routeFor($type)?->addressModes() ?? [];
        // The editor frame (the full page, not the create drawer): the side panel takes the address
        // fields, the "panel": "side" fields and the read-only ones (System fields).
        $framed = $layout !== '@folio/admin/_drawer_layout.twig';
        $systemFields = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField || ($fieldDef->config['hidden'] ?? false) === true) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if (($fieldDef->config['readonly'] ?? false) === true) {
                $stored = isset($translatable[$name]) ? LocaleValue::pick($values[$name] ?? null, $locales, $locale) : LocaleValue::default($values[$name] ?? null, $locales);
                $display = $this->readonlyDisplay(
                    $fieldDef,
                    // nothing stored stays nothing (fromStorage would turn a missing boolean into false)
                    $stored === null || $stored === '' ? $stored : $fieldType->fromStorage($stored),
                    $this->fieldConfig($type, $name, $fieldDef),
                );
                if ($framed) {
                    // The editor frame lists read-only fields under the side panel's "System fields".
                    $systemFields[] = [
                        'name' => $name,
                        'label' => $this->labels->fieldText($type, $name) ?? (new \Preflow\Form\FieldRenderer())->humanize($name),
                        'help' => $this->labels->help($type, $name),
                        'html' => $display,
                    ];
                    continue;
                }
                $fields[] = [
                    'name' => $name,
                    'html' => self::readonlyField($name, $this->labels->fieldText($type, $name), $this->labels->help($type, $name), $display),
                    'translatable' => isset($translatable[$name]),
                    'structured' => false,
                    'falls_back' => false,
                    'reference' => null,
                    'placement' => 'main',
                    'half' => false,
                ];
                continue;
            }
            if ($fieldType instanceof HandlesUpload || $fieldType instanceof BlocksFieldType) {
                $multipart = true; // blocks may contain upload fields
            }

            $isTranslatable = isset($translatable[$name]) && !$fieldType instanceof HandlesUpload;
            $raw = $values[$name] ?? null;
            $placeholder = null;
            $reference = null;
            $fallsBack = false;
            if ($isTranslatable) {
                $value = LocaleValue::pick($raw, $locales, $locale);
                if ($locale !== $locales->default) {
                    [$resolved] = LocaleValue::resolve($raw, $locales, $locale);
                    $reference = self::plain(LocaleValue::pick($raw, $locales, $locales->default));
                    $fallsBack = ($value === null || $value === '') && $resolved !== null;
                    $placeholder = $fallsBack ? mb_substr(self::plain($resolved), 0, 120) : null;
                }
            } else {
                $value = LocaleValue::default($raw, $locales);
            }
            // What an empty field means, as the type's customization words it (#[FieldPlaceholder]).
            if (($own = $this->customizations?->placeholder($type, $name, $locale)) !== null) {
                $placeholder = $own;
                $fallsBack = false;
            }

            $fieldErrors = $errors[$name] ?? [];
            if ($locale !== $locales->default) {
                $fieldErrors = [...($errors[$name . '.' . $locale] ?? []), ...$fieldErrors];
            }

            $ctx = new FieldContext(
                name: $name,
                label: $this->labels->fieldText($type, $name),
                help: $this->labels->help($type, $name),
                value: $fieldType->fromStorage($value),
                errors: $fieldErrors,
                config: $this->fieldConfig($type, $name, $fieldDef),
                required: in_array('required', $fieldDef->validate, true) && (!$isTranslatable || $locale === $locales->default),
                placeholder: $placeholder,
                nestedErrors: self::nestedErrors($errors, $name),
                locale: $locale,
                locales: $locales,
                record: ['type' => $type, 'id' => isset($values[$typeDef->idField]) ? (string) $values[$typeDef->idField] : null],
                validate: $fieldDef->validate,
                length: $fieldDef->length,
                address: isset($addressFields[$name]) ? $this->addressAffix($type, $name, $locale, $locales) : null,
            );
            // main: the form column; address / side: the side panel (outside the <form>, so its
            // controls name the record form with form="folio-record").
            $placement = !$framed ? 'main' : (isset($addressFields[$name]) ? 'address' : (($fieldDef->config['panel'] ?? 'main') === 'side' ? 'side' : 'main'));
            $html = $fieldType->renderEditor($ctx);
            $fields[] = [
                'name' => $name,
                'html' => $placement === 'main' ? $html : FormOwner::assign($html, self::RECORD_FORM),
                'translatable' => $isTranslatable,
                // Blocks hold their own (possibly translated) leaves: no "shared" label.
                'structured' => $fieldType instanceof BlocksFieldType,
                'falls_back' => $fallsBack,
                'reference' => $reference,
                'address' => $addressFields[$name] ?? null,
                'placement' => $placement,
                'half' => ($fieldDef->config['width'] ?? 'full') === 'half',
            ];
            foreach ($fieldType->assets() as $asset) {
                $editorAssets[$asset] = true;
            }
        }

        foreach ($fields as &$entry) {
            $entry['outdated'] = $outdated[$entry['name']] ?? null;
            $entry['unchecked'] = $unchecked[$entry['name']] ?? null;
        }
        unset($entry);

        // Errors of other languages (keys "field.<locale>"), so a save that fails
        // because of another language's value says where to look.
        $localeErrors = [];
        foreach ($errors as $key => $messages) {
            if (!is_string($key) || !str_contains($key, '.')) {
                continue;
            }
            [$field, $code] = explode('.', $key, 2);
            // "_translations.<locale>": a language the record is visible in but has not finished (shown on its own tab too).
            $incomplete = $field === '_translations';
            if (!$locales->has($code) || ($code === $locale && !$incomplete) || (!$incomplete && !isset($typeDef->fields[$field]))) {
                continue;
            }
            $localeErrors[] = [
                'language' => self::languageName($code),
                'url' => ($editUrl ?? $action) . '?locale=' . rawurlencode($code),
                'field' => $incomplete ? '' : $this->labels->field($type, $field),
                'messages' => (array) $messages,
            ];
        }

        $languages = [];
        if ($multilingual) {
            // How far the record is in each language (TranslationStatus::state()): the topbar's
            // language dots and the side panel's bars. A new record counts from its form values.
            $stateOf = $current ?? DynamicRecord::fromArray($typeDef, $values);
            foreach ($locales->available as $code) {
                $state = $this->translations->state($typeDef, $stateOf, $code);
                $languages[] = [
                    'code' => $code,
                    'name' => self::languageName($code),
                    'url' => ($editUrl ?? $action) . '?locale=' . rawurlencode($code),
                    'active' => $code === $locale,
                    'state' => $state,
                    // The share of the default language's texts this one has (the bar's fill).
                    'share' => $state->total > 0 ? max(0.0, min(1.0, ($state->total - $state->missing) / $state->total)) : ($state->completeness === Completeness::Missing ? 0.0 : 1.0),
                    'missing' => $state->missing > 0 ? ['count' => $state->missing, 'text' => FolioText::get('folio.list.missing', ['locale' => $code, 'count' => $state->missing])] : null,
                    'outdated' => $state->outdated > 0 ? ['count' => $state->outdated, 'text' => FolioText::get('folio.list.outdated', ['locale' => $code, 'count' => $state->outdated])] : null,
                    'unchecked' => $state->unchecked > 0 ? ['count' => $state->unchecked, 'text' => FolioText::get('folio.list.unchecked', ['count' => $state->unchecked])] : null,
                ];
            }
        }

        [$panels, $recordActions, $panelAssets] = $this->recordExtras($type, $typeDef, $values, $isNew, $csrf);
        $aiTranslate = $this->aiTranslate($type, $typeDef, $values, $locale, $isNew, $multilingual);
        if ($aiTranslate !== null) {
            $editorAssets += ['ai-translate.css' => true, 'ai-translate-core.js' => true, 'ai-translate.js' => true];
        }
        $aiPending = $isNew ? [] : $this->aiPending($type, $typeDef, $values);
        // A type without translations keeps its share texts under the default language (DraftRecordFactory).
        $seoSection = $this->seoSection($type, $values, $locale, $multilingual ? $locale : $locales->default, $errors[SeoSection::KEY] ?? [], $locales);
        $multipart = $multipart || $seoSection !== null; // the share image may be uploaded
        $recordId = isset($values[$typeDef->idField]) ? (string) $values[$typeDef->idField] : '';
        $singleton = $this->isSingleton($type);
        $publishing = $this->publishingContext($type, $typeDef, $values);
        $html = $this->engine->render('@folio/admin/form.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            // Read-only form (disabled fieldset, no save buttons) without the right to save it.
            'can_save' => $this->can($type . ($isNew ? '.create' : '.edit')),
            'can_publish' => $this->can($type . '.publish'),
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'heading' => $heading,
            'record_title' => $editUrl !== null && !str_ends_with($editUrl, '/new') ? self::recordTitle($values, $locales) : null,
            'action' => $action,
            'record_id' => isset($values[$typeDef->idField]) ? rawurlencode((string) $values[$typeDef->idField]) : '',
            'csrf' => $csrf,
            'fields' => $fields,
            // editor-frame.js: the save state, the Publish split button, the side panel, the editor's keys.
            // The order is admin.js, then fields.js, then editor-frame.js: fields.js uses what admin.js shares
            // (folioT, Folio.copyLater, fillAssetField, ownControls), editor-frame.js uses Folio.relativeTime.
            // The full layout loads admin.js itself and skips it here; the drawer layout loads only this list.
            'editor_assets' => array_keys(['admin.js' => true] + $editorAssets + ($this->publishes($type) ? ['htmx.js' => true] : []) + ['fields.js' => true, 'editor-frame.js' => true]),
            'multipart' => $multipart,
            'layout' => $layout,
            // No preview in the create drawer: its form posts to the save route.
            // A read-only form has nothing to preview (and its user may not post to the preview route).
            'previewable' => $this->frontend->routeFor($type) !== null && $layout !== '@folio/admin/_drawer_layout.twig'
                && $this->can($type . ($isNew ? '.create' : '.edit')),
            'preview_url' => strtok($action, '?') . '/preview',
            'preview_bar' => $this->previewBar($type, $typeDef, $values, $locale, $isNew, $publishing),
            'multilingual' => $multilingual,
            'languages' => $languages,
            'locale_errors' => $localeErrors,
            'locale' => $locale,
            'default_locale' => $locales->default,
            'default_language' => self::languageName($locales->default),
            'available_in' => DynamicRecord::fromArray($typeDef, $values)->availableLocales(),
            'publishing' => $publishing,
            'notice' => $notice,
            'reload_url' => $reloadUrl,
            'panels' => $panels,
            'record_actions' => $recordActions,
            'page_assets' => $panelAssets,
            'ai_translate' => $aiTranslate,
            'ai_pending' => $aiPending,
            'seo_section' => $seoSection,
            'old_addresses' => $isNew ? null : $this->oldAddressesNote($type, (string) ($values[$typeDef->idField] ?? '')),
            // The editor frame (0.75): topbar, main column rows and the side panel.
            'framed' => $framed,
            'record_form' => self::RECORD_FORM,
            'singleton' => $singleton,
            'back_url' => $framed && !$singleton ? $this->prefix . '/' . $type : null,
            'type_count' => $framed && !$singleton ? $this->dm->queryType($type)->paginate(1, 1)->total : null,
            'field_rows' => self::fieldRows($fields),
            'system_fields' => $framed ? $systemFields : [],
            'system_id' => $framed && !$isNew && $recordId !== '' && !$singleton ? $recordId : null,
            'search_result' => $framed && !$isNew ? $this->searchResult($type, $typeDef, $values, $locale) : null,
            'redirects_on' => $this->oldAddresses !== null,
            'delete' => $framed && !$isNew && !$singleton && $recordId !== '' && $this->can($type . '.delete') ? [
                'url' => $this->prefix . '/' . $type . '/' . rawurlencode($recordId) . '/delete',
                'plan' => $this->prefix . '/' . $type . '/_delete-plan',
                'id' => $recordId,
                'label' => self::recordTitle($values, $locales) ?? $this->labelFor($type),
            ] : null,
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /** The editor note shows at most this many old addresses, then how many more. */
    private const int OLD_ADDRESSES_SHOWN = 10;

    /**
     * The editor's "Old addresses" note: the addresses that redirect to the record, as visitors
     * see them, and the link to its redirects for those who may manage them; null when none.
     *
     * @return array{shown: list<string>, more: int, url: ?string}|null
     */
    private function oldAddressesNote(string $type, string $id): ?array
    {
        $addresses = $id === '' || $this->oldAddresses === null ? [] : $this->oldAddresses->of($type, $id);
        if ($addresses === []) {
            return null;
        }

        return [
            'shown' => array_slice($addresses, 0, self::OLD_ADDRESSES_SHOWN),
            'more' => max(0, count($addresses) - self::OLD_ADDRESSES_SHOWN),
            'url' => $this->can('redirects.manage') ? $this->prefix . '/redirects?record=' . rawurlencode($type) . ':' . rawurlencode($id) : null,
        ];
    }

    /** The record form's id: the topbar buttons and the side panel's record controls name it (form="…"). @since 0.75 */
    public const string RECORD_FORM = 'folio-record';

    /**
     * The main column's rows: each field on its own, except that consecutive "width": "half"
     * fields share a row (two side by side where there is room).
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array{half: bool, fields: list<array<string, mixed>>}>
     */
    private static function fieldRows(array $fields): array
    {
        $rows = [];
        foreach ($fields as $field) {
            if (($field['placement'] ?? 'main') !== 'main') {
                continue;
            }
            $half = ($field['half'] ?? false) === true;
            $last = array_key_last($rows);
            if ($half && $last !== null && $rows[$last]['half'] && count($rows[$last]['fields']) < 2) {
                $rows[$last]['fields'][] = $field;
                continue;
            }
            $rows[] = ['half' => $half, 'fields' => [$field]];
        }

        return $rows;
    }

    /**
     * The preview bar's address (board PageEditor): the edited copy's address as "host/path" (the
     * host of app.url; without one, the path), "· draft" while it differs from what is live, and
     * the live address for Open (only while the record is on the site: a publishing type's live
     * copy, at its own address; another type's stored record). Null address: the record has none in
     * this language yet (a route parameter without a value), so the bar offers no copy or open.
     *
     * @param array<string, mixed>      $values
     * @param array<string, mixed>|null $publishing publishingContext()
     * @return array{address: ?string, url: ?string, draft: bool, live_url: ?string, copied: string}|null
     */
    private function previewBar(string $type, TypeDefinition $typeDef, array $values, string $locale, bool $isNew, ?array $publishing): ?array
    {
        if ($this->frontend->routeFor($type) === null) {
            return null;
        }
        $base = rtrim($this->siteUrl ?? '', '/');
        $host = $this->siteUrl !== null ? (string) parse_url($this->siteUrl, PHP_URL_HOST) : '';
        $path = $this->frontend->urlFor(DynamicRecord::fromArray($typeDef, $values), $locale);

        $livePath = null;
        $id = $values[$typeDef->idField] ?? null;
        if (!$isNew && is_scalar($id) && (string) $id !== '') {
            if ($publishing === null) {
                $livePath = $path; // saved is live
            } elseif (($publishing['state'] ?? null) !== PublishState::Draft->value) {
                $snapshot = $this->publisher->query($type)->byIds([(string) $id])->get()->items()[0] ?? null;
                // The snapshot as a record of the type itself, so its address is the type's route.
                $livePath = $snapshot !== null ? $this->frontend->urlFor(DynamicRecord::fromArray($typeDef, $snapshot->toArray()), $locale) : null;
            }
        }
        $days = intdiv($this->previewLinks?->ttl() ?? 0, 86400);

        return [
            'address' => $path !== null ? ($host !== '' ? $host . $path : $path) : null,
            'url' => $path !== null ? $base . $path : null,
            'draft' => $publishing !== null && ($publishing['state'] ?? null) !== PublishState::Published->value,
            'live_url' => $livePath !== null ? $base . $livePath : null,
            'copied' => FolioText::choice('folio.form.link_copied_days', $days, ['days' => $days]),
        ];
    }

    /**
     * The side panel's search result: the page's title and description as search engines get
     * them (FolioPageMeta, from the stored values: the share texts, else the type's fields, else
     * the site's description) and its address as "host › path". Null without SEO or a frontend route.
     *
     * @param array<string, mixed> $values
     * @return array{title: string, address: string, description: string}|null
     */
    private function searchResult(string $type, TypeDefinition $typeDef, array $values, string $locale): ?array
    {
        if ($this->pageMeta === null || !($this->seo?->enabled() ?? false) || $this->frontend->routeFor($type) === null) {
            return null;
        }
        $record = DynamicRecord::fromArray($typeDef, $values);
        $path = $this->frontend->urlFor($record, $locale);
        $base = rtrim($this->siteUrl ?? '', '/');
        $meta = $this->pageMeta->for($record, $locale, $base . ($path ?? '/'), []);
        $host = $this->siteUrl !== null ? (string) parse_url($this->siteUrl, PHP_URL_HOST) : '';
        $parts = array_values(array_filter(explode('/', (string) $path), static fn (string $part): bool => $part !== ''));

        return [
            'title' => $meta->title !== '' ? $meta->title : (self::recordTitle($values, $typeDef->locales) ?? $this->labelFor($type)),
            'address' => implode(' › ', $host !== '' ? [$host, ...$parts] : ($parts === [] ? ['/'] : $parts)),
            'description' => $meta->description,
        ];
    }

    /**
     * The "SEO & sharing" section's values, or null when it is not shown: SEO is off, or the
     * type has no frontend route and is not the site singleton. Texts are in $textLocale (the
     * edited one, or the default for a type without translations).
     *
     * @param array<string, mixed> $values
     * @param list<string>         $errors the share image's refused uploads
     * @return array{open: bool, title: string, description: string, image: ?string, noindex: bool, exclude_sitemap: bool, exclude_llms: bool}|null
     */
    private function seoSection(string $type, array $values, string $locale, string $textLocale, array $errors, \Preflow\Data\Locales $locales): ?array
    {
        if (!($this->seo?->enabled() ?? false)
            || ($this->frontend->routeFor($type) === null && !($type === 'site' && $this->isSingleton($type)))) {
            return null;
        }
        $seo = SeoSection::fromValue($values[SeoSection::KEY] ?? null);
        $assetType = $this->fieldTypes->has('asset') ? $this->fieldTypes->get('asset') : null;

        return [
            'open' => !$seo->isEmpty(),
            // A refused share image: the editor opens on the SEO tab and marks it.
            'error' => $errors !== [],
            'title' => $seo->title($textLocale),
            'description' => $seo->description($textLocale),
            // The asset field's own editor (picker and upload), posting _seo[image_ids][] and _seo[image].
            'image' => $assetType?->renderEditor(new FieldContext(
                name: SeoSection::KEY . '[image]',
                label: FolioText::get('folio.seo.image'),
                value: $seo->image(),
                errors: $errors,
                config: SeoSection::IMAGE_CONFIG,
                locale: $locale,
                locales: $locales,
            )),
            'noindex' => $seo->noindex(),
            'exclude_sitemap' => !$seo->inSitemap(),
            'exclude_llms' => !$seo->inLlms(),
        ];
    }

    /**
     * The Translate button's settings for a stored record's translation tab, or null when the
     * button is not shown: AI is off, the type has nothing to translate, the tab is the default
     * language (the source), or the user lacks ai.translate or the type's edit permission.
     *
     * @param array<string, mixed> $values
     * @return array{estimate_url: string, queue_url: string, locale: string, language: string, intro: string, live: bool}|null
     */
    private function aiTranslate(string $type, TypeDefinition $typeDef, array $values, string $locale, bool $isNew, bool $multilingual): ?array
    {
        if (!($this->translator?->available() ?? false) || $isNew || !$multilingual || !$typeDef->locales->isMultilingual()
            || $locale === $typeDef->locales->default || !isset($values[$typeDef->idField])
            || !$this->can('ai.translate') || !$this->can($type . '.edit')) {
            return null;
        }
        $base = $this->prefix . '/' . $type . '/' . rawurlencode((string) $values[$typeDef->idField]) . '/_ai/';

        return [
            'estimate_url' => $base . 'estimate',
            'queue_url' => $base . 'queue',
            'locale' => $locale,
            'language' => self::languageName($locale),
            'intro' => FolioText::get('folio.ai_queue.intro'),
            // Without publishing the texts go live: the dialog asks for that confirmation.
            'live' => !$this->publishes($type),
        ];
    }

    /**
     * One line per language a queued translation job will still translate this record into, with
     * how many texts are through while it is being translated (the job states those languages;
     * the form does not block editing: texts changed meanwhile are left alone by the job's write).
     * None while AI is off.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function aiPending(string $type, TypeDefinition $typeDef, array $values): array
    {
        $id = $values[$typeDef->idField] ?? null;
        if (!($this->translator?->available() ?? false) || $this->jobs === null || !is_string($id) || $id === '' || !$typeDef->locales->isMultilingual()) {
            return [];
        }
        $lines = [];
        $format = new AdminFormat();
        foreach ($typeDef->locales->available as $code) {
            if ($code === $typeDef->locales->default) {
                continue;
            }
            $job = $this->jobs->pendingFor($type, $id, $code);
            // How far it is while a driver translates this record into this language right now.
            $progress = $job?->items[$id]['progress'] ?? null;
            if ($job !== null && is_array($progress) && ($progress['locale'] ?? null) === $code && (int) ($progress['total'] ?? 0) > 0) {
                $lines[] = FolioText::get('folio.ai_queue.pending_running', [
                    'language' => self::languageName($code),
                    'done' => min((int) ($progress['done'] ?? 0), (int) $progress['total']),
                    'total' => (int) $progress['total'],
                ]);
            } elseif ($job !== null) {
                $lines[] = FolioText::get('folio.ai_queue.pending', [
                    'language' => self::languageName($code),
                    'since' => $format->date($job->queuedAt, 'datetime') ?: '—',
                ]);
            }
        }

        return $lines;
    }

    /**
     * Extension panels (#[FormPanel], rendered) and record action buttons (#[RecordAction],
     * those the user may run: the action's permission and {type}.edit) of a stored record's edit form; none on create.
     *
     * @param array<string, mixed> $values
     * The third element is the panels' merged assets (PageAssets shape; null when none list any).
     *
     * @return array{list<array{label: string, position: string, html: string}>, list<array{label: string, url: string, icon: ?string, confirm: ?string}>, ?array{styles: list<string>, scripts: list<string>, modules: list<string>, htmx: bool}}
     */
    private function recordExtras(string $type, TypeDefinition $typeDef, array $values, bool $isNew, string $csrf): array
    {
        $custom = $this->customized($type);
        $id = $values[$typeDef->idField] ?? null;
        if ($isNew || ($custom['panels'] === [] && $custom['actions'] === []) || !is_string($id) || $id === ''
            || ($record = $this->dm->findType($type, $id)) === null) {
            return [[], [], null];
        }

        $panels = [];
        $assets = null;
        foreach ($custom['panels'] as $panel) {
            $result = ($panel['callable'])($record);
            if ($result === null) {
                continue; // null hides the panel: no heading, no box, no assets
            }
            if ($result instanceof ScreenView && $result->assets !== [] && $this->pageAssets !== null) {
                $own = $this->pageAssets->resolve($panel['extension'], $result->assets, $panel['owner']);
                $assets ??= ['styles' => [], 'scripts' => [], 'modules' => [], 'htmx' => false];
                foreach (['styles', 'scripts', 'modules'] as $kind) {
                    $assets[$kind] = array_values(array_unique([...$assets[$kind], ...$own[$kind]]));
                }
                $assets['htmx'] = $assets['htmx'] || $own['htmx'];
            }
            $panels[] = [
                'label' => $panel['label'],
                'position' => $panel['position'],
                'html' => $result instanceof ScreenView
                    ? $result->render($this->engine, ['prefix' => $this->prefix, 'csrf' => $csrf, 'type' => $type, 'id' => $id, 'record' => $record->toArray()], "Panel \"{$panel['label']}\" of type \"{$type}\"")
                    : htmlspecialchars((string) $result, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
        $actions = [];
        foreach ($custom['actions'] as $action) {
            if ($action['toolbar'] && $this->can($action['permission']) && $this->can($type . '.edit')) {
                $actions[] = [
                    'label' => $action['label'],
                    'url' => $this->prefix . '/' . $type . '/' . rawurlencode($id) . '/_action/' . $action['name'],
                    'icon' => $action['icon'],
                    'confirm' => $action['confirm'],
                ];
            }
        }

        return [$panels, $actions, $assets];
    }

    /** @return array{panels: list<array<string, mixed>>, actions: list<array<string, mixed>>, columns: list<array<string, mixed>>, hooks: array<string, list<\Closure>>} */
    private function customized(string $type): array
    {
        return $this->customizations?->forType($type) ?? ['panels' => [], 'actions' => [], 'bulk' => [], 'columns' => [], 'hooks' => [], 'placeholders' => []];
    }

    /** @param string|array{url: string, label: string} $message a text, or the link of a "{type}_link" flash */
    private static function flash(ServerRequestInterface $request, string $type, string|array $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash($type, $message);
        }
    }

    /** Whether the record holds a machine translation nobody has checked yet, in any language. */
    private function hasUnchecked(TypeDefinition $typeDef, DynamicRecord $record): bool
    {
        foreach ($typeDef->locales->available as $locale) {
            if ($locale !== $typeDef->locales->default && $this->translations->unchecked($typeDef, $record, $locale) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language indicator of a list row: every language of the type, in its order, with how far
     * the record is in it (TranslationStatus::state(): complete, partial or missing, and its
     * outdated and not checked translations). A language the record is not visible in is missing.
     *
     * @return list<array{code: string, name: string, state: \Preflow\Folio\Content\LocaleState}>
     */
    private function languageStates(TypeDefinition $typeDef, DynamicRecord $record): array
    {
        $states = [];
        foreach ($typeDef->locales->available as $locale) {
            $states[] = ['code' => $locale, 'name' => self::languageName($locale), 'state' => $this->translations->state($typeDef, $record, $locale)];
        }

        return $states;
    }

    /**
     * The form's save through the RecordWriter: a new record is created (its id, its place in an
     * orderable list), an existing one saved; a record that leaves the site with it is flashed
     * (leftSite()). null when saved, else $again shows the form again: on a version conflict (409,
     * with the stored version, so saving again overwrites, deliberately; $reload offers the stored
     * one) or a refusal (422: a #[BeforeSave] hook, the rules, what the storage refuses), both with
     * nothing stored; or, the draft stored (PublishFailed), a failed publish: 422 with the "not
     * published" notice, 409 when a save landed in between. $saved says whether the record is
     * stored by now, so a new record's form updates it instead of creating another.
     *
     * @param \Closure(array<string, mixed> $values, array<string, mixed> $errors, int $status, bool $saved, ?string $notice, bool $reload): ResponseInterface $again
     */
    private function saveForm(ServerRequestInterface $request, DynamicRecord $record, SaveOptions $options, bool $new, \Closure $again): ?ResponseInterface
    {
        try {
            $outcome = $new ? $this->writer->create($record, $options) : $this->writer->save($record, $options);
        } catch (PublishFailed $e) {
            $cause = $e->cause();
            if ($cause instanceof VersionConflict) {
                return $again([...$e->outcome->record->toArray(), '_version' => $cause->current], [], 409, true, FolioText::get('folio.form.conflict'), true);
            }

            return $again($e->outcome->record->toArray(), $cause->errors(), 422, true, FolioText::get('folio.form.not_published'), false);
        } catch (VersionConflict $e) {
            return $again([...$record->toArray(), '_version' => $e->current], [], 409, false, FolioText::get('folio.form.conflict'), true);
        } catch (ValidationException $e) {
            return $again($record->toArray(), $e->errors(), 422, false, null, false);
        }
        $this->leftSite($request, $outcome->addressesLeft ? [$outcome->record] : []);

        return null;
    }

    /**
     * How a post saves: by the user, under their right to publish (without it, a pending schedule
     * is cancelled), published when "_action" asks and $publish allows (refusePublish() refused
     * those who may not), against the loaded "_version", scheduled by "_publish_at" when posted.
     */
    private function saveOptions(ServerRequestInterface $request, string $type, bool $publish): SaveOptions
    {
        $body = (array) $request->getParsedBody();

        return new SaveOptions(
            by: self::userId($request),
            mayPublish: $this->can($type . '.publish'),
            publish: $publish && ($body['_action'] ?? null) === 'publish' && $this->publishes($type),
            loadedVersion: is_string($body['_version'] ?? null) && ctype_digit($body['_version']) ? (int) $body['_version'] : null,
            publishAt: array_key_exists('_publish_at', $body) ? (is_string($body['_publish_at']) ? $body['_publish_at'] : '') : null,
        );
    }

    /**
     * Records a save took off the site (SaveOutcome::$addressesLeft: a type without publishing
     * whose status changed) leave their old links answering 404: a warning says so, with a link to
     * pick where they go (Settings → Redirects, filtered to the record) for those who may manage
     * redirects.
     *
     * @param list<DynamicRecord> $left
     */
    private function leftSite(ServerRequestInterface $request, array $left): void
    {
        if ($left === []) {
            return;
        }

        self::flash($request, 'warning', count($left) === 1
            ? FolioText::get('folio.redirects.left_site', ['title' => $this->labeler->label($left[0])])
            : FolioText::get('folio.redirects.left_site_count', ['count' => count($left)]));
        if ($this->can('redirects.manage')) {
            self::flash($request, 'warning_link', [
                'url' => $this->prefix . '/redirects' . (count($left) === 1 ? '?record=' . rawurlencode($left[0]->getType()->key) . ':' . rawurlencode((string) $left[0]->getId()) : ''),
                'label' => FolioText::get('folio.redirects.send_old_links'),
            ]);
        }
    }

    /** A refusal's messages in one line (a bulk action's report, a flash). */
    private static function errorText(ValidationException $e): string
    {
        $messages = [];
        $errors = $e->errors();
        array_walk_recursive($errors, static function (mixed $m) use (&$messages): void {
            $messages[] = (string) $m;
        });

        return implode(' ', $messages);
    }

    /** Publishing through the form without the right to: refused before anything (uploads included) is written. */
    private function refusePublish(ServerRequestInterface $request, string $type): ?ResponseInterface
    {
        $publish = (((array) $request->getParsedBody())['_action'] ?? null) === 'publish';

        return $publish && $this->publishes($type) && !$this->can($type . '.publish')
            ? Forbidden::response($request, $this->engine, $this->prefix, $this->catalog)
            : null;
    }

    /** Without an Access service (unit tests, no auth) everything is allowed, as before. */
    private function can(string $permission): bool
    {
        return $this->access?->can($permission) ?? true;
    }

    private function publishes(string $type): bool
    {
        return $this->publisher !== null && $this->publisher->enabled($type);
    }

    /**
     * Badge, version and actions of a publishing type's editor; null otherwise.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    private function publishingContext(string $type, TypeDefinition $typeDef, array $values): ?array
    {
        if (!$this->publishes($type)) {
            return null;
        }
        $id = $values[$typeDef->idField] ?? null;
        $stored = is_string($id) && $id !== '' ? $this->dm->findType($type, $id) : null;
        $state = $stored !== null ? $this->publisher->state($stored) : PublishState::Draft;
        $base = $this->prefix . '/' . $type . '/' . $id;
        $edit = $stored !== null && $this->can($type . '.edit');
        $publish = $stored !== null && $this->can($type . '.publish');

        $changes = $stored !== null && $state === PublishState::Changed ? $this->publisher->changes($stored) : [];

        return [
            'state' => $state->value,
            'label' => FolioText::get('folio.publishing.' . $state->value),
            // The title's badges: published and/or changes not live.
            'badges' => match ($state) {
                PublishState::Draft => [['tone' => 'draft', 'label' => FolioText::get('folio.publishing.draft')]],
                PublishState::Published => [['tone' => 'published', 'label' => FolioText::get('folio.publishing.published')]],
                PublishState::Changed => [
                    ['tone' => 'published', 'label' => FolioText::get('folio.publishing.published')],
                    ['tone' => 'changed', 'label' => FolioText::get('folio.list.status_changed')],
                ],
            },
            // The side panel's status line and the "N changes" list (Publisher::changes(): fields, then _locales / _seo).
            'status_text' => FolioText::get('folio.form.status_' . ($stored === null ? 'new' : $state->value)),
            'changes' => array_map(fn (string $key): string => $this->changeLabel($type, $typeDef, $key), $changes),
            'version' => (int) ($values['_version'] ?? 0),
            'publish_at' => is_string($values['_publish_at'] ?? null) ? $values['_publish_at'] : '',
            // Shown to those who may edit but not publish: saving cancels the schedule.
            'schedule_cancels' => $edit && !$publish && is_string($values['_publish_at'] ?? null) && $values['_publish_at'] !== '',
            'save_state' => $stored !== null ? PublishingController::stateText($state) : null,
            'revisions' => $stored !== null ? $this->revisionRows($type, $typeDef, (string) $id) : [],
            'autosave_url' => $edit ? $base . '/autosave' : null,
            'share_url' => $edit && $this->previewLinks?->enabled() === true && $this->frontend->routeFor($type) !== null ? $base . '/share' : null,
            'unpublish_url' => $publish && $state !== PublishState::Draft ? $base . '/unpublish' : null,
            // The public addresses unpublishing takes off the site: above 0, it asks where their old links go.
            'unpublish_addresses' => $publish && $state !== PublishState::Draft ? count($this->addresses?->of($type, (string) $id) ?? []) : 0,
            'discard_url' => $edit && $state === PublishState::Changed ? $base . '/discard' : null,
        ];
    }

    /** A changed key's label in the side panel's "N changes" list: the field's label, or what a content key holds. */
    private function changeLabel(string $type, TypeDefinition $typeDef, string $key): string
    {
        return match (true) {
            isset($typeDef->fields[$key]) => $this->labels->field($type, $key),
            $key === '_locales' => FolioText::get('folio.form.change_locales'),
            $key === SeoSection::KEY => FolioText::get('folio.form.change_seo'),
            default => (new \Preflow\Form\FieldRenderer())->humanize(ltrim($key, '_')),
        };
    }

    /** Types with publishing are told to publish; others are already saved. */
    private function confirmedNotice(ServerRequestInterface $request, string $type): ?string
    {
        return ($request->getQueryParams()['confirmed'] ?? null) === '1'
            ? FolioText::get($this->publishes($type) ? 'folio.form.confirmed' : 'folio.form.confirmed_saved')
            : null;
    }

    private static function restoredNotice(ServerRequestInterface $request): ?string
    {
        return ($request->getQueryParams()['restored'] ?? null) === '1'
            ? FolioText::get('folio.form.restored')
            : null;
    }

    /**
     * Revision rows for the editor panel, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function revisionRows(string $type, TypeDefinition $typeDef, string $id): array
    {
        $rows = [];
        foreach ($this->publisher?->revisions($type, $id) ?? [] as $revision) {
            $labels = array_map(
                fn (string $f): string => isset($typeDef->fields[$f]) ? $this->labels->field($type, $f) : $f,
                $revision->changed,
            );
            $rows[] = [
                'date' => $revision->createdAt,
                'by' => $this->authors?->label($revision->createdBy) ?? $revision->createdBy,
                'changed' => $labels,
                'restore_url' => $this->prefix . '/' . $type . '/' . rawurlencode($id) . '/revisions/' . rawurlencode($revision->id) . '/restore',
            ];
        }

        return $rows;
    }

    private static function userId(ServerRequestInterface $request): ?string
    {
        $user = $request->getAttribute(\Preflow\Auth\Authenticatable::class);

        return $user instanceof \Preflow\Auth\Authenticatable ? $user->getAuthId() : null;
    }

    private function isSingleton(string $type): bool
    {
        return $this->catalog->get($type)?->singleton === true;
    }

    /**
     * Field values from the fields' "default" (extension settings declare them), for keys a record
     * lacks, and for a new record's form.
     *
     * @return array<string, mixed>
     */
    private static function defaults(TypeDefinition $typeDef): array
    {
        $values = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if (array_key_exists('default', $fieldDef->config)) {
                $values[$name] = $fieldDef->config['default'];
            }
        }

        return $values;
    }

    /** The editing locale: ?locale= (GET) or _locale (POST), if configured; else the default. */
    private function requestLocale(ServerRequestInterface $request, TypeDefinition $typeDef): string
    {
        $requested = $request->getQueryParams()['locale'] ?? ((array) $request->getParsedBody())['_locale'] ?? null;

        return is_string($requested) && $typeDef->locales->has($requested) ? $requested : $typeDef->locales->default;
    }

    private function localeQuery(TypeDefinition $typeDef, string $locale): string
    {
        return $typeDef->translatableFields() !== [] ? '?locale=' . rawurlencode($locale) : '';
    }

    private static function languageName(string $code): string
    {
        $key = 'folio.languages.' . $code;
        $name = FolioText::get($key);

        return $name !== $key ? $name : strtoupper($code);
    }

    /**
     * Errors inside a field ("<field>.<rest>"), keyed by "<rest>". Locale
     * suffixes of top-level translatable fields ("title.de") are excluded.
     *
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    private static function nestedErrors(array $errors, string $field): array
    {
        $nested = [];
        foreach ($errors as $key => $messages) {
            if (is_string($key) && str_starts_with($key, $field . '.')) {
                $rest = substr($key, strlen($field) + 1);
                if (preg_match('/^[a-f0-9]{16}\./', $rest) === 1) {
                    $nested[$rest] = (array) $messages;
                }
            }
        }

        return $nested;
    }

    /** The record's own name for the editor header: its first non-empty title-like field. */
    private static function recordTitle(array $values, \Preflow\Data\Locales $locales): ?string
    {
        foreach (['title', 'name', 'label', 'heading', 'email'] as $field) {
            $text = mb_substr(self::plain(LocaleValue::default($values[$field] ?? null, $locales)), 0, 80);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /** The field type's admin display (list cells, read-only fields): renderAdmin() when it has one, else renderFrontend(). */
    private static function adminDisplay(\Preflow\Folio\Field\FieldType $type, mixed $value, array $config): string
    {
        return ListCells::display($type, $value, $config);
    }

    /**
     * A read-only value as its field type displays it (the list-cell path): select labels, relation labels,
     * comma-separated roles, formatted dates. Rich text keeps its sanitised markup; the rest is escaped plain text.
     * Returns ready HTML.
     */
    /** @param array<string, mixed>|null $config the field's config with its option labels resolved (fieldConfig()) */
    private function readonlyDisplay(\Preflow\Data\TypeFieldDefinition $fieldDef, mixed $value, ?array $config = null): string
    {
        $type = $this->fieldTypes->get($fieldDef->type);
        $html = self::adminDisplay($type, $value, $config ?? $fieldDef->config);
        if ($fieldDef->type !== 'richtext') {
            $html = htmlspecialchars(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim(strip_tags($html)) === '' ? '—' : $html;
    }

    /** A read-only field: its label and value (ready HTML from readonlyDisplay), no input. */
    private static function readonlyField(string $name, ?string $label, ?string $help, string $valueHtml): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<div class="form-group folio-readonly">' . "\n"
            . '  <span class="form-label">' . $e($label ?? (new \Preflow\Form\FieldRenderer())->humanize($name)) . '</span>' . "\n"
            . '  <div class="folio-readonly-value">' . $valueHtml . '</div>' . "\n"
            . ($help !== null && $help !== '' ? '  <small class="form-help">' . $e($help) . '</small>' . "\n" : '')
            . '</div>';
    }

    /** Plain text for placeholders and references (field values may hold rich text). */
    private static function plain(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Columns for the admin list: "list.columns" from the type JSON, else the
     * first three plain-valued fields that are not hidden.
     *
     * @param array<string, mixed> $listConfig
     * @return array<string, string> field => header label
     */
    private function listColumns(TypeDefinition $typeDef, array $listConfig): array
    {
        $columns = [];
        foreach (\Preflow\Folio\Content\ListQuery::columns($typeDef, $listConfig) as $name) {
            $columns[$name] = $this->labels->field($typeDef->key, $name);
        }

        return $columns;
    }

    /**
     * The address affix of a route parameter field: the static path before it ("/jobs/", with "/de"
     * in another language) and, when the route has no other parameter, what the copy button puts
     * around the typed value (app.url's address, else the path alone). The route's home value copies
     * as the root.
     */
    private function addressAffix(string $type, string $name, string $locale, \Preflow\Data\Locales $locales): ?\Preflow\Folio\Field\AddressAffix
    {
        $route = $this->frontend->routeFor($type);
        if ($route === null) {
            return null;
        }
        $mode = $route->addressModes()[$name] ?? 'segment';
        $token = '{' . ($mode === 'path' ? '...' : '') . $name . '}';
        $at = strpos($route->pattern, $token);
        if ($at === false) {
            return null;
        }
        $language = $locale !== $locales->default ? '/' . $locale : '';
        $before = $language . substr($route->pattern, 0, $at);
        $after = substr($route->pattern, $at + strlen($token));
        $base = rtrim($this->siteUrl ?? '', '/');
        if (str_contains($before . $after, '{')) {
            return new \Preflow\Folio\Field\AddressAffix($mode, (string) preg_replace('~\{[^}]*\}~', '…', $before));
        }
        $home = $route->home !== null && ($route->paramNames[0] ?? null) === $name ? $route->home : null;

        return new \Preflow\Folio\Field\AddressAffix($mode, $before, $base . $before, $after, $home, $home !== null ? $base . ($language !== '' ? $language : '/') : null);
    }

    /**
     * A field's config for its editor and display: a select's options with their labels in
     * the interface language (DisplayLabels::options()); radio cards keep their
     * {label, help} shape; other fields' as they are.
     *
     * @return array<string, mixed>
     */
    private function fieldConfig(string $type, string $name, \Preflow\Data\TypeFieldDefinition $fieldDef): array
    {
        $config = $fieldDef->config;
        if ($fieldDef->type === 'select' && is_array($config['options'] ?? null)) {
            $labels = $this->labels->options($type, $name);
            if (($config['display'] ?? null) === 'cards') {
                // Radio cards keep the {label, help} shape, both in the interface language.
                $helps = $this->labels->optionHelps($type, $name);
                $labels = array_map(null, array_keys($labels), $labels);
                $config['options'] = array_combine(
                    array_column($labels, 0),
                    array_map(static fn (array $pair): array => ['label' => $pair[1]] + (isset($helps[$pair[0]]) ? ['help' => $helps[$pair[0]]] : []), $labels),
                );
            } else {
                $config['options'] = $labels;
            }
        }

        return $config;
    }

    /** @param array<string, mixed> $listConfig */
    private function listQuery(string $type, TypeDefinition $typeDef, array $listConfig): \Preflow\Data\QueryBuilder
    {
        return \Preflow\Folio\Content\ListOrder::apply($this->dm->queryType($type), $typeDef, $listConfig);
    }

    private function isDrawer(ServerRequestInterface $request): bool
    {
        parse_str($request->getUri()->getQuery(), $q);

        return ($q['_drawer'] ?? null) === '1';
    }

    private function labelFor(string $type): string
    {
        return $this->labels->type($type);
    }

    /** One record of the type ("New App"): its singular label, else the type's label. */
    private function singularFor(string $type): string
    {
        return $this->labels->singular($type) ?? $this->labels->type($type);
    }

    private function html(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
