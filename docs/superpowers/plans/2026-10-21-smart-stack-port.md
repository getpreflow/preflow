# smart-stack.works Port Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** smart-stack.works runs on Preflow + Folio with the same look in EN and DE, all content editable in Folio, imported from production, and the Yii deployment retired.

**Architecture:**
- **New project** at `~/Sites/gbits/smart-stack.works`, built on smeyer.me's patterns:
  - Folio types in `config/models/*.json`;
  - section blocks as `BlockComponent`s in `app/Components/*`;
  - the layout in `resources/folio/frontend/_site.twig`;
  - JSON storage;
  - tests booting the real app through a `SiteScaffold`.
- **Content** comes from a one-off importer that parses the production MySQL dump. Its tests and the rendering tests share one committed, content-only fixture.
- **Templates, CSS and JS** are ported from the Yii theme.

**Tech Stack:** PHP 8.4, Preflow `^0.19` (local path repo in development), Folio, Twig, PHPUnit 11, Deployer 7.

**Spec:** `docs/superpowers/specs/2026-10-21-smart-stack-port-design.md`. The spec and these sources are read-only:
- **Visual reference:** https://smart-stack.dev.smyr (the old site running locally), from the project `~/Sites/gbits/crelish.smart-stack.works`:
  - templates and CSS: `themes/smartstack/{layouts,frontend}`;
  - section widgets: `workspace/widgets/*Section`, `DimensionsList`;
  - content fields: `workspace/elements/*.json`.
- **Production data:** `~/Sites/gbits/smart-stack.works-import/` (`prod-db.sql.gz` and `uploads/`).
- **Patterns to copy from smeyer.me:**
  - `config/*`, `deploy.php`, `deploy/htaccess`;
  - `tests/Support/{SiteScaffold,Dom,TextExtractor}.php`;
  - `tests/Integration/SiteAuditTest.php`;
  - `resources/folio/frontend/_site.twig`;
  - any `BlockComponent`.

## Global Constraints

- **URLs:**
  - English at the root (`/`, `/privacy`), German under `/de` (`config/i18n.php`: `default en`, `available [en, de]`, `url_strategy prefix`, `cookie false`).
  - `/en` and `/en/*` answer **301** to the same path without `/en`.
  - French and Italian are dropped.
- **No external requests from any public page:**
  - no Google Fonts, Font Awesome kit, Umami or CDN;
  - fonts are self-hosted woff2 (Barlow Condensed, IBM Plex Sans, IBM Plex Mono, only the weights the reference uses);
  - icons are inline SVG from Font Awesome Free (CC BY 4.0, attribution kept in the source).
- **No cookie on public pages.** The session lives only under `/folio` (`session.paths: ['/folio']`).
- **Scripts:** strict nonce CSP; no inline event handlers; delegated listeners guarded against double registration; animations respect `prefers-reduced-motion`.
- **No hard-coded copy:** every visitor-visible text comes from Folio content (blocks, types, the Site singleton). Only nav and UI labels may live in `lang/{en,de}/*.php`.
- **Data hygiene:** never import or commit the dump's `user`, `analytics_*`, `migration` or leftover rows (course, services, pricing, showroom, testimonials), and nothing from the old `.env`/config.
- **Left out:** Stripe, courses, pricing, services, showroom, testimonials, the contact form, newsletter params, `remotesync.sh`, `cron.sh`, `analytics.sh`, Crelish analytics, the `/users` REST endpoint, the admin gear link.
- **Framework changes:** any change the site needs goes into the framework repo with a test, and is released before the site depends on it on the server.

## Review Focus

1. **German fallback:** a section or record that has no German value (the four audience cards, one section header) must render the English text on `/de`, never an empty heading. The Folio list must flag the audience type as missing German.
2. **Old links:** `/en`, `/en/`, `/en/privacy` and `/en/privacy?x=1` 301 to `/`, `/`, `/privacy` and `/privacy?x=1`. `/de/privacy` and `/tools` answer 200, and unknown paths render the Folio 404 page in the right language, with a 404 status.
3. **Book link:** `/tools` and `/de/tools` show the "coming soon" page, not a 404. The printed book links there.
4. **Repeated rendering:** the hero animation and the nav scripts must not register twice when Folio's admin preview re-renders a block. Reduced motion stops the network animation and the reveal transitions.
5. **Import reruns:** running the importer twice gives the same records (stable ids), not duplicates. A dump row whose translation points at a missing record is skipped with a warning, never a crash.

