# preflow/fields

Field types as core values. A field type here is more than an input: it says what a value looks
like, how it is checked, how it is stored and what a template does with it. All of that works
without Folio. Folio only adds the editor input for each type.

The first type is `link`. More follow in the same package (email, phone, colour, dates, embeds,
tables, locations); each is built the same way (see [Adding a field type](#adding-a-field-type)).

## Install

```
composer require preflow/fields
```

- With Folio: nothing else. Folio requires this package and registers its provider itself.
- Without Folio: add `Preflow\Fields\FieldsServiceProvider` to `config/providers.php`. It tells the
  data package how each type is stored and checked, and adds the template functions to your
  template engine.

## The link field

A link points somewhere and shows a text. In a model file:

```json
"cta": {"type": "link", "label": "Button", "link": {"types": ["page", "post"]}}
```

What it points to is its **kind**:

| kind | target | href on the page |
|---|---|---|
| `record` | a record of the site: `{"type": "page", "id": "p1"}` | the record's current address in the page's language |
| `url` | a web address (`https://…`, `http://…`) or a path on the site (`/contact`) | the address |
| `email` | an email address | `mailto:` and the address |
| `phone` | a phone number as typed (`+41 44 123 45 67`) | `tel:` and the digits (`tel:+41441234567`) |
| `asset` | the id of a file in the media library | the file's address |

The value is stored as one object, always with these four keys:

```json
{"kind": "record", "target": {"type": "page", "id": "p1"}, "label": {"en": "About us", "de": "Über uns"}, "new_tab": false}
```

- `label` is the text the link shows. It is one text, or a text per language (as a translatable
  field stores it). The link field itself is never `translatable`: the label inside it is.
- `new_tab` opens the link in a new tab.
- An empty field is `null`.

A JSON file holds the object; SQL storage keeps JSON text of it in a text column. `Link::fromStored()`
reads both, so code never has to care which storage it runs on.

### Options

Under `"link"` in the field:

- `"kinds"`: the kinds the field takes, e.g. `["record", "url"]`. Without it, every kind.
- `"types"`: the record types a record link may point to, e.g. `["page", "post"]`. Without it, any
  type. (Folio then offers the types that have a public address.)

A mistake in these options stops the boot with a message naming the type, the field and what to
write instead (`"link.kinds" lists "ftp"; use record, url, email, phone or asset.`). So does
`"translatable": true` on a link field.

### Checks

Every save of a typed record checks a link field, with or without Folio (the data package runs the
type's check, see `FieldKinds::rules()` in the data README). A link must have an offered kind and a
target that fits it:

- a record of an offered type,
- a `url` that is `http(s)://` with a host, or a path starting with `/` (never `javascript:`,
  `data:` or `//host`),
- a valid email address,
- a phone number (`Preflow\Validation\Rules\Phone`),
- an asset id.

An empty link passes; add `"validate": ["required"]` to require one. Each refusal is on the field
and says what to do instead ("Must be a web address starting with https:// or http://, or a path
on this site starting with /.").

### In templates

The template functions work in every engine:

```twig
<a{{ link_attrs(page.cta) }}>{{ link_label(page.cta) }}</a>
```

- `link_href(value, locale = null)`: the address, or null.
- `link_attrs(value, locale = null)`: ` href="…"`, plus ` target="_blank" rel="noopener noreferrer"`
  for a new tab, escaped and ready to put into the tag; `''` when there is no address.
- `link_label(value, locale = null)`: the label in the language, along the content languages'
  fallback chain; null without one.

The language is the page's (the render's locale) unless you pass one.

In PHP the same comes from the value:

```php
$link = Link::fromStored($record->get('cta'));          // ?Link
$href = $link?->href($addresses, 'de');                   // ?string
$text = $link?->label('de', $locales);                    // ?string
```

### Where a record lives: RecordAddresses

A link to a record only knows the record's type and id. To get its address, the page asks a
`Preflow\Fields\RecordAddresses`:

```php
interface RecordAddresses
{
    public function addressOf(string $type, string $id, string $locale): ?string;
}
```

- **With Folio** it is bound for you: the record's public address in the language
  (`FrontendResolver::urlFor()`), read as the page reads (published records for a visitor, the
  working copy in a preview).
- **Without Folio** bind your own in a service provider:

  ```php
  $container->singleton(\Preflow\Fields\RecordAddresses::class, fn () => new MyAddresses(...));
  ```

- **Nothing bound:** a record link renders without an `href` (its label only).

`addressOf()` answers null for a record a visitor cannot see: deleted, unpublished, not in that
language. The link then has no `href`, so a page never links to a missing page. Folio's editor marks
such a link: "Linked page no longer available."

For `asset` links the same object may also implement `Preflow\Fields\AssetAddresses`
(`assetAddressOf(string $id): ?string`). Folio's does, with the media library's address of the file.

### Blocks and conditional fields

A link field works in a block like any field:

```php
#[BlockField(type: 'link', label: 'Button', config: ['link' => ['kinds' => ['record', 'url']]])]
public ?array $button = null;
```

With `show_when` it behaves as every conditional field: hidden, it reads as empty (`null`) on the
page and its stored value is kept.

## Adding a field type

Each type of this package is built from the same parts. The link field shows each one:

1. **The value** in `src/Values/`: a readonly class with `fromStored(mixed): ?self` (reads the
   stored shape and JSON text of it, never fails), `toStored(): array|string` (the one stored
   shape), and what a page needs (`Link::href()`, `label()`, `attributes()`).
2. **Its checks** as `ValidationRule`s. A generic check goes to preflow/validation (`Phone`, with its
   message in all of validation's languages); one that only makes sense for this type stays here in
   `src/Rules/` (`ValidLink`). Options of the field get a small reader with a boot check
   (`LinkOptions::fromConfig()`, `check()`).
3. **Its registration** in `FieldsServiceProvider::registerKinds()`: the column kind
   (`$kinds->column('link', ColumnKind::Text)`) and the checks
   (`$kinds->rules('link', fn (array $config) => [...])`).
4. **Its template functions** as `Preflow\View\TemplateFunctionDefinition`s in
   `FieldsExtensionProvider`, never engine-only code.
5. **Folio's input**: a `Preflow\Folio\Field\Types\…FieldType` in Folio that draws the editor and
   returns the same rules from `rules()`; its option check in `TypeCatalog::checkFieldLayout()` (and
   `BlocksFieldType::assertLeaf()` for blocks); its texts in Folio's `lang/{en,de,fr}`.
6. **A stored-shape case** in preflow/testing's `StorageDriverContract::fieldValueShapes()`, so
   every storage driver proves it gives the shape back, plus a round trip through the type's own
   `fromStored()` on JSON, SQLite and MySQL in this package's tests.
