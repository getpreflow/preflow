# smart-stack.works on Preflow + Folio — Port Design

**Date:** 2026-10-21
**Site:** smart-stack.works, the website of Steffen's book *SMART STACK*
**Status:** For Steffen's review (builder check done).
**Follow-up:** the members area (registration, resource library, extensions with
settings, roles on SQLite) gets its own design once this port is live.

## Intent

smart-stack.works runs on Yii2 + Crelish today. It is currently down because of the
recent PHP CVEs, and it carries leftovers from other projects, including an unprotected
REST endpoint for its `user` model. The port moves it to Preflow + Folio, the stack
smeyer.me already runs on, and retires the Yii version quickly.

Visitors see the same site. What changes is how the content is managed: Folio's blocks,
content types, singletons and content i18n instead of Crelish's element records.

The port is also the foundation for what comes next. Steffen is moving the book's
Toolbox (Part III: tool landscapes, KPI tables, 30/60/90-day plans, checklists,
printables) out of the book into an online members area on this site. That content
ages fast in print. The members area is **not** part of this design, but the port must
not make it harder.

## Goals

1. smart-stack.works is served by Preflow + Folio on PHP 8.4 at giantbits.com, and the
   Yii deployment is retired.
2. Every page looks and behaves as it does today, in English and German. English moves
   to the root (`/`, `/privacy`) as Preflow's default language, German stays at `/de/...`,
   and every old `/en/...` URL redirects permanently (301) to its new address.
3. All content is editable in Folio: the home sections visually in place, repeating items
   as content types, site-wide values in the Site singleton, both languages through
   Folio's content i18n.
4. The live content and media are imported from production, not retyped.

## Non-goals

- The members area: registration, book codes, screening, approval, the resource
  library, download counters, ratings, comments, book feedback, testimonials.
- Folio extensions and the settings screen (they come with the members area).
- French and Italian. They are configured today but never linked; they are dropped.
- Analytics of any kind.
- A redesign. Copy and layout stay as they are, except the privacy text (below).

## Anti-goals

- A visible regression in look or behaviour, in either language.
- Any external request from a public page: no Google Fonts, no Font Awesome kit, no
  Umami, no CDN.
- A cookie on public pages. The session exists only under `/folio`.
- Content hard-coded in templates. If a visitor can read it, Steffen can edit it in Folio
  (UI strings like nav labels may stay in translation files, as on smeyer.me).
- Carrying over leftovers: Stripe, courses, pricing, services, showroom, testimonials,
  the contact form, newsletter params, `remotesync.sh`, `cron.sh`, `analytics.sh`,
  Crelish analytics, the `user` REST endpoint, the languagecorner/forum-holz files.

## What visitors see today

The reference is the current Yii site. Production is down, but the same code runs
locally at https://smart-stack.dev.smyr (project `~/Sites/gbits/crelish.smart-stack.works`,
theme `themes/smartstack`). The local database is older than production; production
content is what gets imported.

- **Home** (`/en`, `/de`): one long page of ten sections in this order: Hero, Story,
  Dimensions (the ten forces), Audience, Why now, Beyond (career levels), Practice,
  Foundation, Book (with the whitepaper PDF download), Author. The nav uses anchors
  `#origin #the-forces #for-who #why-now #beyond #practice #book`. The hero has an
  animated network background.
- **Privacy** (`/en/privacy`, `/de/privacy`) and a **404** page (`page-not-found`).
- **Header:** EN/DE toggle (links follow the new URL scheme); the admin gear link when logged in (drop it; Folio has its
  own entry).
- **Footer:** LinkedIn, `mailto:steffen@smart-stack.works`, `#book`, `/{lang}/privacy`.
- **Also:** `sitemap.xml`, `robots.txt`, favicons, `site.webmanifest`, hreflang and
  canonical tags (now EN and DE only).
- **JS** (plain, inline today): nav scroll state, IntersectionObserver reveal, smooth
  anchor scrolling, nav highlighting on scroll, the hero network animation.

## Content model (decided)

The content moves from Crelish elements to Folio as follows.

| Today (Crelish) | In Folio |
|---|---|
| `hero`, `story`, `foundation`, `book`, `author` (one record each) | Blocks on the home page, one per section, with the record's fields as block fields |
| `sectionheader` (keyed per section) | Fields of the block whose header it is (label, title, subtitle) |
| `dimension` (10), `audiencerole` (4), `whynow` (3), `careerlevel` (4), `practice` (6) | Content types (collections), ordered, each listed by its section block |
| `page` (home, privacy, page-not-found) | Folio pages: home as a block page; privacy and 404 as pages whose body is blocks |
| `textimage` (privacy sections) | A text(-image) block used on the privacy page |
| `globals/main` (siteName, contactEmail, authorName, authorRole) | The Site singleton, plus the footer links |
| `asset` (portrait, its crop, the whitepaper PDF) | The Folio asset library |
| `translation` rows (DE) | Folio content i18n: EN is the default language, DE the second |

