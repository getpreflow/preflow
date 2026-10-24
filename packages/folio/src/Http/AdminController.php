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
use Preflow\Folio\Content\ListQuery;
use Preflow\Folio\Content\ListView;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RelationLabels;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\AfterDelete;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\Badge;
use Preflow\Folio\Extension\BeforeDelete;
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
    ) {
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
                'label' => $listing->label,
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
            return new Response(404, [], 'Unknown type');
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
            $localized = $record->localized($typeDef->locales->default);
            $cells = [];
            $marked = [];
            foreach ($columns as $name => $label) {
                $fieldDef = $typeDef->fields[$name];
                if (isset($relationLabels[$name])) {
                    $ids = RelationLabels::ids($localized->get($name));
                    $text = implode(', ', array_filter(array_map(static fn (string $id) => $relationLabels[$name][$id] ?? null, $ids), static fn (?string $l) => $l !== null));
                } else {
                    $html = self::adminDisplay(
                        $this->fieldTypes->get($fieldDef->type),
                        $this->fieldTypes->get($fieldDef->type)->fromStorage($localized->get($name)),
                        $fieldDef->config,
                    );
                    // Plain text: list cells never render field HTML (e.g. rich text) raw.
                    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                // cells: plain text (Twig escapes it); marked: escaped HTML with the search words in <mark>.
                $cells[$name] = $text;
                $marked[$name] = $text === '' ? '' : Highlighter::html($text, $query->q);
            }
            $rows[] = [
                'id' => $record->getId(),
                'cells' => $cells,
                'marked' => $marked,
                // Extension columns (#[ListColumn]): plain text, or a Badge; both escaped by the template.
                'extra' => array_map(static function (array $column) use ($record): string|array {
                    $value = ($column['callable'])($record);

                    return $value instanceof Badge ? ['label' => $value->label, 'tone' => $value->tone()] : (string) $value;
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
        );
        // "No … yet" only when the type has no records at all; a search without hits says so instead.
        $hasRecords = $rows !== [] || ($list['filtered'] && $this->dm->queryType($type)->limit(1)->get()->items() !== []);

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->html($this->engine->render($partial ? '@folio/admin/_list_results.twig' : '@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
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
        ]));
    }

    public function recordLabel(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        $payload = json_encode(
            ['id' => $id, 'label' => $this->labeler->label($record)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], (string) $payload);
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);
        $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
        $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

        $locale = $this->requestLocale($request, $this->registry->get($type));

        // The fields' declared defaults, so a new record's form starts from them (a boolean defaulting to true is checked).
        return $this->form($type, self::defaults($this->registry->get($type)), $action, 'New ' . $this->labelFor($type), [], $csrf, 200, $layout, $locale, $this->prefix . '/' . $type . '/new');
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $this->isSingleton($type)) {
            return new Response(404, [], 'Unknown type');
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
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), 'New ' . $this->labelFor($type), $this->drafts->uploadErrors(), $csrf, 422, $layout, $locale);
        }
        $record->set($typeDef->idField, bin2hex(random_bytes(16)));
        if (($this->catalog->get($type)?->list['orderable'] ?? false) === true) {
            $record->set(self::ORDER, $this->maxOrder($type) + 10);
        }
        $data = [$typeDef->idField => $record->getId()];
        if (($errors = $this->beforeSave($record)) !== null) {
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), 'New ' . $this->labelFor($type), $errors, $csrf, 422, $layout, $locale);
        }

        if ($this->publishes($type)) {
            // Once saved, the record exists: a re-rendered form must update it, not create another.
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
            $base = $this->prefix . '/' . $type . '/' . $record->getId();
            $failed = $this->savePublishing($request, $record, $type, $base . ($drawer ? '?_drawer=1' : ''), 'Edit ' . $this->labelFor($type), $csrf, $locale, $base . '/edit', $layout);
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
                    'New ' . $this->labelFor($type),
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
            return new Response(404, [], 'Unknown type');
        }
        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->form(
            $type,
            $record->toArray(),
            $this->prefix . '/' . $type . '/' . $id,
            'Edit ' . $this->labelFor($type),
            [],
            $csrf,
            locale: $this->requestLocale($request, $this->registry->get($type)),
            editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit',
            notice: self::restoredNotice($request),
        );
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $current = $this->dm->findType($type, $id);
        if ($current === null && !($this->isSingleton($type) && $id === $type)) {
            return new Response(404, [], 'Not found');
        }
        $existing = $current?->toArray() ?? [$typeDef->idField => $id]; // a singleton is created on first save

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        if (($refused = $this->refusePublish($request, $type)) !== null) {
            return $refused;
        }
        $locale = $this->requestLocale($request, $typeDef);
        $record = $this->drafts->build($typeDef, $request, $existing, $locale, $this->can('assets.upload'));
        if ($this->drafts->uploadErrors() !== []) {
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . '/' . $id, 'Edit ' . $this->labelFor($type), $this->drafts->uploadErrors(), $csrf, 422, locale: $locale, editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit');
        }
        if (($errors = $this->beforeSave($record)) !== null) {
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . '/' . $id, 'Edit ' . $this->labelFor($type), $errors, $csrf, 422, locale: $locale, editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit');
        }

        if ($this->publishes($type)) {
            $failed = $this->savePublishing($request, $record, $type, $this->prefix . '/' . $type . '/' . $id, 'Edit ' . $this->labelFor($type), $csrf, $locale, $this->prefix . '/' . $type . '/' . $id . '/edit');
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
                    'Edit ' . $this->labelFor($type),
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
            return new Response(404, [], 'Unknown type');
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
            return new Response(404, [], 'Not found');
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
            return new Response(404, [], 'Singletons cannot be deleted');
        }
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        try {
            $this->customizations?->runHooks(BeforeDelete::class, $record);
        } catch (Refused $e) {
            self::flash($request, 'error', $e->getMessage());

            return new Response(302, ['Location' => $this->prefix . '/' . $type]);
        }
        $this->dm->deleteType($type, $id);
        if ($this->publishes($type)) {
            $this->publisher->forget($type, $id);
        }
        $this->customizations?->runHooks(AfterDelete::class, $record);

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
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
            return new Response(404, [], 'Unknown action');
        }
        if (!$this->can($action['permission'])) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }
        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        $response = ($action['callable'])($record, $request);
        if ($response instanceof ResponseInterface) {
            return $response;
        }
        if ($response !== null) {
            throw new \Preflow\Folio\Extension\ExtensionException("Record action \"{$action['name']}\" of type \"{$type}\" must return a response or null, not " . get_debug_type($response) . '.');
        }
        self::flash($request, 'success', $action['label'] . ': done');

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
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField || ($fieldDef->config['hidden'] ?? false) === true) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            if (($fieldDef->config['readonly'] ?? false) === true) {
                $stored = isset($translatable[$name]) ? LocaleValue::pick($values[$name] ?? null, $locales, $locale) : LocaleValue::default($values[$name] ?? null, $locales);
                $fields[] = [
                    'name' => $name,
                    'html' => self::readonlyField($name, $fieldDef->label, $fieldDef->help, $this->readonlyDisplay(
                        $fieldDef,
                        // nothing stored stays nothing (fromStorage would turn a missing boolean into false)
                        $stored === null || $stored === '' ? $stored : $fieldType->fromStorage($stored),
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
                label: $fieldDef->label,
                help: $fieldDef->help,
                value: $fieldType->fromStorage($value),
                errors: $fieldErrors,
                config: $fieldDef->config,
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

        // Errors of other languages (keys "field.<locale>"), so a save that fails
        // because of another language's value says where to look.
        $localeErrors = [];
        foreach ($errors as $key => $messages) {
            if (!is_string($key) || !str_contains($key, '.')) {
                continue;
            }
            [$field, $code] = explode('.', $key, 2);
            if ($code === $locale || !$locales->has($code) || !isset($typeDef->fields[$field])) {
                continue;
            }
            $localeErrors[] = [
                'language' => self::languageName($code),
                'url' => ($editUrl ?? $action) . '?locale=' . rawurlencode($code),
                'field' => $typeDef->fields[$field]->label ?? (new \Preflow\Form\FieldRenderer())->humanize($field),
                'messages' => (array) $messages,
            ];
        }

        $languages = [];
        if ($multilingual) {
            foreach ($locales->available as $code) {
                $languages[] = [
                    'code' => $code,
                    'name' => self::languageName($code),
                    'url' => ($editUrl ?? $action) . '?locale=' . rawurlencode($code),
                    'active' => $code === $locale,
                ];
            }
        }

        $isNew = strtok($action, '?') === $this->prefix . '/' . $type;
        [$panels, $recordActions] = $this->recordExtras($type, $typeDef, $values, $isNew, $csrf);
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
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /**
     * Extension panels (#[FormPanel], rendered) and record action buttons (#[RecordAction],
     * those the user may run: the action's permission and {type}.edit) of a stored record's edit form; none on create.
     *
     * @param array<string, mixed> $values
     * @return array{list<array{label: string, position: string, html: string}>, list<array{label: string, url: string, icon: ?string, confirm: ?string}>}
     */
    private function recordExtras(string $type, TypeDefinition $typeDef, array $values, bool $isNew, string $csrf): array
    {
        $custom = $this->customized($type);
        $id = $values[$typeDef->idField] ?? null;
        if ($isNew || ($custom['panels'] === [] && $custom['actions'] === []) || !is_string($id) || $id === ''
            || ($record = $this->dm->findType($type, $id)) === null) {
            return [[], []];
        }

        $panels = [];
        foreach ($custom['panels'] as $panel) {
            $result = ($panel['callable'])($record);
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

        return [$panels, $actions];
    }

    /** @return array{panels: list<array<string, mixed>>, actions: list<array<string, mixed>>, columns: list<array<string, mixed>>, hooks: array<string, list<\Closure>>} */
    private function customized(string $type): array
    {
        return $this->customizations?->forType($type) ?? ['panels' => [], 'actions' => [], 'columns' => [], 'hooks' => [], 'placeholders' => []];
    }

    private static function flash(ServerRequestInterface $request, string $type, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash($type, $message);
        }
    }

    /**
     * One badge per locale the record is visible in; "de · 2 missing" when
     * fields that are required in the default locale are empty in that locale.
     *
     * @return list<string>
     */
    private function languageBadges(TypeDefinition $typeDef, DynamicRecord $record): array
    {
        $locales = $typeDef->locales;
        $required = array_values(array_filter(
            $typeDef->translatableFields(),
            static fn (string $f) => in_array('required', $typeDef->fields[$f]->validate, true),
        ));

        $badges = [];
        foreach ($record->availableLocales() as $locale) {
            $missing = 0;
            if ($locale !== $locales->default) {
                foreach ($required as $field) {
                    $value = LocaleValue::pick($record->get($field), $locales, $locale);
                    $missing += ($value === null || $value === '') ? 1 : 0;
                }
            }
            $badges[] = $missing > 0 ? "{$locale} · {$missing} missing" : $locale;
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
    private function savePublishing(ServerRequestInterface $request, DynamicRecord $record, string $type, string $action, string $heading, string $csrf, string $locale, string $editUrl, string $layout = '@folio/admin/_layout.twig'): ?ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        // "Publish at" schedules a publish: only for those who may publish. A save by
        // anyone else cancels a pending schedule, so their changes never go live unreviewed.
        if (!$this->can($type . '.publish')) {
            $record->set('_publish_at', '');
        } elseif (array_key_exists('_publish_at', $body)) {
            $record->set('_publish_at', self::publishAt($body['_publish_at']));
        }
        $loaded = is_string($body['_version'] ?? null) && ctype_digit($body['_version']) ? (int) $body['_version'] : null;

        try {
            $this->publisher?->saveDraft($record, $loaded);
        } catch (VersionConflict $e) {
            $values = $record->toArray();
            $values['_version'] = $e->current; // saving again overwrites, deliberately
            return $this->form($type, $values, $action, $heading, [], $csrf, 409, $layout, $locale, $editUrl,
                notice: 'Someone else changed this record. Save again to overwrite, or reload to discard your edits.',
                reloadUrl: $editUrl,
            );
        }

        if (($body['_action'] ?? null) === 'publish') {
            try {
                $this->publisher?->publish($record, self::userId($request));
            } catch (ValidationException $e) {
                $this->customizations?->runHooks(AfterSave::class, $record); // the working copy was saved
                return $this->form($type, $record->toArray(), $action, $heading, $e->errors(), $csrf, 422, $layout, $locale, $editUrl,
                    notice: 'Saved as draft — not published.',
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

    private static function restoredNotice(ServerRequestInterface $request): ?string
    {
        return ($request->getQueryParams()['restored'] ?? null) === '1'
            ? 'Restored into the working copy — publish to make it live.'
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
                fn (string $f): string => isset($typeDef->fields[$f])
                    ? ($typeDef->fields[$f]->label ?? (new \Preflow\Form\FieldRenderer())->humanize($f))
                    : $f,
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
        return ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'it' => 'Italian'][$code] ?? strtoupper($code);
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
        return $type instanceof \Preflow\Folio\Field\DisplaysInAdmin ? $type->renderAdmin($value, $config) : $type->renderFrontend($value, $config);
    }

    /**
     * A read-only value as its field type displays it (the list-cell path): select labels, relation labels,
     * comma-separated roles, formatted dates. Rich text keeps its sanitised markup; the rest is escaped plain text.
     * Returns ready HTML.
     */
    private function readonlyDisplay(\Preflow\Data\TypeFieldDefinition $fieldDef, mixed $value): string
    {
        $type = $this->fieldTypes->get($fieldDef->type);
        $html = self::adminDisplay($type, $value, $fieldDef->config);
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
        $names = \Preflow\Folio\Content\ListQuery::columns($typeDef, $listConfig);

        $renderer = new \Preflow\Form\FieldRenderer();
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = $typeDef->fields[$name]->label ?? $renderer->humanize($name);
        }

        return $columns;
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
        foreach ($this->catalog->all() as $listing) {
            if ($listing->key === $type) {
                return $listing->label;
            }
        }
        return ucfirst($type);
    }

    private function html(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
