# GEO (generative engine optimisation) — design

Date: 2026-11-01. Owner-approved direction in conversation. **(my call)** = decided while writing, open to review.
First of the SEO/GEO projects (ROADMAP "Later: SEO and GEO"); SEO basics, redirects and the audit command follow as
separate projects.

## Why

AI assistants (ChatGPT, Perplexity, Claude …) increasingly answer questions about a topic instead of sending people
to the site. The owner wants each Preflow site to be the source those assistants read:

- **smart-stack.works** (the main case): the site is the original source of a unique concept (own IP). An assistant
  asked about Smart Stack or its topics should be able to give the full picture, from the site, attributed to it.
- **BG Genius**: what the site is and which games it teaches.
- **holz-objekte.org**: what the site is and the details of the objects it covers.

Success: an assistant that reads a site gets clean, complete, text-first content, not navigation, scripts and
markup noise, and can read a whole concept in one go.

## Goals

1. **Markdown view of every public page:** the same URL with `.md` appended (`/about.md`, `/de/about.md`; the home
   page as `/index.md`, `/de/index.md` **(my call)**), and the same Markdown for a request to the page URL with
   `Accept: text/markdown` (preferred over `text/html`).
2. **Per-component control:** a component template may contain `{% apply geo %}…{% endapply %}` holding the
   Markdown that represents it. The page's Markdown is the sequence of its components' contributions in render order.
3. **Automatic by default (owner-approved):** a component without a geo block contributes its rendered HTML
   converted to Markdown. An empty geo block (`{% apply geo %}{% endapply %}`) leaves the component out. So every
   page has a usable Markdown view with no work, and authors add geo blocks only where it matters.
4. **`/llms.txt`** following the llms.txt convention: `# Site name`, a `> summary` paragraph, optional notes, then
   `## sections` of `- [Title](url.md): description` links.
5. **`/llms-full.txt`** (owner: "the option to have a full should be there"): the site's content in one Markdown
   file — the Markdown views of the listed pages concatenated with a heading and source URL per page.
