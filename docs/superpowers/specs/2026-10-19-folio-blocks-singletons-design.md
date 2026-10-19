# Folio Blocks and Singletons — Design

**Date:** 2026-10-19
**Packages:** `preflow/folio` (main), `preflow/components` (discovery hook), `preflow/htmx` (used by the admin)
**Status:** Approved (2026-10-19).
**Depends on:**
- the groundwork spec (field types, generic frontend, strict CSP, self-hosted htmx);
- the content i18n spec (locale maps, `LocalizedRecord`, `DraftRecordFactory`).

**Context:** Folio's `matrix` field orders *references to standalone records*. That suits
reusable content, but a page made of sections (hero, convictions, a list of "now" items)
needs content the page *owns*: nested, ordered and edited in place. Storyblok calls these
"bloks" and has users define their schemas in its UI. Preflow already has a primitive for
"a piece of page with its own logic and template", the component, so in Folio a block
**is** a component.

## Goal

```php
#[Block(key: 'hero', label: 'Hero', group: 'Sections')]
final class Hero extends Component
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'richtext', translatable: true)]
    public string $intro = '';

    #[BlockField(type: 'blocks', allowed: ['tradeoff_note'], max: 1)]
    public array $tradeoff = [];
}
```

A page type declares `"sections": {"type": "blocks", "allowed": ["hero", "worldview", …]}`.
The editor builds the page from those blocks, and the template renders them with
`{{ folio_blocks(record.sections) }}`. Schema, logic, template and CSS live in one
component folder and are versioned in git.

A singleton type (`"singleton": true`) holds one-off content such as site settings. It has
no list and is readable anywhere through `folio_get('site')`.

## Storyblok, adapted

| Storyblok | Folio |
|---|---|
| Component schemas edited in the web UI | Schemas are **PHP attributes on the component**: reviewed, versioned, typed, co-located. There is no schema editor; Preflow treats structure as code. |
| Bloks: nestable, with an allowed list per field | A `blocks` field with `allowed`, `min` and `max`. Nesting is unlimited in principle, with a sane default depth limit of 4. |
| Block picker with groups and icons | A picker grouped by `group`, showing `label`, an optional `description`, and an `icon` that is a name from Folio's bundled icon set (no external icon font). |
| Content as JSON delivered to a JS frontend | Content is rendered **server-side** by the block's own component. |
| Global components / data-only bloks | A block class that is **not** a Component is data-only; its parent's template renders it. |

Anti-goals:
- defining blocks in JSON as well (one way only);
- client-rendered blocks;
- a schema UI.

## Non-goals

- Click-to-edit in the preview, drag-and-drop reordering, draft/publish and an asset
  library. These belong to the editor-experience spec, which builds on the `_uid`s and
  markers defined here.
- Reusing one block instance in several pages. That is what `matrix` and `relation` are
  for.
- Converting existing `matrix` data to blocks.

## 1. Defining blocks

- `#[Preflow\Folio\Block\Block(key, label, group = 'Blocks', icon = null, description = null)]`
  goes on a class.
  - `key` is the stable storage identifier. It is required, so renaming a class never
    orphans content.
- `#[Preflow\Folio\Block\BlockField(type, label = null, help = null, translatable = false,
  validate = [], …config)]` goes on public properties. It takes the same field types and
  config as JSON type fields, including `blocks` itself, so blocks nest.
- **Component blocks** extend `Preflow\Components\Component`. Folio passes the block's
  localized data as props and fills the `#[BlockField]` public properties before
  `resolveState()` runs, so a block component needs no boilerplate and can still add its
  own logic. It is handled by the base class or a small trait; builder's call.
- **Data-only blocks** are plain final classes with the attributes (e.g. `now_item`,
  `link`). They have no template, and the parent component iterates them.
- **Discovery:** Folio reads the component map that core already builds
  (`discoverComponents()`). It registers classes carrying `#[Block]`, and also scans
  `app/Blocks/` for data-only blocks.
  - Duplicate keys fail at boot.
  - The resulting `BlockRegistry` is cached in `storage/cache` when debug is off.

## 2. Storage

A `blocks` field stores an ordered list inside the record:

```json
"sections": [
  { "_uid": "b7f3…", "_block": "hero",
    "headline": { "en": "I make the invisible parts of software visible." },
    "tradeoff": [ { "_uid": "c1a2…", "_block": "tradeoff_note", "chose": { "en": "one conviction" } } ] }
]
```

- `_uid` is random and stable for the block's lifetime. Preview patching and click-to-edit
  use it later.
- Translatable sub-fields use the locale-map shape from the i18n spec. Structure (blocks,
  order, types) is shared across locales. `LocalizedRecord` resolves it recursively.
- An unknown `_block` (a block class was deleted) is kept in storage untouched. The editor
  shows it as an "unknown block" card that can only be removed, and the frontend skips it.
  Nothing is silently lost.

## 3. Editing (admin)

- **Field UI:** the `blocks` field shows an ordered stack of collapsible block cards.
  - Each card has a header: the icon, the label, and a summary (the first non-empty string
    field).
  - Card actions: collapse, move up, move down, duplicate, delete.
  - An "Add block" button sits between cards and at the end.
  - Nested `blocks` fields render the same way inside the card.
