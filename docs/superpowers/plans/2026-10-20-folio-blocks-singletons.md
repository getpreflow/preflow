# Folio Blocks and Singletons Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pages built from blocks: Preflow components (or data-only classes) whose fields are declared as PHP attributes. Blocks are stored inline, ordered, nestable and translatable, edited as cards with an htmx block picker, and rendered with `folio_blocks()`. Singleton types hold one-off content readable through `folio_get()`.

**Architecture:**
- Core exposes the discovered component map. Folio's `BlockRegistry` reflects `#[Block]`/`#[BlockField]` from those classes and from `app/Blocks/` into `BlockDefinition`s, whose fields reuse `TypeFieldDefinition`, so every field type works inside blocks unchanged.
- A new `blocks` field type builds its value through a `StructuredFieldType` hook in `DraftRecordFactory`. The hook gets the existing value, the editing locale and the uploaded files, so per-locale merging and nested uploads work.
- `BlockComponent` hydrates `#[BlockField]` properties from props.
- Singletons are a type flag handled by `AdminController` and a `Singletons` service.

**Tech Stack:** PHP ≥ 8.4, PHPUnit 11, Twig, htmx 2.0.11 (the vendored copy, served same-origin in the admin), vanilla ES5 `admin.js`.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-blocks-singletons-design.md`

**Branch:** `feat/blocks-singletons` from `main`.

## Global Constraints

- **Conventions:** `declare(strict_types=1)`; `final` classes and `final readonly` value
  objects; tests named `test_snake_case`.
- **CSP:** no inline handlers, `style=` attributes or un-nonced tags in admin output.
  `CspGuardTest` must stay green.
- **Block storage shape:** a list of
  `{"_uid": "<16 hex>", "_block": "<key>", <field>: <value>…}`.
  - Translatable leaves use the locale map `{"en": …, "de": …}`.
  - Structure (blocks, order, keys) is shared across locales.
  - JSON files store it natively; SQL stores it as JSON text (`PdoDriver` already
    encodes arrays).
- **`_uid`** is `bin2hex(random_bytes(8))`. It is stable for the block's lifetime and
  regenerated recursively on duplicate.
- **Input naming** is keyed by uid, not position:
  - `sections[<uid>][<field>]`;
  - nested `sections[<uid>][<child>][<cuid>][<field>]`;
  - order per level in `…[_order][]`;
  - a hidden `…[<uid>][_block]` holds the key.
- **Unknown block keys** are kept untouched. In the editor they appear as a card that can
  only be removed; on the frontend they are skipped.
- **Nesting:** the default depth limit is 4 (`config/folio.php` `blocks.max_depth`). A
  deeper `blocks` field renders as a notice and is not editable.
- **Locales:** `required` inside blocks binds the default locale only, as at the top level.
- **Single-locale apps:** leaves are plain values, with no maps.

## Review Focus

1. **A block class deleted while content uses it.** Editing and saving the page must keep
   that block's stored data byte-for-byte, and the frontend must render the rest of the
   page (Task 2: `test_unknown_block_survives_save_untouched`; Task 5:
   `test_unknown_block_is_skipped_on_frontend`).
2. **Reordered cards.** A card moved up in the DOM must save in the new
   order even though its input names didn't change, including inside nested levels
   (Task 2: `test_order_follows_order_inputs_not_post_order`).
3. **Editing German after blocks were added in English.** German leaves merge into
   existing blocks by uid. A block added while editing German appears in English too, with
   empty leaves that fall back (Task 2: `test_locale_merge_by_uid`).
4. **A malformed POST** (missing `_block`, an unknown uid not in `_order`, a non-array
   `_order`, depth beyond the limit). The save must not crash: such blocks are dropped
   with no PHP warnings, and the rest saves (Task 2: `test_malformed_input_is_ignored`).
5. **A block component that throws during render.** It must show its error boundary while
   the rest of the page renders (Task 5: `test_throwing_block_is_contained`).

---

### Task 1: Block definitions, discovery and hydration

**Files:**
- Create: `packages/components/src/ComponentMap.php`
  - `final readonly class ComponentMap(array $classes)`, where `$classes` maps short
    names to FQCN.
  - `all(): array<string, class-string>`.
- Modify: `packages/core/src/Application.php` (`bootComponentLayer`): bind
  `ComponentMap` with the discovered map.
- Create: `packages/folio/src/Block/Block.php`
  - `#[\Attribute(\Attribute::TARGET_CLASS)] final class Block(string $key, string $label, string $group = 'Blocks', ?string $icon = null, ?string $description = null)`.
