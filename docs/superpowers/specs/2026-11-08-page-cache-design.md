# Performance step 3: a page cache that refreshes on write (Preflow 0.60)

The owner asked for this on 2026-11-07 and 2026-11-08: "as fast as possible", and "Why does the save/publish … not trigger
the cache build already?". This is step 3 of 3:
- step 1 (0.58) removed the per-request waste;
- step 2 (0.59) made boot structural: lazy admin, a boot manifest, a route cache.

What is left on every page is rendering, plus the host's floor. The local timings for languagecorner /home are 3.9 ms,
holz home is about 90 ms TTFB live.

## Intent

Visitors who are not signed in get finished pages from a cache. The cache must never show an old state to someone who
acted on a change, for example:
- the owner following the "your project is approved" mail;
- a reader opening a news item staff just published;
- a visitor looking for a job a feed imported overnight.

The baseline is "nothing to do, it just works". Developers who want to squeeze more can loosen freshness per dependency.

## The model (decided with the owner)

The model sorts everything on a page by one question: **is it the same for every visitor, or different per visitor?**

1. **The same for everyone is cached.** This covers content, lists, like counts and prices.
2. **What is different per visitor is live,** and is filled in on every request. The two kinds:
   - **Per-request values**, replaced automatically with no template change: the CSP nonce and CSRF/form tokens.
   - **Personal parts**, marked by the developer. A `{% live %}…{% endlive %}` block in Twig (and the Blade equivalent),
     or a component marked live. Only these are rendered on each visit, on the server, so there is no flicker and no
     extra browser request. Examples:
     - the guest album on holz-objekte ("3 objects in your album", "in your album" button states);
     - a shop's cart count;
     - "you liked this";
     - a greeting.

   The owner rejected live *props* for shared values such as like counts. Shared values are cached and refreshed on
   change.
3. **Signed-in visitors always get live pages,** for staff, members and editors alike. So the staff member previewing a
   project before approval never sees a cached copy.

## Freshness: every write refreshes what it touched

- **Dependencies are recorded while rendering.** While a page renders, the data layer notes what it read:
  - a record ("object 1234");
  - a type listing ("the published objects", "news");
  - and so on.
- **Writes mark what changed.** Every write through the data layer records one small "refresh needed" entry naming
  what changed. That includes writes from Folio, from a site's controller, from a cron import and from the
  publisher/scheduler.
  - The writer never thinks about the cache.
  - Entries are merged, so an import of 300 jobs is one refresh, not 300.
- **A refresh job works out the scope from the entries:**
  - one record → the pages that showed it, plus the pages listing its type;
  - a bulk change of a type → every page listing that type, in one batch;
  - something every page uses (menu, footer, site settings, layout, templates, a deploy) → the whole site.
- **Two speeds, chosen per dependency by the developer.** The default is fresh.
  - **fresh** (default): affected pages are rebuilt right away, so the first visitor after the change already gets
    the new page.
  - **next visit**: affected pages are only marked out of date. The next visitor gets the existing page instantly
    while a fresh one is built.
  - **timed** (e.g. every 12 h): changes to that dependency are ignored, and the page refreshes on its schedule.

  The owner's example: on forum-holzkarriere's job detail page, the job itself is fresh, and "other jobs of this
  company" may be set to next visit or timed. The detail page stays one cached copy and is instant in every setting.
- **Time.** Pages can carry an expiry, for an event date passing or a sale ending at midnight. Folio's scheduled
  publishing (`_publish_at`, `Publishing\Scheduler`) is a write and refreshes like any other.
- **A safety maximum age of 4 hours** (owner, 2026-11-08) applies to every cached page and fragment by default.
  - It works like "next visit": once a page is older than 4 h, the next visitor still gets it instantly and a fresh
    copy is built behind them, or by the cron.
  - It covers pages that change with time alone ("upcoming", end dates, "new" badges) and any write the cache could
    not see.
  - Developers may set a shorter expiry per page or dependency; the 4 h is configurable per site.