- **Picker:** the allowed blocks, grouped. With a single allowed block, the button adds it
  directly.
- **htmx enters the admin here.** Add, duplicate and remove request a server-rendered card
  fragment (`GET|POST {prefix}/_blocks/…`), which is swapped in with no page reload.
  - The rest of the form stays a plain POST, so saving works without JS.
  - The admin includes the self-hosted htmx through the groundwork's include mechanism.
  - Move up/down is pure DOM reordering in `admin.js`. No request is needed because naming
    is order-independent, see below.
- **Input naming is keyed by `_uid`, not by position:**
  - `sections[b7f3…][headline]`;
  - nested blocks as `sections[b7f3…][tradeoff][c1a2…][chose]`;
  - a hidden `sections[_order][]` per level carries the order.
  - Reordering and nested add/remove therefore never renumber anything.
- **Uploads inside blocks:** asset fields inside blocks post as nested file inputs.
  `DraftRecordFactory` must flatten nested PSR-7 uploaded files to the right block path.
  (The asset library in the editor-experience spec will mostly replace in-block uploads with
  picking, but the field must work now.)
- **Validation:**
  - Block-field rules are validated per block instance.
  - Errors render in place on the card, and the card auto-expands.
  - A summary at the top of the form links to each error.
  - `min`/`max` on a `blocks` field are validated too.
- **Live preview** keeps working: the draft includes block data. Preview renders wrap each
  block in `data-folio-block="{_uid}"` (preview only), so later click-to-edit can map clicks
  to cards.

## 4. Rendering (frontend)

- `{{ folio_blocks(record.sections) }}` renders each block in order through its
  component, with props = localized data plus `_uid`. Data-only blocks are skipped (their
  parent renders them).
- `{{ folio_block(block) }}` renders one block.
- A component block's template sees its fields as variables, as with any component prop.
- Child data-only blocks arrive as arrays of localized data, ready to iterate.
- Errors inside one block are contained by the component `ErrorBoundary`, so one broken
  block does not take the page down.

## 5. Singletons

- Type JSON: `"singleton": true`.
- Admin:
  - the nav entry links straight to the edit form;
  - there is no list, "new" or delete;
  - the record id is the type key;
  - the record is created on first save. Before that, the form shows defaults.
- Frontend:
  - `folio_get('site')` returns the localized singleton record (`null` before its first
    save);
  - it is resolved lazily and memoized per request;
  - components can inject `Preflow\Folio\Content\Singletons` instead of using the Twig
    function.
- Singletons may contain `blocks` fields (e.g. nav links as data-only `link` blocks).

## 6. Blocks vs matrix vs relation

The README documents the choice:
- **blocks:** content owned by this record, edited in place;
- **matrix:** an ordered mix of references to standalone, reusable records;
- **relation:** references to records of one type.

## New / affected files

- `folio/src/Block/`:
  - `Block.php`, `BlockField.php` (attributes)
  - `BlockRegistry.php`, `BlockDefinition.php`
  - `BlockHydrator.php` (props → public properties)
  - `BlocksFieldType.php`
  - `BlockEditorController.php` (fragments)
- `folio/src/Content/Singletons.php`
- `TypeCatalog` (singleton flag, nav)
- `FolioRoutes` (block fragment routes, singleton routing)
- `AdminController` (singleton edit/save)
- `DraftRecordFactory` (nested blocks and files)
- Twig helpers: `folio_blocks`, `folio_block`, `folio_get`
- `templates/admin/blocks/*.twig` (card, picker, unknown block)
- `admin.js` (move up/down, collapse) and `admin.css`
- a small bundled SVG icon set (inline SVG sprite, same-origin)
- `components`: expose the discovered component map (if it is not already reachable) so
  Folio can read it.
- `examples/folio-demo`: a page with a blocks field, a nested block, a data-only block and a
  `site` singleton.

## Testing

- **Registry:**
  - discovery of component and data-only blocks;
  - duplicate key → boot error;
  - the cache is used when debug is off.
- **Storage:**
  - round trip of nested blocks;
  - `_uid` is kept across saves;
  - unknown blocks survive a save untouched.
- **Editing:**
  - uid-keyed input with reordering produces the right order;
  - nested add, duplicate and remove fragments;
  - duplicate assigns fresh `_uid`s recursively;
  - per-instance validation errors land on the right card;
  - `min`/`max`;
  - a nested upload ends up in the right block;
  - saving with JS off (no fragments) works for edits to existing blocks.
- **i18n:**
  - editing `de` changes only `de` leaves in nested blocks;
  - structure changes made in `de` are shared.
- **Rendering:**
  - order;
  - props hydrate the public properties;
  - a data-only child iterates;
  - one throwing block is contained;
  - `data-folio-block` appears only in preview.
- **Singletons:**
  - the nav goes straight to the form;
  - first save creates the record;
  - `folio_get` returns `null` before the first save and memoizes;
  - the singleton is localized.
- **CSP:** the new admin templates pass the groundwork's inline-attribute guard test.

## Open for the builder

- Base class vs trait for hydrating block components.
- Exact icon set (small, MIT/ISC-licensed SVGs, bundled).
- Default nesting depth limit (4 suggested) and whether it is configurable.
