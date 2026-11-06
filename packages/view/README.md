# preflow/view

Engine-agnostic template interfaces and asset pipeline for Preflow. Defines the contracts that engine adapters (`preflow/twig`, `preflow/blade`) implement. Ships a CSP-nonce-aware asset collector with deduplication.

## Installation

```bash
composer require preflow/view
```

Requires PHP 8.4+. Install an engine adapter to render templates:

```bash
composer require preflow/twig   # Twig 3
composer require preflow/blade  # Laravel Blade
```

## What's included

| Component | Description |
|---|---|
| `TemplateEngineInterface` | Engine contract: render, exists, addFunction, addGlobal, getTemplateExtension |
| `TemplateFunctionDefinition` | Value object describing a template function (name, callable, isSafe) |
| `TemplateExtensionProvider` | Interface for packages that supply template functions and globals |
| `AssetCollector` | Collects CSS/JS across components, deduplicates by xxh3 hash |
| `JsPosition` | Enum: `Head`, `Body`, `Inline` |
| `NonceGenerator` | Generates one random nonce per request for CSP |

## TemplateEngineInterface

The central contract. Engine adapters implement this to plug into Preflow.

```php
use Preflow\View\TemplateEngineInterface;

$html = $engine->render('blog/post', ['post' => $post]);
$engine->exists('partials/nav');                          // bool
$engine->getTemplateExtension();                          // 'twig' or 'blade.php'
$engine->addGlobal('siteName', 'My App');                 // available in all templates
$engine->addFunction(new TemplateFunctionDefinition(
    name: 'greet',
    callable: fn (string $name) => "Hello, {$name}!",
    isSafe: true,                                         // skip output escaping
));
```

## TemplateExtensionProvider

Feature packages implement this interface to register template functions without depending on a specific engine.

```php
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class MyExtensionProvider implements TemplateExtensionProvider
{
    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'myHelper',
                callable: fn (string $arg) => strtoupper($arg),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return ['appVersion' => '1.0'];
    }
}
```

Built-in providers: `ComponentsExtensionProvider`, `HtmxExtensionProvider`, `TranslationExtensionProvider`.

## AssetCollector API

```php
// Add CSS (key is optional; defaults to xxh3 hash of content)
$assets->addCss('.btn { ... }');
$assets->addCss('.btn { ... }', key: 'btn-styles'); // explicit dedup key

// Add JS
$assets->addJs('console.log("body")', JsPosition::Body);
$assets->addJs('console.log("head")', JsPosition::Head);
$assets->addJs('console.log("inline")', JsPosition::Inline);

// Render (called by engine extensions; also usable directly)
$assets->renderHead();    // <script nonce="...">head JS</script>
$assets->renderAssets();  // <style nonce="...">CSS</style><script nonce="...">body JS</script>
$assets->renderCss();
$assets->renderJsHead();
$assets->renderJsBody();
$assets->renderJsInline();

// CSP nonce for the current request
$assets->getNonce(); // base64 random, stable within one request
```

Every `<style>` and `<script>` tag rendered by `AssetCollector` carries the same `nonce` attribute. Preflow sends a matching `Content-Security-Policy` header itself (strict by default; override per directive in `config/security.php`, sent as report-only in debug mode). For a `<script>` or `<style>` you write by hand, add `nonce="{{ csp_nonce() }}"`; without it the browser blocks the tag.

Identical blocks (same xxh3 hash) are deduplicated automatically — safe to include the same component multiple times.

### Forked collectors and inspection

`fork()` creates an isolated child `AssetCollector` that shares the same nonce but collects assets independently. Use it when rendering a sub-component that should not pollute the parent collector (e.g. fragment responses).

```php
$child = $assets->fork();
$html  = $componentRenderer->renderFragment($component, $child);
// $assets is untouched; $child holds only the fragment's CSS/JS
```

`hasCss()` and `hasJs()` let you check before rendering — useful to decide whether to append inline styles to a fragment response.

```php
if ($child->hasCss() || $child->hasJs()) {
    $html .= $child->renderHead() . $child->renderAssets();
}
```

## GEO: Markdown for AI search