- **The dial is developer power** (owner: "dont think this can be trusted to staff"). It is set in the template or
  component, never in Folio settings.

## Where rebuilding happens

The host (cyon) runs no background workers.
- Rebuilds run right after the response of the request that wrote is sent. Core already finishes the request with
  `fastcgi_finish_request`/`litespeed_finish_request` in `Application.php` around line 500. They run within a time
  budget.
- What is left over is picked up by a scheduled command (cron).
- A rebuild is an internal anonymous render of the URL. The SEO audit already renders internally.

## Folio

- Baseline: nothing to do. The existing Cache screen gets a "page cache" entry (clear), and two manual actions: "refresh
  this page" and "refresh everything".
- The same actions exist as commands for those who squeeze.
- No freshness settings in Folio.
- A live block in a Folio page/block type is declared by the developer in the block's template or component.

## Placement

Writes come from everywhere (Folio, controllers, crons), and personal parts appear in site templates as much as in Folio
pages, so the cache lives in the framework. Following the owner's rule (framework = abstractions, interfaces, hooks;
Folio = UI adapter):
- the cache, its store, the recording of dependencies and the refresh job are framework (core/data/view/components);
- `{% live %}` and its Blade twin are template integrations;
- Folio only adds its UI and marks its own personal parts.

## Goals

1. A cached anonymous GET is served without rendering. Its own PHP time is measured locally like steps 1–2, with a
   target well under 1 ms over boot. Live TTFB is close to the host's floor.
2. Correctness first: after any write through the data layer, no visitor gets a page that showed the old state, except
   where a developer chose next visit or timed for that dependency.
3. Personal parts and per-request values are always current and never leak between visitors.
4. Zero configuration for the default. One mark (`live`) for personal parts, one optional dial per dependency.

## Non-goals

- Browser or CDN caching of HTML. HTML stays uncached in browsers, so the freshness promise holds. The existing ETag
  behaviour stays.
- Caching for signed-in visitors.
- Caching non-GET requests, or responses that set cookies.
- A static-file fast path that bypasses PHP (the CSP nonce rules it out). Possible later.

## Anti-goals

- **Stale after acting:** the approval-mail owner, or a reader of just-published news, seeing the old page.
- **One visitor's personal data in another's page.**
- **A write path that silently doesn't refresh.** Uncovered writes must be visible or impossible, not quietly stale.
- **Configuration needed for the default to be correct.**
- **Over-engineering:** no per-value live props, no staff-facing tuning.

## Constraints

- PHP 8.4+, no new runtime dependency. cyon hosting: LiteSpeed, no workers, cron available. BG Genius shares its whole
  `storage` folder between releases.
- Works with the step 2 boot manifest, the lazy admin and the lazy DB connection.
- Released as 0.60.0 with a ROADMAP entry and before/after timings. Then the 5 sites: holz-objekte.org, BG Genius,
  smart-stack.works, smeyer.me and languagecorner.ch. For each: back up, run the suites serial and parallel, deploy.

## Calls I made (to be checked by the owner)