6. **Records with detail views** (holz objects, BG games): their detail templates use geo blocks with record data
   (e.g. an object's facts as a list), and sites add their detail pages to `llms.txt` through a hook.
7. **Opt-in per site (my call):** config `geo` (e.g. `config/geo.php` or a `geo` key the app reads — the builder
   picks whichever matches how other packages read config); nothing changes on upgrade until a site turns it on.

## Non-goals

- No SEO features in this project (sitemap, robots.txt, meta/OG, JSON-LD, redirects, audit) — separate projects.
- No Folio admin UI in this first step: summaries and descriptions come from existing fields (the `site` singleton's
  description, records' `meta_description`) or config. A Folio SEO/GEO panel comes with the SEO project.
- No AI-generated summaries.
- No per-crawler access control (that is robots.txt, SEO project).

## Anti-goals

- Pages whose HTML is fine but whose Markdown is garbage (scripts, inline SVG, cookie banners, menu text, "Read more"
  link soup). The converter must produce clean Markdown from typical component HTML.
- Leaking non-public content: drafts/working copies, `noindex` pages, member-only pages, preview links, form CSRF
  tokens or hidden inputs must never appear in a Markdown view or in `llms.txt`.
- Making every component author write Markdown twice.

## Design

### Collection

- A Twig filter `geo` (registered next to `css`/`js` in `PreflowExtension`) passes its content to a request-scoped
  collector (a new `GeoCollector`, or part of the asset collector — builder's choice) and outputs nothing in HTML.
- `ComponentRenderer` already renders each component; for each rendered component the collector keeps, in render
  order, either its geo block (if the template called the filter during that render) or its rendered inner HTML for
  conversion. Nested components: a parent with a geo block owns its whole subtree (children's contributions inside
  it are dropped); a parent without one contributes its own HTML minus the children's spans, and the children
  contribute themselves in place **(my call — the builder may simplify to "convert the page's main content HTML,
  substituting each component that has a geo block" if that gives the same result more simply)**.
- Content outside components (a page template's own HTML, e.g. `app/pages/about.twig`) is converted too, in place.
- Layout chrome is excluded by default **(my call)**: the converter takes the page's `<main>` element when there is
  one (else `<body>`), so navigation, header and footer outside `<main>` drop out; `<script>`, `<style>`,
  `<template>`, `<svg>`, `<form>` controls, `[hidden]`, `aria-hidden="true"` and elements marked `data-geo="skip"`
  are removed before conversion. Sites can also leave a component out with an empty geo block.
- Geo blocks may use the page's normal Twig data (record fields, translations), so a record detail template can write
  e.g. `## {{ object.title }}` and a facts list. The Markdown is not escaped as HTML, but values inserted into it
  are plain text (the builder defines how `{{ }}` output inside a geo block is escaped for Markdown).

### HTML → Markdown

- Headings, paragraphs, emphasis, links (absolute URLs), lists, tables, blockquotes, code, images as
  `![alt](absolute-url)` (only when they have alt text **(my call)**), definition lists as bold term + text.
- A maintained PHP library may be used (e.g. `league/html-to-markdown`) if it fits the framework's dependency rules;
  otherwise a small converter. Builder's call, stated in the plan.

### Whose view: always the anonymous public view (my call)

The Markdown (page `.md`, `llms.txt`, `llms-full.txt`) is always rendered as an anonymous visitor would see it: an
internal sub-request without the requester's cookies or session (the builder takes care of request-scoped services
such as the asset collector, content locale and translator, e.g. by rendering in an isolated scope as `fork()` does
for assets). A signed-in member asking for `/about.md` gets the same Markdown as everyone; nothing personal can end
up in a Markdown view or in a cache. Pages that redirect or refuse an anonymous visitor have no Markdown view.

### Serving

- A middleware (works for Folio frontend routes, file-routed `app/pages` and controller routes alike) recognises
  `<path>.md` and `Accept: text/markdown` on GET/HEAD, renders the underlying page internally, and returns
  `text/markdown; charset=utf-8` with the page's Markdown, prefixed by a title line (`# Page title`) and the
  canonical URL **(my call)**.
- Only pages that are public and indexable get one: a page whose response is not 200, carries `X-Robots-Tag:
  noindex` or a `<meta name="robots" content="noindex">`, requires authentication, or is a Folio preview → 404 for
  the `.md` URL **(my call)**.
- `Link: <…md>; rel="alternate"; type="text/markdown"` header (and a `<link rel="alternate">` via `head()`) on HTML
  pages when GEO is on **(my call)**.
- Caching: responses may be cached like the HTML (same cache rules); no new cache layer required.

### llms.txt / llms-full.txt

- Built per request (or cached and cleared on publish — builder's choice, using Folio's publish hooks if cached).
- Entries come from (my call):
  - **Automatic:** published records of the Folio types listed in the GEO config (default: `page` only), per locale,
    via the resolver's URLs; title from the record's title field, description from `meta_description` when the type
    has one. Other routed types (BG games, holz objects) are added by the site through the hook, so nothing appears
    twice.
  - **Hook:** a registry where a site or package **adds** entries (title, URL, description, section, order, include
    in full: yes/no) **and filters** the final list (a callback that may drop or change entries), the same idea as
    the sitemaps the sites build today. smart-stack.works drops its not-found and tools pages this way.
  - **Never listed:** records whose page is `noindex` by a record flag the config names (if the type has one), and
    any entry whose Markdown view answers 404. To keep llms.txt cheap the builder may check this lazily in
    `llms-full.txt` only and rely on the hook/flag for `llms.txt`.
- Order and sections (my call): every entry has a `section` (default "Pages", the home page first) and an `order`
  number; entries sort by section (in the order sections first appear, home section first) then by `order`, then
  title. Sites set order and sections in the hook, so smart-stack.works can put its concept pages in reading order;
  `llms-full.txt` follows the same order, which is what lets an assistant read a concept in one go.
- Summary and site name: from config, falling back to the `site` singleton's name/description fields when the site
  has them **(my call — the exact field names come from config, not hard-coded)**.
- Languages: `/llms.txt` lists the default locale's pages and has a `## Other languages` section linking
  `/de/llms.txt` etc.; each locale has its own `llms.txt` and `llms-full.txt` **(my call)**.
- `llms-full.txt`: the Markdown views of all entries marked for full, in llms.txt order; a site may limit it (e.g.
  holz: pages only, not hundreds of objects) through the entry flag or config.

### Sites (adopt after the framework release)

- **smart-stack.works:** turn GEO on; add geo blocks where the automatic Markdown is not the full picture (e.g. the
  Forces/Hero components that pull related records); summary from the `site` singleton.
- **holz-objekte.org:** turn on; geo block on the object detail template with the object's facts; register gallery
  objects as llms.txt entries (not in full).
- **BG Genius:** turn on; geo block on the game page (`templates/folio/game.twig`, the player is interactive) with
  the game's description, players, time, complexity and what the tutorial covers (layers); register games as
  entries.
- smeyer.me: turn on with defaults (standing rule: sites stay current).

## Constraints

- Framework rule: generic mechanism in the packages (view/twig/components/core; Folio only as the source of published
  pages and URLs); site wording and data stay in the sites.
- Strict CSP unaffected (Markdown responses are not HTML).
- Must not change any HTML output when GEO is off; when on, HTML pages only gain the alternate link.
- Full framework suite green; new behaviour tested (filter collection order, nested components, empty block,
  conversion cleanliness, noindex/preview/auth → no Markdown, llms.txt per locale, hook entries, full file).
- Release as a framework minor (0.49.0 **(my call)**), then the four sites adopt and deploy (standing rule; owner
  approves pushes/deploys).

## Left to the builder

- Collector placement, the converter choice, the middleware's internal render mechanism, caching, exact config keys.