---

### Task 1: Project skeleton that boots, is served locally, and leaks nothing

**Files:**
- Create in `~/Sites/gbits/smart-stack.works/`:
  - `composer.json`
  - `public/index.php`
  - `preflow`
  - `.env.example`
  - `.gitignore` (copy smeyer.me's: `.env`, `vendor`, `storage/*` except `.gitkeep`, `public/media`)
- Create `config/`:
  - `app.php` (name `smart-stack.works`)
  - `i18n.php`
  - `data.php` (JSON)
  - `folio.php` (remote `production` → `public_html/smart-stack.works/current`)
  - `auth.php` (smeyer.me's, cookie `ss_admin`)
  - `security.php`
  - `htmx.php` (`never`)
  - `providers.php`
  - `commands.php`
- Create:
  - `resources/folio/frontend/_site.twig`: the shell with the head and `@font-face`, empty header and footer slots;
  - `public/fonts/*.woff2`;
  - `README.md`;
  - `deploy.php` and `deploy/htaccess`: smeyer.me's, with `application smart-stack.works` and `branch main`.
- Test: `tests/Support/{SiteScaffold,Dom}.php`, `tests/Integration/BootTest.php`.

**Interfaces:**
- Produces:
  - `SiteScaffold` (`bootSite()`, `removeSite()`, `app()`, `get(string $uri): ResponseInterface`, `html(string $uri): string`, `seed()`; `seed()` is a no-op until Task 3);
  - the local vhost `https://smartstack.dev.smyr` → `public/`. The name `smart-stack.dev.smyr` stays with the Yii reference.

- [ ] **Step 1:** `git init` (branch `main`); `composer.json` with the smeyer.me path repo and `preflow/{core,routing,twig,data,folio,i18n,auth,htmx,view,components,validation} ^0.19 || @dev`, dev: `preflow/{devtools,testing}`, `phpunit/phpunit ^11`, `deployer/deployer ^7`; `composer install`.
- [ ] **Step 2:** Write `BootTest`:
  ```php
  test_home_answers_in_both_languages(): get('/') and get('/de') are 200 text/html, <html lang="en"> and <html lang="de">
  test_public_pages_set_no_cookie(): get('/'), get('/de') have no Set-Cookie
  test_csp_header_has_a_nonce(): Content-Security-Policy matches /script-src[^;]*'nonce-/ and has no 'unsafe-inline' in script-src
  test_fonts_are_self_hosted(): the html has no fonts.googleapis.com/kit.fontawesome.com/umami; every @font-face url() starts with /fonts/ and the file exists in public/
  ```
- [ ] **Step 3:** Run `vendor/bin/phpunit tests/Integration/BootTest.php`. Expected: FAIL. With no `page` type and no home record, `/` is 404.
- [ ] **Step 4:** Add a minimal `config/models/page.json`: `frontend.route /{slug}`, `home: home`; fields `title`, `slug` (both translatable) and `sections` (blocks, `allowed: []` for now). Then:
  - in the scaffold's `seed()`, add one published `home` page with `_locales [en, de]`;
  - write `_site.twig` with `{{ folio_alternate_links(alternates) }}` and `<html lang="{{ locale }}">`;
  - copy the woff2 files; take the weights from the reference's Google Fonts URL in `themes/smartstack/layouts/main.twig`.
- [ ] **Step 5:** Run the test. Expected: PASS.
- [ ] **Step 6:** `cp .env.example .env`, then:
  - run `php preflow key:generate`;
  - set `APP_DEBUG=1` and `APP_SECURE=1`;
  - set the local test login with `php preflow auth:user` (`admin@site.test`, test password).
  - Run `~/Sites/vhosts/setup-vhost.sh` with name `smartstack` and document root `~/Sites/gbits/smart-stack.works/public`.
  - Check: `curl -s -o /dev/null -w '%{http_code}' https://smartstack.dev.smyr/` gives `200`.
- [ ] **Step 7:** Commit `chore: Preflow + Folio skeleton for smart-stack.works`.

### Task 2: Content model: types, the Site singleton, icons

**Files:**
- Create in `config/models/`:
  - `force.json`
  - `audience_role.json`
  - `why_now.json`
  - `career_level.json`
  - `practice.json`
  - `site.json` (singleton)
- Modify: `page.json` (fields `meta_title`, `meta_description`).
- Create: `app/Icons/Icons.php`, `app/Icons/svg/*.svg`.
- Test: `tests/Integration/ContentModelTest.php`, `tests/Unit/IconsTest.php`.

**Interfaces:**
- Produces:
  - type keys `force`, `audience_role`, `why_now`, `career_level`, `practice`, `site`, `page`;
  - each collection is `orderable`, has `publishing: false`, and gets `frontend.published` `status`/`published` like smeyer.me's collections;
  - `Icons::names(): list<string>` (the nine names below);
  - `Icons::svg(string $name): string`: inline `<svg aria-hidden="true" focusable="false">`, or `''` for an unknown name;
  - the Twig function `icon(name)`, registered in `app/Providers/AppServiceProvider.php`.
- **Fields:** they mirror the Crelish element JSON (`workspace/elements/{dimension,audiencerole,whynow,careerlevel,practice}.json`) one to one. Text fields are `translatable: true`, except numbers, letters and icon names. The first text field is `required`.
- **The `icon` field:** `type: select`, options from `Icons::names()`: `arrows-rotate`, `bullseye`, `clipboard-list`, `code-branch`, `code-merge`, `layer-group`, `microchip`, `route`, `user-plus`.
- **`site` fields:**
  - `name`, `contact_email`, `author_name`, `author_role`, `linkedin_url`, `meta_title`, `meta_description`, `footer_copyright` (all translatable except URL and email);
  - `privacy_page`, `not_found_page`, `tools_page` (relations to `page`);
  - `nav` (blocks of `link`: label, anchor or URL).

- [ ] **Step 1:** Tests:
  ```php
  test_collections_are_orderable_and_translatable(): each of the five types has list.orderable true and its text fields translatable
  test_missing_german_is_flagged(): an audience_role with only en values shows "de · 1 missing" in GET /folio/audience_role (logged in as the test admin)
  test_icons_cover_the_data(): Icons::names() === the nine names; every svg() starts with "<svg" and has aria-hidden; svg('nope') === ''
  ```
- [ ] **Step 2:** Run them. Expected: FAIL (unknown types, no `Icons` class).
- [ ] **Step 3:** Implement the type JSON, `Icons` (the paths come from Font Awesome Free 6 solid; a header comment carries the CC BY 4.0 attribution) and the `icon()` Twig function.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit `feat: content types, site singleton and bundled icons`.

### Task 3: Import production content

**Files:**
- Create:
  - `app/Import/CrelishDump.php`
  - `app/Import/CrelishImporter.php`
  - `app/Console/ImportCommand.php` (`site:import`)
  - `tests/Fixtures/crelish-content.sql`
- Modify:
  - `config/commands.php`;
  - `tests/Support/SiteScaffold.php`: `seed()` imports the fixture.
- Test: `tests/Unit/CrelishDumpTest.php`, `tests/Integration/ImportTest.php`.

**Interfaces:**
- **`CrelishDump::fromFile(string $path): self`** reads `.sql` or `.sql.gz`. Then:
  - `rows(string $table): list<array<string, ?string>>`: the column names come from `CREATE TABLE`; the values are parsed from `INSERT INTO ... VALUES (...),(...);`, with SQL escapes and `NULL`;
  - `tables(): list<string>`.
- **`CrelishImporter::import(CrelishDump $dump, string $uploadsDir): ImportReport`:**
  - writes Folio records through `DataManager` and the asset library;
  - the record id is the Crelish `uuid`, so a rerun replaces instead of duplicating;
  - `ImportReport` has `counts(): array<string, int>` and `warnings(): list<string>`.
- **Mapping:**

  | Crelish source | Folio target |
  |---|---|
  | `dimension` | `force` |
  | `audiencerole` | `audience_role` |
  | `whynow` | `why_now` |
  | `careerlevel` | `career_level` |
  | `practice` | `practice` |
  | `hero`, `story`, `foundation`, `book`, `author`, `sectionheader` | Blocks in the home page's `sections`, in the order of Task 4 |
  | `textimage` | `text_image` blocks on the privacy page |
  | `page` | Pages `home`, `privacy` and `not-found` (from `page-not-found`), plus a new page `tools` (Task 5) |
  | `globals/main` | `site` |
  | `asset` | The asset library: only files the content references (portrait, crop, current whitepaper) |
  | `translation` rows (`language = de`) | The `de` value of the matching field |

  - Section headers become fields of their block.
  - Crelish `state = 2` maps to published.
  - Any other language and any table not listed is ignored.
- **`site:import <dump> <uploads>`** prints the counts and warnings. `--dry-run` writes nothing.

- [ ] **Step 1:** Build the fixture with a throwaway script:
  - keep only `CREATE TABLE` plus `INSERT` for `page, hero, story, foundation, book, author, sectionheader, dimension, audiencerole, whynow, careerlevel, practice, textimage, asset, translation` from `prod-db.sql.gz`;
  - in `translation`, keep only rows whose `source_model_uuid` exists in those tables and whose language is `de`;
  - check: `grep -ciE "user|analytics|stripe|@" tests/Fixtures/crelish-content.sql` must match only the public contact address and column names. Review it before committing.
- [ ] **Step 2:** Tests:
  ```php
  CrelishDumpTest: test_parses_escaped_values_nulls_and_multi_row_inserts (inline SQL with 'it\'s', "a\\nb", NULL, two rows); test_reads_gzip
  ImportTest:
    test_counts_match_production(): counts() === ['force' => 10, 'audience_role' => 4, 'why_now' => 3, 'career_level' => 4, 'practice' => 6, 'page' => 4, 'site' => 1] + assets 3
    test_german_values_land_in_de(): one force has a de body different from en
    test_home_blocks_in_order(): home sections keys === ['hero','story','forces','audience','why_now','beyond','practice','foundation','book','author']
    test_rerun_is_idempotent(): import twice → same counts, same ids
    test_orphan_translation_is_a_warning(): fixture + a translation row for an unknown uuid → warnings() names it, no exception
    test_no_personal_data(): no imported record contains a value matching an email pattern other than the site contact address
  ```
- [ ] **Step 3:** Run. Expected: FAIL (the classes are missing).
- [ ] **Step 4:** Implement. The block keys used here are the ones Task 4 defines. Until Task 4 registers them, the test asserts the stored keys only.
- [ ] **Step 5:** Run. Expected: PASS. Then run the real import into local storage:
  - `php preflow site:import ~/Sites/gbits/smart-stack.works-import/prod-db.sql.gz ~/Sites/gbits/smart-stack.works-import/uploads`;
  - check that the counts match and the warnings are understood.
- [ ] **Step 6:** Commit `feat: import production content from the Crelish dump`. Commit the fixture; never commit `storage/data` or `public/media`.

### Task 4: The home page: ten section blocks

**Files:**
- Create in `app/Components/`, each with `.php` and `.twig`:
  - `Hero`
  - `Story`
  - `Forces`
  - `Audience`
  - `WhyNow`
  - `Beyond`
  - `Practice`
  - `Foundation`
  - `Book`
  - `Author`
- Modify: `config/models/page.json` (`sections.allowed`).
- Test: `tests/Integration/HomeTest.php`, `tests/Support/TextExtractor.php` (copied from smeyer.me).

**Interfaces:**
- Consumes:
  - Task 2's types and `icon()`;
  - Task 3's block keys: `hero`, `story`, `forces`, `audience`, `why_now`, `beyond`, `practice`, `foundation`, `book`, `author`.
- Produces: each block renders `<section id="…">` with the reference anchors:

  | Block | Section id |
  |---|---|
  | `story` | `origin` |
  | `forces` | `the-forces` |
  | `audience` | `for-who` |
  | `why_now` | `why-now` |
  | `beyond` | `beyond` |
  | `practice` | `practice` |
  | `book` | `book` |

  Hero and author follow the reference.
- **Ported from the reference widgets:** markup, scoped CSS (`{% apply css %}`), and the copy fields marked `folio_editable()`. The collection blocks read `folio_list('force')` and the other collections.
- **Book block:** the whitepaper is an `asset` field rendered as a plain download link.
- **Hero network animation:** component JS guarded by `window.__ssHeroNetwork`; it stops under reduced motion.

- [ ] **Step 1:** Tests:
  ```php
  test_sections_render_in_order_with_anchors(): ids in document order === the reference sequence
  test_every_visible_text_comes_from_content(): TextExtractor over '/' minus all imported field values (en) leaves only lang/ UI strings
  test_german_home_uses_german_and_falls_back(): '/de' contains a known de force body; the audience cards show their en titles (no empty headings: no h3/h4 with empty text)
  test_whitepaper_is_a_plain_local_link(): the book CTA href starts with /media/ and ends with .pdf, no tracking params
  test_icons_inline(): why_now and practice items each contain an <svg aria-hidden="true">
  ```
- [ ] **Step 2:** Run. Expected: FAIL (the blocks don't exist).
- [ ] **Step 3:** Port each widget. Order: hero, story, forces, audience, why_now, beyond, practice, foundation, book, author.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Visual check in Chrome:
  - https://smartstack.dev.smyr/ against https://smart-stack.dev.smyr/en, at 1280px and 390px wide;
  - then `/de` against the reference's `/de`.
  - Text differences that come from production content being newer than the local reference are expected. Layout, type and colour differences are not, so fix them.
- [ ] **Step 6:** Commit `feat: home page sections as Folio blocks`.

### Task 5: Chrome, other pages, URLs

**Files:**
- Create:
  - `app/Components/{SiteHeader,SiteFooter,TextImage,ComingSoon}`
  - `app/pages/_error.twig`
  - `app/Http/LegacyEnRedirect.php`
  - `app/Controllers/SitemapController.php`
  - `public/{robots.txt,site.webmanifest,favicon*}`: copied from the reference `web/`
  - `lang/{en,de}/site.php`
- Modify: `_site.twig`, `config/middleware.php` (or the front controller; see Step 3), `page.json` (`sections.allowed` adds `text_image` and `coming_soon`).
- Test: `tests/Integration/PagesTest.php`, `tests/Integration/UrlsTest.php`.

**Interfaces:**
- **`LegacyEnRedirect`** (PSR-15) answers 301 for `/en` or `/en/...`, keeping the query string. It must see the path before `LocaleMiddleware` strips the prefix. Hook it in wherever the test proves that holds.
- **`_error.twig`** renders `folio_find('page', site.not_found_page)` in the request locale, with the status of the error.
- **`/sitemap.xml`** lists every published page in each locale it's visible in, as absolute URLs with `xhtml:link` alternates. It leaves out `not-found` and `tools`.
- **Header:**
  - `site.nav` anchors become `{home url}#anchor`;
  - the EN/DE toggle uses the page's `alternates`;
  - nav scroll state and active-section highlight, guarded by `window.__ssNav`.
- **Footer:** LinkedIn, the contact email, `#book`, the privacy page URL.
- **`tools` page:** published in EN and DE, holding one `coming_soon` block: a heading and a text, both translatable. The importer (Task 3) creates it with a short holding text. Steffen edits it later.

- [ ] **Step 1:** Tests:
  ```php
  UrlsTest (data provider): '/en' → 301 '/'; '/en/' → 301 '/'; '/en/privacy' → 301 '/privacy'; '/en/privacy?x=1' → 301 '/privacy?x=1'
  UrlsTest: test_tools_answers_in_both_languages (200, coming_soon heading); test_unknown_path_is_a_localized_404 ('/nope' 404 with the en not-found title, '/de/nope' 404 with the de title)
  UrlsTest: test_sitemap_lists_pages_with_alternates (home, privacy in en + de; no not-found, no tools); test_old_crelish_paths_are_gone ('/users', '/crelish', '/site/login' are 404); test_canonical_and_hreflang ('/privacy' has <link rel=canonical> to its absolute en URL and hreflang en, de, x-default; '/de/privacy' canonical is the de URL)
  PagesTest: test_privacy_renders_its_text_image_blocks (en 8 sections, de shows de text); test_nav_toggle_links_to_the_other_language; test_footer_links (linkedin host, mailto, privacy url per locale)
  ```
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the full suite (`vendor/bin/phpunit`). Expected: PASS.
- [ ] **Step 5:** Visual check of privacy, 404 and tools in both languages, and of the header and footer at both widths, against the reference.
- [ ] **Step 6:** Commit `feat: header, footer, privacy, 404, tools, sitemap and /en redirects`.

### Task 6: Site audit and the privacy text

**Files:**
- Create: `tests/Integration/SiteAuditTest.php` (adapted from smeyer.me).
- Modify: the privacy page content (locally, in Folio).

**Interfaces:**
- **Audit pages:** `/`, `/de`, `/privacy`, `/de/privacy`, `/tools`, `/de/tools`, and a 404.
- **Allowed outbound hosts:** `www.linkedin.com` only.

- [ ] **Step 1:** Write `SiteAuditTest`. Copy from smeyer.me the tests for:
  - all resources being same-origin;
  - no `script[@src]`;
  - every `<script>` and `<style>` carrying the response nonce;
  - no inline `on*` attributes;
  - no `Set-Cookie`;
  - internal links resolving to 200 (or 301 for `/en`).
- [ ] **Step 2:** Run. Expected: PASS. A failure here is a finding against Tasks 1–5: fix it there, with a test.
- [ ] **Step 3:** Draft the new privacy text, EN and DE, as `text_image` blocks:
  - the host (giantbits.com, Switzerland, server logs);
  - no analytics or tracking;
  - no cookies on public pages (the admin login sets one, under `/folio` only);
  - fonts and icons self-hosted, no external requests;
  - email contact and its rights;
  - the responsible party (from the current privacy page).
- [ ] **Step 4:** **Stop: ask Steffen to approve the wording** and paste both texts in chat. Apply his edits.
- [ ] **Step 5:** Run the suite and commit `feat: site audit; privacy text for the new site`. The content goes to the server with the first deploy (Task 7) or through the Folio admin.

### Task 7: Deploy, go live, retire Yii

**Files:**
- Modify:
  - `deploy.php`: shared `.env`, `public/.htaccess`, `storage/data`, `public/media`;
  - `README.md`: setup, import, deploy, the `folio:pull` remote.

- [ ] **Step 1:** Ask Steffen before each outward step. Then push to GitHub `smeyerme/smart-stack.works`, branch `main`; `develop` stays untouched.
- [ ] **Step 2:** On the server:
  - create `public_html/smart-stack.works/shared/.env` with a new `APP_KEY`, `APP_DEBUG=0` and `APP_SECURE=1`;
  - Steffen sets his own login with `! ssh giantbits.com '…php84 preflow auth:user'`, or locally then copies it, so the password is never typed through Claude;
  - run `vendor/bin/dep deploy`.
- [ ] **Step 3:** Copy content and media to `shared/`, either by rsyncing local `storage/data` and `public/media`, or by running `site:import` on the server. Then check the deployment through a temporary host mapping or a staging subdomain:
  - `curl --resolve smart-stack.works:443:<server ip> https://smart-stack.works/` and the other audit pages answer 200 with the expected titles;
  - `/en` answers 301.
- [ ] **Step 4:** **Stop: Steffen points the domain's document root at `public_html/smart-stack.works/current/public`** (hosting panel). Verify live:
  - every audit page, in both languages;
  - `/users` and `/crelish` are 404;
  - no external requests, checked in Chrome DevTools.
- [ ] **Step 5:** **Stop: ask before deleting** the Yii deployment `public_html/deployer.smart-stack.works`. Keep a tarball of its `shared/` in `~/Sites/gbits/smart-stack.works-import/` first.
- [ ] **Step 6:** Update the memory notes (project location, deploy path, remote), then commit `docs: deploy and content workflow`.
