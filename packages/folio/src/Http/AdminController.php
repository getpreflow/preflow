<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
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

    /** Field types shown as list columns by default. */
    private const LIST_TYPES = ['string', 'number', 'int', 'integer', 'float', 'select', 'boolean', 'date'];

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
                $record?->toArray() ?? [],
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

        $multilingual = $typeDef->translatableFields() !== [];
        $states = $this->publishes($type) ? $this->publisher->states($type) : null;
        $rows = [];
        foreach ($this->listQuery($type, $typeDef, $listConfig)->get()->items() as $record) {
            $localized = $record->localized($typeDef->locales->default);
            $cells = [];
            foreach ($columns as $name => $label) {
                $fieldDef = $typeDef->fields[$name];
                $html = $this->fieldTypes->get($fieldDef->type)->renderFrontend(
                    $this->fieldTypes->get($fieldDef->type)->fromStorage($localized->get($name)),
                    $fieldDef->config,
                );
                // Plain text: list cells never render field HTML (e.g. rich text) raw.
                $cells[$name] = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $rows[] = [
                'id' => $record->getId(),
                'cells' => $cells,
                'languages' => $multilingual ? $this->languageBadges($typeDef, $record) : [],
                'state' => $states !== null ? ($states[(string) $record->getId()] ?? PublishState::Draft) : null,
            ];
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        return $this->html($this->engine->render('@folio/admin/list.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'columns' => $columns,
            'rows' => $rows,
            'orderable' => $orderable,
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

        return $this->form($type, [], $action, 'New ' . $this->labelFor($type), [], $csrf, 200, $layout, $locale, $this->prefix . '/' . $type . '/new');
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

        $locale = $this->requestLocale($request, $typeDef);
        $record = $this->drafts->build($typeDef, $request, [], $locale);
        if ($this->drafts->uploadErrors() !== []) {
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : ''), 'New ' . $this->labelFor($type), $this->drafts->uploadErrors(), $csrf, 422, $layout, $locale);
        }
        $record->set($typeDef->idField, bin2hex(random_bytes(16)));
        if (($this->catalog->get($type)?->list['orderable'] ?? false) === true) {
            $record->set(self::ORDER, $this->maxOrder($type) + 10);
        }
        $data = [$typeDef->idField => $record->getId()];

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

        $locale = $this->requestLocale($request, $typeDef);
        $record = $this->drafts->build($typeDef, $request, $existing, $locale);
        if ($this->drafts->uploadErrors() !== []) {
            return $this->form($type, $record->toArray(), $this->prefix . '/' . $type . '/' . $id, 'Edit ' . $this->labelFor($type), $this->drafts->uploadErrors(), $csrf, 422, locale: $locale, editUrl: $this->prefix . '/' . $type . '/' . $id . '/edit');
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
            $missing = $missing || !is_int($record->get(self::ORDER));
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

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
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

        if ($this->dm->findType($type, $id) === null) {
            return new Response(404, [], 'Not found');
        }

        $this->dm->deleteType($type, $id);
        if ($this->publishes($type)) {
            $this->publisher->forget($type, $id);
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
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
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
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

        $html = $this->engine->render('@folio/admin/form.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'heading' => $heading,
            'action' => $action,
            'csrf' => $csrf,
            'fields' => $fields,
            'editor_assets' => array_keys($editorAssets + ($this->publishes($type) ? ['htmx.js' => true] : [])),
            'multipart' => $multipart,
            'layout' => $layout,
            'previewable' => $this->frontend->routeFor($type) !== null,
            'preview_url' => $action . '/preview',
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
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
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
     * Full validation (blocks, then the type's rules), then save.
     *
     * @throws ValidationException
     */
    private function saveWithBlocks(TypeDefinition $typeDef, DynamicRecord $record): void
    {
        $this->validator->validate($record);
        $this->dm->saveType($record, false);
    }

    private function publishes(string $type): bool
    {
        return $this->publisher !== null && $this->publisher->enabled($type);
    }

    /**
     * Publishing types: save the working copy (no rules; _publish_at and the
     * loaded _version from the form), then publish when "_action" asks for it.
     * Returns the form to re-render on a version conflict or a failed publish.
     */
    private function savePublishing(ServerRequestInterface $request, DynamicRecord $record, string $type, string $action, string $heading, string $csrf, string $locale, string $editUrl, string $layout = '@folio/admin/_layout.twig'): ?ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (array_key_exists('_publish_at', $body)) {
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
                return $this->form($type, $record->toArray(), $action, $heading, $e->errors(), $csrf, 422, $layout, $locale, $editUrl,
                    notice: 'Saved as draft — not published.',
                );
            }
        }

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

        return [
            'state' => $state->value,
            'label' => $state->label(),
            'version' => (int) ($values['_version'] ?? 0),
            'publish_at' => is_string($values['_publish_at'] ?? null) ? $values['_publish_at'] : '',
            'save_state' => $stored !== null ? PublishingController::stateText($state) : null,
            'revisions' => $stored !== null ? $this->revisionRows($type, $typeDef, (string) $id) : [],
            'autosave_url' => $stored !== null ? $base . '/autosave' : null,
            'share_url' => $stored !== null && $this->previewLinks?->enabled() === true && $this->frontend->routeFor($type) !== null ? $base . '/share' : null,
            'unpublish_url' => $stored !== null && $state !== PublishState::Draft ? $base . '/unpublish' : null,
            'discard_url' => $stored !== null && $state === PublishState::Changed ? $base . '/discard' : null,
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
                'by' => $revision->createdBy,
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
     * first three plain-valued fields.
     *
     * @param array<string, mixed> $listConfig
     * @return array<string, string> field => header label
     */
    private function listColumns(TypeDefinition $typeDef, array $listConfig): array
    {
        $names = array_values(array_filter(
            (array) ($listConfig['columns'] ?? []),
            static fn ($name) => is_string($name) && isset($typeDef->fields[$name]),
        ));

        if ($names === []) {
            foreach ($typeDef->fields as $name => $fieldDef) {
                if ($name !== $typeDef->idField && in_array($fieldDef->type, self::LIST_TYPES, true)) {
                    $names[] = $name;
                }
                if (count($names) === 3) {
                    break;
                }
            }
        }

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