- Create: `packages/folio/src/Block/BlockField.php`
  - `#[\Attribute(\Attribute::TARGET_PROPERTY)] final class BlockField(string $type = 'string', ?string $label = null, ?string $help = null, bool $translatable = false, array $validate = [], array $config = [], array $allowed = [], ?int $min = null, ?int $max = null)`.
  - `allowed`/`min`/`max` are merged into the config.
- Create: `packages/folio/src/Block/BlockDefinition.php`
  - `final readonly`: `key`, `label`, `group`, `icon`, `description`,
    `class-string $class`, `bool $isComponent`, `array<string, TypeFieldDefinition> $fields`.
- Create: `packages/folio/src/Block/BlockRegistry.php`
  - Constructor `(list<class-string> $classes)`.
  - `static discover(ComponentMap|array $components, string $blocksDir, ?string $cacheFile = null, bool $useCache = false): self`.
  - `get(string $key): ?BlockDefinition`.
  - `all(): array<string, BlockDefinition>`.
  - `grouped(list<string> $allowed): array<string, list<BlockDefinition>>`.
- Create: `packages/folio/src/Block/BlockComponent.php`
  - `abstract class BlockComponent extends Component`. It overrides `setProps()` to call
    `parent::setProps()`, then assigns each `#[BlockField]` public property from props
    (when present).
  - It exposes `protected string $blockUid` and `public string $_uid`.
- Create: `packages/folio/src/Block/BlockConfigException.php`.
- Modify: `packages/folio/src/FolioServiceProvider.php`. In `register()`, bind
  `BlockRegistry` lazily via `discover(container ComponentMap or [], app/Blocks, storage/cache/folio-blocks.php, useCache: !debug)`.
- Test: `packages/folio/tests/Block/BlockRegistryTest.php`, fixtures under `packages/folio/tests/Fixtures/Blocks/`
  - `HeroBlock` (a component with a template);
  - `LinkBlock` (data-only);
  - `DuplicateHero` (the same key).

**Interfaces:**
- Produces: `BlockRegistry::get/all/grouped`, and `BlockDefinition::$fields` typed as
  `TypeFieldDefinition`, whose constructor is
  `(name, type, searchable, transform, validate, label, help, config, translatable)`.

- [ ] **Step 1: Write the failing tests:**
  - `test_discovers_component_and_data_only_blocks`: `get('hero')->isComponent === true`; `get('link')->isComponent === false`; `get('hero')->fields['headline']->translatable === true`.
  - `test_duplicate_key_is_a_boot_error`: `BlockConfigException`, with the message naming both classes and the key.
  - `test_blocks_field_config_carries_allowed_min_max`: the `tradeoff` field's config is `['allowed' => ['tradeoff_note'], 'max' => 1]`.
  - `test_cache_is_used_when_enabled`: discover with a cache file, delete the fixture dir from the scan list, discover again with `useCache: true` → same keys.
  - `test_grouped_respects_allowed`.
  - `test_block_component_hydrates_fields_from_props`: `setProps(['headline' => 'H', '_uid' => 'u1', 'unrelated' => 'x'])` → `$c->headline === 'H'`, `$c->_uid === 'u1'`; non-BlockField props are not assigned.
  - `test_app_component_map_is_bound`: a booted temp app with `app/Components/X/X.php` → `ComponentMap::all()['X']`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio --filter BlockRegistryTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** Discovery reflects each class's `#[Block]`. Data-only classes come from `app/Blocks/**/*.php` mapped PSR-4 to `App\Blocks\…`.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): block attributes, registry and hydrating block components`.