GEO (generative engine optimisation) gives AI assistants and search engines a clean Markdown version of your public
pages, and a map of them in `/llms.txt` ([llmstxt.org](https://llmstxt.org)). It is off until you turn it on; while
it is off, nothing about your pages changes.

### Turning it on

Create `config/geo.php`:

```php
<?php

return [
    'enabled' => true,
    // The heading and summary of llms.txt. Leave them null to take them from Folio's "site"
    // singleton (the fields named in site_fields, in each language), else the app name.
    'name' => null,
    'summary' => null,
    'site_fields' => ['name' => 'name', 'summary' => 'meta_description'],
    // Folio types whose published records llms.txt lists, and a boolean field that keeps a record out.
    'types' => ['page'],
    'noindex_field' => null,
    // Serve /llms-full.txt (every listed page's Markdown in one file).
    'full' => true,
    // The audit reports llms-full.txt above this size (MB) as too large to be useful (since 0.55).
    'full_max_mb' => 5,
    // Pages without a Markdown view; also left out of llms.txt.
    'exclude' => ['/thank-you'],
];
```

Then every public page has a Markdown view:

- `/about.md` (or `/about` asked for with `Accept: text/markdown`), `/index.md` for the home page, `/de/index.md`
  for the German home page.
- The page is rendered as an anonymous visitor sees it: no cookies, no session, so nothing personal ends up in it.
- Pages that don't answer 200 (redirects too), are `noindex` (`<meta name="robots" content="noindex">` or an
  `X-Robots-Tag` header), Folio previews and excluded paths have none: their `.md` URL is a 404. Asked for with
  `Accept: text/markdown`, they (and redirects, JSON, files…) answer as they always do.
- The Markdown, `/llms.txt` and `/llms-full.txt` are sent with `Cache-Control: public, max-age=3600`.
- The Markdown starts with the page's title, a `Source:` line with its canonical URL and, when the page names one,
  an `Updated:` line with the date its content last changed (its `og:updated_time` or `article:modified_time`;
  with SEO on that is `PageMeta::$modified`, for Folio records their publish time). It is sent with
  `Link: <canonical URL>; rel="canonical"`, so search engines index the HTML page, not its `.md` URL. Since 0.55.
- HTML pages that have one point to it with `<link rel="alternate" type="text/markdown">` and a `Link` header.

### What ends up in the Markdown

By default the page's `<main>` element (else `<body>`) is converted: headings, paragraphs, links and images with
`alt` text (as absolute URLs), lists, tables, quotes and code. Navigation, header and footer outside `<main>` drop
out, and so do scripts, styles, SVG, form controls, hidden elements and anything you mark with `data-geo="skip"`:

```twig
<div class="share-buttons" data-geo="skip">…</div>
```

When a component's HTML doesn't tell the whole story (an interactive player, a chart, data in attributes), write
its Markdown yourself in a geo block. It takes the place of that component's HTML in the Markdown view and prints
nothing in the HTML page:

```twig
{% apply geo %}
## {{ game.title }}

- Players: {{ game.players }}
- Playing time: {{ game.minutes }} minutes

{{ game.summary }}
{% endapply %}
```

In Blade:

```blade
@geo
## {{ $game->title }}
- Players: {{ $game->players }}
@endgeo
```

Values you print inside a block come out as plain text (HTML entities decoded). A parent component's block covers
its children; an empty block leaves the component out. A block in a page or layout template outside any component
comes before the page's converted HTML; to have only the block, mark the page's own HTML `data-geo="skip"`.

This is the case for Folio record detail templates (a game, a gallery object): they are not components, so their
geo block is a page-level block and comes **in addition to** the converted HTML of the template. When the block
should stand alone, mark the template's own HTML `data-geo="skip"`:

```twig
{% apply geo %}
## {{ record.title }}
- Players: {{ record.players }}
{% endapply %}
<article class="game-detail" data-geo="skip">…</article>
```

### llms.txt and llms-full.txt

`/llms.txt` lists your pages, grouped by section, each linking to its Markdown view:

```markdown
# Game Club

> Board games, explained.

## Pages

- [Welcome](https://club.test/index.md)
- [About us](https://club.test/about.md): Who we are

## Other languages

- [de](https://club.test/de/llms.txt)
```

Each language has its own (`/de/llms.txt`); the default language's file links the others. Their heading is in the
file's language (translation key `geo.other_languages`: en, de and fr ship with preflow/view; a site renames it, or
adds another language, in its own `lang/{locale}/geo.php`). A language without the key falls back to the site's
fallback language, then English (default `it`, fallback `de`, no `lang/it/geo.php`: "Andere Sprachen").
`/llms-full.txt` (and `/de/llms-full.txt`) holds the Markdown of every listed page in the same order, each under its
title and source URL; `'full' => false` turns it off. Pages without a Markdown view (404, noindex…) are left out of
it.

With Folio, the published records of the `types` in your config are listed in each language: title from the
`title` field (else `name`, else the first list column), description from `meta_description`, and the record's URL
in that language. The home page comes first. Types behind a frontend permission are never listed. The index does
not render pages to find a `noindex`, so a record's "SEO & sharing" settings (with SEO on) or `noindex_field` are
what keep it out of llms.txt.

