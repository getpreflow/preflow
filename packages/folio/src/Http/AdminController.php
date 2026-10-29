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
use Preflow\Folio\Content\RecordDeleter;
use Preflow\Folio\Content\RecordId;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RelationLabels;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\Badge;
use Preflow\Folio\Extension\Link;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Validation\ValidationException;
use Preflow\Folio\Override\ActionResolver;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    private readonly DraftRecordFactory $drafts;
    private readonly RecordValidator $validator;
    private readonly DisplayLabels $labels;
    private readonly TranslationStatus $translations;

    /** System key holding the manual position of a record in an orderable list. */
    private const ORDER = '_order';

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
        ?DraftRecordFactory $drafts = null,
        ?RecordValidator $validator = null,
        private readonly ?Publisher $publisher = null,
        private readonly ?\Preflow\Folio\Publishing\PreviewLinks $previewLinks = null,
        private readonly ?\Preflow\Auth\Access\Access $access = null,
        private readonly ?Customizations $customizations = null,
        private readonly ?\Preflow\Folio\Users\AuthorNames $authors = null,
        private ?ListExport $export = null,
        private readonly ?\Preflow\Folio\Extension\Screen\PageAssets $pageAssets = null,
        ?DisplayLabels $labels = null,
        ?TranslationStatus $translations = null,
    ) {
        $this->labels = $labels ?? new DisplayLabels($catalog, $registry);
        $this->translations = $translations ?? new TranslationStatus($fieldTypes, $this->labels);
        $this->drafts = $drafts ?? new DraftRecordFactory($fieldTypes);
        $this->validator = $validator ?? new RecordValidator($fieldTypes, $dm);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (($override = $this->overrides->resolve('Content', 'Index')) !== null) {
            return $override->handle($request);
        }

        $cards = [];
        foreach ($this->catalog->all() as $listing) {
            $cards[] = [
                'key' => $listing->key,
                'label' => $this->labels->type($listing->key),
                'count' => count($this->dm->queryType($listing->key)->get()->items()),
            ];
        }

        return $this->html($this->engine->render('@folio/admin/dashboard.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'cards' => $cards,
        ]));
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
                notice: self::restoredNotice($request),
            );
        }

        $typeDef = $this->registry->get($type);
        $listConfig = $this->catalog->get($type)?->list ?? [];
        $orderable = ($listConfig['orderable'] ?? false) === true;
        $columns = $this->listColumns($typeDef, $listConfig);
        $extraColumns = $this->customized($type)['columns'];

        $multilingual = $typeDef->translatableFields() !== [];
        $states = $this->publishes($type) ? $this->publisher->states($type) : null;

        $query = ListQuery::fromRequest($request, $typeDef, $listConfig, $typeDef->locales->default);
        $result = $query->fetch($this->dm->queryType($type));
        $records = array_values($result->items);
        $relations = new RelationLabels($this->dm, $this->labeler);
        // Relation columns (only when "list.columns" names them): the page's labels in one query per target.
        $relationColumns = array_values(array_filter(array_keys($columns), static fn (string $name) => $typeDef->fields[$name]->type === 'relation'));
        $relationLabels = $relationColumns === [] ? [] : $relations->forRecords($typeDef, $relationColumns, $records);

        $rows = [];
        foreach ($records as $record) {
            $cells = [];
            $marked = [];
            foreach ($columns as $name => $label) {
                $text = ListCells::text($this->fieldTypes, $typeDef, $name, $record, $relationLabels, $this->labels);
                // cells: plain text (Twig escapes it); marked: escaped HTML with the search words in <mark>.
                $cells[$name] = $text;
                $marked[$name] = $text === '' ? '' : Highlighter::html($text, $query->q);
            }
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
                'languages' => $multilingual ? $this->languageBadges($typeDef, $record) : [],
                'state' => $states !== null ? ($states[(string) $record->getId()] ?? PublishState::Draft) : null,
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
        );
        // "No … yet" only when the type has no records at all; a search without hits says so instead.
        $hasRecords = $rows !== [] || ($list['filtered'] && $this->dm->queryType($type)->limit(1)->get()->items() !== []);

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->html($this->engine->render($partial ? '@folio/admin/_list_results.twig' : '@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'empty_text' => $this->labels->emptyState($type),
            'types' => $this->catalog->all(),
            'columns' => $columns,
            'extra_columns' => array_column($extraColumns, 'label'),
            'rows' => $rows,
            'list' => $list,
            'has_records' => $hasRecords,
            // Manual positions only mean something in the list's own order, unfiltered.
            'orderable' => $orderable && !$list['sorted'] && !$list['filtered'],
            'multilingual' => $multilingual,
            'publishing' => $states !== null,
            'csrf' => $csrf,
            'bulk' => $this->bulkBar($type, $typeDef, $listConfig),
            // The Export button (header: the whole filtered list; bulk bar: the ticked rows), only with OpenSpout installed.
            'export_url' => $this->exporter()->available() ? $this->prefix . '/' . $type . '/_export' : null,
            // The view to return to after a bulk action (search, filters, sort, page).
            'list_query' => http_build_query($query->query(), '', '&', PHP_QUERY_RFC3986),
        ]));
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
            $records = $exporter->records($type, ListQuery::fromRequest($request, $typeDef, $listConfig, $typeDef->locales->default));
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
            $picker->page($type, $text('label'), $text('order'), mb_substr($text('q') ?? '', 0, 200), $page),
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
        return $this->form($type, self::defaults($this->registry->get($type)), $action, FolioText::get('folio.form.new', ['label' => $this->labelFor($type)]), [], $csrf, 200, $layout, $locale, $this->prefix . '/' . $type . '/new');
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
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), FolioText::get('folio.form.new', ['label' => $this->labelFor($type)]), $this->drafts->uploadErrors(), $csrf, 422, $layout, $locale);
        }
        $record->set($typeDef->idField, bin2hex(random_bytes(16)));
        if (($this->catalog->get($type)?->list['orderable'] ?? false) === true) {
            $record->set(self::ORDER, $this->maxOrder($type) + 10);
        }
        $data = [$typeDef->idField => $record->getId()];
        if (($errors = $this->beforeSave($record)) !== null) {
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), FolioText::get('folio.form.new', ['label' => $this->labelFor($type)]), $errors, $csrf, 422, $layout, $locale);
        }

        if ($this->publishes($type)) {
            // Once saved, the record exists: a re-rendered form must update it, not create another.
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
            $base = $this->prefix . '/' . $type . '/' . $record->getId();
            $failed = $this->savePublishing($request, $record, $type, $base . ($drawer ? '?_drawer=1' : ''), FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), $csrf, $locale, $base . '/edit', $layout,
                newAction: $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''),
                newHeading: FolioText::get('folio.form.new', ['label' => $this->labelFor($type)]),
            );
            if ($failed !== null) {
                return $failed;
            }
        } else {
            try {
                $this->saveWithBlocks($typeDef, $record);
            } catch (ValidationException $e) {
                $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
                $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

                return $this->form(
                    $type,
                    $record->toArray(),
                    $action,
                    FolioText::get('folio.form.new', ['label' => $this->labelFor($type)]),
                    $e->errors(),
                    $csrf,
                    422,
                    $layout,
                    $locale,
                );
            }
        }

        if ($drawer) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/drawer_saved.twig', [
                'type' => $type,
                'id' => $data[$typeDef->idField],
            ]));
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type . $this->localeQuery($typeDef, $locale)]);
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
            notice: self::restoredNotice($request) ?? self::confirmedNotice($request),
        );
    }

    /**
     * "Still correct": the translations in a language (all, or those under "path") are marked as
     * made from the current default texts. Saves the working copy with the version check; nothing
     * is published.
     */
    public function confirmTranslation(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }
        $typeDef = $this->registry->get($type);
        $record = $this->dm->findType($type, $id);
        $body = (array) $request->getParsedBody();
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        if ($record === null || !$typeDef->locales->has($locale) || $locale === $typeDef->locales->default) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $path = is_string($body['path'] ?? null) && $body['path'] !== '' ? $body['path'] : null;

        $confirmed = $this->translations->confirm($typeDef, $record, $locale, $path);
        $loaded = is_string($body['_version'] ?? null) && ctype_digit($body['_version']) ? (int) $body['_version'] : null;
        try {
            if ($this->publishes($type)) {
                $this->publisher?->saveDraft($confirmed, $loaded);
            } else {
                $this->dm->saveType($confirmed, false);
            }
        } catch (VersionConflict $e) {
            $values = $record->toArray();
            $values['_version'] = $e->current;
            $editUrl = $this->prefix . '/' . $type . '/' . $id . '/edit';

            return $this->form($type, $values, $this->prefix . '/' . $type . '/' . $id, FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), [],
                $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '', 409, locale: $locale, editUrl: $editUrl,
                notice: FolioText::get('folio.form.conflict'), reloadUrl: $editUrl . '?locale=' . rawurlencode($locale));
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type . '/' . $id . '/edit?locale=' . rawurlencode($locale) . '&confirmed=1']);
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
        if (($errors = $this->beforeSave($record)) !== null) {
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . '/' . $id, FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), $errors, $csrf, 422, locale: $locale, editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit');
        }

        if ($this->publishes($type)) {
            $failed = $this->savePublishing($request, $record, $type, $this->prefix . '/' . $type . '/' . $id, FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]), $csrf, $locale, $this->prefix . '/' . $type . '/' . $id . '/edit');
            if ($failed !== null) {
                return $failed;
            }
        } else {
            try {
                $this->saveWithBlocks($typeDef, $record);
            } catch (ValidationException $e) {
                return $this->form(
                    $type,
                    $record->toArray(),
                    $this->prefix . '/' . $type . '/' . $id,
                    FolioText::get('folio.form.edit', ['label' => $this->labelFor($type)]),
                    $e->errors(),
                    $csrf,
                    422,
                    locale: $locale,
                    editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit',
                );
            }
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type . $this->localeQuery($typeDef, $locale)]);
    }

    /**
     * Move a record one step up or down in an orderable list (POST direction=up|down).
     * Records that have no _order yet are numbered in their current order first.
     */
    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type) || ($this->catalog->get($type)?->list['orderable'] ?? false) !== true) {
            return new Response(404, [], FolioText::get('folio.errors.unknown_type'));
        }

        $typeDef = $this->registry->get($type);
        $records = array_values($this->listQuery($type, $typeDef, ['orderable' => true])->get()->items());

        $missing = false;
        foreach ($records as $record) {
            // Numeric, not int: SQL drivers return positions as text.
            $missing = $missing || !is_numeric($record->get(self::ORDER));
        }
        if ($missing) {
            foreach ($records as $i => $record) {
                $record->set(self::ORDER, ($i + 1) * 10);
                $this->dm->saveType($record, validate: false);
                $this->syncOrder($type, $record);
            }
        }

        $ids = array_map(static fn ($r) => (string) $r->getId(), $records);
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        $direction = (string) (((array) $request->getParsedBody())['direction'] ?? '');
        $neighbour = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
        if ($neighbour !== null && isset($records[$neighbour])) {
            $a = $records[$index];
            $b = $records[$neighbour];
            $orderA = $a->get(self::ORDER);
            $a->set(self::ORDER, $b->get(self::ORDER));
            $b->set(self::ORDER, $orderA);
            $this->dm->saveType($a, validate: false);
            $this->dm->saveType($b, validate: false);
            $this->syncOrder($type, $a);
            $this->syncOrder($type, $b);
        }

        // Back to the page the move was made on.
        $page = (int) (((array) $request->getParsedBody())['page'] ?? 1);

        return new Response(302, ['Location' => $this->prefix . '/' . $type . ($page > 1 ? '?page=' . $page : '')]);
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
            $this->deleter()->delete($type, [$id]);
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

        $deleter = $this->deleter();
        $payload = json_encode($deleter->summary($deleter->plan($type, $ids)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], $payload);
    }

    private function deleter(): RecordDeleter
    {
        return new RecordDeleter(
            $this->dm,
            $this->catalog,
            $this->publisher,
            $this->customizations !== null ? $this->customizations->runHooks(...) : null,
            $this->labels,
        );
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
            $this->deleter()->delete($type, array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $records));
            self::flash($request, 'success', FolioText::get('folio.common.done_count', ['label' => FolioText::get('folio.common.delete'), 'count' => count($records)]));
        } catch (Refused $e) {
            self::flash($request, 'error', $e->getMessage());
        }

        return new Response(302, ['Location' => $this->bulkReturn($request, $type)]);
    }

    /**
     * Any other bulk action (POST {type}/_bulk/{name}, ids[]; the route asks {type}.edit):
     * "set-{field}" for a select or boolean field the model's "list.bulk" names (value: one of
     * its options, '1'/'0' for a boolean), saved per record on the form's save path; a failing
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

        $done = 0;
        $failed = [];
        foreach ($records as $record) {
            $record->set($field['name'], $stored);
            try {
                $this->saveChanged($record);
                $done++;
            } catch (ValidationException $e) {
                $messages = [];
                $errors = $e->errors();
                array_walk_recursive($errors, static function (mixed $m) use (&$messages): void {
                    $messages[] = (string) $m;
                });
                $failed[] = $this->labeler->label($record) . ' — ' . implode(' ', $messages);
            }
        }

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
     * @return array{fields: list<array{label: string, url: string, options: array<string, string>}>, actions: list<array{label: string, url: string, confirm: ?string}>, delete: ?array{url: string, plan: string}}|null
     */
    private function bulkBar(string $type, TypeDefinition $typeDef, array $listConfig): ?array
    {
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

        return $fields === [] && $actions === [] && $delete === null ? null : ['fields' => $fields, 'actions' => $actions, 'delete' => $delete];
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
        $raw = ((array) $request->getParsedBody())['_query'] ?? '';
        $params = [];
        parse_str(is_string($raw) ? ltrim($raw, '?') : '', $params);
        $typeDef = $this->registry->get($type);
        $query = ListQuery::fromRequest(
            $request->withQueryParams($params),
            $typeDef,
            $this->catalog->get($type)?->list ?? [],
            $typeDef->locales->default,
        )->query();

        return $this->prefix . '/' . $type . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
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
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField || ($fieldDef->config['hidden'] ?? false) === true) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if (($fieldDef->config['readonly'] ?? false) === true) {
                $stored = isset($translatable[$name]) ? LocaleValue::pick($values[$name] ?? null, $locales, $locale) : LocaleValue::default($values[$name] ?? null, $locales);
                $fields[] = [
                    'name' => $name,
                    'html' => self::readonlyField($name, $this->labels->fieldText($type, $name), $this->labels->help($type, $name), $this->readonlyDisplay(
                        $fieldDef,
                        // nothing stored stays nothing (fromStorage would turn a missing boolean into false)
                        $stored === null || $stored === '' ? $stored : $fieldType->fromStorage($stored),
                        $this->fieldConfig($type, $name, $fieldDef),
                    )),
                    'translatable' => isset($translatable[$name]),
                    'structured' => false,
                    'falls_back' => false,
                    'reference' => null,
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
            );
            $fields[] = [
                'name' => $name,
                'html' => $fieldType->renderEditor($ctx),
                'translatable' => $isTranslatable,
                // Blocks hold their own (possibly translated) leaves: no "shared" label.
                'structured' => $fieldType instanceof BlocksFieldType,
                'falls_back' => $fallsBack,
                'reference' => $reference,
            ];
            foreach ($fieldType->assets() as $asset) {
                $editorAssets[$asset] = true;
            }
        }

        foreach ($fields as &$entry) {
            $entry['outdated'] = $outdated[$entry['name']] ?? null;
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
            foreach ($locales->available as $code) {
                $missing = $current !== null ? count($this->translations->missing($typeDef, $current, $code)) : 0;
                $late = $current !== null ? count($this->translations->outdated($typeDef, $current, $code)) : 0;
                $languages[] = [
                    'code' => $code,
                    'name' => self::languageName($code),
                    'url' => ($editUrl ?? $action) . '?locale=' . rawurlencode($code),
                    'active' => $code === $locale,
                    'missing' => $missing > 0 ? ['count' => $missing, 'text' => FolioText::get('folio.list.missing', ['locale' => $code, 'count' => $missing])] : null,
                    'outdated' => $late > 0 ? ['count' => $late, 'text' => FolioText::get('folio.list.outdated', ['locale' => $code, 'count' => $late])] : null,
                ];
            }
        }

        [$panels, $recordActions, $panelAssets] = $this->recordExtras($type, $typeDef, $values, $isNew, $csrf);
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
            'editor_assets' => array_keys($editorAssets + ($this->publishes($type) ? ['htmx.js' => true] : [])),
            'multipart' => $multipart,
            'layout' => $layout,
            // No preview in the create drawer: its form posts to the save route.
            // A read-only form has nothing to preview (and its user may not post to the preview route).
            'previewable' => $this->frontend->routeFor($type) !== null && $layout !== '@folio/admin/_drawer_layout.twig'
                && $this->can($type . ($isNew ? '.create' : '.edit')),
            'preview_url' => strtok($action, '?') . '/preview',
            'multilingual' => $multilingual,
            'languages' => $languages,
            'locale_errors' => $localeErrors,
            'locale' => $locale,
            'default_locale' => $locales->default,
            'default_language' => self::languageName($locales->default),
            'available_in' => DynamicRecord::fromArray($typeDef, $values)->availableLocales(),
            'publishing' => $this->publishingContext($type, $typeDef, $values),
            'notice' => $notice,
            'reload_url' => $reloadUrl,
            'panels' => $panels,
            'record_actions' => $recordActions,
            'page_assets' => $panelAssets,
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
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

    private static function flash(ServerRequestInterface $request, string $type, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash($type, $message);
        }
    }

    /**
     * Badges per locale the record is visible in: the locale alone when finished, "de · 2 missing"
     * for texts the default language has and the locale has not, "de · 1 outdated" for translations
     * made from a default text that has changed since.
     *
     * @return list<array{text: string, state: 'missing'|'outdated'|'ok'}>
     */
    private function languageBadges(TypeDefinition $typeDef, DynamicRecord $record): array
    {
        $badges = [];
        foreach ($record->availableLocales() as $locale) {
            $missing = count($this->translations->missing($typeDef, $record, $locale));
            $outdated = count($this->translations->outdated($typeDef, $record, $locale));
            if ($missing > 0) {
                $badges[] = ['text' => FolioText::get('folio.list.missing', ['locale' => $locale, 'count' => $missing]), 'state' => 'missing'];
            }
            if ($outdated > 0) {
                $badges[] = ['text' => FolioText::get('folio.list.outdated', ['locale' => $locale, 'count' => $outdated]), 'state' => 'outdated'];
            }
            if ($missing === 0 && $outdated === 0) {
                $badges[] = ['text' => $locale, 'state' => 'ok'];
            }
        }

        return $badges;
    }

    /**
     * The #[BeforeSave] hooks, before validation. A hook throwing ValidationException refuses
     * the save: its errors, for the form to show again.
     *
     * @return array<string, list<string>>|null
     */
    private function beforeSave(DynamicRecord $record): ?array
    {
        try {
            $this->customizations?->runHooks(BeforeSave::class, $record);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return null;
    }

    /**
     * Full validation (blocks, then the type's rules), then save and the #[AfterSave] hooks.
     *
     * @throws ValidationException
     */
    private function saveWithBlocks(TypeDefinition $typeDef, DynamicRecord $record): void
    {
        $this->validator->validate($record);
        $this->dm->saveType($record, false);
        $this->customizations?->runHooks(AfterSave::class, $record);
    }

    /**
     * The form's save path for a record changed without the form (a bulk set): #[BeforeSave];
     * publishing types save the working copy (schedule as the form handles it), other types
     * validate fully and save; then #[AfterSave].
     *
     * @throws ValidationException from a BeforeSave hook or validation (nothing saved)
     */
    private function saveChanged(DynamicRecord $record): void
    {
        $this->customizations?->runHooks(BeforeSave::class, $record);
        $type = $record->getType()->key;
        if ($this->publishes($type)) {
            $this->applySchedule($record, $type, []);
            $this->publisher->saveDraft($record, null);
            $this->customizations?->runHooks(AfterSave::class, $record);
        } else {
            $this->saveWithBlocks($record->getType(), $record);
        }
    }

    /**
     * "Publish at" schedules a publish: only for those who may publish. A save by anyone
     * else cancels a pending schedule, so their changes never go live unreviewed.
     *
     * @param array<string, mixed> $body the posted form ("_publish_at", when it has one)
     */
    private function applySchedule(DynamicRecord $record, string $type, array $body): void
    {
        if (!$this->can($type . '.publish')) {
            $record->set('_publish_at', '');
        } elseif (array_key_exists('_publish_at', $body)) {
            $record->set('_publish_at', self::publishAt($body['_publish_at']));
        }
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
     * Publishing types: save the working copy (no rules; _publish_at and the
     * loaded _version from the form), then publish when "_action" asks for it.
     * The #[AfterSave] hooks run once the working copy is saved (after the publish attempt).
     * Returns the form to re-render on a version conflict or a failed publish.
     */
    private function savePublishing(ServerRequestInterface $request, DynamicRecord $record, string $type, string $action, string $heading, string $csrf, string $locale, string $editUrl, string $layout = '@folio/admin/_layout.twig', ?string $newAction = null, ?string $newHeading = null): ?ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $this->applySchedule($record, $type, $body);
        $loaded = is_string($body['_version'] ?? null) && ctype_digit($body['_version']) ? (int) $body['_version'] : null;

        try {
            $this->publisher?->saveDraft($record, $loaded);
        } catch (VersionConflict $e) {
            $values = $record->toArray();
            $values['_version'] = $e->current; // saving again overwrites, deliberately
            return $this->form($type, $values, $action, $heading, [], $csrf, 409, $layout, $locale, $editUrl,
                notice: FolioText::get('folio.form.conflict'),
                reloadUrl: $editUrl,
            );
        } catch (ValidationException $e) {
            // A unique value another record has: nothing saved, so a new record's form creates it again.
            return $newAction !== null
                ? $this->form($type, $record->toArray(), $newAction, $newHeading ?? $heading, $e->errors(), $csrf, 422, $layout, $locale)
                : $this->form($type, $record->toArray(), $action, $heading, $e->errors(), $csrf, 422, $layout, $locale, $editUrl);
        }

        if (($body['_action'] ?? null) === 'publish') {
            try {
                // Checked against the version the draft save just wrote: a save landing in between conflicts.
                $this->publisher?->publish($record, self::userId($request), (int) $record->get('_version'));
            } catch (VersionConflict $e) {
                $this->customizations?->runHooks(AfterSave::class, $record); // the working copy was saved
                $values = $record->toArray();
                $values['_version'] = $e->current;
                return $this->form($type, $values, $action, $heading, [], $csrf, 409, $layout, $locale, $editUrl,
                    notice: FolioText::get('folio.form.conflict'),
                    reloadUrl: $editUrl,
                );
            } catch (ValidationException $e) {
                $this->customizations?->runHooks(AfterSave::class, $record); // the working copy was saved
                return $this->form($type, $record->toArray(), $action, $heading, $e->errors(), $csrf, 422, $layout, $locale, $editUrl,
                    notice: FolioText::get('folio.form.not_published'),
                );
            }
        }
        $this->customizations?->runHooks(AfterSave::class, $record);

        return null;
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

        return [
            'state' => $state->value,
            'label' => $state->label(),
            'version' => (int) ($values['_version'] ?? 0),
            'publish_at' => is_string($values['_publish_at'] ?? null) ? $values['_publish_at'] : '',
            // Shown to those who may edit but not publish: saving cancels the schedule.
            'schedule_cancels' => $edit && !$publish && is_string($values['_publish_at'] ?? null) && $values['_publish_at'] !== '',
            'save_state' => $stored !== null ? PublishingController::stateText($state) : null,
            'revisions' => $stored !== null ? $this->revisionRows($type, $typeDef, (string) $id) : [],
            'autosave_url' => $edit ? $base . '/autosave' : null,
            'share_url' => $edit && $this->previewLinks?->enabled() === true && $this->frontend->routeFor($type) !== null ? $base . '/share' : null,
            'unpublish_url' => $publish && $state !== PublishState::Draft ? $base . '/unpublish' : null,
            'discard_url' => $edit && $state === PublishState::Changed ? $base . '/discard' : null,
        ];
    }

    private function syncOrder(string $type, DynamicRecord $record): void
    {
        if ($this->publishes($type)) {
            $this->publisher->syncOrder($record);
        }
    }

    /**
     * A valid future "Y-m-d\TH:i" (datetime-local) value in the app's zone; else ''
     * (cleared). A past time is dropped: it is either a mistake or a stale tab
     * re-sending a schedule that already ran. '' rather than null, because SQL
     * drivers skip null values.
     */
    private static function publishAt(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return $at !== false && $at->format('Y-m-d\TH:i') === $value && $at > new \DateTimeImmutable() ? $value : '';
    }

    private static function confirmedNotice(ServerRequestInterface $request): ?string
    {
        return ($request->getQueryParams()['confirmed'] ?? null) === '1'
            ? FolioText::get('folio.form.confirmed')
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
     * A field's config for its editor and display: a select's options with their labels in
     * the interface language (DisplayLabels::options()); other fields' as they are.
     *
     * @return array<string, mixed>
     */
    private function fieldConfig(string $type, string $name, \Preflow\Data\TypeFieldDefinition $fieldDef): array
    {
        $config = $fieldDef->config;
        if ($fieldDef->type === 'select' && is_array($config['options'] ?? null)) {
            $config['options'] = $this->labels->options($type, $name);
        }

        return $config;
    }

    /** @param array<string, mixed> $listConfig */
    private function listQuery(string $type, TypeDefinition $typeDef, array $listConfig): \Preflow\Data\QueryBuilder
    {
        return \Preflow\Folio\Content\ListOrder::apply($this->dm->queryType($type), $typeDef, $listConfig);
    }

    private function maxOrder(string $type): int
    {
        $max = 0;
        foreach ($this->dm->queryType($type)->get()->items() as $record) {
            $max = max($max, (int) $record->get(self::ORDER));
        }

        return $max;
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

    private function html(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
