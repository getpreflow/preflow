# preflow/blocks

Page sections as typed classes. A block type is a PHP class with a `#[Block]` attribute; its fields
are `#[BlockField]` properties. A page stores its blocks as plain data (a list inside the record),
and a template renders them with `blocks()`, each through its own component. Folio adds the
`blocks` field type and its editor; the blocks themselves need no Folio.

## Install

```
composer require preflow/blocks
```

Add `Preflow\Blocks\BlocksServiceProvider` to `config/providers.php`. With Folio, skip this: Folio
registers it itself.

## Write a block type

A block that renders itself is a component. Put the class and its template in one folder under
`app/Components`:

```php
<?php
// app/Components/Blocks/Banner/Banner.php

namespace App\Components\Blocks\Banner;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

#[Block(key: 'banner', label: 'Banner', group: 'Sections', icon: 'flag', description: 'A heading with a short text')]
final class Banner extends BlockComponent
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $title = '';

    #[BlockField(type: 'text', translatable: true)]
    public string $text = '';

    #[BlockField(type: 'blocks', allowed: ['quote'], max: 3)]
    public array $quotes = [];
}
```

```twig
{# app/Components/Blocks/Banner/Banner.twig #}
<section class="banner">
    <h2>{{ title }}</h2>
    <p>{{ text }}</p>
    {% for quote in quotes %}<blockquote>{{ quote.text }}</blockquote>{% endfor %}
</section>
```

- **`key`** is what the stored data names (`"_block": "banner"`). Keep it when you rename the
  class, and the stored blocks stay yours. Two classes with the same key fail when the registry is first built (the first page with blocks, the editor, or `preflow optimize`), naming both.
- **`label`**, **`group`**, **`icon`** and **`description`** are for an editor (Folio's lists the
  blocks by group). A block without a group is in `Block::DEFAULT_GROUP` ("Blocks").
