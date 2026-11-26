# preflow/fields

Field types as core values. A field type here is more than an input: it says what a value looks
like, how it is checked, how it is stored and what a template does with it. All of that works
without Folio. Folio only adds the editor input for each type.

The types so far are `link`, `email`, `phone`, `color`, `code` and `json`. More follow in the same
package (dates, embeds, tables, locations); each is built the same way (see
[Adding a field type](#adding-a-field-type)).

## Install

```
composer require preflow/fields
```

- With Folio: nothing else. Folio requires this package and registers its provider itself.
- Without Folio: add `Preflow\Fields\FieldsServiceProvider` to `config/providers.php`. It tells the
  data package how each type is stored and checked, and adds the template functions to your
  template engine.
- Upgrading: the field type key `link` now belongs to this package. A site or package that had its
  own field type `link` (a Folio field type, or `FieldKinds` registrations under that key) gets this
  package's check and boot check for it; rename yours before upgrading.

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

### Search, transfer and export

A link field is not searchable (0.91): its label is short text on a button, and a structured value
is not indexed, like a blocks field. Transfer and export carry the stored object as it is.

### Blocks and conditional fields

A link field works in a block like any field:

```php
#[BlockField(type: 'link', label: 'Button', config: ['link' => ['kinds' => ['record', 'url']]])]
public ?array $button = null;
```

With `show_when` it behaves as every conditional field: hidden, it reads as empty (`null`) on the
page and its stored value is kept.

## Email, phone, colour, code and JSON

All five are registered by `FieldsServiceProvider` (their column, their check, the template
functions), so a site without Folio stores and checks them the same way. None of them is
`translatable` (a boot error says so: the value is the same in every language). Their options
(`default_region`, `palette`, `palette_only`, `language`) are keys of the field itself, next to
`"type"`; an object under the type's own name (`"color": {…}`) is a boot error that says where the
option goes.

| type | stored | check | page |
|---|---|---|---|
| `email` | the address, text | the `email` rule | `mailto_href(value)` |
| `phone` | `{"as_typed": "044 123 45 67", "e164": "+41441234567"}` | `ValidPhone` | `tel_href(value)` |
| `color` | `"#rrggbb"` or `"#rrggbbaa"`, lowercase | the `color` rule | the text itself |
| `code` | the code as plain text | none (any text is code) | `code_html(value, language)` |
| `json` | JSON text, as written | the `json` rule | `json_data(value)` |

An empty value is `null` and passes (`"validate": ["required"]` makes it required).

The stored shapes above are what Folio, `Phone::of()` and `Color::parse()` produce. A caller that
writes records itself (REST, a seed, an import) builds them with those first: a plain phone string
or an uppercase colour is read back as given, and a phone's `e164` must match its `as_typed`
(the check refuses a mismatch).

### Phone and its E.164 form

The number is kept as typed and gets an E.164 form (`+` and the digits with the country code) for
`tel:` links and for matching. There is no library; `Values\Phone::e164()` does it in four steps:

1. A number with a leading `+` keeps its digits; a leading `00` means `+`. A `(0)` directly after
   the country code (`+41 (0)44 123 45 67`, `0041 (0)44 …`) is the trunk 0 written for people and is
   dropped; a bare 0 elsewhere stays (`+39 06 …`). A `+` or `00` number has at least 7 digits after
   it (service numbers are written without `+`).
2. Any other number is national. It gets the calling code of the field's `"default_region"`
   (`"phone": {"type": "phone", "default_region": "CH"}`), after dropping the one leading `0` of
   regions that write a trunk prefix (not LI, IT, ES, PT, LU, DK, NO, PL, CZ). The regions are in `Phone::REGIONS`: CH, LI, DE, AT, FR, IT,
   ES, PT, NL, BE, LU, GB, IE, DK, SE, NO, FI, PL, CZ, US, CA. For any other country numbers are
   typed with `+`.
3. A short number (up to 5 digits: 112, 1414) is a service number: no E.164 form, dialled as typed.
4. A longer national number that cannot be completed (no `default_region`, or longer than 15
   digits with the code) is refused: "Cannot tell which country this number belongs to: start it
   with + and the country code, e.g. +41 44 123 45 67."

A stored `e164` must be what `as_typed` normalizes to (with the field's region, or no region for
an international `as_typed`); a mismatch is refused, so a `tel:` link never dials another number.
`Phone::of($typed, $region)->toStored()` builds the stored value in code (a seed, an import).
Without Folio, a plain text number is accepted by the check and read as `as_typed`.

### Colour

`"palette"` lists the site's colours (`["#c00000", "#0a5ca8"]`, or names to colours:
`{"Brand red": "#c00000"}`); Folio shows them as swatches. `"palette_only": true` refuses every
other colour, with or without Folio. `Values\Color::parse()` reads what people type (`C00`,
`#C00000`) and gives the lowercase stored text.

### Code

The code is stored as plain text, so it is found by loose search like any text. The language is a
setting of the field, one per field: `"snippet": {"type": "code", "language": "php"}` (a short name:
letters, digits and `+ # . _ -`). Folio shows it beside the label. `code_html(value, "php")` outputs
`<pre><code class="language-php">…</code></pre>` (no class without a language); the code is always
escaped.

### Search and transfer

`email` and `code` are text: loose search finds them. `phone` and a link are stored as objects and,
like a blocks field, are not searched in 0.91; `color` and `json` are not searched either. Transfer and export carry every stored value as
it is.

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
5. **Folio's input**: a `Preflow\Folio\Field\Types\…FieldType` in Folio that draws the editor. Its
   `rules()` does not repeat the core checks: Folio's record and block validation add
   `FieldKinds::rulesOf()` for every field. Its option check goes in
   `TypeCatalog::checkFieldLayout()` (and `BlocksFieldType::assertLeaf()` for blocks); its texts in
   Folio's `lang/{en,de,fr}`.
6. **A stored-shape case** in preflow/testing's `StorageDriverContract::fieldValueShapes()` (an
   object) or `textValueShapes()` (text), so every storage driver proves it gives the value back, plus a round trip through the type's own
   `fromStored()` on JSON, SQLite and MySQL in this package's tests.
