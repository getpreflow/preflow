# Model Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A developer writes one PHP model class; app code gets typed objects, and Folio edits the same model as a type without a second definition.

**Architecture:** A model class marked `#[Entity(type: 'job')]` produces a *schema array* of exactly the shape a model JSON file has (`ModelSchema::of()`). `TypeRegistry` serves that schema as the type `job`, merged with an optional admin-only `config/models/job.json` that may hold presentation only. Everything downstream already consumes schema arrays and `TypeDefinition`s (Folio's `TypeCatalog`, `TableSync`, admin, publishing, page cache), so Folio works on `DynamicRecord`s of the same table and needs no class-specific code paths. App code reads and writes the class through `DataManager` as before, with the type's behaviour (ids, localization, loose search, untouched bookkeeping columns).

**Tech Stack:** PHP 8.4+, PHPUnit 11, the Preflow monorepo (`packages/data`, `packages/core`, `packages/folio`, `packages/devtools`).

**Spec:** `docs/superpowers/specs/2026-11-09-model-bridge-design.md`

## Global Constraints

- The rule behind every call: **a class-backed model behaves exactly like the equivalent JSON type does today**: same tables, columns, ids, stored formats, read and write paths and page cache tags. Where this plan is silent, today's JSON-type behaviour is the answer.
- The class doesn't depend on Folio: no Folio import, base class or attribute in a model class.
- One definition per key: a class and a JSON file both defining the schema of a key is a boot error with a clear message, never a silent override.
- Opt-in is explicit: only `#[Entity(type: …)]` makes a class a type; it appears in Folio only when an admin file exists for that key. Existing typed models (the skeleton's `Post`) and all JSON types work unchanged.
- Folio's columns (`_version`, `_publish_at`, `_share_token`, `_published_at`, `_published_by`, `_order`, and every other column the class doesn't declare) are invisible to the class; a typed save leaves them untouched.
- Derived types go into the boot manifest (`preflow optimize`); a class-backed type costs no more per request than a JSON type.
- Framework rules (owner): features in the framework's packages behind public interfaces; Folio is only the UI adapter.
- Code style: match the surrounding code (docblocks explain why, `final` classes, `declare(strict_types=1)`, plain sentences in messages).
- Tests: `vendor/bin/phpunit` per package directory (e.g. `cd packages/data && ../../vendor/bin/phpunit`); never run two suites at once.

## Review Focus

1. A class with a nullable property and no `#[Field]` (a plain public property): it must not become a field of the type, and a save must still write it as today.
2. A type moved from JSON to a class whose JSON had a field the class forgot: Folio must not silently drop that column's data; the column stays (nothing is ever dropped) and the generator's round-trip test catches the omission.
3. An admin file left with `"fields": {"title": {"type": "string"}}` next to a class: a boot error naming the file, the key and the field, not a merge.
4. A typed `find()` of a publishing type in app code returns the working copy (drafts included), as `findType()` does; only `PublishedModels` reads published copies.
5. Saving an object loaded through `PublishedModels` (read-only) throws a `LogicException` before any write.

---

## File Structure

**packages/data**
- Modify `src/Attributes/Entity.php`: optional `type` (the opt-in).
- Modify `src/Attributes/Field.php`: `type`, `label`, `help`, `config`, `default`.
- Create `src/Attributes/Relation.php`: relation config on a property.
- Create `src/ModelSchema.php`: class → schema array; admin overlay merge; the list of schema keys.
- Create `src/ModelClasses.php`: discovers type-marked classes (key → class), manifest-cacheable.
- Modify `src/TypeRegistry.php`: class-backed types, admin overlay, conflict errors, `schema()` accessor.
- Modify `src/ModelMetadata.php`: `type` (the key or null).
- Modify `src/DataManager.php`: uuid on insert for type-marked classes, type-aware typed queries, `findAs()`/`queryAs()` read-only loads, refusal to save read-only objects.
- Modify `src/QueryBuilder.php`: a typed builder can carry the derived `TypeDefinition`.
- Modify `src/ModelLabelKeys.php`: class-backed types' label keys.

**packages/core**
- Modify `src/Application.php` (~line 709): build `ModelClasses` and give `TypeRegistry` the classes, also when `config/models` doesn't exist.

**packages/folio**
- Modify `src/Content/TypeCatalog.php`: list class-backed types through their merged schema.
- Create `src/Publishing/PublishedModels.php`: typed reads of published copies.
- Modify `src/FolioServiceProvider.php`: pass the registry to `TypeCatalog`; register `PublishedModels`.

**packages/devtools**
- Modify `src/Command/MakeModelCommand.php`: `--from-type=<key>`.

**Docs:** `packages/data/README.md`, `packages/folio/README.md`, `packages/devtools/README.md`, `ROADMAP.md`.

---

### Task 1: A model class describes a type (`ModelSchema::of`)

**Files:**
- Modify: `packages/data/src/Attributes/Entity.php`, `packages/data/src/Attributes/Field.php`, `packages/data/src/ModelMetadata.php`
- Create: `packages/data/src/Attributes/Relation.php`, `packages/data/src/ModelSchema.php`
- Test: `packages/data/tests/ModelSchemaTest.php`, fixtures in `packages/data/tests/Fixtures/ModelSchema/`

**Interfaces:**
- Produces:
  - `#[Entity(table: string, storage: string = 'default', type: ?string = null)]`
  - `#[Field(searchable, translatable, transform, unique, index, length, type: ?string = null, label: string|array|null = null, help: string|array|null = null, config: array = [], default: mixed = null)]` (existing parameters keep their order and defaults; the new ones are appended)
  - `#[Relation(to: string, onDelete: string = 'restrict', onSelfDelete: ?string = null, labelField: ?string = null)]` on a property; written to the field's `config['relation']` with the JSON keys `to`, `onDelete`, `onSelfDelete`, `labelField` (null ones left out)
  - `ModelMetadata::$type: ?string`
  - `final class ModelSchema { public static function of(string $modelClass): array; public const array SCHEMA_FIELD_KEYS; public const array SCHEMA_TYPE_KEYS; }`

The schema array has the model-file shape: `key`, `table`, `storage`, `id_field`, `fields` (name → field array). A field array holds `type`, and only what is set: `label`, `help`, `validate` (from `#[Validate]` rules, without `on:` scenarios' rules, which stay class-only), `translatable`, `unique`, `index`, `length`, `searchable`, `transform`, `default`, `relation`, and the `config` entries spread in at top level (so `config: ['options' => […]]` becomes `"options": […]`, as a JSON file writes it).

Field type when `type` is not given, from the PHP property type: `string` → `string`, `int`/`float` → `number`, `bool` → `boolean`, `\DateTimeInterface` → `date`, `array` → `text` is wrong for blocks, so **an `array` property without `type` is an error** ("give the field a type: blocks, matrix, asset or relation"). A `#[Relation]` property is `relation`. Only properties with `#[Field]` or `#[Relation]` are fields; the `#[Id]` property is the `id_field` and not a field; `#[Timestamps]` adds nothing to `fields` (JSON types don't declare timestamps).

`SCHEMA_TYPE_KEYS = ['key', 'table', 'storage', 'id_field', 'fields', 'matrixable', 'views']`; `SCHEMA_FIELD_KEYS = ['type', 'validate', 'translatable', 'unique', 'index', 'length', 'searchable', 'transform', 'default', 'relation', 'options', 'accept', 'time', 'blocks', 'fields']` (the last for matrix sub-fields). Presentation keys are everything else (`label`, `help`, `readonly`, `hidden`, `placeholder`, …).

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_class_gives_the_schema_its_json_file_would(): void
{
    // Fixtures/ModelSchema/Job.php: #[Entity(table: 'job', type: 'job')], #[Id] ?string $uuid,
    // title (#[Field(label: 'Title')] #[Validate('required')] string), remote (#[Field(type: 'select',
    // config: ['options' => ['no' => 'No', 'hybrid' => 'Hybrid', 'fully' => 'Fully']])] ?string),
    // company (#[Relation(to: 'company', labelField: 'name')] ?string), logo (#[Field(type: 'asset',
    // config: ['accept' => ['image/*']])] ?string), salary_from (#[Field] ?int), open (#[Field] bool)
    $this->assertSame(
        json_decode(file_get_contents(__DIR__ . '/Fixtures/ModelSchema/job.json'), true),
        ModelSchema::of(Job::class),
    );
}

public function test_an_unmarked_model_is_not_a_type(): void
{
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('#[Entity(type: …)]');
    ModelSchema::of(PlainPost::class); // #[Entity(table: 'posts')] only
}

public function test_an_array_property_needs_a_field_type(): void
{
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('blocks, matrix, asset or relation');
    ModelSchema::of(UntypedArray::class);
}

public function test_a_property_without_field_or_relation_is_not_a_field(): void
{
    $this->assertArrayNotHasKey('scratch', ModelSchema::of(WithPlainProperty::class)['fields']);
}

public function test_metadata_knows_the_type_and_old_entities_stay_untyped(): void
{
    $this->assertSame('job', ModelMetadata::for(Job::class)->type);
    $this->assertNull(ModelMetadata::for(PlainPost::class)->type);
}
```

`job.json` is written by hand in model-file shape (`"relation": {"to": "company", "labelField": "name"}`, the select's `"options"` at field level, `"validate": ["required"]`, `"label": "Title"`).

- [ ] **Step 2: Run them to see them fail**

Run: `cd packages/data && ../../vendor/bin/phpunit tests/ModelSchemaTest.php`
Expected: FAIL, class `ModelSchema` not found.

- [ ] **Step 3: Implement the attributes, `ModelMetadata::$type` and `ModelSchema::of()`**

Read the property attributes once with reflection; keep property declaration order as field order (Folio's form order).

- [ ] **Step 4: Run the data suite**

Run: `cd packages/data && ../../vendor/bin/phpunit`
Expected: PASS (existing model tests unchanged).

- [ ] **Step 5: Commit**

```bash
git add packages/data
git commit -m "feat(data): a model class marked with a type describes it as a schema"
```

---

### Task 2: The type registry serves class-backed types

**Files:**
- Create: `packages/data/src/ModelClasses.php`
- Modify: `packages/data/src/TypeRegistry.php`, `packages/data/src/ModelSchema.php` (overlay), `packages/data/src/ModelLabelKeys.php`, `packages/core/src/Application.php:705-720`
- Test: `packages/data/tests/TypeRegistryClassesTest.php`, `packages/data/tests/ModelClassesTest.php`, `packages/core/tests/ModelClassesBootTest.php`

**Interfaces:**
- Consumes: `ModelSchema::of()`, `SCHEMA_TYPE_KEYS`, `SCHEMA_FIELD_KEYS` (Task 1)
- Produces:
  - `final class ModelClasses { public function __construct(list<string> $paths, ?BootManifest $manifest = null); public function all(): array /* key => class-string */; public function classFor(string $key): ?string; }`. Scans `*.php` under each path for classes with `#[Entity(type: …)]` (a cheap text check for `type:` inside `#[Entity` before autoloading); two classes with one key is an error naming both. Remembered in the manifest as `data.model_classes` with the paths as sources.
  - `TypeRegistry::__construct(string $modelsPath, ?Locales $locales = null, array $classes = [])`: `$classes` is key → class.
  - `TypeRegistry::schema(string $type): array`: the merged schema array of a class-backed type, or the file's schema for a JSON type.
  - `TypeRegistry::classFor(string $type): ?string`
  - `ModelSchema::overlay(array $classSchema, array $adminFile, string $file): array`
- Config: `data.model_paths` (list, default `[app/Models]` under the base path).

Rules of `TypeRegistry` for a key with a class:
- no file: the type is the class's schema;
- a file with any of `table`, `storage`, `id_field`, `matrixable`, `views`, or a `fields` entry with a `SCHEMA_FIELD_KEYS` key: `\RuntimeException` naming the file, the key and the first offending key ("… is defined by App\Models\Job; this file may only hold its admin settings");
- a file with `fields` entries for fields the class doesn't have: error naming the field;
- otherwise the overlay: the file's type-level keys added, its per-field presentation keys merged into the class's fields (the file wins for `label` and `help`).
- A JSON file with no schema keys and no class for its key: error "no model class for type …".
- `keys()` and `has()` include class keys; `define()` refuses a class key.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_class_is_a_type_without_a_file(): void
{
    $registry = new TypeRegistry($this->emptyDir(), null, ['job' => Job::class]);
    $this->assertTrue($registry->has('job'));
    $this->assertSame(['job'], $registry->keys());
    $this->assertSame('job', $registry->get('job')->table);
    $this->assertSame('relation', $registry->get('job')->fields['company']->type);
    $this->assertSame(Job::class, $registry->classFor('job'));
}

public function test_the_admin_file_adds_presentation(): void
{
    $dir = $this->dirWith('job.json', ['key' => 'job', 'label' => 'Jobs', 'list' => ['columns' => ['title']],
        'fields' => ['title' => ['label' => 'Job title', 'readonly' => true]]]);
    $registry = new TypeRegistry($dir, null, ['job' => Job::class]);
    $this->assertSame('Job title', $registry->get('job')->fields['title']->label);
    $this->assertTrue($registry->get('job')->fields['title']->config['readonly']);
    $this->assertSame('Jobs', $registry->schema('job')['label']);
    $this->assertSame(['required'], $registry->get('job')->fields['title']->validate, 'the class keeps its schema');
}

public function test_a_file_redefining_the_schema_is_an_error(): void
{
    $dir = $this->dirWith('job.json', ['key' => 'job', 'fields' => ['title' => ['type' => 'text']]]);
    $this->expectExceptionMessage('job.json');
    $this->expectExceptionMessage('"type"');
    (new TypeRegistry($dir, null, ['job' => Job::class]))->get('job');
}

public function test_a_file_for_a_field_the_class_lacks_is_an_error(): void { /* fields.nope.label → message names "nope" */ }
public function test_a_slim_file_without_a_class_is_an_error(): void { /* {"key":"job","label":"Jobs"} → "no model class" */ }
public function test_json_types_are_unchanged(): void { /* an existing full JSON type parses as before; schema() returns the file */ }
```

`ModelClassesTest`: finds `Job` in a fixture directory, ignores `PlainPost`, errors on two classes with one key, and returns the remembered value from a recording-then-loaded `BootManifest` without scanning (assert by deleting the fixture file between record and load).

`ModelClassesBootTest`: an app with `app/Models/Job.php` marked `type: 'job'` and no `config/models` directory has `TypeRegistry` bound and `->has('job')`.

- [ ] **Step 2: Run them to see them fail**

Run: `cd packages/data && ../../vendor/bin/phpunit --filter 'TypeRegistryClasses|ModelClasses'`
Expected: FAIL.

- [ ] **Step 3: Implement `ModelClasses`, the registry rules, `ModelSchema::overlay()`, the label keys and the core wiring**

`ModelLabelKeys` reads `TypeRegistry::schema()` for class keys so their labels are translatable like a JSON type's.

- [ ] **Step 4: Run the data and core suites**

Run: `cd packages/data && ../../vendor/bin/phpunit`, then `cd packages/core && ../../vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/data packages/core
git commit -m "feat(data): the type registry serves model classes, with an admin-only file for presentation"
```

---

### Task 3: Typed reads and writes behave like the type

**Files:**
- Modify: `packages/data/src/DataManager.php`, `packages/data/src/QueryBuilder.php`
- Test: `packages/data/tests/TypedTypeParityTest.php` (SQLite and JSON storage), `packages/data/tests/ReadOnlyModelTest.php`, `packages/core/tests/PageCache/ClassTypeTagsTest.php`

**Interfaces:**
- Consumes: `ModelMetadata::$type`, `TypeRegistry::get()`, `TypeRegistry::classFor()` (Tasks 1–2)
- Produces:
  - `DataManager::query(Job::class)` on a type-marked class returns a `QueryBuilder` that carries the derived `TypeDefinition` (localized comparison of translatable fields, `searchLoose` across relations) and hydrates `Job` objects.
  - `DataManager::findAs(string $modelClass, string|int $id, string $table): ?Model` and `DataManager::queryAs(string $modelClass, string $table): QueryBuilder`: load the class from another table of the same shape; the objects are read-only.
  - `DataManager::isReadOnly(Model $model): bool`; `save()`, `insert()`, `update()`, `delete()` of a read-only object throw `\LogicException('… was loaded read-only (from table …) and cannot be saved')`.

Behaviour to pin:
- Inserting a type-marked class whose id property is a string type and empty sets the id to `bin2hex(random_bytes(16))`, Folio's id format (`AdminController.php:529`), before the write.
- A typed save writes only the class's public properties; a stored `_version` = 3 and `_publish_at` survive a `save($job)` unchanged.
- Translatable fields: what a typed save stores is byte-identical to what `saveType()` stores for the same values (a locale map as JSON text on SQL); the property's PHP type is the implementer's choice, documented in the README.
- Reads and writes report exactly the tags the same JSON type reports (`reportRead`/`reportWrite` with the same storage, table and id).

- [ ] **Step 1: Write the failing tests**

```php
public function test_insert_generates_the_id_folio_would(): void
{
    $job = new Job(); $job->title = 'Carpenter';
    $this->dm->save($job);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $job->uuid);
}

public function test_a_typed_save_leaves_folios_columns_alone(): void
{
    $record = DynamicRecord::fromArray($this->types->get('job'), ['uuid' => 'a1', 'title' => 'Old', '_version' => 3, '_publish_at' => '2026-12-01 08:00:00']);
    $this->dm->saveType($record, validate: false);
    $job = $this->dm->find(Job::class, 'a1');
    $job->title = 'New';
    $this->dm->save($job);
    $stored = $this->dm->findType('job', 'a1');
    $this->assertSame('New', $stored->get('title'));
    $this->assertSame(3, (int) $stored->get('_version'));
    $this->assertSame('2026-12-01 08:00:00', $stored->get('_publish_at'));
}

public function test_a_typed_save_stores_what_save_type_stores(): void { /* same values incl. a translatable map → raw rows equal */ }
public function test_a_typed_query_compares_translatable_fields_in_the_locale(): void { /* ->locale('de')->where('title', 'Schreiner') finds it */ }
public function test_a_typed_loose_search_reaches_related_records(): void { /* searchLoose('Acme') finds jobs of company Acme */ }
```

```php
public function test_an_object_loaded_from_another_table_cannot_be_saved(): void
{
    $job = $this->dm->findAs(Job::class, 'a1', 'job__published');
    $this->assertTrue($this->dm->isReadOnly($job));
    $this->expectException(\LogicException::class);
    $this->dm->save($job);
}
```

`ClassTypeTagsTest`: with the page cache's recorder observing, `find(Job::class, 'a1')`, `query(Job::class)->get()`, `save($job)` and `findAs(…, 'job__published')` record the same tags as `findType('job', 'a1')`, `queryType('job')->get()`, `saveType()` and `findIn(ShadowTypes-style def, 'a1')`.

- [ ] **Step 2: Run them to see them fail**

Run: `cd packages/data && ../../vendor/bin/phpunit --filter 'TypedTypeParity|ReadOnlyModel'`
Expected: FAIL.

- [ ] **Step 3: Implement in `DataManager` and `QueryBuilder`**

Track read-only objects in a `\WeakMap` on the `DataManager`. The typed `QueryBuilder` gets the `TypeDefinition` only when the class is type-marked and the registry has the key; otherwise it behaves exactly as today.

- [ ] **Step 4: Run the data and core suites**

Run: `cd packages/data && ../../vendor/bin/phpunit`, then `cd packages/core && ../../vendor/bin/phpunit tests/PageCache`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/data packages/core
git commit -m "feat(data): typed reads and writes of a class-backed type behave like the type"
```

---

### Task 4: Folio edits class-backed types

**Files:**
- Modify: `packages/folio/src/Content/TypeCatalog.php`, `packages/folio/src/FolioServiceProvider.php:70-95`
- Create: `packages/folio/src/Publishing/PublishedModels.php`
- Test: `packages/folio/tests/ClassTypeCatalogTest.php`, `packages/folio/tests/ClassTypeAdminAppTest.php` (web test through preflow/testing, like the existing admin app tests), `packages/folio/tests/PublishedModelsTest.php`

**Interfaces:**
- Consumes: `TypeRegistry::schema()`, `TypeRegistry::classFor()`, `DataManager::findAs()`, `DataManager::queryAs()` (Tasks 2–3); `ShadowTypes::published()` naming (`{table}__published`)
- Produces:
  - `TypeCatalog::__construct(string $modelsPath, ?BootManifest $manifest = null, ?TypeRegistry $types = null)`: an admin file whose key is class-backed is listed from `$types->schema($key)` (so `hidden` checks, `transfer` file checks and labels see the class's fields). A class without an admin file is not listed.
  - `final class PublishedModels { public function find(string $modelClass, string|int $id): ?Model; public function query(string $modelClass): QueryBuilder; }`: read-only objects from `{table}__published`; refuses (`\LogicException`) a class whose type doesn't have publishing on.

- [ ] **Step 1: Write the failing tests**

`ClassTypeCatalogTest`: a site with `Job` (`type: 'job'`) and `config/models/job.json` = `{"key": "job", "label": "Jobs", "publishing": true, "list": {"columns": ["title", "company"]}}` lists `job` with label "Jobs" and publishing on; a list column naming a field the admin file marks `hidden` fails as for JSON types; without the admin file `job` is not listed but `TypeRegistry::has('job')`.

`ClassTypeAdminAppTest` (SQLite, `folio.tables` run as in the existing app tests):
```php
public function test_staff_create_edit_and_publish_a_class_backed_record(): void
{
    // sign in as admin; POST the create form for /folio/job with title "Carpenter";
    // the list at /folio/job shows "Carpenter";
    // edit it to "Senior carpenter"; publish it
    $job = $this->dm->find(\Tests\Fixtures\Job::class, $uuid);
    $this->assertSame('Senior carpenter', $job->title, 'the app reads what staff saved');
    $published = $this->app->get(PublishedModels::class)->find(\Tests\Fixtures\Job::class, $uuid);
    $this->assertSame('Senior carpenter', $published->title);
}

public function test_an_app_save_does_not_disturb_publishing(): void
{
    // publish in Folio, then $dm->save($job) with a new title from app code:
    // the published copy keeps the old title, Folio shows unpublished changes, _version unchanged by the app save
}
```

Also in `ClassTypeAdminAppTest`: after staff save a draft change "Draft title" without publishing, `$dm->find(Job::class, $uuid)->title` is "Draft title" (app code reads the working copy, as `findType()` does) while `PublishedModels::find()` still gives the published title.

`PublishedModelsTest`: a draft-only record is not found; `query()->where('title', …)` returns read-only `Job` objects; a type without publishing is refused.

- [ ] **Step 2: Run them to see them fail**

Run: `cd packages/folio && ../../vendor/bin/phpunit --filter 'ClassType|PublishedModels'`
Expected: FAIL.

- [ ] **Step 3: Implement the catalog change, `PublishedModels` and the provider wiring**

The boot manifest key `folio.type_listings` gains the registry's class map as a source so a new class invalidates it.

- [ ] **Step 4: Run the Folio suite**

Run: `cd packages/folio && ../../vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/folio
git commit -m "feat(folio): staff edit model classes like types; typed reads of published copies"
```

---

### Task 5: Generate a class from a JSON type

**Files:**
- Modify: `packages/devtools/src/Command/MakeModelCommand.php`
- Test: `packages/devtools/tests/MakeModelFromTypeTest.php`, fixtures `packages/devtools/tests/Fixtures/types/object.json` (a copy of holz-objekte.org's `config/models/object.json`) and `game.json` (a copy of bggenius-preflow's game type), plus a hand-written one covering translatable, matrix, blocks, unique-with-scope and a multilingual label

**Interfaces:**
- Consumes: `ModelSchema::of()`, `ModelSchema::overlay()`, `TypeRegistry` rules (Tasks 1–2)
- Produces: `preflow make:model <Class> --from-type=<key>`: writes `app/Models/<Class>.php` (namespace `App\Models`, `#[Entity(table, storage, type)]`, one property per field with the attributes that reproduce it, PHP types from the field type, nullable unless `validate` has `required`), and rewrites `config/models/<key>.json` to its admin-only part. Refuses when the class file exists or the key is already class-backed. Prints what it wrote and "Check the class, then run your tests: nothing in the database changes."

- [ ] **Step 1: Write the failing tests**

```php
/** @dataProvider types */
public function test_the_generated_class_round_trips_to_the_same_type(string $fixture): void
{
    $original = json_decode(file_get_contents($fixture), true);
    $this->run('make:model', ucfirst($original['key']), '--from-type=' . $original['key']);
    require $this->base . '/app/Models/' . ucfirst($original['key']) . '.php';
    $registry = new TypeRegistry($this->base . '/config/models', null, [$original['key'] => 'App\\Models\\' . ucfirst($original['key'])]);
    $this->assertEquals((new TypeRegistry($this->fixtureDir))->get($original['key']), $registry->get($original['key']));
    $this->assertSame([], array_intersect(array_keys(json_decode(file_get_contents($this->base . '/config/models/' . $original['key'] . '.json'), true)), ['table', 'storage', 'id_field']));
}

public function test_it_refuses_an_existing_class(): void { /* exit code 1, nothing rewritten */ }
```

- [ ] **Step 2: Run them to see them fail**

Run: `cd packages/devtools && ../../vendor/bin/phpunit tests/MakeModelFromTypeTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `--from-type`**

The `TypeDefinition` equality is the contract: whatever the class needs (`config:` for keys without a dedicated parameter) to reproduce the type exactly. A JSON key the generator can't express is an error naming it, never a silent drop.

- [ ] **Step 4: Run the devtools suite**

Run: `cd packages/devtools && ../../vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/devtools
git commit -m "feat(devtools): make:model --from-type turns a JSON type into a model class"
```

---

### Task 6: Docs and release 0.61.0

**Files:**
- Modify: `packages/data/README.md` (a section "Model classes as types": the opt-in, field types and inference, `#[Relation]`, ids, read-only loads, what goes on the class vs the admin file; "content without code is a JSON file; the app's own models are PHP classes; Folio edits both"), `packages/folio/README.md` (admin-only files for model classes, `PublishedModels`), `packages/devtools/README.md` (`make:model --from-type`), `ROADMAP.md` (0.61.0 entry with follow-ups; current state line)

- [ ] **Step 1: Write the docs**
- [ ] **Step 2: Run the full suite**

Run: `vendor/bin/phpunit` from the repo root (about 11 minutes)
Expected: OK, no failures.

- [ ] **Step 3: Commit, tag and push**

```bash
git add packages/*/README.md ROADMAP.md
git commit -m "docs: 0.61.0"
git tag v0.61.0 && git push origin main && git push origin v0.61.0
```

- [ ] **Step 4: Check both split runs (main and the tag) succeeded; rerun failed jobs**
- [ ] **Step 5: Upgrade and deploy the 5 sites** (suites serially, backups first, as for 0.60.1). No site moves a type to a class in this plan.