1. **Raw SQL.** Sites read with raw SQL; holz-objekte has 18 `DataManager::raw()` call sites (`Catalog`, `Tiles`,
   `Likes`…). Raw reads record the tables they name, parsed conservatively from `FROM`/`JOIN`. When the tables can't be
   determined, the read depends on the whole storage. `DataManager::execute()` writes are handled the same way.
   - Writes that bypass the data layer (a bare `$pdo->exec`, as in holz's `AppliedCopyright` and `MemberKind`) can't
     be seen. A public `PageCache::changed(...)` call exists for them, and they are listed in the docs.
2. **Deploy.** A deploy invalidates the whole page cache, because templates and assets change. The cache is keyed per
   release, so BG's shared storage never serves another release's pages. Pages build again on first visit and through
   the cron. Serving a previous release's page after a deploy is not allowed, because it could reference assets that
   are gone.
3. **Opt-in per site** with one config switch, default off when an existing site upgrades, on in the skeleton. All 5
   sites are switched on after their verification.
4. **Cache keys:** host + path + the query string normalised (sorted, tracking parameters ignored) + locale.
   - Search and filter pages are cached like any page, but are not rebuilt eagerly; they are dropped and built on
     their next visit.
   - Pages with endless variants are only rebuilt on visit.
5. **Which requests use the cache.** A signed-in visitor is recognised by a small marker cookie, without opening the
   session.
   - preflow/auth sets the marker at login, including a login through a remember cookie, and clears it at logout.
   - Any request carrying the marker, or a remember cookie, bypasses the cache.
   - A leftover session cookie alone does not bypass, so the session is never opened to serve a cached page.
   - Other cookies (holz's guest album cookie) do not bypass; personal parts render from them live.
   - BG Genius starts a session on every request (no `session.paths` in its `config/auth.php`). Its switch-on step
     limits sessions to the paths that need them, like the other four sites, so its anonymous pages set no cookie.
     Otherwise rule 6 would skip all of them.
6. **What is never stored:**
   - a page whose render read the session user;
   - a page that set a cookie;
   - a page that answered with anything but 200;
   - a page that has a personal part outside a live block, such as a CSRF form token in a non-replaceable place.

   Where this can be detected, the page is skipped and logged rather than stored.
7. **htmx fragments are cached.** On holz, most gallery browsing (item, next/previous, "more") is `hx-get` fragments.
   - A GET fragment is cached like a page. Its key adds the request headers the response varies on: `HX-Request`,
     `HX-Target`, and site headers such as `X-Gallery-Load`, which the site declares through `Vary`.
   - Fragments record dependencies and refresh like pages. They are rebuilt on visit, not eagerly.
   - A history-restore request is a full page.
8. **Per-request values beyond the nonce and CSRF.** holz's own `App\Http\FormToken`, an HMAC of the visitor's
   IP-derived client, is used by the like button and the contact form.
   - The framework offers a hook through which a site registers its own per-request values, swapped like the nonce.
   - In development and tests, a page is rendered twice as two different anonymous visitors before it is first stored,
     and any difference outside live blocks and registered values refuses the store and is logged. A personal value the
     developer forgot therefore shows up as an uncached page with a log line, never as a leak.
   - Each site's switch-on step includes an audit that moves its personal parts (album, like state, tokens) into
     `{% live %}` or the hook.
9. **Rebuild budget:** after-response rebuilds stop after about 2 s per request (configurable). The cron command runs every minute
   where the site has one.

10. **Every number and list is configurable per site** (owner, 2026-11-08: "configurable"). These are one config file
    with the defaults above:
    - the switch;
    - the 4 h safety maximum age;
    - the after-response rebuild budget;
    - whether search and filter pages are cached, and which query parameters are ignored;
    - which request headers fragments vary on;
    - the cookies that mean "signed in".

    The defaults are what makes the zero-configuration promise hold.

## Left to the builder

- The store format and location: under `storage/cache/pages`, per release key.
- The tag vocabulary and how the data layer records reads, including `findMany`, type queries and the media library.
- How live blocks are serialised and re-rendered: a signed placeholder naming the block/component and its identifying
  inputs, with batch rendering where many share one kind.
- How the per-dependency dial is spelled in Twig, Blade and components.
- The refresh queue's storage, merging and locking.
- How placeholders are signed (an app secret), so user text can never forge one.

## Verification

Tests prove:
- the approval-mail case: approve, then an anonymous GET of the object page and its category list shows the new state
  with no stale step;
- a feed-style bulk import refreshes once;
- next visit and timed behave as described;
- personal parts differ per visitor and never leak;
- the nonce and form tokens are fresh per request;
- signed-in requests bypass the cache;
- raw-SQL reads and writes invalidate;
- a deploy invalidates.

Measure before and after, on languagecorner (/home, /classes, 404, robots.txt) and holz (home, a list, an object,
/album), locally and live. Run every site's suites serial and parallel.