### Task 2: The `blocks` field: building and storing values

**Files:**
- Create: `packages/folio/src/Field/StructuredFieldType.php`
  - `interface StructuredFieldType { public function buildValue(mixed $input, mixed $existing, array $files, BuildContext $ctx): mixed; }`
- Create: `packages/folio/src/Field/BuildContext.php`
  - `final readonly`: `Locales $locales`, `string $locale`, `bool $writeUploads`,
    `int $depth = 0`.
- Create: `packages/folio/src/Field/Types/BlocksFieldType.php`
  - Key `blocks`.
  - Constructor `(BlockRegistry $blocks, FieldTypeRegistry $fieldTypes, int $maxDepth = 4)`.
  - Implements `FieldType` and `StructuredFieldType`.
- Modify: `packages/folio/src/Content/DraftRecordFactory.php`. A field type implementing
  `StructuredFieldType` gets
  `buildValue($submitted[$name] ?? [], $existing[$name] ?? null, $uploads[$name] ?? [], new BuildContext($locales, $locale, $writeUploads))`.
  Its result is stored as-is, and it is not also handled as translatable.
- Modify: `FolioServiceProvider`. Register `BlocksFieldType` and wire the registry; the
  field registry is needed for the leaves, so pass a closure or set it after
  construction.
- Test: `packages/folio/tests/Field/BlocksFieldTypeTest.php`

**Interfaces:**
- `buildValue` rules:
  - **Order:** iterate `input['_order']` (a list of uids; non-arrays are ignored). For
    each uid with an `input[uid]` array and a string `_block`, take `existing` by uid,
    then:
    - **Unknown key** with `input[uid]['_raw']` (JSON) → decode it and keep it verbatim.
    - **Known key:** for each block field:
      - nested `blocks` → recurse with depth + 1, stopping at `maxDepth`;
      - an upload field → `storeUploads` with the nested files and kept paths, minus
        `<field>_remove`;
      - a scalar → `normalizeInput` then `toStorage`;
      - a translatable leaf (multilingual only) → merge into the existing leaf's map at
        `$ctx->locale`.
  - **Uids** must match `/^[a-f0-9]{16}$/`; others are dropped.
- `fromStorage` decodes a JSON string to a list; `toStorage` returns the list.
  `normalizeInput` is unused (the structured path wins) and returns `[]`.
- **Errors:** `validateBlocks(array $value, array $config, Locales $l): array<string, list<string>>`,
  keyed `uid.field`, nested `uid.child.cuid.field`, plus `''` for the field-level
  `min`/`max`. The rules are a field's `validate` plus the type's `rules()`; `required`
  is checked on the default-locale value.

