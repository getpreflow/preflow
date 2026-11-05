# `preflow seo:audit` — design

Owner request, 2026-11-05. Roadmap: "An SEO audit command" (Later: SEO and GEO). Follows SEO (0.50), GEO (0.49),
redirects (0.52) and the 0.53.x fixes.

## Why

The owner does not want to depend on an external audit to learn where a site has a problem. On 2026-11-05 an
external SEO & GEO audit of smeyer.me found a missing raster favicon, apple-touch icon and manifest, an
`aria-hidden` block with focusable content, a 404 page without a link home, a share image without alt text and
ETags that never produced a 304 — none of which the framework would have told us. Preflow should find such
problems itself, on demand, and help resolve them.

## Owner decisions (2026-11-05)

1. **On demand only.** Never part of a deploy and never blocking one: most findings are content (in the database),
   not code.
2. **Two outputs:** the command prints its findings in the terminal, and writes them to a report file that Folio
   reads and shows. Folio only **shows the last run**; it cannot start one.
3. **Each environment checks itself.** Run locally, it checks the local site and content; run on the server, it
   checks production. The report is written into that environment's storage, where its own Folio shows it.
4. **Sites can add their own checks.** The framework provides the plumbing; holz-objekte.org gets two example
   checks as the proof of concept: every published object has a photo credit; every published object has a place.
5. **No score.** Issues are counted by severity; the report lists what needs fixing, most serious first.
6. **An audit run leaves no trace in the site's data (builder check, owner chose the suggestion).** Its renders are
   marked internal: the redirect middleware does not record not-found entries or count redirect hits for them, the
   media package does not generate missing image variants for them (images are checked against files that exist),
   and sites can see the marker to skip their own counters. This needs a small public "internal render" signal in
   the framework core that routing, media and sites honour.
7. **Runs work in batches and can be resumed (builder check, owner chose the suggestion).** The server kills SSH
   processes after ~12 minutes. The command saves its progress; run again, it continues where it stopped. The
   report has a status: complete, or incomplete with "N of M pages", and Folio shows that status. A new full run
   starts over only when asked (a flag) or when the last run completed.

## Goals

- `preflow seo:audit` renders every public page of the site **inside the app** (no network; the way GEO renders a
  page for its Markdown view: anonymous, no cookies or session) and runs checks on each response, plus site-wide
  checks (robots.txt, sitemap, llms.txt, the 404 page, favicons, conditional requests).
- Each finding has: a check id, a severity (**critical / warning / notice**), the page URL (and language), a short
  message saying what is wrong and what to do, and — when it comes from content — where to fix it (the Folio record:
  type + id, or the media asset id), so Folio can link straight to the editor.
- Terminal output: grouped by severity with counts, then by check; a non-zero exit code only for a usage error (an
  audit with findings still exits 0 — it is a report, not a gate). Options to limit the run (e.g. one URL or a
  maximum number of pages) for quick checks — builder's call on the exact flags.
- Report file: one JSON file in the environment's storage (overwritten each run), with the run's time, the site
  origin, counts per severity and the findings.
- Folio: a read-only screen (under Settings) showing the last report: when it ran, counts per severity, findings
  grouped by check, each with a link to the page and, for content findings, to the record or asset to fix. A
  permission for it (new permissions are announced to role managers since 0.53). A site without a report yet sees
  how to run one.
- **Site checks:** a public interface/registry (the framework-abstractions rule: the mechanism in a package core,
  Folio only shows the report) through which a site or package registers checks that run with the built-in ones and
  report findings in the same shape. holz-objekte.org registers the two example checks.

## Rendering and scope (builder check, my calls)

- Pages are rendered as a visitor sees them in production: the debug toolbar and other debug-only output are off
  for audit renders even where `app.debug` is on locally (otherwise every local page reports debug markup); 5xx
  are still reported.
- **Audited pages:** the public pages list (`PublicPages`, every language, including pages kept out of the sitemap
  or llms.txt), each with all page checks.
- **Link targets** found on audited pages get a status check only (200, redirect — noted when it chains — or
  404/5xx), not the page checks. Query-string URLs (search, filters, pagination) and paths robots.txt disallows are
  not followed. Each target is checked once per run.

## Built-in checks (my call: the roadmap list plus what the external audit flagged)

Per page:
- missing or empty `<title>`; duplicate titles across pages (same language); missing meta description; duplicate
  descriptions (notice);
- images without `alt` (an empty `alt` on decorative images is fine); a share image without `og:image:alt`;
- broken internal links (a link or image to a path on this site that answers 404 or 5xx; redirects are fine but
  noted when they chain);
- hreflang: an alternate whose page doesn't exist or doesn't link back; a missing x-default where the site has
  several languages;
- missing canonical; a canonical on another host than `app.url`;
- noindex on a page that is in the sitemap (and the reverse: a sitemap URL that isn't 200);
- `aria-hidden` elements containing focusable content;
- more than one `<h1>` or none.

Site-wide:
- robots.txt, sitemap.xml and llms.txt answer when SEO/GEO are on; the sitemap is valid XML and lists only 200
  pages;
- the 404 page answers 404 and contains a link to the home page;
- a raster favicon (≥48 px) or `/favicon.ico`, an apple-touch icon, a web manifest;
- conditional requests: a public page with an ETag answers its own ETag with 304, and two consecutive renders give
  the same ETag (catches per-request content such as timing tokens, as found on smeyer.me);
- the site share image (and the owner in JSON-LD) set when SEO is on.

Severities: critical = search engines or visitors are hurt (broken links, 5xx, noindex in the sitemap, missing
title); warning = clearly wrong but not harmful (missing description, missing alt, hreflang mismatch, no raster
favicon); notice = best practice (duplicate descriptions, no manifest, no 304).

## Non-goals

- A score or grade.
- DNS and email checks (DMARC, MTA-STS, SPF): they are not part of the environment the command runs in, and not
  the site's code or content (my call).
- AI-text detection, performance measuring (PageSpeed), external link checking.
- Fixing anything automatically; starting a run from Folio.

## Anti-goals

- Blocking or slowing a deploy.
- Personal data in the report (it renders as an anonymous visitor; account/private pages are not audited).
- A report that lies about the environment: findings must describe the environment the command ran in.

## Constraints

- holz-objekte.org has ≈1,150 public URLs; a full run may take minutes — print progress; keep memory flat.
- Works on JSON-only sites (smeyer.me) and SQL sites.
- Every text an editor reads in Folio in en/de/fr (Folio's admin languages).

## Left to the builder

- Package placement (`preflow/view` next to SEO/GEO, per the earlier "SEO/GEO is view-related" placement), class
  names, the check interface's exact shape, the JSON schema, the report path, the Folio screen layout and its
  permission name, the command's flags.
- How "where to fix" is resolved for Folio pages (record from the public pages list) and media (asset id from a
  media URL).
- Tests per check (a passing and a failing fixture), the command end to end on a small app, the Folio screen with
  and without a report, the holz-objekte example checks in the site repo.
