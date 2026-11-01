# SEO basics — design

Date: 2026-11-01. Owner-approved direction in conversation. **(my call)** = decided while writing, open to review.
Second of the SEO/GEO projects (ROADMAP "Later: SEO and GEO"), after GEO (0.49). Redirects on slug change and the
`seo:audit` command follow as separate projects.

## Why

An external SEO/GEO audit of smart-stack.works (score 82 %, grade B) found the gaps every Preflow site shares: no
Open Graph or X cards, no structured data (JSON-LD), no AI crawlers named in robots.txt, no ETag/Last-Modified, no
`lastmod` in the sitemap, no security.txt, no theme-color, no `max-image-preview:large`. Today each site solves the
rest itself, differently: smart-stack and holz-objekte each have their own sitemap controller (holz with `lastmod`
for gallery objects only, smart-stack without), holz has a dynamic robots.txt, smart-stack a static one, smeyer.me and
BG Genius have neither; smart-stack hides its not-found and tools pages twice (sitemap controller and GEO filter).

The owner wants what is best for each site: pages shared or found should show **meaningful** cards and data for
their context, and people should come to the site because the content is there.

## Goals

1. **Share cards (Open Graph + X card), meaningful per context (owner):** a site-wide default, a per-page override,
   and in between the record's own data. Precedence for title, description and image:
   1. the record's explicit SEO override ("SEO & sharing" section, below);
   2. the record's own fields, mapped per content type in config (e.g. a job's title and teaser, a holz object's
      first photo, a game's cover);
   3. the site default (the "SEO & sharing" section of the `site` singleton, then config).

   Owner's example: on holz karriere (a future case, not in scope) a job detail page uses the job's data, the job
   listing page its own page data, the site default only fills gaps.
   Tags: `og:title`, `og:description`, `og:image` (absolute, with width/height/alt), `og:url` (canonical),
   `og:type` (`website`, `article` where a type says so), `og:site_name`, `og:locale` (+ `og:locale:alternate`),
   `twitter:card` (`summary_large_image` when there is an image, else `summary`). No `twitter:title`/`description`
   duplicates (X falls back to og:) **(my call)**.
2. **Structured data (JSON-LD):**
   - Built in: `WebSite` (name, url, `inLanguage`) on the home page and the site's owner as `Person` or
     `Organization` (name, url, logo/image, `sameAs` profile links), from config and the site singleton.
     `WebPage` (name, description, url, `inLanguage`, `isPartOf` the WebSite) on every indexable page **(my call)**.
   - Per content type through a hook: a site registers a schema builder for a type
     (`fn(record, locale, context): array`), e.g. holz objects → `VisualArtwork`/`Product`, BG games → `Game`/`Course`,
     smart-stack → `Book` + `Person`, a future job type → `JobPosting`. The framework renders, links (`@id`s) and
     safely escapes the result. Site-specific schemas stay in the sites (framework rule).
   - Small helpers for the common shapes (`WebPage`, `BreadcrumbList`, `Article`, `ImageObject`) so sites don't write
     raw arrays for those **(my call)**.