- [ ] **Step 1: Write the failing tests** (a fixture registry with `hero {headline: string translatable required, tradeoff: blocks allowed [note] max 1}` and `note {chose: string translatable, cover: asset}`):
  - `test_builds_ordered_blocks_with_uids`.
  - `test_order_follows_order_inputs_not_post_order`: the `_order` list `[b, a]` with inputs posted `a` then `b` → stored `[b, a]`, nested too.
  - `test_locale_merge_by_uid`: existing `[{_uid:a,_block:hero,headline:{en:H}}]`, a German POST with headline `Ü` plus a new uid `c` → `a.headline = {en:H, de:Ü}`; `c` is present with `headline {de:…}`.
  - `test_unknown_block_survives_save_untouched`: existing contains `{_uid:z,_block:gone,foo:{x:1}}`, the POST carries `z[_raw]` → stored identical.
  - `test_nested_upload_lands_in_right_block`: `files = [uid => [tradeoff => [cuid => [cover => UploadedFile]]]]` → the child's `cover` path is set, and with `writeUploads: false` it is not.
  - `test_malformed_input_is_ignored`: missing `_block`, a bad uid, `_order` as a string, depth 5 → no exception, no PHP warning (`failOnWarning` is on), and valid siblings kept.
  - `test_validate_blocks_per_instance_and_min_max`: an empty English headline → `['a.headline' => [...]]`; two tradeoff children over `max 1` → `['a.tradeoff' => [...]]`.
  - `test_single_locale_stores_plain_leaves`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio --filter BlocksFieldTypeTest`. Expected: FAIL.
- [ ] **Step 3: Implement**, including the `DraftRecordFactory` hook.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): inline nested blocks field with per-locale merge`.

### Task 3: Block validation on save

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`.
  - In `store`/`update`, after building the draft, for each `blocks` field call
    `validateBlocks` and prefix the error keys with `<field>.`.
  - Any errors → re-render with 422 and those keys, without saving.
  - Errors are merged with `DataManager` validation errors when both occur: validate the
    blocks first, then try `saveType` inside the same `try`, and merge before rendering.
- Modify: `packages/folio/src/Field/FieldContext.php`. Add
  `public array $nestedErrors = []` (keys relative to the field, e.g. `a.headline`),
  `public ?string $locale = null` and `public ?Locales $locales = null`. `form()` fills
  them.
- Test: additions to `FolioAppTest`, with a scaffold helper `enableBlocks()` that:
  - writes `config/models/page.json` with `sections: {type: blocks, allowed: [hero, link]}`;
  - copies the Task 1 fixtures into `app/Components/Hero/` and `app/Blocks/`.

- [ ] **Step 1: Write the failing tests:**
  - `test_block_validation_error_is_422_and_not_saved`: an empty hero headline → 422, the stored page is unchanged, and the body contains the error message inside the card for that uid.
  - `test_valid_blocks_save`: 302, and the stored `sections` has 1 hero.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): validate blocks per instance on save`.

### Task 4: Block editor UI

**Files:**
- Modify: `BlocksFieldType::renderEditor(FieldContext $ctx)`. It renders through Twig
  templates, so the field type gets the engine via the constructor, plus a
  `renderCard(BlockDefinition, array $block, string $namePrefix, FieldContext $ctx, int $depth): string`
  that the fragment controller reuses.
- Create: `packages/folio/templates/admin/blocks/field.twig`, `card.twig`, `picker.twig` and `unknown.twig`.
  - **Card:** a header with icon, label, summary (the first non-empty string leaf in the
    editing locale) and buttons with `data-block-collapse`, `data-block-up`,
    `data-block-down`, `data-block-duplicate`, `data-block-remove`. The body holds the
    leaf editors, named per the global constraint.
  - **Picker:** one button per allowed block, grouped. Each has
    `hx-get="{prefix}/_blocks/{key}/new?name={urlencoded prefix}&locale={locale}&depth={d}"`,
    `hx-target="closest [data-blocks-list]"` and `hx-swap="beforeend"`. With a single
    allowed block, render just that button.
  - **Errors:** cards with `nestedErrors` for their uid render the messages and the class
    `is-open`, and the field shows a summary list linking `#block-<uid>`.
- Create: `packages/folio/src/Http/BlockEditorController.php`
  - `newBlock(ServerRequestInterface): ResponseInterface` returns one card for a fresh
    uid. The `name` prefix must match `/^[a-z_][a-z0-9_]*(\[[a-f0-9]{16}\]\[[a-z_][a-z0-9_]*\])*$/`.
  - Route: `GET {prefix}/_blocks/{key}/new`, protected by the admin middleware.