- **`family`** (since 0.75) says what kind of content the block holds, a `Preflow\Data\TypeFamily`
  (`Page`, `Reference`, `Asset`, `Member`, `Shop`, `Live`): an editor tints the block's tile with it
  (Folio's block cards and picker). Without one the tile is neutral.
- **`#[BlockField]`** takes the options of a type field: `type`, `label`, `help`, `translatable`,
  `validate`, and `config` for anything else (select `options`, …). A `blocks` field nests blocks:
  `allowed` lists the keys it takes, `min` and `max` how many. Field names starting with `_` are
  refused (`_uid`, `_block` and `_order` are the system's). A select may take its options from
  another field **of the same block** instead of `options` (since 0.90):
  `#[BlockField(type: 'select', config: ['options_from' => 'answers'])]` beside a `tags` field
  `answers`. Each block uses its own values (two blocks of one type each choose from their own
  list); Folio's editor shows them as the options, and a save accepts only one of the values the
  same block submits. A name that is not another field of the block, a `blocks` (or `matrix`)
  field as the source (it holds entries, not values), the select itself, both
  `options` and `options_from`, `display: cards`, or `options_from` on a field that is not a
  select fail when the registry is built, naming the block (`Block "poll": field "answer": …`).
  A field may show only while a rule on another field **of the same block** holds (since 0.91;
  the rule is preflow/data's `show_when`, see its README):
  `#[BlockField(type: 'string', config: ['show_when' => ['field' => 'kind', '=' => 'link']])]`.
  Each block decides on its own values, so two blocks of one type can show different fields. A
  rule that cannot work (malformed, naming a field the block does not have, the field itself, a
  translatable field, or a cycle) fails when the registry is built, naming the block and the
  field: `Block "offer": field "button_url": show_when names "style", which is not a field of
  the block. Name a field of the same block.`
- **`BlockComponent`** fills each `#[BlockField]` property from the block's data before
  `resolveState()`, cast to the property's type. The block's id is `$this->_uid`. Everything else
  is an ordinary component: constructor injection, `resolveState()`, actions, CSS next to it.

### Data-only blocks

A block that its parent renders needs no component. Put it in `app/Blocks` (namespace
`App\Blocks\…`):

```php
<?php
// app/Blocks/Quote.php

namespace App\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'quote', label: 'Quote')]
final class Quote
{
    #[BlockField(type: 'string', translatable: true)]
    public string $text = '';
}
```

The banner above prints its quotes in its own template. `blocks()` skips a data-only block it
meets on its own.

### From a package

A package brings its block types by being installed: list them in its `composer.json`.

```json
"extra": {
    "preflow": {
        "blocks": ["Acme\\Cta\\CtaBlock"]
    }
}
```

A listed class without `#[Block]` fails when the registry is first built (the first page with blocks, the editor, or `preflow optimize`), naming the package.

## Render

```twig
{{ blocks(page.sections) }}
```

`blocks(items, preview = false)` takes the stored list (or its JSON) and renders each block
through its component, in order:

- **Language:** translatable fields hold one value per language (`{"en": "…", "de": "…"}`); a block
  gets the value of the current locale (`BlockLocale`, below).
- **Nesting:** a block template renders its own `blocks` fields with `blocks(children)`. Every
  level renders; stored blocks with more levels than `max_depth` (older data, an import) log one
  warning per request (the site's PSR logger, else `error_log()`).
- **Skipped:** unknown keys (a removed class), data-only blocks, and a block that can't be built
  (it is left out, never the page; the page cache doesn't store that page). A block that throws
  while rendering is contained by the component error boundary.
- **Preview:** `blocks(items, true)` marks each block's own root element (no wrapper) with
  `data-block="<uid>"` and `data-block-label`, nested blocks included, so an editor can find
  them on the page.

Values reach the template as stored. Escape them as usual (`{{ title }}`). One exception (since
0.91): a field its `show_when` hides reaches the template as `null`, in the block and in every
block nested in it (a data-only child its parent prints too), each deciding on its own values.
The stored block keeps the value. For values you read yourself, ask the registry:
`$blocks->visibleBlock($block)` (one block and its nested ones),
`$blocks->visibleBlocksIn($values, $type->fields)` (a record's blocks fields; Folio's `folio_get`,
`folio_list`, `folio_find` and a page's `record` use it) and `$blocks->hiddenFieldsOf($key, $values)`.
Without any `show_when`, they return what they are given.

In PHP, the same renderer: `$container->get(Preflow\Blocks\BlockRenderer::class)->render($items)`.
The registry of all block types is `Preflow\Blocks\BlockRegistry` (`all()`, `get($key)`,
`grouped($allowed)`).

## Storage

A blocks field is an ordered list inside the record; nothing extra to migrate:

```json
[
    {"_uid": "a1b2c3d4e5f60718", "_block": "banner", "title": {"en": "Hello", "de": "Hallo"},
     "quotes": [{"_uid": "0f1e2d3c4b5a6978", "_block": "quote", "text": {"en": "…"}}]}
]
```

### Checked on every write (0.92)

A block's values are checked wherever a record is saved with validation, not only in an editor's
form: a seed, an import or an API controller calling `DataManager::saveType()` is refused for the
same block value, with the same key and message. A section whose required title is empty fails as
`sections.<uid>.title` ("This field is required."); a quote inside it as
`sections.<uid>.quotes.<cuid>.text`; a block in a page area as `_areas.main.<uid>.title`.

Each block is checked on its own values, in the default language: each field's `validate`
(`required` included), a select's options (`options`, or `options_from` read from the same block),
and the checks a field type brings (`FieldKinds::rules()`, such as preflow/fields' link). What is
not checked: a field its `show_when` hides, blocks in an area the page's layout does not show, a
block whose class is gone, and a field the caller gives no rules (`$rules[$field] = []`).

**How many blocks (0.94).** A blocks field's `min` and `max` are checked by the core too, for a
field of the type and for a blocks field inside a block: `Preflow\Blocks\Rules\BlockCount`
(`BlockCount::of($config)`), with the messages `validation.blocks.min` and `validation.blocks.max`.
A stored value outside its limits reads as before, but its record cannot be saved until it is fixed.
Folio's form gives the same rule (the old `Preflow\Folio\Field\Types\BlockCount` is an alias for one
release), and the message shows once. A `_uid` used twice anywhere in one field value, nested lists
included, is refused (`uid_twice`).

You write none of this: `BlocksServiceProvider` runs `Preflow\Blocks\BlockValueChecks` for you. An
adapter adds rules for block fields with `BlockValueChecks::extraRules()`; `BlockLists` is the one
place that knows which field types hold blocks.

### Cleaned on every write (0.93)

Rich text inside blocks is cleaned when the record is stored, on every typed write, with or without
validation: a `richtext` field of a block (every language), in nested blocks, in the columns of an
`areas` field and in a page's `_areas`. It is the same cleaning a record's own rich text gets
(`DataManager::storageValues()`), so an import or an API body stores the same safe HTML Folio's form
stores. Clean HTML keeps its bytes, so saving a record again does not move its change date. A block
whose class is gone, and a key its block does not declare, are stored as given. Rows a script
writes straight to a driver are not cleaned; rendering cleans them again (`CoreBlockTextCleaner`).

### Field values (0.93)

`BlocksServiceProvider` registers what a `blocks` and an `areas` field's values mean on the
DataManager's `FieldKinds` (`FieldKinds::valueOf()`), for readers and writers of any type:

| Value | Shape | Does |
|---|---|---|
| `Values\BlocksFieldValue` | `blocks` | each block field read and written through its own value (a number stored as `"3"` reads `3`, rich text cleaned, locale maps kept), a field its `show_when` hides read as `null`; `references()` lists the records and assets every block points at |
| `Values\AreasFieldValue` | `areas_value` | read as `LayoutResource::areasField()`, each block as above; `merge()` is `LayoutInput::mergeField()` |

## Page layouts (0.92)

### The problem it solves

A landing page has a hero and a main part. An article has only a main part. If both are one type
with one blocks field, the editor has to remember which blocks belong where, and the template has
to guess. If they are two types, switching a page from one to the other means copying its content
by hand. And when the editor tries the other layout "just to see", content should not vanish.

### The idea: a layout is a class with named areas

A **layout** names the areas of a page. An **area** is a place that holds blocks, like a blocks
field. A page stores which layout it uses and the blocks of each area.

| Question | Where it lives | Example |
|---|---|---|
| Which layouts exist? | classes with `#[Layout]` | `app/Layouts/Landing.php` |
| Which areas does a layout have? | its public array properties with `#[Area]` | `$hero`, `$main` |
| Which layouts may a type use? | the type's `"layouts"` option | `{"allowed": ["landing", "standard"], "default": "standard"}` |
| Which one does a page use? | the page's `_layout` | `"landing"` |
| What do the areas hold? | the page's `_areas` | `{"hero": [...], "main": [...]}` |

```php
// app/Layouts/Landing.php
use Preflow\Blocks\Layouts\{Area, Layout};

#[Layout(key: 'landing', label: 'Landing page', template: 'layouts/landing.twig')]
final class Landing
{
    #[Area(label: 'Hero', allowed: ['hero'], max: 1)]
    public array $hero = [];

    #[Area(label: 'Main', except: ['hero'])]
    public array $main = [];
}
```

```json
"layouts": {"allowed": ["landing", "standard"], "default": "standard", "pinned": {"home": "landing"}}
```

`key` is what is stored, so keep it once pages use it. `template` is a template name your engine
resolves (it is not a file next to the class). An area takes `allowed` (block keys; none means
every block), `except`, `min`, `max` and `help`. `pinned` maps a page's slug (in the default
language; the slug field is `"layouts": {"slug_field": …}`, default `slug`) to a layout: that
page always uses it, even if `allowed` does not list it, and renaming it is refused.

Layouts are found like blocks: in `app/Layouts`, in `config/blocks.php` `'layouts'` (a list of
classes) and in installed packages (`extra.preflow.layouts` in their `composer.json`). The result
is cached (`layouts.cache`, the boot manifest). A type with `"layouts"` gets two columns,
`_layout` and `_areas` (Folio's `preflow migrate` adds them; without Folio add them yourself). A
class-backed type declares them in code with the same keys (since 0.93), checked at boot the same way:

```php
#[Entity(table: 'pages', type: 'page', layouts: ['allowed' => ['landing', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'landing']])]
```

or in its admin file, like a model file does; not in both (the boot stops naming the file and the class).

### Rendering: you pass the areas yourself

A page's template gets `layout` (its `key`, `label`, `pinned` and `template`) and `areas`. You
decide where each area goes:

```twig
<main>
  {{ blocks(areas.hero) }}
  {{ blocks(areas.main) }}
</main>
```

Nothing matches templates by name for you. In Folio's frontend, the template of the layout comes
before `page.twig` in the chain; a type's own template may include it with
`{% include layout.template %}`. Without Folio, ask `PageLayouts::resolve($record)` for a
`ResolvedLayout` and use its `context()`.

Which layout a page renders with is decided in this order: its pin, the stored `_layout`, the
type's `default`, the first allowed layout that exists, none. If the stored or pinned layout
cannot be used (its class is gone, or the type no longer allows it), `fallback` is true and the
page renders with the layout found instead. A deleted layout class is never a boot error.

### Nothing is lost

An editor switches a page from "Landing" (hero, main) to "Standard" (main). The hero blocks are
no longer rendered, but they are **kept**, shown in a "Not shown in this layout" panel. Switch
back and they are in the hero area again. `Preflow\Blocks\Layouts\Placement` is the one class that
decides this (`placed()`, `unplaced()`); it only reads. A layout switch, a removed layout, a pin
or a save without the panel's blocks never drop anything. Only an explicit Remove does.

### The rules a save is checked against

A write of a page is checked by the core (`DataManager`'s `RecordRules`, see preflow/data), so
Folio, REST, imports and seeds get the same answers:

- the `_layout` must be one the type allows (or the pin). A stored key that is stale passes
  unchanged, so a page on a removed layout stays savable; nobody can write an unknown key;
- the blocks of an area must be ones it accepts, within its `min` and `max`. A save refuses only
  what it introduces: a page stored with an over-full area can shrink it, not grow it;
- the slug of a pinned page does not change (also when a draft is stored);
- every block value, as in "Checked on every write". Problems are keyed `_areas.<area>` for an
  area's limits and `_areas.<area>.<uid>.<field>` for a block's value. Blocks in an area the
  layout does not show are not checked.

`preflow migrate` and `db:check` print a report (`LayoutReport`) of what needs a person: a layout
nobody declares, a pin without a page, pages on a layout that is gone, pages with blocks in areas
their layout does not show.

### The `areas` field

A layout from a *named set*, plus the blocks of each of its areas, can also be a field of a type
or of a block. The columns block of preflow/block-library is one. A package registers the set in
its service provider; the field names it:

```php
$container->get(Preflow\Blocks\Layouts\AreaLayoutSets::class)->add('columns', fn () => new AreaLayoutSet('columns', [...], fallback: 'full'));
```

```php
#[BlockField(type: 'areas', config: ['layouts' => 'columns'])]
```

It is stored as `{"layout": "2-1", "areas": {"c1": [...], "c2": [...]}}` and it gets the same
"not shown" rule. Name your areas like `c1`, `c2`, never `0`, `1` (a PHP list keyed 0, 1, 2 would be stored as a JSON
list, not a map). The core takes any name a set gives; Folio's form ignores a posted area whose name
does not start with a letter or `_`. The properties of a `#[Layout]` class are checked at boot: a
lower-case letter first, then lower-case letters, digits and `_`. A stored layout key the set no
longer has resolves to the set's first layout with as many areas, else its `fallback`, else its
first layout (`AreaLayoutSet::resolve($key, $count)`).

### For APIs

Everything above can be read and written as JSON through core classes, with or without Folio.

Since 0.93 you don't call these yourself for a record: the blocks package plugs them into the
core's read, write and describe classes (preflow/data's `RecordOutput`, `RecordInput` and
`Description`, see "Records over an API" in its README). `BlocksServiceProvider` registers them:

| Registered as | Class | Adds |
|---|---|---|
| an output part (`RecordParts::output()`) | `LayoutResource` | `_layout`, `_areas` and `_unplaced` to a read of a type with layouts; `references()` lists what the area blocks point at, so `_media` covers them |
| an input part (`RecordParts::input()`) | `LayoutInput` | takes `_layout` and `_areas` from a write body, merged as below |
| a description contributor (`DescriptionContributors::add()`) | `ContentDescription` | the sections `blocks`, `layouts`, `area_layout_sets`, and per type with layouts `layouts` with its `output` map: `{"_layout": "resolved_layout", "_areas": "area_map", "_unplaced": "area_map"}` |
| field values (`FieldKinds::value()`) | `BlocksFieldValue`, `AreasFieldValue` | a `blocks` and an `areas` field read and written block by block (above) |

So a controller reads a page with `$output->toArray($record)` and gets the layout keys beside the
fields, and `$input->update($record, $body)` takes them from the body. The direct calls below stay
for 0.92 code: `LayoutResource::toArray()`, `areasField()` and `LayoutInput::mergeField()` are
deprecated since 0.93.0 (removed no earlier than 0.95), their output unchanged; `ContentDescription::toArray()`
keeps its version 1 output byte for byte.

| Class | Does |
|---|---|
| `LayoutResource::toArray($record)` | the page's `_layout`, its `_areas` and its `_unplaced` areas as JSON-safe data; `areasField($field, $value)` does the same for an `areas` field |
| `LayoutInput::merge($def, $stored, $input)` | the values after a body `{"_layout": "…", "_areas": {…}}`: only the areas the body names change, every other keeps its blocks, a block without `_uid` gets a fresh one |
| `ContentDescription::toArray()` | blocks, layouts, area layout sets and the types' layouts as one array with `"version": 1`, declared data only, for an OpenAPI or GraphQL schema builder |

`merge()` refuses what no layout of the type declares (an area name, a block key, a `_uid` that is
not 16 hex digits, a value of the wrong shape), naming the key. You then save the result with
`DataManager::saveType()`, and the rules above decide the rest.

**Hidden block fields (0.94).** A block field declared `"hidden": true` stays out of the API, as a
type's own hidden field does since 0.93: it is not in the read output (blocks, `areas` fields,
`_areas`, `_unplaced`), not in the description, and a write that sends it is refused as
`unknown_field` at `<field>.<uid>.<leaf>`. A write that leaves it out keeps its stored value. Folio's
form still edits it (it merges with `LayoutInput::merge(…, hiddenFields: true)`).

The keys start with an underscore, as they are stored: an underscore key is a system value, never
a field of your type. So a type with layouts can still have its own field called `layout` (a
"wide" or "narrow" select, say). Your controller reads and writes that field by its name, and
`_layout` beside it is the page layout:

```json
{"id": "p1", "title": "About", "layout": "wide", "_layout": {"key": "standard", …}, "_areas": {"main": […]}, "_unplaced": {}}
```

## Linked records in the preview: `preview_mark()` (0.92)

A page often shows other records: a team member's card, a post teaser. In Folio's live preview an
editor should be able to click such a card and edit that record. Nothing is marked on its own,
because only your template knows which element is the card. You mark it:

```twig
<article class="card"{{ preview_mark('member', m) }}>…</article>
```

`preview_mark(type, record)` takes the type's key and the record: its id, an array holding the
type's id field, or a `DynamicRecord`. It prints the attributes of `RecordPreviewMarks`
(` data-record-type="member" data-record-id="…"` by default, Folio's own `data-folio-record`,
`data-folio-record-id` and `data-folio-label`) only while the render is a preview. On a visit, in a
share link and outside every render it prints nothing, so no markup changes for visitors.

- **Blade:** write a space before the directive: `<article @preview_mark('member', $m)>`. Blade
  leaves `<article@preview_mark(…)>` as plain text.
- **Clickable ids:** the id must match `^[A-Za-z0-9_-]{1,64}$` for the click to open the record;
  Folio ignores any other id.
- **Your own marks:** bind a `RecordPreviewMarks` (`attributes(string $type, string $id): array`).
  A name that is not a lower-case `data-…` name (`data-` and lower-case letters, digits and `-`) is refused with an exception.

## Type preview (0.94)

### The problem it solves

A page has an address, so an editor can see it while writing it. A job ad, a team member or a
testimonial has none: it shows up as a card in a list or as a teaser on another page. Without a
way to show one record as the site shows it, its editor types blind and checks the result on the
site after publishing.

### The idea: a type names the component that shows one record

A type's `"preview"` option names the component (or components) that render one of its records.
Each is a **view**. One component is one view:

```json
"preview": {"component": "Teaser"}
```

Several are several views, in this order; the first is the default:

```json
"preview": {
    "views": {
        "card": {"label": "Card", "component": "JobPreview"},
        "row": {"label": "List row", "component": "JobPreview"}
    }
}
```

| Key | Means |
|---|---|
| `component` | one view, key `default`, no label |
| `views` | several views (one of `component` and `views`, not both) |
| a view's `component` | a name in the component map, or a class extending `Preflow\Components\Component` (required) |
| a view's `label` | text, or a translation key (optional) |
| a view's key | `^[a-z][a-z0-9_-]*$`; `page` is reserved (Folio's name for the route's own preview) |

A class-backed type declares the same in code: `#[Entity(type: 'job', preview: ['component' => 'Teaser'])]`
(preflow/data). An admin file may not set `"preview"` for such a class too; the boot stops, naming both.

Nothing is found by name: the type file names its components. The boot checks the option
(`blocks.type_previews`, kept in the boot manifest beside `blocks.type_layouts`) and stops with the
type's name and what to do for a malformed option or a component that does not exist:

```text
Type "job": preview view "card" names the component "JobCrd", which does not exist. Create app/Components/JobCrd/JobCrd.php, or name an existing component.
```

A type without `"preview"` is unchanged.

### Rendering one record: `PreviewCanvas`

`Preflow\Blocks\Preview\PreviewCanvas` renders a record through one view, as a whole HTML page:

1. the view's component gets exactly these props: `record` (the record's values in one language,
   as a page template reads them), `type`, `view` (the view's key), `locale` and `preview` (true);
2. the **canvas** template gets `preview` (the component's HTML, printed as it is), `record`,
   `type`, `view`, `locale`, and whatever more you hand it in `$canvasContext`. It is any template
   name your engine resolves; you choose it on purpose;
3. the CSS and JS the component collected are put into the page (`AssetCollector::injectInto()`).

A component that fails to build or render shows its error boundary inside the canvas. A component
that needs more than the record (a related company, say) reads it itself.

`PreviewCanvas` opens no [render context](../core/README.md): you render inside your own preview
context, so nothing is sent and nothing is written. In a controller that has `TypePreviews $previews`,
`PreviewCanvas $canvas` and `RecordOutput $output`:

```php
$preview = $this->previews->forType('job');          // null for a type without "preview"
$values = $this->output->toArray($record, new ReadOptions(locale: $locale));
$ctx = Contexts::current()->asVisitor()->public()->in($locale)->workingCopies()
    ->capturing()->skippingWrites()->because(Purpose::Preview);

$html = Contexts::run($ctx, fn () => $this->canvas->render($preview, $view, $values, $locale, 'preview/canvas.twig'));
```

`$view` is a view key; an unknown key, `''` or `null` is the first view. `TypePreview::keys()`
lists them, `view($key)` gives a `PreviewView` (`key`, `label`, `component`). `BlocksServiceProvider`
binds `TypePreviews` and `PreviewCanvas` only when they are unbound, so a site may bind its own.

Folio's form uses the same classes for its live preview of such types, with its own canvas
(see preflow/folio, "Type preview").

**The shape of `record` is the caller's choice.** Folio's pane passes its `Content\RecordRenderer::values()`:
the values a page template reads. The snippet above passes `RecordOutput::toArray()`: the record as
the API outputs it. These two shapes can differ for the same record. If a component is used in Folio's
pane and in your own endpoint, it must accept both shapes, or your endpoint must pass the template
values as Folio does.

## Configuration

`config/blocks.php` (a missing file means the defaults):

```php
return [
    // Block classes besides app/Components, app/Blocks and the packages' (a block in vendor code, a test fixture).
    'classes' => [App\Content\Teaser::class],
    // How many levels of blocks an editor saves in all: the field's own blocks are level 1, blocks in their blocks fields level 2, ….
    'max_depth' => 4,
    // Page layout classes (#[Layout]) besides app/Layouts and the packages' (since 0.92).
    'layouts' => [App\Layouts\Landing::class],
    // Where blocks.cache is kept: storage/cache by default, or a path (absolute, or from the project root).
    'cache_path' => null,
];
```

Discovery reflects over every component, so its result is kept: in the boot manifest (`preflow
optimize`), and otherwise, when debug is off, in `blocks.cache`. The cache is keyed by the block
classes and their files' modification times, so adding, removing or editing a block rebuilds it.
`cache:clear` and Folio's Settings → Cache list it as `blocks`.

## Extend

The renderer asks three interfaces (and `preview_mark()` a fourth); each has a plain default, and an app (or Folio) binds its own
in a provider's `register()`:

| Interface | Asks | Default | Folio binds |
|---|---|---|---|
| `BlockLocale` | the locale to render in (`localeOf(RenderContext $ctx)`, `locales()`; since 0.83 `localeOf()` is part of `BlockLocale`, which was `ContextBlockLocale`'s in 0.81 and 0.82, and `get()` is gone) | the render context's locale when it is a content locale, else the default | its content locale (the page's; the editing locale in a preview) |
| `BlockTextCleaner` | `block(array $block): array`, the values on their way to the template | the core cleaner (`CoreBlockTextCleaner`, 0.93): rich text cleaned, nested blocks and columns included | its rich-text sanitizer (nested blocks included) |
| `BlockPreviewMarks` | the root attributes of a block in preview (`data-…` only) | `data-block`, `data-block-label` | `data-folio-block`, `data-folio-label` |
| `RecordPreviewMarks` (0.92) | the attributes of a linked record's element in preview (`attributes($type, $id)`, `data-…` only) | `data-record-type`, `data-record-id` | `data-folio-record`, `data-folio-record-id`, `data-folio-label` |

Since 0.93 blocks render their rich text cleaned without Folio too: the default `CoreBlockTextCleaner`
cleans it with the core's HTML cleaner (`Preflow\Data\Html\HtmlCleaner`; bind your own to make it
stricter or looser), as every save does. Before 0.93 the default changed nothing. Bind a
`BlockTextCleaner` to do something else with a block's values (below).

```php
$container->singleton(Preflow\Blocks\BlockTextCleaner::class, fn () => new App\Content\MarkdownBlocks());
```

**Checks of block fields (0.91).** A package adds its own check of each block's fields to
`BlockFieldChecks` in its provider's `register()`; it runs once, when the registry is built (not
for a registry read from the boot manifest or `blocks.cache`), and a `\LogicException` it throws
(`field "starts": …`) stops the build as a `BlockConfigException` with the block in front
(`Block "event": field "starts": …`). preflow/fields checks its field types' options this way.

Your provider may register before BlocksServiceProvider, so bind the instance when it is not bound
yet (BlocksServiceProvider does the same):

```php
if (!$container->bound(Preflow\Blocks\BlockFieldChecks::class)) {
    $container->instance(Preflow\Blocks\BlockFieldChecks::class, new Preflow\Blocks\BlockFieldChecks());
}
$container->get(Preflow\Blocks\BlockFieldChecks::class)->add(static function (array $fields): void {
    // $fields: field name => Preflow\Data\TypeFieldDefinition
});
```
