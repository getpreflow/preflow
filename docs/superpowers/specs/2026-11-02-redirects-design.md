# Redirects and the "not found" list — design

Owner request, 2026-11-02. Roadmap: "301 redirects on slug change" under "Later: SEO and GEO". Project 2 of 3
(after CSRF in 0.51, before grant-once role seeding).

## Why

When a page's address changes or a page goes away, old links (search results, other sites, bookmarks, print)
end in a 404. The owner has always wished for "either a redirect to the new place or the option to provide a page
that should be redirected to". Folio keeps no record of old addresses today (`FrontendResolver::match()` only knows
current slugs; `FrontendController::show()` throws `NotFoundHttpException` when nothing matches), and none of the
sites has a redirect list — their existing redirects are generic rules in code (see "Existing redirects").

## Goals

1. **Automatic:** when a record's public address changes (any field its type's route is built from, e.g. the
   slug, in any language), its old address answers with a permanent redirect to the record's current address.
   *(My calls after the builder check:)* this covers every way an address changes: publish (also the scheduler
   and revision restore, which go through publishing) and, for types without the publishing workflow, a plain
   save; the old address comes from the live record before the change. When a record loses its address in one
   language only (translated slug cleared, language removed), that address redirects to the record's
   default-language address. (Folio addresses are built from a record's own fields only; there is no parent/tree
   path today.)
2. **Removed pages:** deleting or unpublishing a record that has a public address offers "send its old links
   to…" with a page picker; skipping it keeps today's 404. *(My call:)* for types without publishing,
   "unpublishing" is the status change that takes the record off the site. A bulk delete offers one optional
   target for the whole batch; records deleted by cascade that had addresses are listed in the delete plan and
   get the same target.
3. **By hand:** a redirects list in Folio where an editor enters an old address and picks where it goes.
   - Exact entries (`/old-page`) and **area entries** (everything under `/workshops-2024/`).
   - An area entry either sends everything to one target, or keeps the rest of the address under a new prefix
     (`/workshops-2024/basel` → `/workshops/basel`).
4. **The "not found" list:** addresses visitors actually hit that ended in a 404, with a count and when last hit,
   most-hit first; each line can be turned into a redirect in a click (pick the target) or dismissed.

## Non-goals