- **Collections, not nested blocks**, for the forces and the other repeating items: the
  members area will filter resources by force, so `dimension` must be a content type that
  other types can relate to.
- **Icons** (`whynow`, `practice` use Font Awesome names): bundled as inline SVGs from
  Font Awesome Free (CC BY 4.0, attribution kept in the source). The icon field becomes a
  choice of the bundled icons. Today's data uses nine: `arrows-rotate`, `bullseye`,
  `clipboard-list`, `code-branch`, `code-merge`, `layer-group`, `microchip`, `route`,
  `user-plus`.
- **German gaps:** production has German for everything except the audience cards
  (`audiencerole`) and one section header. Those show English in German until filled in;
  Folio's i18n must make the gap visible to Steffen, not hide it.

## Decided otherwise

- **URLs (Steffen, 2026-10-21):** English at the root, German under `/de`, 301 redirects
  from `/en` and `/en/*`. No framework change for a prefixed default language.
- **`/tools` (Steffen, 2026-10-21):** the book draft already prints "smart-stack.works/tools"
  (the interactive Force Map template). `/tools` is where the members area will live. The
  port ships a short "coming soon" page there, in EN and DE and editable in Folio, so the
  printed link never ends in a 404.
- **Storage:** site content in JSON files, like smeyer.me. The members area later adds an
  SQLite store next to it; Preflow's data layer keeps both side by side, and members-area
  types can relate to `dimension` across them.
- **Launch:** the domain moves to the new site once it matches the Yii reference visually
  in both languages and Steffen has approved the privacy text. Everything else follows
  after launch; the site is down anyway.

- **Repo and location:** a new Preflow + Folio project at `~/Sites/gbits/smart-stack.works`,
  set up like smeyer.me (framework from the local path repo in development, `^0.19` from
  Packagist on the server). *My call:* it is pushed to the existing GitHub repo
  `smeyerme/smart-stack.works` on a new `main` branch, leaving the Yii history on `develop`.
- **Deployment:** Deployer to `giantbits.com` (user `containr`, `/usr/local/bin/php84`) like
  smeyer.me, to a new path `public_html/smart-stack.works` with document root
  `current/public`. The Yii release at `public_html/deployer.smart-stack.works` stays
  untouched until the new site is verified; then the domain is pointed at the new
  document root and the old deployment is removed. Repointing the domain may be a step
  only Steffen can do in the hosting panel.
- **Admin login:** Folio at `/folio`, one config login from `.env` (set with
  `php preflow auth:user`), session limited to `/folio`.
- **Fonts:** self-hosted woff2, only the weights the site uses: Barlow Condensed,
  IBM Plex Sans, IBM Plex Mono (all OFL).
- **Whitepaper:** a plain download link to the asset. No tracking for now; a download
  counter can come with the members area.
- **Privacy text:** rewritten in EN and DE to match the new reality: no analytics, no
  external fonts or scripts, no cookies on public pages, the host. The current text is
  already wrong (it says "no analytics" while Umami runs, and names other fonts). The
  builder drafts it; Steffen approves the wording before go-live.
- **Content import:** from the production data fetched on 2026-10-21 to
  `~/Sites/gbits/smart-stack.works-import/` (a MySQL dump `prod-db.sql.gz` and `uploads/`).
  It contains personal data (two old user records, analytics rows) that must not be
  imported or committed; only content tables and the three SMART STACK assets are used.
- **Content sync later:** a `production` remote for `folio:pull`, as on smeyer.me.

## Constraints

- Preflow `^0.19` and Folio, PHP 8.4, Twig, no build step, strict nonce CSP, no inline
  event handlers (delegated listeners, guarded against double registration).
- Zero external requests and no cookies on public pages, verified by tests as on smeyer.me
  (a site audit over every public URL).
- A framework change the site needs goes through the framework repo with tests and a
  release before the site depends on it on the server.
- Accessibility at least as good as today; reduced motion respected by the reveal and
  hero animations.

## Success

- On the new deployment, every page in EN and DE matches the local Yii reference
  visually, and every visitor-visible text is editable in Folio.
- The audit tests pass: no external requests, no cookies, CSP clean, no broken links.
- The domain serves the new site, `/users` and `/crelish` are gone, and the Yii
  deployment is removed.

## Left to the builder

- Block and type keys, field names, and how the section blocks read their collections.
- How the import reads the dump (temporary local MySQL, or parsing) and maps Crelish
  UUIDs and translation rows. It runs once; it need not stay in the repo.
- How the root `/` behaves (match what Crelish does today: redirect to the default
  language or serve it).
- How templates and CSS are ported (from `themes/smartstack`), and how the JS is split
  into components.
- Further redirects for old URLs beyond `/en/*`, if any are worth keeping (old Crelish
  asset paths, the whitepaper's tracked-download URL).
- Which of the two whitepaper PDFs in `uploads/` is current (the book record's link decides),
  and turning hard-coded copy (hero `t()` strings, the English-only meta description) into
  block or Site fields.
