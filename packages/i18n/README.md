# Preflow I18n

Translations, locale detection, and pluralization for Preflow applications.

## Installation

```bash
composer require preflow/i18n
```

## What it does

- Loads PHP translation files from `lang/{locale}/{group}.php`, and the groups installed packages
  declare (`extra.preflow.translations`), with the site's files laid over them key by key
- Admin overrides (a layer for texts changed in the admin), display labels as plain strings or
  per-language maps, and a `translations:check` command
- Resolves dot-notation keys with `:param` replacement and locale fallback
- ICU-style pluralization with exact, range, and simple two-form rules
- PSR-15 middleware for locale detection (URL prefix → cookie → Accept-Language)
- Twig functions for global and component-scoped translations
- Machine translation through the AI connection (`preflow/ai`): single texts, batches, whole
  collections, with tuning, a glossary and a cost estimate (see "Machine translation" below)

## Configuration

`config/i18n.php`:

```php
return [
    'default'      => 'en',
    'available'    => ['en', 'de'],
    'fallback'     => 'en',
    'url_strategy' => 'prefix', // 'prefix' | 'none'
];
```

## Translation files

`lang/en/blog.php`:

```php
return [
    'title'      => 'My Blog',
    'published'  => 'Published on :date',
    'post_count' => '{0} No posts|{1} One post|[2,*] :count posts',
];
```

## API

### Translator

```php
$translator = new Translator(
    langPath: __DIR__ . '/lang',
    locale: 'en',
    fallbackLocale: 'en',
);

$translator->get('blog.title');                          // "My Blog"
$translator->get('blog.published', ['date' => '2026-01-01']); // "Published on 2026-01-01"
$translator->choice('blog.post_count', 5);              // "5 posts"
$translator->setLocale('de');
$translator->getLocale();                               // "de"
$translator->get('blog.title', [], 'fr');               // in another locale, the current one unchanged
```

Parameters are replaced one after the other in the order they are passed. In one text, don't use
a parameter whose name is the start of another (`:to` next to `:total`): `:to` would also replace
the start of `:total`.

## Package translations

A package declares its translation groups in its `composer.json`. Preflow reads them from
Composer's `vendor/composer/installed.json` when the app boots, like `extra.preflow.commands`:

```json
"extra": { "preflow": { "translations": {
    "members":       { "path": "lang", "scope": "public" },
    "members-admin": { "path": "lang", "scope": "admin" },
    "codes": "lang"
} } }
```

- The key is the group: `lang/{locale}/members-admin.php` holds the keys `members-admin.…`.
- `path` is the folder, relative to the package root. It must stay inside the package.
- `scope` is `admin` (the admin interface and mails to staff) or `public` (member pages, mails
  to members, validation on public forms). A plain string is the path with scope `admin`.
- A bad declaration (unknown scope, missing folder, a path outside the package, a group a second
  package declares again) is skipped with an `error_log` line. It never breaks the boot.

The declaration replaces the `$translator->addDefaults('group', $path)` call a provider used to
make. `addDefaultsIfMissing('group', $path, 'admin')` is still there for apps without Composer
metadata (tests); it does nothing when a declaration already registered the group.

A translator is booted on every site that has `preflow/i18n`, with or without a `lang/` folder,
so packages always have their texts. The locale middleware is added only when the site has
`lang/` or `config/i18n.php`, so the frontend of a site without either does not change.

### Which text wins

For each locale in the chain (the asked locale, then the site default, then `en`), the first
of these that has the key:

1. an admin override (admin requests only, `admin` groups only, see below);
2. the site's `lang/{locale}/{group}.php`, key by key;
3. the package's file.

A key found nowhere renders as the key itself; `translations:check` catches it. A site
changes single phrases of a package by putting only those keys into its own file of the same
group.

### Admin overrides

`TranslationOverrides` (`get(string $locale, string $key): ?string`) is the layer above the
site files. `Translator::setOverrides()` sets it, and `useOverrides(true)` turns it on; it is
off by default. Folio turns it on for the length of an admin request and stores the texts its
Translations screen saves. Outside always-overridden groups (below), an override never reaches a public page:

- only keys of an `admin` group take one, plus keys under prefixes registered with
  `Translator::overridable('site.types.')` (Folio registers its model-label prefixes);
- a `public` group never does, unless it is registered with `alwaysOverride()`.

