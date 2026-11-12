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
- **`#[BlockField]`** takes the options of a type field: `type`, `label`, `help`, `translatable`,
  `validate`, and `config` for anything else (select `options`, …). A `blocks` field nests blocks:
  `allowed` lists the keys it takes, `min` and `max` how many. Field names starting with `_` are
  refused (`_uid`, `_block` and `_order` are the system's).
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

Values reach the template as stored. Escape them as usual (`{{ title }}`).

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

## Configuration

`config/blocks.php` (a missing file means the defaults):

```php
return [
    // Block classes besides app/Components, app/Blocks and the packages' (a block in vendor code, a test fixture).
    'classes' => [App\Content\Teaser::class],
    // How many levels of blocks an editor saves in all: the field's own blocks are level 1, blocks in their blocks fields level 2, ….
    'max_depth' => 4,
    // Where blocks.cache is kept: storage/cache by default, or a path (absolute, or from the project root).
    'cache_path' => null,
];
```

Discovery reflects over every component, so its result is kept: in the boot manifest (`preflow
optimize`), and otherwise, when debug is off, in `blocks.cache`. The cache is keyed by the block
classes and their files' modification times, so adding, removing or editing a block rebuilds it.
`cache:clear` and Folio's Settings → Cache list it as `blocks`.

## Extend

The renderer asks three interfaces; each has a plain default, and an app (or Folio) binds its own
in a provider's `register()`:

| Interface | Asks | Default | Folio binds |
|---|---|---|---|
| `BlockLocale` | the locale to render in (`get()`, `locales()`, `scoped()`) | the request's locale (i18n's Translator) when it is a content locale, else the default | its content locale (the page's; the editing locale in a preview) |
| `BlockTextCleaner` | `block(array $block): array`, the values on their way to the template | as stored | its rich-text sanitizer (nested blocks included) |
| `BlockPreviewMarks` | the root attributes of a block in preview (`data-…` only) | `data-block`, `data-block-label` | `data-folio-block`, `data-folio-label` |

Without Folio, rich-text values are rendered as stored: the default cleaner changes nothing, so bind a
`BlockTextCleaner` to clean them (below).

```php
$container->singleton(Preflow\Blocks\BlockTextCleaner::class, fn () => new App\Content\MarkdownBlocks());
```