### Adding, changing and ordering entries

Since 0.50 the pages llms.txt lists come from the public pages list the sitemap reads too; add and filter pages
there (see "The public pages" under SEO below). `GeoEntries::source()` and `filter()` still work, but are deprecated.

Records with their own detail pages (games, gallery objects…) and pages outside Folio are added through
`GeoEntries`, from one of your service providers. A source gets the language and returns `GeoEntry` objects; a
filter gets the whole list and returns the list you want:

```php
use Preflow\View\Geo\GeoEntries;
use Preflow\View\Geo\GeoEntry;

public function register(Container $container): void
{
    $geo = $container->get(GeoEntries::class);

    // Each game's detail page in its own section: listed in llms.txt, kept out of llms-full.txt.
    $geo->source(fn (string $locale): array => array_map(
        fn (Game $game): GeoEntry => new GeoEntry(
            title: $game->title($locale),
            url: $game->url($locale),       // absolute, or a path on this site: "/de/spiele/schach"
            description: $game->teaser($locale),
            section: 'Games',
            full: false,
        ),
        $container->get(GameRepository::class)->published(),
    ));

    // Drop the not-found page, and put the guide's chapters in reading order.
    $chapters = ['Getting started' => 1, 'Rules' => 2, 'Strategy' => 3];
    $geo->filter(fn (array $entries, string $locale): array => array_map(
        fn (GeoEntry $e): GeoEntry => isset($chapters[$e->title])
            ? new GeoEntry($e->title, $e->url, $e->description, 'Guide', $chapters[$e->title])
            : $e,
        array_filter($entries, fn (GeoEntry $e): bool => !str_ends_with($e->url, '/not-found')),
    ));
}
```