3. **One list of a site's public pages (owner: "by default in both, with a way to exclude from one or the other"):**
   the GEO entries registry (`GeoEntries`/`GeoEntry`, `FolioGeoEntries`) is generalised into one public-pages list
   that feeds the sitemap, llms.txt/llms-full.txt and later the audit. Each entry carries title, URL, description,
   section, order, `lastmod`, its language alternates, and per-channel flags (sitemap, llms, llms-full). By default
   an entry is in every channel. A page leaves a channel by its record flags (below) or a site filter in code
   (smart-stack's not-found and tools pages). A noindex page leaves all channels.
   **Which records (my call):** the published records of every Folio type with a public frontend route (no
   `permission`), so holz objects and BG games are in without extra config, plus hook entries. `config/seo.php` may
   narrow it (`types`); GEO's `types` key keeps narrowing the llms channels only. The list works whether GEO is on or
   off.
4. **Per-record "SEO & sharing" section in Folio** (on every type with a frontend route, and on the `site`
   singleton where it is the site default) **(my call: a built-in section like Folio's visibility fieldset, not
   fields each site adds to its model JSON)**:
   - Share title, share description (translatable), share image (media asset; per-asset crop for the share format).
   - "Hide from search engines" (noindex: robots meta `noindex`, left out of sitemap and llms.txt).
   - "Leave out of sitemap", "Leave out of AI index" (llms.txt + llms-full.txt).
   - Collapsible (`<details>`), collapsed when empty. Stored with the record as reserved meta (like `_locales`), so
     model JSON files don't change **(my call; builder picks the exact storage key)**.
   - **Goes through publishing like every other field (my call):** a change on the working copy shows in previews
     and takes effect on the live page, cards, sitemap and llms.txt when the record is published; it is part of the
     publish snapshot and revisions.
   - **Per record, not per locale (my call):** the flags and the share image apply to all languages; only share title
     and description are translatable. A language version that is switched off is already handled by Folio's
     visibility (`_locales`), which the list and the alternates respect.
   - The share title/description override only the share cards and JSON-LD; the page's `<title>` and
     `<meta name="description">` stay the site layout's job (they already work and pass the audit) **(my call)**.
5. **Robots meta:** indexable public pages get `max-image-preview:large`; noindex pages (record flag) get
   `noindex`. A page template that already prints a robots meta wins (no duplicate tags).
6. **robots.txt, generated:** the configured disallowed paths (default: Folio admin path and the component endpoints
   `/--component/`, `/*/--component/`) for `*`, then explicit groups for AI **search** crawlers (OAI-SearchBot,
   Claude-SearchBot, PerplexityBot, …) and AI **training** crawlers (GPTBot, ClaudeBot, Google-Extended, CCBot,
   Applebot-Extended, …), each repeating the disallowed paths (a crawler's own group replaces `*`). Training crawlers
   are allowed by default (owner: training on the site's content is beneficial); one config switch blocks them.
   Ends with `Sitemap:` and a comment pointing to `/llms.txt` when GEO is on. Extra raw lines from config.
7. **sitemap.xml, generated** from the public-pages list: every entry in the sitemap channel with `<lastmod>` (the
   record's publish time, `_published_at`; an entry's own `lastmod` for hook entries; **left out when unknown**, e.g.
   types without publishing — no new timestamp is added to records **(my call)**), `xhtml:link` alternates per
   language plus `x-default` (the default locale). Replaces the sites' own sitemap controllers on adoption.
8. **Conditional responses for public pages:** `ETag` on 200 HTML responses to anonymous GET/HEAD that are not
   `private`/`no-store` and set no cookie (on the PSR response **or** natively via `header()` — the PHP session and
   the locale cookie set theirs natively; check `headers_list()` as `GeoMiddleware` does); a matching
   `If-None-Match` answers 304. **ETag only, no `Last-Modified` (my call):** a page's publish time doesn't cover
   content pulled from other records (listings, related items), so a date-based 304 could be stale; Google
   recommends ETag anyway. A visitor's first response that sets the locale cookie simply gets no ETag. Such responses get `Cache-Control: no-cache` when they have
   none. **The ETag ignores the per-request CSP nonce** (pages carry a fresh nonce in every inline tag, so a raw body
   hash would never match) **(my call; builder decides how — hash the body with the response's nonce removed, or
   equivalent)**.
9. **security.txt** at `/.well-known/security.txt` (and `/security.txt` redirecting to it) from config: `Contact`
   (required to serve it), `Expires` computed (now + 180 days, so it never goes stale) **(my call)**,
   `Preferred-Languages` from the site's locales, `Canonical`, optional `Policy`.
10. **theme-color** meta from config (light and optional dark value with `media`).
11. **Opt-in per site:** `config/seo.php` (`SeoConfig::fromFile`, same convention as `config/geo.php`); a missing
    file or `'enabled' => false` changes nothing — no tag, header, route or admin section — so upgrading is safe.
    Individual parts can be switched off (e.g. a site keeps its own robots.txt) **(my call)**.

## Non-goals

- Redirects on slug change and the `seo:audit` command (next projects).
- `<title>` and meta description generation (sites' layouts already do it).
- Generated share images (text rendered onto an image); sitemap index files for more than 50 000 URLs.
- DNS/mail items from the audit (DMARC, MTA-STS) — owner handles DNS.
- Content advice from the audit (question headings, "reads like AI") — editorial work.
- Domain schemas in the framework (JobPosting, Product, Game…): sites provide them through the hook.

## Anti-goals

- Duplicate or conflicting tags (two descriptions, two robots metas, two canonicals) — the framework adds only what
  the page doesn't already have.
- Members-only, gated, preview or noindex pages in the sitemap, llms.txt, cards or JSON-LD.
- A 304 or shared cache entry for a personalised response (a signed-in member must never get another user's or a
  stale personalised page).
- Any change to a site that hasn't turned SEO on.
- Invalid JSON-LD or tags broken by user content (escape `</script>`, quotes, HTML in rich text → plain text).

## Design notes

### Where things live (framework rule: mechanism in packages, Folio as UI adapter, wording in sites)

- `preflow/view` (next to `Geo/`): `SeoConfig`, the public-pages list (generalised from `GeoEntries`; GEO reads it),
  the head renderer for cards/robots/theme-color/JSON-LD, the schema registry and helpers, the robots.txt,
  sitemap.xml and security.txt responders, the conditional-response handling. Builder decides whether these are
  routes or a middleware like `GeoMiddleware` **(builder's call)**.
- `preflow/folio`: the "SEO & sharing" section in the record editor, the Folio source of public pages (today's
  `FolioGeoEntries`, extended with `lastmod`, alternates and the record flags), and the per-record head data for
  Folio frontend pages (FrontendController already knows the record, locale and alternates).
- `preflow/media`: a share-image variant (1200×630, cover, honouring the editor's per-preset crop) in a format the
  platforms accept — **JPEG** (box variants are WebP/AVIF only today; Facebook/LinkedIn/X need JPEG/PNG) — with a
  stable absolute URL.
- Head tags go in through `AssetCollector::addHeadTag` (already injected before `</head>` on Folio, members and
  core pages). Non-Folio pages (controllers, file-routed pages) set their values through a small service / Twig
  function (e.g. `{% do seo({title: …, image: …, schema: …}) %}`) **(builder names it)**.

### Per-type field mapping (config)

```php
'types' => [
    'page'   => ['title' => ['meta_title', 'title'], 'description' => ['meta_description'], 'image' => []],
    'object' => ['title' => ['title'], 'description' => ['teaser', 'description'], 'image' => ['photos'], 'og_type' => 'article'],
],
```

First non-empty field wins; for an image field holding several assets the first asset is used. Rich text becomes
plain text, trimmed to a sensible length (~200 chars for descriptions) **(my call)**. Types not listed use the
built-in defaults (`meta_title`/`title`/`name`, `meta_description`, no image).

### Compatibility with GEO

`GeoEntries`/`GeoEntry` keep working for 0.50 (deprecated aliases or adapters onto the public-pages list) so
smart-stack's GEO filter doesn't break; smart-stack moves its filter to the new list on adoption **(my call)**.
GEO's `noindex_field` is replaced by the record flag; the config key stays honoured.

## Sites (adopt after the framework release; owner approves each deploy)

- **smart-stack.works:** turn on; share image on the site singleton; `Person` (author, LinkedIn) + `Book` schema;
  drop its sitemap controller and static robots.txt; move the not-found/tools filter to the public-pages list; AI
  training allowed.
- **holz-objekte.org:** turn on; objects mapped (title, text, first photo) with a `VisualArtwork`/`Product` schema;
  objects into the public-pages list (replacing `SitemapEntries` and its controller, keeping `lastmod`); its
  RobotsController's disallow list moves to config; theme-color from config.
- **BG Genius:** turn on; games mapped (name, description, cover) with a `Game`/`Course` schema; has no `site`
  singleton, so the site defaults come from config.
- **smeyer.me:** turn on with defaults (standing rule: sites stay current).

## Constraints

- Strict CSP: JSON-LD is `<script type="application/ld+json">` — data, not executed; it carries the page nonce if
  the CSP requires it for all scripts (builder checks the policy).
- No change to HTML output when SEO is off; when on, pages gain only tags they don't already have.
- Full framework suite green; tests for each goal, incl. precedence, flags per channel, members/preview pages
  excluded, ETag stable across requests with different nonces, 304 never for personalised responses, escaping.
- Release as a framework minor (0.50.0 **(my call)**), then the four sites adopt and deploy (standing rule).

## Left to the builder

- Exact class names and storage key of the SEO section, routes vs middleware for the generated files, how the ETag
  excludes the nonce, the share-image URL scheme, the list of crawler user agents (current at build time).