- Modify: `FolioRoutes`, `AssetController` and `assetMap()`.
  - Serve `htmx.js` (from `preflow/htmx` `assets/htmx.min.js`) and `icons.svg`.
  - `_layout.twig` adds `<script src="{{ folio_asset('htmx.js') }}" defer>` when any form
    field declares the asset `htmx.js` (`BlocksFieldType::assets()` returns `['htmx.js']`).
- Create: `packages/folio/assets/icons.svg`, a sprite with the symbols `block`, `text`,
  `image`, `list`, `link`, `quote`, `code` and `grid` (simple 24px stroke icons drawn for
  this purpose, no third-party set). Use it as
  `<svg aria-hidden="true"><use href="{{ folio_asset('icons.svg') }}#name"></use></svg>`.
- Modify: `packages/folio/assets/admin.js`:
  - delegated handlers for collapse, up/down (move the card DOM node and rewrite that
    level's `_order` inputs), duplicate (clone, then regenerate uids in `name`, `id` and
    `data-block-uid` recursively with 16 random hex characters, and update `_order`) and
    remove;
  - a `htmx:load` listener that re-runs matrix init and appends the new card's uid to
    `_order`.
- Modify: `packages/folio/assets/admin.css` (card styles).
- Test: `packages/folio/tests/Integration/FolioAppTest.php`, `packages/folio/tests/Assets/AdminJsTest.php`, `CspGuardTest` (it already scans templates).

- [ ] **Step 1: Write the failing tests:**
  - `test_blocks_editor_renders_cards_picker_and_order`: an edit form with one hero → `data-block-uid="<uid>"`, `name="sections[_order][]" value="<uid>"`, `name="sections[<uid>][headline]"`, a picker `hx-get` containing `/folio/_blocks/hero/new` and `/folio/_blocks/link/new`, and the htmx script tag.
  - `test_new_block_fragment_returns_card_with_fresh_uid`: GET `/folio/_blocks/hero/new?name=sections&locale=en&depth=0` → 200, and a 16-hex uid inside `sections[<uid>][headline]`.
  - `test_new_block_fragment_rejects_bad_name_and_unknown_key`: `name=x"><script>` → 400; key `nope` → 404.
  - `test_unknown_block_renders_remove_only_card`.
  - `test_nested_blocks_render_inside_card` (hero with a tradeoff child) and `test_depth_limit_shows_notice`.
  - `AdminJsTest::test_block_editor_hooks`: `admin.js` contains `data-block-up`, `data-block-duplicate`, `htmx:load` and `[_order]`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: PASS. Then check by hand in a browser (folio-demo, `APP_DEBUG=0`):
  - add, move, duplicate and remove blocks;
  - save, and check the order is kept;
  - confirm no CSP violations.
- [ ] **Step 5: Commit** `feat(folio): block editor cards with htmx picker`.

### Task 5: Rendering blocks

**Files:**
- Modify: `FolioServiceProvider` (`boot`). Register the Twig functions:
  - `folio_blocks(mixed $blocks, bool $preview = false)`, which is safe HTML;
  - `folio_block(array $block, bool $preview = false)`.
  - They delegate to a new `packages/folio/src/Block/BlockRenderer.php`, constructor
    `(BlockRegistry, ComponentRenderer, Container, ContentLocale)`, with
    `render(mixed $blocks, bool $preview): string`.
- `BlockRenderer` rules:
  - Decode a JSON string, then `LocaleValue::resolveTree($blocks, $locales, ContentLocale::get())`.
  - Per block: skip unknown keys and data-only blocks. Otherwise build the component via
    the container, set props (the block data plus `_uid`) and render it with
    `ComponentRenderer::render`, which already contains errors through `ErrorBoundary`.
  - With `$preview`, wrap each block in `<div class="folio-block" data-folio-block="{uid}">`.
- Modify: `FrontendController`/`PreviewController`. Pass `preview` (already there).
  Templates call `{{ folio_blocks(record.sections, preview) }}`.