`new GeoEntry(title, url, description = '', section = 'Pages', order = 100, full = true)`. Entries are sorted by
section, in the order the sections first appear (the home page's section, `order` 0, first), then by `order`, then
by title. Filters run after all sources, in the order they were registered.

## SEO: share cards, structured data, sitemap

SEO adds what search engines and link previews read: robots, Open Graph and Twitter card tags, `theme-color` and
JSON-LD in each public page's `<head>`, and `/robots.txt`, `/sitemap.xml` and `/.well-known/security.txt`. Public
pages also get an `ETag`, so a repeat visit can be answered with `304 Not Modified`. It is off until you turn it on;
while it is off, nothing about your pages changes.

It only adds tags a page doesn't already have: a robots meta, `og:image` or `theme-color` your layout prints wins.
`<title>` and `<meta name="description">` stay your layout's job.

### Turning it on

Create `config/seo.php` (the skeleton has one with every key commented):

```php
<?php

return [
    'enabled' => true,
    // og:site_name and the WebSite name in JSON-LD. Null takes it from Folio's "site" singleton
    // (the field site_fields names, in each language), else app.name.
    'name' => null,
    'site_fields' => ['name' => 'name', 'description' => 'meta_description'],
    // Who runs the site: a schema.org Organization or Person, on the home page.
    'owner' => [
        'type' => 'Organization',
        'name' => 'Game Club',
        'url' => 'https://club.example',
        'logo' => 'https://club.example/logo.png',
        'same_as' => ['https://social.example/gameclub'],
    ],
    // Per Folio type: the fields the share title, description and image come from (the first
    // that isn't empty; an image field's first asset) and the Open Graph type.
    'types' => [
        'post' => ['title' => ['title'], 'description' => ['lead', 'body'], 'image' => ['cover'], 'og_type' => 'article'],
    ],
    // Folio types in the sitemap; null lists every type with a public frontend route.
    'listed_types' => null,
    'robots' => [
        'enabled' => true,
        'disallow' => ['/--component/', '/*/--component/'], // Folio adds its admin path itself
        'ai_training' => true,                              // false shuts out AI training crawlers
        'extra' => [],                                      // raw lines, appended as they are
    ],
    'sitemap' => true,
    // Served only with a contact (mailto: or https: URL); Expires is always 180 days ahead.
    'security_txt' => ['contact' => 'mailto:security@club.example', 'policy' => null],
    'theme_color' => ['light' => '#ffffff', 'dark' => '#111111'], // or one '#hex'
    'etag' => true,
];
```

With Folio on SQL storage, run `preflow migrate` before turning SEO on: the "SEO & sharing" section is stored in a
new `_seo` column, and saving a record in the editor fails until it exists.

What a page gets:

- `<meta name="robots" content="max-image-preview:large">`, or `noindex` for a page hidden from search engines.
- `og:title`, `og:description`, `og:image` (with width, height and alt), `og:url`, `og:type`, `og:site_name`,
  `og:locale` and its alternates, `og:updated_time` when the page knows when it last changed (`modified`; Folio
  records: their publish time; since 0.55), and `twitter:card` (`summary_large_image` with an image).
- JSON-LD: the `WebSite` and its owner on each language's home page, a `WebPage` on every page, and the nodes the
  page or its type adds (below). A page that prints its own `application/ld+json` (in the head or the body) gets
  none of ours.
- Pages that are `noindex` (their own robots meta, an `X-Robots-Tag` header, or the record's setting) and private
  responses (`Cache-Control: private` or `no-store`: members' pages, gated Folio pages, share previews) get no cards
  and no JSON-LD, unless the page says its content is public (`public_content`, below). Paths in robots.txt's
  Disallow that a package adds (Folio's admin) get no tags at all.
- No `ETag` for private responses, responses that set a cookie, or requests that carry the session cookie.
- A `304` carries no `Content-Security-Policy`: the browser keeps the policy it stored with the cached page, whose nonces match that page (a fresh nonce would block its inline scripts and styles). Since 0.53.2.

### Folio pages

On a Folio page, the record fills the cards. Each value comes from the first of:

1. the record's **SEO & sharing** section in the editor (share title and description per language, share image),
2. the fields `types` maps for its type,
3. the built-in fields: `meta_title`, `title`, `name` for the title, `meta_description` for the description,
4. for the description and image, the `site` singleton: its own SEO & sharing section, then its `site_fields`
   description. A page without a title of its own keeps its `<title>`.

Each value is chosen on its own: a page can have its own share title, a description from its `lead` field and the
site's share image. Share texts count only in languages the record is visible in. Images go through the media
library's `share` format (1200×630 JPEG, with the crop editors set for it); private, deleted and non-image assets are
skipped. The section's settings (hide from search engines, leave out of the sitemap, leave out of the AI index) are
content: they take effect when the record is published, and a share-preview link shows the working copy as
`noindex`. A record GEO's `noindex_field` hides is `noindex` too.

### Pages outside Folio: `seo()`

A controller's template or a file-routed page sets its own values; keys are `PageMeta`'s, in snake_case. A template's
values are merged over what a Folio record set.

```twig
{% do seo({
    title: game.title,
    description: game.teaser,
    image: game.cover_url,       {# an image URL; or the array MediaLibrary::shareImage() returns #}
    og_type: 'article',
    noindex: false,
    modified: game.updated_at,   {# ISO 8601: og:updated_time, dateModified, the Markdown's Updated line (0.55) #}
    schema: {'@type': 'Game', name: game.title},
}) %}
```

`image` takes a URL as it is (no size or alt). For the media library's 1200×630 share crop with its size and alt
text, the controller passes `MediaLibrary::shareImage($assetId, $locale)` to the template (say as `share_image`),
which gives it on: `image: share_image`.

A page whose content is the same for everyone but whose response is private, because it embeds one visitor's
tokens or state (a gallery page with the visitor's likes), says so with `public_content: true`, on its own or with
other keys: `{% do seo({public_content: true}) %}`. It then gets the cards and JSON-LD of a public page; the response
stays as it is (its `Cache-Control` untouched, no `ETag`), and `noindex` still wins. Since 0.53.1. The flag is set
per template: put `seo({public_content: true})` in the template of the public page itself, not in a layout shared
with members' or gated pages (a gated Folio type answers `private, no-store` too, and would get cards).

In Blade: `@seo(['title' => $game->title, 'description' => $game->teaser])`. In PHP, set or merge the request's
`Preflow\View\Seo\SeoContext` (`set(new PageMeta(...))`, `merge([...])`). An unknown key in a template's `seo()` is
ignored (debug mode logs it); `PageMeta::with()` and `merge()` in PHP reject one.

### The public pages: sitemap and llms.txt

`/sitemap.xml` and GEO's `/llms.txt` list the same pages, from `Preflow\View\Seo\PublicPages`. With Folio, the
published records of every type with a public frontend route are in it (no `permission`; narrowed by
`listed_types` for the sitemap, by GEO's `types` for llms.txt), in each language they are visible in, with their
publish time as `<lastmod>` and their other languages as `hreflang` alternates. A record hidden from search engines
is in neither; the other two settings take it out of one.

Add your own pages with a source, and drop, change or reorder pages with a filter, from one of your service
providers:

```php
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

public function register(Container $container): void
{
    $pages = $container->get(PublicPages::class);

    // Each game's page: in the sitemap and llms.txt, not in llms-full.txt.
    $pages->source(fn (string $locale): array => array_map(
        fn (Game $game): PublicPage => new PublicPage(
            title: $game->title($locale),
            url: $game->url($locale),               // absolute, or a path on this site
            description: $game->teaser($locale),
            section: 'Games',
            lastmod: $game->updatedAt->format(DATE_ATOM),
            alternates: ['en' => $game->url('en'), 'de' => $game->url('de')],
            llmsFull: false,
        ),
        $container->get(GameRepository::class)->published(),
    ));

    // The not-found page in no list; the tools pages in the sitemap only.
    $pages->filter(fn (array $pages, string $locale): array => array_map(
        fn (PublicPage $p): PublicPage => str_contains($p->url, '/tools/') ? $p->with(['llms' => false, 'llmsFull' => false]) : $p,
        array_filter($pages, fn (PublicPage $p): bool => !str_ends_with($p->url, '/not-found')),
    ));
}
```

`new PublicPage(title, url, description = '', section = 'Pages', order = 100, lastmod = null, alternates = [],
sitemap = true, llms = true, llmsFull = true)`; `with([...])` changes some of them. Pages are sorted as GEO's entries
were (sections in the order they first appear, then `order`, then title).

**Moving a GEO source or filter here:** `GeoEntries::source()` and `filter()` still work in 0.50 but are deprecated,
and their entries never reach the sitemap. Replace `GeoEntries` with `PublicPages` and `GeoEntry` with
`PublicPage`; `full: false` becomes `llmsFull: false`, and `new GeoEntry($e->title, $e->url, …)` in a filter becomes
`$p->with([...])`:

```php
// Before
$container->get(GeoEntries::class)->filter(fn (array $entries) => array_filter(
    $entries, fn (GeoEntry $e) => !str_ends_with($e->url, '/not-found'),
));

// After: out of llms.txt and the sitemap
$container->get(PublicPages::class)->filter(fn (array $pages) => array_filter(
    $pages, fn (PublicPage $p) => !str_ends_with($p->url, '/not-found'),
));
```

### Structured data for your types

Domain schemas (a game, a product, a job offer) are your site's: register a builder for a Folio type with
`Preflow\View\Seo\SchemaRegistry`. It gets the record's values in the page's language, the language and the page's
`PageMeta` (absolute `url`, `title`, `description`, `image`), and returns one node or a list. Its nodes join the
page's graph after the `WebPage`, escaped safely for the page:

```php
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\Schema;
use Preflow\View\Seo\SchemaRegistry;

$schemas = $container->get(SchemaRegistry::class);

$schemas->forType('job', fn (array $job, string $locale, PageMeta $meta): array => [
    '@type' => 'JobPosting',
    'title' => $meta->title,
    'description' => $meta->description,
    'url' => $meta->url,
    'datePosted' => $job['posted_at'],
    'employmentType' => $job['part_time'] ? 'PART_TIME' : 'FULL_TIME',
    'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Game Club', 'sameAs' => 'https://club.example'],
    'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $job['city']]],
]);

// The owner per language instead of the one in config (same shape as 'owner').
$schemas->owner(fn (string $locale): array => ['type' => 'Person', 'name' => 'Ada', 'url' => 'https://ada.example', 'same_as' => []]);
```

`Schema::breadcrumbList()`, `Schema::article()` and `Schema::imageObject()` build the common shapes, for builders and
`seo({schema: …})` alike.

### Site defaults without Folio

Folio gives the site name, description and share image from its `site` singleton and keeps its admin out of
robots.txt (all but its legacy `/_uploads/` images, which public pages show). A site without Folio can do the
same from a provider:

```php
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SeoSite;

$site = $container->get(SeoSite::class);
$site->defaults(fn (string $locale): PageMeta => new PageMeta(
    title: 'Game Club',                         // the site's name
    description: 'Board games every Friday',    // pages without their own
    image: ['url' => '/img/share.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Our club room'],
));
$site->disallow('/admin/');                     // in robots.txt, and no head tags there
$site->allow('/admin/uploads/');               // an exception under it (Allow:), head tags as usual
```

## SEO audit

`preflow seo:audit` checks the site's public pages for the problems that cost visitors and search engines:
a missing title, duplicate texts, images without `alt`, broken internal links, language versions that don't
point at each other, a 404 page that answers 200. It is run by hand, never as part of a deploy. Each
environment checks itself: the pages are rendered inside the app, with no network, as an anonymous visitor
would get them and with the debug toolbar off. Run it where the site runs (on the live server for the live
site, locally for your copy).

```
preflow seo:audit [--fresh] [--limit=N] [--url=/path] [--budget=600]
```

It audits what `PublicPages` lists (the same pages as the sitemap, every language, with the sitemap's and
`llms.txt`'s exclusions), prints the progress, the number of findings per severity and the findings grouped
by severity and check. There is no score. Severities are `critical` (visitors or search engines are
blocked or misled), `warning` (should be fixed) and `notice` (worth a look).

### The report and resuming

A full run saves its report in `storage/data/seo-audit.json` (the site's own storage, so each environment
has its own); Folio shows it (*Settings → SEO audit*). The report says `complete`, or `incomplete` with
done/total pages. A run stops itself before the time budget (`--budget`, default 600 seconds, which keeps
it under the roughly twelve minutes some servers allow a shell process) and says how to go on: run the
command again and it continues where it stopped. `--fresh` starts over. Running it again after a complete
run starts a new audit. While one audit runs, a second says "another audit is running" and does nothing.

`--limit=N` (the first N pages) and `--url=/path` (one page, without the site checks) are quick checks:
they print their findings and never touch the saved report, so they can't wipe the progress of a long run.

The exit code is 0 whenever the audit ran, with or without findings and finished or not (and when another
audit is running); 1 for a bad flag, an app that doesn't boot or a run that fails. Use the saved report,
not the exit code, to decide whether the site is clean.

### What it checks

Pages (each public page that answers 2xx; the `status.*` rows cover the rest):

| Id | Severity | What |
|---|---|---|
| `status.error` | critical | The page answers something other than 200 in this environment (5xx included) |
| `status.redirect` | warning | The page redirects; list the address it ends at instead |
| `title.missing` | critical | No `<title>` |
| `title.duplicate` | warning | Several pages of a language share a title |
| `description.missing` | warning | No meta description |
| `description.duplicate` | notice | Several pages of a language share a description |
| `h1.count` | notice | No `<h1>`, or more than one |
| `img.alt-missing` | warning | An image without an `alt` attribute (an empty `alt=""` is fine) |
| `og.image-alt-missing` | notice | A share image without its alt text |
| `aria-hidden.focusable` | warning | A focusable element inside `aria-hidden` content |
| `canonical.missing` | notice | No canonical link |
| `canonical.foreign-host` | warning | The canonical link points to another site |
| `noindex.in-sitemap` | critical | The page is `noindex` but the sitemap lists it |
| `hreflang.unpaired` | warning | A language version that doesn't link back, or an alternate that isn't one of the site's public pages |
| `hreflang.x-default-missing` | notice | A page with language alternates but no `x-default` |
| `geo.markdown-thin` | warning | GEO is on and the page's Markdown view is empty, or holds under a third of the words its `<main>` shows (pages under 50 words: only an empty one). Renders each `.md` once more. Since 0.55 |
| `geo.type-schema-missing` | notice | SEO is on and three or more pages under one address segment (`/spiele/…`) carry only generic JSON-LD (WebPage, BreadcrumbList…), nothing that says what they are about; one finding per group. Since 0.55 |
| `link.broken` | critical | An internal link whose target answers 404, 410 or 5xx (also redirect loops) |
| `link.broken` | notice | An internal link that goes through two or more redirects |

Links, images and other targets are checked for their status once per run, not per page; query-string URLs
and paths `robots.txt` disallows are left out; files in `public/` count as fine; 401 and 403 count as
members-only, not broken. A not-yet-made image size counts as fine (see below).

The site (once per run, after the pages):

| Id | Severity | What |
|---|---|---|
| `robots.missing` | warning | No robots.txt |
| `sitemap.missing` | warning | No sitemap |
| `sitemap.invalid` | critical | The sitemap isn't valid XML with a urlset or sitemapindex |
| `sitemap.non200` | critical | A URL in the sitemap doesn't answer 200 |
| `llms.missing` | warning | No `/llms.txt` for a language (when GEO is on) |
| `llms.full-too-large` | warning | A language's `/llms-full.txt` is larger than `full_max_mb` in `config/geo.php` (default 5). Since 0.55 |
| `notfound.status` | critical | An address that doesn't exist answers something other than 404 |
| `notfound.no-home-link` | warning | The 404 page has no link to the home page |
| `favicon.raster-missing` | warning | No linked raster icon of at least 48 px and no `/favicon.ico` |
| `apple-touch.missing` | notice | No apple-touch icon |
| `manifest.missing` | notice | No web app manifest |
| `etag.no-304` | notice | The home page doesn't answer 304 to its own ETag |
| `etag.unstable` | notice | Two renders a second apart give different ETags |
| `etag.cookie` | notice | The home page sets a cookie for new visitors, so it gets no ETag |
| `share-image.missing` | notice | No default share image |
| `owner.missing` | notice | No site owner for structured data |

A check that throws is not a crash: a page check reports one warning per check, "The check failed on N pages
and was skipped there"; a site or run check reports under its group id (`etag`, `sitemap`, `icons`, `notfound`). Out of scope: DNS and email setup, links to other sites, page speed, and judging the text itself.

### No trace

An audit render is marked as internal (`Preflow\Core\Http\InternalRender`, the request attribute
`preflow.internal_render`) and leaves nothing behind. Honouring the marker, the app writes no not-found
entry and counts no redirect hit; media generates no image variant (a variant that is allowed but not yet
made answers 404 with the header `X-Preflow-Variant: pending`, which the audit counts as fine; anything
else missing is a plain 404 and a finding); no debug toolbar is added; no session file is written; mail
queued for later during the render is held back (mail sent synchronously during a GET render still goes out). Site code that has side effects on a visit (counters, logs) can skip
them:

```php
use Preflow\Core\Http\InternalRender;

if (!InternalRender::is($request)) {
    $this->visits->count($request->getUri()->getPath());
}
```

### Limits worth knowing

- A page whose links were already queued for their own turn isn't rendered again for the link check: its
  own result (`status.error` or `status.redirect`) stands, and a broken link to it shows up there, not as
  `link.broken` on the linking page.
- One page with very many new link targets renders them all in one go, so a single page can take a while.
- The ETag check sees headers the app sets through the response, not cookies set by a native `setcookie()`.
- If the 404 page's home link is an absolute production URL, auditing locally reports `notfound.no-home-link`
  falsely; the link is matched by host.
- In debug mode a failing component's dev panel can still appear inside an otherwise answering page.
- A site installed under a sub-path isn't supported.

### Writing site checks

Add your own checks from a service provider, to the `AuditRegistry`; they run after the built-in ones and
report in the same shape, so they show up in the console and in Folio. There are three kinds:

- `PageCheck`: looks at each audited page on its own (`check(RenderedPage $page)`).
- `RunCheck`: sees every page through `collect()` and reports in `finish()`. A run can stop and continue in
  another process, so what it collected must come back through `state()` (JSON-safe) and `restore()`.
- `SiteCheck`: runs once for the whole site and may render pages through the `PageRenderer`.

A `Finding` has the check id, a severity (`Severity::Critical`, `Warning`, `Notice`), a message that says what
is wrong and what to do, and optionally a page URL, a locale and a `Target` that tells Folio where to fix it:
`new Target('record', 'game', '42')` links to the record's editor, `'asset'` to the library.

```php
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Audit\Target;

final class GamesWithoutPlayerCount implements SiteCheck
{
    public function __construct(private readonly GameRepository $games) {}

    public function id(): string
    {
        return 'game.players-missing';
    }

    public function check(PageRenderer $renderer): array
    {
        $findings = [];
        foreach ($this->games->all() as $game) {
            if ($game->players === null) {
                $findings[] = new Finding(
                    $this->id(),
                    Severity::Warning,
                    "{$game->title} has no player count; add one so it can show in search results.",
                    target: new Target('record', 'game', (string) $game->id),
                );
            }
        }

        return $findings;
    }
}

// in a service provider's boot():
$container->get(AuditRegistry::class)->addSite(new GamesWithoutPlayerCount($container->get(GameRepository::class)));
```

## QR codes

`qr_svg($data, $options)` returns an inline SVG (safe HTML, no escaping). In Twig it is a function, in Blade the
directive `@qr_svg(...)`.

```twig
{{ qr_svg('https://example.org/ticket/42', { size: 160, ecc: 'Q' }) }}
```

```blade
@qr_svg('https://example.org/ticket/42', ['size' => 160])
```

Options: `size` (pixels, default 200, at least 21), `margin` (modules, default 2), `ecc` (`L`, `M`, `Q`, `H`;
default `M`), `color` and `background` (a CSS colour for SVG: hex, `rgb()`/`hsl()` or a name; anything else throws an
`InvalidArgumentException`). Every light module takes the background colour, so a coloured or inverted code stays
readable (check inverted ones with your scanner; not every reader handles them). In PHP, `Preflow\View\Qr\QrCode::svg()` and
`QrCode::png()` (PNG colours must be hex; the size is rounded down to whole pixels per module). QR codes need the
suggested package `chillerlan/php-qrcode` (`^5.0`); without it you get a `RuntimeException`: "QR codes need the
chillerlan/php-qrcode library. Install it with: composer require chillerlan/php-qrcode".

## PDFs from templates

A PDF is a document class plus a template. The template is ordinary Twig or Blade of the site's engine; the
renderer turns the HTML into a PDF with mPDF.

```php
use Preflow\View\Pdf\{PageSettings, PdfDocument, PdfRenderer, PdfResponse};

final class CertificateDocument extends PdfDocument
{
    public string $name = '';          // public properties are the template variables
    public ?Game $game = null;

    public function template(): string { return __DIR__ . '/certificate.twig'; } // name or absolute path

    public function resolveState(): void { $this->game = /* load what the template needs */; }

    public function page(): PageSettings
    {
        return new PageSettings(format: 'A4', orientation: 'L', marginTop: 20, footerTemplate: __DIR__ . '/footer.twig');
    }

    public function fileName(): string { return 'certificate.pdf'; }
}

$doc = new CertificateDocument();
$doc->name = 'Ada Lovelace';

$bytes = $renderer->render($doc);                 // PDF bytes, never written to disk
$html  = $renderer->html($doc);                   // an HTML preview, needs no PDF library
return PdfResponse::download($bytes, $doc->fileName());   // or PdfResponse::inline()
```

`PdfRenderer` is bound in the container of a booted app (when `preflow/view` and a template engine are installed).
`resolveState()` runs before every render and preview. `PageSettings` takes `format`, `orientation` (`P` or `L`),
margins in mm, `headerTemplate`/`footerTemplate` (templates rendered with the same variables and repeated on every
page, also when your CSS has `@page` rules) and a custom `width`/`height` in mm (together, they win over format and
orientation). A template is tied to one engine's syntax, so use the extension of the engine you run.

`html()` is only a preview: a browser lays the page out, mPDF does not, so page numbers are empty, page breaks are a
dashed line and the layout is approximate. Check the real thing with `render()`. `PdfResponse` sends
`application/pdf` with `Cache-Control: private, no-store`.

Configure in `config/view.php`:

```php
return [
    'pdf' => [
        'temp_dir' => 'storage/cache/pdf',   // mPDF's cache and scaled image copies; relative to the project root
        'fonts' => ['Certificate Script' => 'resources/fonts/script.ttf'],   // CSS font-family name => file
        'default_font' => 'dejavusanscondensed',
    ],
];
```

`fonts` registers the regular face only; bold and italic print in it too. A name that matches a font family bundled
with mPDF (for example `DejaVu Sans` or `Times`) is refused with an `InvalidArgumentException`: register the file
under another name. `default_font` may be such a configured name. Bind your own `PdfEngine` to use another renderer.

### Building blocks

Twig functions (Blade: `@pdf_...(...)`, all return safe HTML):

| Function | Does |
|---|---|
| `pdf_page_number()`, `pdf_page_count()` | "Page X of Y" in header, footer or body (empty in the preview) |
| `pdf_page_break()` | starts a new page |
| `pdf_table($rows, $columns, $options)` | a table whose header row repeats on every page; `$columns` maps row key to header text; options `class`, `widths`; cells are escaped |
| `pdf_image($assetIdOrPath, $options)` | an image, scaled down; options `width_mm`, `max_px` (1200), `allow_private`, `alt` |
| `pdf_qr($data, $options)` | an inline SVG QR code; `size_mm` (30) plus the `qr_svg` options |

`pdf_image` takes an absolute file path or a media asset id (needs `preflow/media`) and nothing else: no URLs, no
relative paths. It reads the file from the server, so it must never get user input. A path must be an image file
(one `getimagesize()` reads, or an SVG); anything else throws an `InvalidArgumentException`. A private media asset
needs `allow_private: true`. JPEG, PNG, GIF and WebP images longer than `max_px` are scaled to a JPEG in
`temp_dir/mpdf` for the render and removed afterwards (mPDF sweeps old files there, should a process die first);
formats GD cannot read (for example AVIF) are not scaled, so keep them small. An image that has to be scaled is
refused when it is over 40 megapixels; a smaller one goes in as it is.

### Security

mPDF fetches what the template output points to: `<img src>`, CSS `url()` and similar, local files and URLs
alike. Never print raw user-supplied URLs or paths into a `PdfDocument` template; pass images through `pdf_image`,
which accepts image files only.

### CSS limits

mPDF supports a good part of CSS 2 but no flexbox and no grid. Lay pages out with tables, floats and fixed widths,
and check the PDF itself.

### Suggested packages and errors

Both are optional (`suggest`); without them the matching call throws a `RuntimeException`:

- `mpdf/mpdf` (`^8.2`): "PDFs need the mPDF library. Install it with: composer require mpdf/mpdf". `html()` still works.
- `chillerlan/php-qrcode` (`^5.0`): see above. `pdf_qr` needs it too.
- Scaling images needs PHP's `gd` extension.
