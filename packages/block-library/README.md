# preflow/block-library

Ready-made blocks that a site switches on and restyles. The first one is `columns`. More blocks
(hero, text, gallery and others) come in later releases and are built the same way.

## The problem: one section, many column layouts

A page section is sometimes one column, sometimes two with a wide left side, sometimes three
cards in a row. Without a columns block, a site either writes a block per variant (`two_columns`,
`three_columns`, `wide_left`), each with its own template and CSS, or it gives editors raw HTML.
Both break when an editor changes their mind: the content has to be moved by hand into another
block, and something gets lost on the way.

## The idea: one block, a column layout an editor picks

The `columns` block holds a **column layout** and the blocks of each column. The site lists the
layouts an editor can pick from; a default set ships. One template renders every layout. When an
editor switches to fewer columns, the blocks of the columns that are gone are kept and shown in a
"Not shown in this layout" panel. They are never rendered and never dropped.

| Question | Where it lives | Example |
|---|---|---|
| Which layouts can an editor pick? | `config/blocks.php` `column_layouts` | `full`, `1-1`, `2-1`, `1-2`, `1-1-1`, `1-1-1-1` |
| How wide is each column? | the layout's `widths` (shares of the row, 1–12) | `[2, 1]`: two thirds and one third |
| Which blocks may go into a column? | `config/blocks.php` `column_blocks` | every block except `columns` (the default) |
| How is it stored? | the block's `columns` field (an `areas` field) | `{"layout": "2-1", "areas": {"c1": [...], "c2": [...]}}` |
| How does it look? | `Columns.css` and two CSS custom properties | `--pf-columns-gap`, `--pf-columns-breakpoint` |

## Install

```
composer require preflow/block-library
```

Then list the provider in `config/providers.php` (with or without Folio):

```php
return [
    // …
    \Preflow\BlockLibrary\BlockLibraryServiceProvider::class,
];
```

Installing the package registers the `columns` block (its `composer.json` lists it under
`extra.preflow.blocks`). The provider registers the column layouts the block takes its layouts
from (the area layout set `columns`) and the texts in English, German and French. A site that
installs the package but does not list the provider gets this error when the block registry is
built:

```
Block "columns": field "columns": No package registers the area layout set "columns". The package
that brings the block registers it in its service provider (config/providers.php).
```

## Column layouts

Without any config the editor picks from the default set:

| key | label | widths |
|---|---|---|
| `full` | Full width | `[1]` |
| `1-1` | 1/2 + 1/2 | `[1, 1]` |
| `2-1` | 2/3 + 1/3 | `[2, 1]` |
| `1-2` | 1/3 + 2/3 | `[1, 2]` |
| `1-1-1` | 1/3 × 3 | `[1, 1, 1]` |
| `1-1-1-1` | 1/4 × 4 | `[1, 1, 1, 1]` |

A site's own list replaces the default set. To add to the defaults, spread them:

```php
// config/blocks.php
use Preflow\BlockLibrary\ColumnLayouts;

return [
    'column_layouts' => [
        ...ColumnLayouts::defaults(),
        ['key' => '3-1', 'label' => '3/4 + 1/4', 'widths' => [3, 1]],
    ],
];
```

Each entry has a `key` (stored with the block, so keep it once editors use it; lower-case letters,
digits, `-` and `_`), a `label` (a text, or a translation key such as `block-library.layouts.full`)
and `widths` (1 to 6 columns, each a whole number from 1 to 12). The columns are named `c1`, `c2`,
… in that order, and the editor labels them "Column 1", "Column 2", … in its language (the key
`block-library.columns.column` with the column's number).

A mistake is refused when the block registry is built (when `preflow optimize` builds the boot
manifest, or on the first request without one), naming the entry, for example:

```
Block "columns": field "columns": config/blocks.php column_layouts[2] ("1-1-1-1-1-1-1"): 7 column
widths; a layout has 1 to 6 columns, one width each, such as [2, 1].
```

### A layout that is removed

Removing a layout from the list is safe. A block still stored with that key renders with the
list's first layout that has as many columns, else `full`, else the list's first layout. Columns
the new layout lacks are kept and shown in the editor's "Not shown in this layout" panel;
`preflow db:check` lists the records.

### Which blocks a column takes

```php
'column_blocks' => ['except' => ['columns']],        // the default: every block but columns itself
'column_blocks' => ['allowed' => ['text', 'image']], // only these
'column_blocks' => ['except' => []],                 // columns inside columns too (within max_depth)
```

Use one of `allowed` and `except`, not both.

## Rendering

The block renders through its own Twig or Blade template, whichever engine the site uses:

```html
<div class="pf-columns pf-columns--2-1">
  <div class="pf-columns__cell pf-columns__cell--w2">…the blocks of c1…</div>
  <div class="pf-columns__cell pf-columns__cell--w1">…the blocks of c2…</div>
</div>
```

The blocks inside a column render like any other blocks: in the page's language, with rich text
cleaned, and with the preview marks in Folio's live preview.

You don't write a template per layout, and you don't add the CSS yourself: the block adds
`Columns.css` to the page's style element once (with the page's CSP nonce, no `style` attributes).

### Restyling

The columns stack below a breakpoint without a media query: the breakpoint is the block's own
width, so it works the same in a narrow sidebar as in a full-width section. Two custom properties
change the look, on `.pf-columns` or any parent:

```css
.pf-columns { --pf-columns-gap: 2rem; --pf-columns-breakpoint: 48rem; }
```

| property | default | what it does |
|---|---|---|
| `--pf-columns-gap` | `1.5rem` | space between columns, and between stacked cells |
| `--pf-columns-breakpoint` | `40rem` | below this block width every column takes a row of its own |

`pf-columns--{key}` lets a site style one layout differently, for example `.pf-columns--1-1-1`.

## Without Folio

Everything above is core: the layouts, the stored value, the rules a save is checked against
(which blocks a column takes, values of blocks in a column) and the template. Folio only draws the
layout picker, one block list per column and the "Not shown in this layout" panel. A REST or import
write of a page with a columns block is checked by the same rules as Folio's form.

**One sentence to remember:** the site lists the column layouts, the editor picks one, one template
renders them all, and a switch never loses a block.