- Moving the sites' existing generic rules into the list (see below): they stay in code.
- Redirect rules with regular expressions or wildcards in the middle of an address.
- Analytics: the not-found list is a tool for fixing links, not traffic statistics.
- Redirecting between domains (the sites' `.htaccess` host rules stay).
- A type's route pattern changed in config (`/blog/{slug}` → `/news/{slug}`) moves every record without a
  publish; that is not detected automatically (my call) — an editor adds an area entry by hand.

## Anti-goals

- Redirect chains or loops (A → B → C, or A → B → A).
- A redirect that hides a real page: an address that a page currently has always shows the page.
- Storing visitor data in the not-found list (no IP address, no user agent, no referrer).
- The not-found list filling with bot noise (`/wp-login.php`, `/.env`, `/xmlrpc.php`…) or growing without limit.
- Open redirects: a hand-made target outside the site is only what an editor typed; a request can never choose it.

## How it works (decided)

### Targets point at records, not at addresses

An automatic redirect, a "send its old links to…" choice and a hand-made entry whose target is picked from the
site all store the **target record** (type + id), not its address. The address is resolved when the redirect
is answered, in the language of the old address (falling back to the target's default-language address when it
has none in that language). So:
- a page renamed twice redirects from both old addresses straight to the current one — no chains;
- if the target record is later deleted, its redirects become broken (shown as such in the list) and answer 404
  until an editor picks a new target.

A hand-made entry may instead have a free URL target (absolute `https://…` or a path on this site) for external
or non-Folio destinations.

### When a redirect applies

Only when nothing else answers: a public GET/HEAD request ends in a thrown `NotFoundHttpException` — Folio's
`FrontendController` finding no record, a site controller's own 404 (a BG Genius game whose slug changed), or a
router miss (*my call after the builder check*: one pipeline middleware around the kernel's handler catches them
all). Not redirected or recorded: the 404 that a gated Folio page answers to visitors without access (it is a
response, not an exception, and must keep hiding the page), and paths a package declares non-public (admin,
components, uploads and private files, members): a small registry where packages declare such path prefixes
(new; Folio, media and members register theirs), independent of whether SEO is on. A page that currently holds an address always wins; a redirect for that address is then
dropped (my call: removed automatically when a record is published at that address, and shown as removed).
Lookup order for an unanswered address: exact entry, then the longest matching area entry.

Answers: 301 for automatic and removed-page redirects; a hand-made entry can choose 301 (permanent, default) or
302 (temporary). The original query string is kept (my call). Only GET and HEAD are redirected; other methods
keep their 404.

### Languages

Addresses are stored as the visitor sees them, per language (an old `/de/werkstatt` and an old `/workshop` are
separate entries, each knowing its language). The locale prefix is stripped before the app runs
(`LocaleMiddleware`), so the lookup uses the stripped path plus the request's locale. All four sites use the
`prefix` URL strategy; with `'none'` (no prefix), entries are language-neutral (checked 2026-11-02).

### The not-found list

- Recorded: address (path, without query string), language, count, first and last hit. Nothing about the visitor.
- Only public GET/HEAD requests that end in 404 after the redirect lookup; never the non-public paths of the
  registry above.
- Bot noise is filtered: a built-in list of probe patterns (`.php` outside the site's own routes, `/wp-…`,
  `/.env`, `/.git`, `xmlrpc`, `/cgi-bin`…; site-configurable), and the list hides addresses hit only once.
- Bounded: at most 1000 addresses (configurable); the least recently hit are dropped first. Recording costs one
  small write per 404.
- An address that gets a redirect leaves the list; a dismissed address stays hidden unless it is hit again
  after 30 days (my call).

### Folio

- **Settings → Redirects**, two tabs: *Redirects* (the list: old address, language, target, kind
  automatic/removed/hand-made, permanent or temporary, hits since created, broken flag; add, edit, delete,
  search) and *Not found* (the list above with "Redirect…" and "Dismiss").
- Deleting or unpublishing a record with a public address shows "Send its old links to…" with the page picker
  (and a free URL option), optional.
- A record's editor shows the addresses that redirect to it (my call: a small "Old addresses" note in the
  sidebar), so an editor sees why an old link still works.
- Permission `redirects.manage` for the screen; the automatic redirects need no permission (they follow from
  publishing).

### Placement (framework rule: abstractions in packages, Folio is the UI adapter)

My call: the mechanism — redirect lookup and answer, the not-found recorder with its noise filter, and the
interfaces for where redirects and not-found entries are stored — lives in a package core (`preflow/routing` or
`preflow/view`; builder's call), usable without Folio by any site that binds a store. Folio supplies the stores
(its own storage, SQL or JSON like its other internal data, with `preflow migrate` for SQL sites), the automatic
redirects from publishing, the delete/unpublish prompt and the Settings screen.

Off by default? No (my call): redirects are always on once the package is upgraded — they only act on requests
that would otherwise 404. The not-found recording can be turned off in config.

## Existing redirects (recon, 2026-11-02)

None of the four sites keeps a list of old addresses; everything is generic code, and stays:
smart-stack `LegacyEnRedirect` (`/en/…` → `/…`), holz-objekte `LegacyDeRedirect` (`/de/…`, `/home`), its old
gallery query links (`?filter=`, `?cat=` via `legacy_id`), `/checkout` and `/ordercomplete`, and the old French and
Italian domains in `.htaccess`; BG Genius `OldAdminRedirect` (`/admin` → `/folio`). The new feature covers what
these don't: renamed and removed pages, hand-made entries, and finding broken links.

## Left to the builder

- Package placement between routing and view, class names, storage layout, the hook into a 404 (middleware
  around the kernel's handler or a hook in `FrontendController` plus the router miss).
- How publishing detects an address change per language (compare the live address before and after publish).
- The probe-pattern list and the exact UI layout (follow Folio's existing Settings screens).
- Tests: rename twice (no chain), rename back (the redirect is removed, the page wins), language-specific slugs,
  area entries both modes and longest match, deleted target (broken), query string kept, HEAD, POST not
  redirected, not-found recording (no visitor data, noise filtered, cap, dismiss), permissions.
- READMEs and the ROADMAP item with upgrade notes (`preflow migrate` for SQL sites).