A site group that staff edit but public pages or mails render (Folio fills these from
`translations.editable` in `config/folio.php`) is registered with
`Translator::alwaysOverride('review-mail')`: its overrides apply on every request, with
`useOverrides()` on or off, in the asked locale (`get($key, $params, 'fr')`). A locale's own
file still wins over a fallback locale's override. An `admin` group throws
`InvalidArgumentException`, and a group declared `admin` later (`setScopes()`,
`addDefaultsIfMissing()`) drops out; `alwaysOverridden()` lists the groups, sorted.

`Translator::isOverridable($key)` tells whether a key can take one.
`packageEntries($group, $locale)` and `siteEntries($group, $locale)` list each layer on its
own; `entries()` gives both merged, without overrides.

### Labels from other files

Model files write display texts as a plain string or as a map per language:
`"label": "Apps"` or `"label": {"en": "Apps", "de": "Anwendungen"}`.

- `Labels::pick($value, $locale, $default)` picks the locale's entry, else the default's, else
  English, else the first.
- `Labels::resolve($translator, $key, $value)`: a translation key wins (override, site file,
  package file, in the translator's current locale); then `pick()`; then the key along the
  fallback chain; else null.

`DerivedKeys` collects keys that come from such files, with the file's own text, so
`translations:check` can check them: `$derived->add('Folio model labels', fn () => [key => text])`.

## `preflow translations:check`

```bash
php preflow translations:check            # every group
php preflow translations:check --group=folio
```

The release gate for "no raw keys". It lists:

- per group and language, the keys that exist in English but not in that language (site and
  package files together). A package group's own keys are checked in every language any
  package delivers; keys a site adds to a package group (in its `lang/en/{group}.php`) only in
  the languages the site has `lang/` files for, so a site offering English and German isn't
  asked for French texts of its own keys; a site group in the languages it has files for;
- keys used as `t('…')` in package templates and model files and in the site's `app/`,
  `resources/`, `templates/` and `config/models/`, that exist in no language;
- derived keys (model labels) of package groups that have no text in a delivered language,
  neither in a language file nor in the model file's map. A site's own types need none: their
  plain label is a valid fallback.

It exits 1 when anything is missing, else prints `Translations complete: N group(s), en, de, fr.`
The command comes with `preflow/i18n` (`extra.preflow.commands`) and needs `preflow/devtools`.
A site sees it after `composer update` has refreshed `vendor/composer/installed.json`.

### PluralResolver

Supports three formats:

```
{0} No posts|{1} One post|[2,*] :count posts   // exact + range
[2,10] Some|[11,*] Many                         // range only
One post|:count posts                           // simple two-form (1 = first, else second)
```

### LocaleMiddleware

PSR-15 middleware. Detects locale in priority order: URL prefix → cookie → `Accept-Language` header → configured default. `Accept-Language` is read by `AcceptLanguage::best()`; a language with `q=0` means "not this one" and is never picked (since 0.37). Strips the locale prefix from the request path. A `locale` cookie is set only when enabled (`'cookie' => true` in `config/i18n.php`; the default is off for the `prefix` strategy, on for `none`) and only when the locale changed.

Template helpers: `t()`/`tc()` (parameters are HTML-escaped), `current_locale()`, `available_locales()` and `locale_url(path, locale = current)`, which returns `/about` for the default locale and `/de/about` otherwise.

```php
$middleware = new LocaleMiddleware(
    translator: $translator,
    availableLocales: ['en', 'de'],
    defaultLocale: 'en',
    urlStrategy: 'prefix', // request to /de/blog strips /de, sets locale to "de"
);
```

`urlPrefix()` (since 0.46) returns the prefix stripped from the current request's URL: `'/de'` for `/de/blog`, `'/en'` when the default locale was in the URL, and `''` when there was none or the strategy is `none`. It is set only while `process()` runs: cleared on entry and restored to the previous value when `process()` returns or throws, so a long-running worker never carries it into the next request, and code that runs after the response (deferred mail) or in a nested handle never sees a stale prefix. Preflow's core registers the middleware in the container as `LocaleMiddleware::class` and uses `urlPrefix()` to keep component URLs on the page's locale (`/de/--component/…`, see preflow/htmx).

### TranslationExtension (Twig)

Register with your Twig environment:

```php
$twig->addExtension(new TranslationExtension($translator));
```

```twig
{# Simple key #}
{{ t('blog.title') }}

{# With parameters #}
{{ t('blog.published', { date: '2026-01-01' }) }}

{# Pluralization — third arg is the count #}
{{ t('blog.post_count', { count: 5 }, 5) }}

{# Component-scoped — resolves "my-component.label" #}
{{ tc('label', 'MyComponent') }}
```

`tc()` converts the component name from PascalCase to kebab-case and uses it as the translation group.

## Machine translation

Machine translation fills missing translations with AI, and keeps a human in the loop. It sits on
the AI connection in `preflow/ai` (this package requires it), so read that README first for
`config/ai.php`, keys and costs. Everything here works without Folio. With AI off, the classes
still load and resolve; only calling the translator throws `AiUnavailable`.

### Setup

List the providers in `config/providers.php`. Apps with Folio get them from Folio. Apps without
Folio add both:

```php
return [
    Preflow\Ai\AiServiceProvider::class,
    Preflow\I18n\Machine\MachineServiceProvider::class,
];
```

`MachineServiceProvider` binds `MachineTranslator`, `Estimator`, `TextSources`, `JobStore`
(files in `{storage}/jobs`), `JobRunner` and `TranslationSettings`, all from `config/ai.php`.

### MachineTranslator

```php
$translator = $container->get(Preflow\I18n\Machine\MachineTranslator::class);

// One text, on the fly. Throws AiUnavailable (AI off or unusable) or TranslationFailed.
$de = $translator->translate('Welcome back, {name}!', to: 'de', from: 'en');

// Many texts. One TranslationResult per unit, in the same order.
$results = $translator->translateUnits($units, note: 'This is a game for children.');
```

`translate()` takes an optional `Tuning` and a `TextFormat` (`Plain`, `Html` or `Markdown`).
`translateUnits()` never throws for one bad text: that unit comes back with status `Failed` and
an `error`, and the others are still delivered. Only `AiUnavailable` (no key, no credit, provider
down) ends the whole call. A `note` is a one-off instruction for this call.

How a batch works:

- Units go to the model as numbered JSON items, grouped by language and type into requests of at
  most `translation.batch_chars` characters (default 12000).
- A request the provider withholds (blocked, cut off, refused, broken JSON) is split in halves and
  retried down to single units. A text that still fails is reported as failed, never dropped.
  A Gemini recitation block gets one more try with a request to word it freely.
- A request that gets no answer at all (a timeout, a 5xx or 429 after the provider's own retry)
  is not split: halves would fail the same way. Its units fail once with the reason. When the
  next request fails that way too, the call throws `AiUnavailable` ("not reachable"), so a run
  stops instead of sending request after request to a provider that is down.
- Requests ask for low reasoning (`AiRequest::$lowReasoning`): translating needs little, and
  thinking tokens are billed as output.
- Each answer is checked (see `MarkupCheck`). A result that fails the check counts as failed.

### TranslationUnit

A `TranslationUnit` is one piece of text to translate, whatever it came from. It does not know
about records. Its main fields:

- `id`: a path, unique within one item (`title`, `sections.2.body`).
- `label`: what a person sees, such as "Step 3 › Option B".
- `source`, `from`, `to`: the text, its language and the target language.
- `format`: `TextFormat::Plain` (default), `Html` or `Markdown`.
- `kind`: an optional tag such as `quiz-option`, so tuning can target a kind of text.
- `previousSource` and `current`: for a text that is outdated, the source the translation was
  made from and the translation itself. The model then adapts the current translation to the
  change and keeps the existing wording elsewhere.
- `context`: short facts for the model, such as `['type' => 'Game', 'record' => 'Cascadia']`.
- `tuning`: instructions for this unit only, already worked out by the source.
- `type`: the collection key, used for glossary type limits and for the type tuning level.

### MarkupCheck

A model sometimes drops a tag or rewrites a placeholder. That would break a page, so such a
result is rejected as failed instead of stored.
`MarkupCheck::matches($source, $result, $format, $protectedTokens = [])` is true when:

- the `{placeholders}` and `:placeholders` of the source are all still in the result, no more and
  no fewer (their order may change, because grammar does);
- for `Html`, the tags are the same, in the same order, opening and closing;
- for `Plain`, the result is not HTML (unless the source was);
- for `Markdown`, the placeholder rule only;
- for each protected token pattern, the source and the result hold the same matches, in any order.

#### Protected tokens

A project's own token syntax can be protected the same way. List regular expressions in
`config/ai.php`:

```php
'translation' => [
    // {{glossary:key}} and {{glossary:key|shown text}}: the key must stay, the shown text may be translated.
    // The shown text excludes braces and line breaks, so an unclosed one cannot swallow the next token.
    'protected_tokens' => ['/\{\{glossary:(\w+)(?:\|[^{}\r\n]*)?\}\}/'],
],
```

- A pattern **with a capture group 1** compares only what the group captures. Above, a result
  must keep every key (`meeple` stays `meeple`). It may translate the shown text, or add one.
- A pattern **without a group** compares the whole match, so the token must come back unchanged.
- Each match counts: dropping, adding or changing one fails the unit with `markup changed`,
  like a lost tag.
- The patterns also go into the prompt as one rule, so the model knows what to keep.
- An invalid pattern throws an `InvalidArgumentException` naming
  `translation.protected_tokens[i]` on the first translation. It never lets tokens through
  unchecked.

`TranslationSettings::protectedTokens()` reads them.

### Tuning

Tuning is a list of instructions (plus an optional answer template) that goes with every
request. It is layered, from general to specific, and later levels are added after earlier ones:

| Level | Key | Where the default lives |
|---|---|---|
| Project | `project` | `config/ai.php` `tuning` |
| Language | `lang:{locale}` | `config/ai.php` `translation.languages` |
| Type | `{type}` | `config/ai.php` `translation.types` |
| Field | `{type}.{field}` | the field in the model JSON: `"ai": {"tuning": "…"}` |
| Kind | `{type}.{field}:{kind}` | the field in the model JSON: `"ai": {"kinds": {"kind": "…"}}` |
| One-off | | the `note` of a call or a run |

```php
'tuning' => ['Our product is called "Foo"; never translate it.'],
'translation' => [
    'languages' => ['de' => ['Address the reader with "du".']],
    'types'     => ['game' => ['Keep the tone playful.']],
],
```

An admin can edit any level on a settings screen (Folio: Settings → AI → Tuning). Those edits are
stored apart in `{storage}/settings.json`, and a stored edit **replaces** the default of that one
level and leaves the others alone. Saving an empty edit removes it, which brings the default
back. Defaults are in git and edits are not, so a deploy never overwrites an edit. Field and kind
instructions travel with their item as a hint; the other levels go into the system part, so the
provider can cache them across a batch.

`TranslationSettings` reads all this: `tuning($to, $type)`, `unitTuning(...)`, `glossary()`,
`levels()` and `saveLevel()`.

### Glossary

The glossary holds terms that must be translated one fixed way, or not at all.

```php
'glossary' => [
    ['term' => 'Meeple', 'translations' => ['de' => 'Meeple', 'fr' => null], 'types' => ['game'], 'note' => 'A game piece'],
    ['term' => 'Worker Placement', 'translations' => ['de' => 'Arbeitereinsatz']],
],
```

- `translations` is per target language. A value is the rendering to use. `null` means keep the
  term as it is in that language.
- The term is found by whole word and ignoring case, with Unicode letters and digits as the word
  edges ("Meeple" does not match "Meeples"). Spaces inside a term match any run of spaces.
- `types` limits an entry to some collections; empty means all. `note` is passed to the model.
- Only entries whose term occurs in a batch (and that have a rendering for the target language)
  are sent, so a big glossary costs nothing on texts it does not touch.
- After translating, a unit whose source has the term but whose result lacks the required
  rendering gets a warning `glossary:{term}`. It is still a usable result; people see the
  warning in the review.

An admin's stored glossary (Folio: Settings → AI → Glossary) replaces the config one as a whole
once it has rows.

### Estimator

`Estimator::estimate($units)` tells before any call what a run will send and cost: `units`,
`characters`, `inputTokens`, `outputTokens`, `requests` and `costUsd` (null when the model has
no price). It counts a quarter of the characters as tokens and sizes the very batches a run would
send. It leaves out the tuning and glossary lines, which are small next to the texts. Treat it as
close, not exact.

### Translating collections: TextSource and JobRunner

To translate many items (a Folio type, or your own data), implement `TextSource` and register it
under a collection key in `TextSources`:

- `collection()`, `label()`: the key and a name for people;
- `items()` and `title($id)`: the ids of all items and a title for each;
- `units($id, $to, Scope $scope)`: the units of one item. `Scope::Needed` is only what is missing
  or outdated, `Scope::All` everything with a source text;
- `write($id, $to, $texts)`: store the translated texts (unit id to text) and return a
  `WriteOutcome` (`Written`, `Conflict` when someone changed the item meanwhile, or `Skipped`).
  A write must never replace a text that changed since `units()` read it (the model may take
  minutes, and a person may save meanwhile): refuse the whole write as `Conflict`, or leave such
  texts out and name them in `WriteOutcome::$leftAlone`.
  A source should mark the texts as machine-made and must never publish. `MachineMarks` helps
  with that: it keeps a fingerprint of each AI text, so a text counts as not checked only while
  it is exactly what the machine wrote;
- `writesLive()`: true when a write is public at once (no publishing step).

`JobRunner` runs a job over a source one item at a time, so a web request or a cron tick stays
short:

- `plan($collection, $locales, $scope, $ids = null, $note = null)` counts the units, estimates
  the cost and saves a job; nothing is written to the source;
- `start($jobId)` sets it running (also after a stop, and takes up a job still marked running
  whose driver went away);
- `unfinished($collection, $max = 5)` lists the collection's jobs that are not done, newest
  first (`JobStore::recent()`), so a screen can offer to continue them;
- `step($jobId, $maxItems = 1)` does the next pending items and saves the state. One item that
  fails is recorded and does not stop the run. `AiUnavailable` stops the job (state `stopped`,
  with the message) and is rethrown;
- `stop($jobId)` keeps what is done.

The job (`Job`) is a small JSON file with a state (`planned`, `running`, `stopped`, `done`), the
estimate, the real cost so far (`costUnknown` is true once a request had no price, so `costUsd`
is only part of it) and one status per item (`pending`, `done`, `failed`, `conflict`,
`skipped`). While an item is translated by a translator that implements `ReportsProgress`
(`AiTranslator` does: after each request), the item carries
`progress => {locale, done, total}` for the language in hand; it goes away when the item is
through.

A cron script for data that has nothing to do with Folio. `PhraseSource` is your own
`TextSource`.

```php
<?php
// bin/translate-phrases.php, run by cron: */5 * * * * php /path/to/bin/translate-phrases.php
require __DIR__ . '/../vendor/autoload.php';

use Preflow\Ai\AiUnavailable;
use Preflow\Core\Application;
use Preflow\I18n\Machine\{JobRunner, Scope, TextSources};

$root = dirname(__DIR__);
$app = Application::create($root);
$app->boot();
$c = $app->container();
$c->get(TextSources::class)->add('phrases', static fn () => new PhraseSource($root . '/data/phrases.json'));
$runner = $c->get(JobRunner::class);

$marker = $root . '/storage/ai/phrases.job';        // the id of the job in progress
$id = is_file($marker) ? trim((string) file_get_contents($marker)) : null;
if ($id === null) {
    $job = $runner->plan('phrases', ['de', 'fr'], Scope::Needed);
    if (($job->estimate->costUsd ?? 0.0) > 2.0) {    // a safety net: never start a big run unseen
        fwrite(STDERR, "Estimated cost too high: {$job->estimate->costUsd}\n");
        exit(1);
    }
    file_put_contents($marker, $id = $job->id);
    $runner->start($id);
}
try {
    $job = $runner->step($id, maxItems: 5);          // short: a few items per tick
} catch (AiUnavailable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($job->state === 'done') {
    unlink($marker);
}
```

Texts written this way are machine translations that nobody has checked, so decide for your own
data how a person gets to review them before they go live.

### Background translation

Jobs that were started (`JobRunner::start()`) are queued and worked by a cron job, so nobody has to
keep a browser open. Add this line to the crontab of the server:

```
* * * * * cd <app> && php vendor/bin/preflow translate:work
```

`translate:work [--seconds=N]` works the queued jobs, oldest first, one record at a time, and
stops after N seconds (default 50) so the next minute's run does not overlap. It prints one line
per record and a total with the cost, but only when it worked records or stopped: an idle run
prints nothing, so cron does not send a mail every minute. With AI switched off it does nothing
and prints nothing.
A job still marked running (its browser was closed or its process died) is continued. If AI
cannot be used, the job is stopped with the reason and the run ends. `TranslationWorker::lastRun()`
tells when the worker last started, so a screen can show whether the cron job runs at all.

### Without a key

Tests can swap the provider for `Preflow\Ai\Testing\FakeAiClient`; see the `preflow/ai` README.
