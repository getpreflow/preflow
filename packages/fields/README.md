# preflow/fields

Field types as core values. A field type here is more than an input: it says what a value looks
like, how it is checked, how it is stored and what a template does with it. All of that works
without Folio. Folio only adds the editor input for each type.

The types are `link`, `email`, `phone`, `color`, `code`, `json`, `datetime`, `time`, `daterange`,
`embed` (a video), `table` and `location`; each is built the same way (see
[Adding a field type](#adding-a-field-type)).

At a glance (each type's section below has the options, the checks and the template functions):

| type | stored | searched by loose search |
|---|---|---|
| `link` | an object: kind, target, label, new tab | no |
| `email` | text | yes |
| `phone` | an object: as typed and the E.164 form | no |
| `color` | `#rrggbb` or `#rrggbbaa` text | no |
| `code` | plain text (the language is a field option) | yes |
| `json` | JSON as text | no |
| `datetime` | UTC ISO text | no |
| `time` | `HH:MM` text | no |
| `daterange` | an object: start and end | no |
| `embed` | an object: provider, id, address, poster | no |
| `table` | an object: head and rows | no |
| `location` | an object: lat, lng, address | no |

A field in a model file, a block or a model class (`#[Field(type: 'link')]`) uses these keys the
same way. Every value is checked in the core, so a seed, an import and the REST write path refuse the
same values Folio's form refuses.

## Install

```
composer require preflow/fields
```

- With Folio: nothing else. Folio requires this package and registers its provider itself.
- Without Folio: add `Preflow\Fields\FieldsServiceProvider` to `config/providers.php`. It tells the
  data package how each type is stored and checked, and adds the template functions to your
  template engine.
- Upgrading: the twelve type keys above now belong to this package. A site or package that has its
  own Folio field type under one of them (or `FieldKinds` registrations under that key) gets this
  package's core checks and boot checks for it; rename yours before upgrading.

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

## Date with time, time and date range

`datetime`, `time` and `daterange` are registered by `FieldsServiceProvider` like the others. None of
them is `translatable`; their options (`zone`, `with_time`) are keys of the field itself, next to
`"type"` (an object under the type's own name is a boot error).

| type | stored | check | page |
|---|---|---|---|
| `datetime` | UTC ISO text, `"2026-03-29T12:30:00Z"` | `ValidDateTime` | `datetime_local(value, format, zone)` |
| `time` | `"HH:MM"`, 24 hours (`"09:05"`) | `ValidTime` | `time_format(value, format)` |
| `daterange` | `{"start": "2026-03-01", "end": "2026-03-07"}`, or with `"with_time": true` the two ends as UTC ISO text | `ValidDateRange` | `daterange_start(value, format, zone)`, `daterange_end(...)` |

An empty value is `null` and passes (`"validate": ["required"]` makes it required).

### Zones: what is stored and what people see

A `datetime` is one instant, stored in **UTC**. People type and read it in the field's **zone**:
`"zone": "Europe/Zurich"` on the field, else the **site's zone**, which is `timezone` in
`config/app.php` (the application sets it as PHP's default zone; when `timezone` is missing or not a zone name, PHP's own default applies, `date.timezone` in php.ini, which differs between machines: set it). So an event at a place reads the
same for every editor, whatever their browser's zone. A site changes the zone in one place; a
field sets its own for a place elsewhere. A `time` is a wall-clock time and has no zone and no date
(`"09:05"` is 09:05 wherever it is read). `"zone"` on a `time`, or on a `daterange` without
`"with_time"`, is a boot error (a date has no zone either).

UTC ISO text sorts correctly as text, so a `datetime` column sorts by the instant on every storage:
its column kind is `date` (the same as a `date` field), and Folio's list lets you sort by it. A
`time` is a short text column (`HH:MM` sorts as text); a `daterange` is an object and a text column.
Folio's list does not offer a sort header for it and ignores `?sort=` for it; use a `datetime` or
`date` field beside it if the list needs an order.

### The clock changes: spring forward and fall back

Two clock times do not map to one instant. The rule is the same for every zone:

- **A time that does not exist** (the hour the clocks skip when they go forward, in
  `Europe/Zurich` 02:00 to 03:00 on 2026-03-29) is **refused**, with a message naming the time and the
  zone: "2026-03-29 02:30 does not exist in Europe/Zurich (clocks go forward). Choose another
  time." Nothing is shifted silently.
- **A time that happens twice** (the hour the clocks repeat when they go back, 02:00 to 03:00 on
  2026-10-25) is the **earlier instant**: 02:30 is read as summer time (00:30 UTC), not as winter
  time (01:30 UTC). The editor cannot pick the later one; choose 03:00 or later, or type the
  UTC value.

`DateTimeValue::fromLocal($text, $zone)` applies the rules (null for a time that does not exist) and
`DateTimeValue::localProblem()` says why. Without Folio the check wants the stored form
(`2026-03-29T12:30:00Z`); text typed in the zone is refused (the check reads a time of the skipped
hour as the "does not exist" message, anything else as "Must be a date and time, typed as 2026-03-29 14:30 in the editor (stored in UTC as 2026-03-29T12:30:00Z)."). `DateTimeValue::fromLocal($text, (new DateOptions())->zone())->toStored()`
builds the stored value from what a person typed (a seed, an import).

### Date range

`{"start", "end"}`, both given, the end on or after the start (the same day is fine; a range counts
both ends). The refusal names both: "The end (2026-03-01) is before the start (2026-03-07). Choose
an end on or after 2026-03-07." With `"with_time": true` the ends are date-times in UTC, typed in
the field's zone like a `datetime`, and shown in that zone in the message. A range with one end is
refused ("Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.").

### In templates

`datetime_local(value, format = "Y-m-d H:i", zone = null)` writes the instant with a PHP `date()`
format in the site's zone (or the zone named); `time_format(value, format = "H:i")` reformats a time
(`"g:i a"` gives `9:05 am`); `daterange_start(value, format = null, zone = null)` and
`daterange_end(...)` write an end (default `Y-m-d`, or `Y-m-d H:i` for a range with a time). All
give `''` for an empty value; a zone name that is no zone (`"Nowhere/Land"`) throws a `LogicException` naming it. They are `TemplateFunctionDefinition`s, so they work in every
template engine. The value for `<time datetime="…">` is the stored text itself.

## Video embed, table and location

`embed`, `table` and `location` are registered by `FieldsServiceProvider` like the others (their
column, their check, the template functions), so a site without Folio stores and checks them the
same way. Their options are keys of the field itself, next to `"type"`; an object under the type's
own name (`"table": {…}`) is a boot error that says where the option goes. None of them takes
`"translatable"` (a boot error): a video and a place are the same in every language, and a table
translates each cell inside the value.

| type | stored | check | page |
|---|---|---|---|
| `embed` | `{"provider": "youtube", "id": "aqz-KE-bpKQ", "url": "https://youtu.be/aqz-KE-bpKQ", "poster": "a0f3"}` | `ValidEmbed` | `embed_player(value, title)`, `embed_data(value)` |
| `table` | `{"head": ["Size", "Price"], "rows": [["S", "CHF 10"]]}`, a cell a text or a text per language | `ValidTable` | `table_html(value)`, `table_data(value)` |
| `location` | `{"lat": 47.3769, "lng": 8.5417, "address": "Bahnhofplatz, Zürich"}` | `ValidLocation` | `location_data(value)`, `geo_href(value)` |

An empty value is `null` and passes (`"validate": ["required"]` makes it required). All three are
objects: a JSON file holds them, SQL storage JSON text in a text column, and each value's
`fromStored()` reads both.

### The video embed

```json
"video": {"type": "embed"}
"clip":  {"type": "embed", "providers": ["peertube"]}
```

The editor pastes the address of the video as the browser shows it. These are recognized
(`Values\Embed::fromUrl()`):

- YouTube: `youtube.com/watch?v=…` (also with `&list=…&index=…` from a playlist, and on `m.` and `music.`), `youtu.be/…`, `youtube.com/shorts/…`,
  `youtube.com/live/…`, `youtube.com/embed/…` and `youtube-nocookie.com/embed/…`. The id is
  YouTube's 11 characters.
- Vimeo: `vimeo.com/76979871`, `vimeo.com/channels/…/76979871`, `vimeo.com/groups/…/videos/…`,
  `vimeo.com/showcase/…/video/…` and `player.vimeo.com/video/76979871`. A video shared with a
  private link keeps its hash: `vimeo.com/76979871/8272103f6e` (or `?h=8272103f6e`) has the id
  `76979871:8272103f6e`.

The address is stored as pasted (`url`); the player address is never stored, it comes from the
provider. Any other address is refused, naming the host and what to paste instead: "Videos from
www.dailymotion.com cannot be embedded here; paste the address of a video on YouTube, Vimeo." A
stored `provider` and `id` must be the ones the `url` gives (a seed or the REST API may also write
the plain address: `"https://youtu.be/aqz-KE-bpKQ"` passes and reads back as the video), and
`Embed::fromStored()` checks them again: a value that disagrees with its address (a file edited by
hand, an import past the check) reads as no video and renders nothing. `poster` is the id of an
image of the media library, or null: with Folio (its `RecordAddresses` implements
`Preflow\Fields\AssetImages`) the check refuses an id that is no image of the library or no file at
all; a site without a media library (nothing implements `AssetImages`) checks only that it is an id
as text. A poster and a link to a file count as used in the library (Folio's `ReferencesAssets`):
the file cannot be deleted while a record shows it, and cleanup never bins it.

**Another video host.** A site adds one in code and lists it on the fields that offer it:

```php
final class PeerTube implements \Preflow\Fields\EmbedProvider
{
    public function name(): string { return 'peertube'; }
    public function label(): string { return 'PeerTube'; }
    public function idOf(string $url): ?string
    {
        return preg_match('~^https://video\.example\.org/w/([A-Za-z0-9]+)\z~', $url, $m) === 1 ? $m[1] : null;
    }
    public function playerUrl(string $id): string { return 'https://video.example.org/videos/embed/' . rawurlencode($id); }
}

// in a service provider's register(), so the hosts are known when the content types are read:
$container->singleton(\Preflow\Fields\EmbedProviders::class, fn () => new \Preflow\Fields\EmbedProviders(new PeerTube()));
```

YouTube and Vimeo are always offered; `"providers"` lists only added ones (listing `youtube` or
`vimeo` is a boot error). With Folio, a field (or a block's field) that lists a name no provider is
registered for stops the boot, naming the field and how to register one. Where no such check
runs (a site without Folio has no content-type catalog), the save is refused on the field instead:
"Videos from "peertube" cannot be saved here: the site has no video provider of that name. …";
it never becomes an error page. `EmbedProviders::add()` works too, as long as it runs before the
types are read (Folio reads them in its `boot()`): `register()` is the safe place. Anchor an
`idOf()` pattern with `\z`, not `$` (which also matches before a final line break).

**On the page.** `{{ embed_player(page.video, "Our film") }}` prints the built-in player
(`PrivacyEmbedPlayer`): a real button (keyboard and screen readers: "Play video: Our film"), with
the poster when there is one, and a note "The video plays from YouTube." with a link to the video
there. Until a visitor presses the button the page loads **nothing** from the video's host: the
iframe waits in an inert `<template>`, the poster is the site's own file (never the provider's
thumbnail), and the player address is `youtube-nocookie.com` for YouTube and `dnt=1` for Vimeo.
The click puts the iframe in place of the button (it has the title, and gets the focus). The
texts are in English, German and French (the page's language; another one reads English);
without a title the iframe's is "Video (YouTube)". The title is optional.

The player's script (`assets/embed-player.js`, one click listener for every player) and a few
layout rules (`assets/embed-player.css`: a 16:9 box, no colours, every rule at zero specificity so
any rule of the site wins) are added to the page's assets once, so they print with the page's CSP
nonce like every collected script and style (where the layout calls `{{ head() }}` and
`{{ assets() }}`, else before `</head>` and `</body>` of the page). No inline handler, no style
attribute. The
poster's address comes from the bound `RecordAddresses` when it also implements `AssetAddresses`
(Folio's does); without one the player shows no poster. Without an asset collector (an app that
renders outside preflow/view's page pipeline) the script could not reach the page, so
`embed_player()` prints a plain link to the video (`<a class="preflow-embed-link">`, the title as
its text) instead of a button that would do nothing.

**The site's Content-Security-Policy** allows frames from the site only (`frame-src 'self'`), so
the player stays blank after the click until the site allows the providers' player hosts, in
`config/security.php`:

```php
return [
    'csp' => [
        'frame-src' => ["'self'", 'https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
    ],
];
```

A directive given there replaces the default one, so keep `'self'`. Add the player host of each
provider the site added. Nothing else is needed: the poster is the site's own image (`img-src
'self'`) and the script is the page's own.

**Your own markup.** Two ways, both for every template engine:

- Bind your own `Preflow\Fields\EmbedPlayer` (`html(EmbedView $view): string`) in a service provider:
  `$container->singleton(\Preflow\Fields\EmbedPlayer::class, fn () => new MyPlayer());`.
  `embed_player()` and Folio's page output then print yours. `EmbedView` holds the value, the
  provider's label, the player address, the poster's address, the title and the language (its
  `text()` gives the built-in texts). Keep the three hooks and the script does the click for you:
  an element with `data-preflow-embed`, a `<button data-preflow-embed-play>` inside it, and a
  `<template data-preflow-embed-frame>` holding the iframe.
- Or write the markup in the template from `embed_data(value)`: `provider`, `id`, `url`, `poster`,
  `player` (the address to load after a click), `label` and `poster_url`. A template that does this
  loads nothing itself: the script comes with `embed_player()` only.

Either way: load nothing from the provider before the visitor starts the video.

### The table

```json
"prices": {"type": "table", "max_columns": 4, "max_rows": 20}
```

A header row (`head`, or `null` without one) and rows of plain-text cells. Each cell is a text, or
a text per language (`{"en": "Small", "de": "Klein"}`) as a link's label: the rows and columns are
the same in every language and each cell translates on its own. `Values\Table::fromStored()` pads a
short row with `''`; a table with no text in any cell is `null`.

- `"max_columns"` (the widest row or the header) and `"max_rows"` (the header not counted) limit it,
  with or without Folio: "This table has 5 rows; at most 4 are allowed. Remove a row." Without them
  there is no limit.
- The check refuses what is no table: rows that are no list, a cell that is no text (a number
  too: write `"10"`), a text per language that is not text ("Must be a table: an optional header
  row and rows of text cells.").

`table_html(value, locale = null)` prints `<table class="preflow-table">` with the header row in
`<thead>` (`<th scope="col">`) and every text escaped, each cell in the page's language along the
content languages' fallback chain. `table_data(value, locale = null)` gives the texts as
`{head: list|null, rows: list<list>}` for your own markup.

### The location

```json
"place": {"type": "location"}
```

A latitude from -90 to 90 and a longitude from -180 to 180 (degrees as maps and phones give them:
north and east positive), both numbers (a number as text is read too), and an optional address line.
A refusal names the coordinate: "The latitude must be a number from -90 to 90 (north is positive),
such as 47.3769." A place needs both coordinates; an address alone is refused. The field takes no
options.

No map is drawn by default, in the editor or on the page: no map tiles, no third-party request.
`location_data(value)` gives `{lat, lng, address}` for the site's own map or text, and
`geo_href(value)` a `geo:47.3769,8.5417` address that opens the map app on a phone.

**A map in the editor: LocationPicker.** A site that wants one binds a
`Preflow\Fields\LocationPicker`:

```php
interface LocationPicker
{
    /** @param array{lat: string, lng: string, address: string} $inputIds */
    public function editorHtml(array $inputIds, ?Location $value, array $config): string;

    /** @return list<string> */
    public function editorAssets(): array;
}
```

Folio puts its `editorHtml()` below the field's inputs and loads its `editorAssets()` (files the site
serves through Folio's `AdminAssets`). The picker writes a picked place into the inputs (by their
ids, firing `input`); the inputs stay the value, so what is saved is what they hold and the core
check applies as without a picker. Its tiles or search come from a third party: the admin's
Content-Security-Policy must allow them (`img-src`, `connect-src`). No inline script or style.

### Search, transfer, blocks

`embed`, `table` and `location` are not searched in 0.91: they are structured values, like a link
or a blocks field, and loose search does not reach them (no core change: a table's cells would need
a search over the texts inside an object, which no storage offers yet). Transfer and export carry the
stored objects as they are. In a block the options go in the field's `config`
(`#[BlockField(type: 'table', config: ['max_columns' => 2])]`); the checks apply there too.

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