- Test: `packages/folio/tests/Block/BlockRendererTest.php` and `FolioAppTest`.

- [ ] **Step 1: Write the failing tests:**
  - `test_renders_component_blocks_in_order`.
  - `test_data_only_children_reach_parent_template`: the hero template iterates `tradeoff` children's `chose`.
  - `test_unknown_block_is_skipped_on_frontend`.
  - `test_throwing_block_is_contained`: a fixture `BoomBlock` throws in `resolveState` → the output contains the other blocks, and the boom block shows the error boundary output (debug) or nothing (prod).
  - `test_preview_markers_only_in_preview`.
  - `test_blocks_render_in_page_locale` (multilingual scaffold) → `/de/…` shows the German headline, and a missing leaf falls back to English.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): render blocks through their components`.

### Task 6: Singletons

**Files:**
- Modify: `TypeListing`/`TypeCatalog`. Add `public bool $singleton = false`, from JSON
  `"singleton": true`.
- Modify: `AdminController`:
  - `list()` for a singleton renders the edit form for the record with id = type key,
    with the action `{p}/{type}/{key}`. When no record exists yet, the form shows
    defaults.
  - `update()` with id = type key for a singleton creates the record when missing.
  - `createForm`/`store`/`destroy`/`reorder` return 404 for singletons.
  - The dashboard links singletons straight to the form (same URL as the list).
- Create: `packages/folio/src/Content/Singletons.php`
  - Constructor `(DataManager, TypeCatalog, ContentLocale)`.
  - `get(string $type): ?array`: the localized `toArray()`, memoized per type+locale;
    null when the type isn't a singleton or there's no record yet.
  - `record(string $type): ?DynamicRecord`.
- Modify: `FolioServiceProvider`. Bind `Singletons` and register the Twig function
  `folio_get(string $type): ?array`.
- Test: `FolioAppTest` and `packages/folio/tests/Content/SingletonsTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_singleton_nav_goes_straight_to_form`: GET `/folio/site` → 200 with a form whose action is `/folio/site/site`, no "New" button and no delete.
  - `test_first_save_creates_record`: POST `/folio/site/site` → 302, and `findType('site','site')` is not null.
  - `test_singleton_new_and_delete_are_404`.
  - `test_folio_get_returns_null_before_first_save_then_values`.
  - `test_singleton_is_localized` (multilingual): `folio_get('site').name` shows German under `/de/…`.
  - `test_memoized_per_request`: two calls make one query (spy driver or count via DataManager wrapper).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): singleton content types and folio_get()`.

### Task 7: Demo and docs

**Files:**
- `examples/folio-demo`:
  - `app/Components/Hero/Hero.{php,twig}` (a block component with translatable headline
    and intro, plus a nested `links` field of data-only `link` blocks);
  - `app/Blocks/LinkBlock.php`;
  - `config/models/page.json` gains `sections: {type: blocks, allowed: [hero]}`;
  - `config/models/site.json` (singleton: name, tagline translatable);
  - the seed adds a hero to Home and a site record;
  - `resources/folio/frontend/page.twig` override rendering `folio_blocks(record.sections, preview)`.
- `packages/folio/README.md`:
  - a "Blocks" section: defining blocks, data-only blocks, storage shape, editing,
    rendering, depth;
  - a "Blocks vs matrix vs relation" section;
  - a "Singletons" section;
  - the Status line updated.
- Test: `examples/folio-demo/tests/FolioDemoTest.php` gains `test_demo_renders_hero_block` (seeded temporary copy, as in the i18n plan).

- [ ] **Step 1: Write the failing test.**
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Examples`. Expected: FAIL.
- [ ] **Step 3: Implement the demo and docs.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `docs(folio): blocks and singletons in the demo and README`.

---

## After the plan

- Add this plan's row to `docs/superpowers/plans/README.md`.
