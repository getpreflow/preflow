# holz-objekte.org on Preflow + Folio — Inventory and Port Plan

**Date:** 2026-10-23
**Site:** holz-objekte.org, "die Galerie der Schweizer Holzbranche" (CO2-Institut Schweiz):
timber buildings, bridges, towers, furniture and products, entered by the companies that built
them and shown with their CO₂ savings.
**Old code:** `~/Sites/gbits/containr.holz-objekte` (Yii 1.1.29 + the home-grown "containr" CMS),
live at giantbits.com `~/public_html/holzobjekte.org` (not a git checkout; it equals the repo's
last commit 0fc56bb6 plus the uncommitted `GbImageHelper.php` line 224 fix).
**Status:** overview for Steffen's review. Each step below gets its own spec and plan
(superpowers:writing-plans) when we reach it; this document fixes the order, the scope of each
step and the calls made so far.

## Why

The site works, but people who submit objects keep hitting "the upload did not work" with no
explanation, although every upload mechanism tested fine. The code is 2013-era Yii 1 with
SQL injection throughout, a public cron URL, anonymous upload endpoints, SMTP credentials in
code and passwords mailed in clear text. The port fixes the submission flow for good, and it is
the next real-world test of Preflow + Folio: MySQL, the members system from smart-stack.works,
several admins with different roles, heavy htmx, and one delivered JS file for the interactive
parts.

## Goals

1. **Submitting an object just works.** On phones, in locked-down company browsers and on slow
   connections, every failure says in plain language what happened and what to do, and Steffen
   can see every failure that happened.
2. Visitors see largely the same site (layout, colours, Polaris type, side panel, gallery), with
   better accessibility (keyboard, focus, contrast, labels, alt text, reduced motion) and a light
   modernization (no letterboxed images, responsive images, no Bootstrap 2/3 mix).
3. German and French as today; every old URL keeps working or redirects (301).
4. All data is imported from production: 436 objects, 413 accounts (their passwords keep working),
   2,145 images, categories, likes, top objects, ads, CO₂ factors, shop products and orders.
5. Admin in Folio, with two kinds of admins: **super admins** (2 people: everything, including
   admins, roles, settings, extensions) and **content admins** (objects, moderation, categories,
   pages, top object, ads, CO₂ factors; not admins, roles or settings).
6. MySQL as the site's SQL storage, supported by the framework end to end (migrations included).

## Non-goals

- New features beyond today's (no maps, no lightbox, no new languages: Italian stays off).
- A JS build pipeline. The interactive parts ship as one hand-written ES module.
- Redesign. Modernize, don't reinvent.

## Anti-goals (a failure even if it technically works)

- An upload that fails silently, or a message that blames "the server" without a next step.
- Losing what someone typed in the form because an image failed.
- Existing companies having to re-register or reset their password.
- Any old public URL (shared on social media or linked by partners) ending in a 404.

---

## Backup (done 2026-10-23)

`~/Sites/gbits/holzobjekte-bkup/` (personal data: never commit it, never put it in a repo):
- `db/containr_stageholzobjekte.sql`: mysqldump of the live database (MariaDB 10.6,
  `--single-transaction`), 754 KB, 28 tables. The live site loads `database.stage.php` for every
  non-dev host; `database.prod.php` is unused. A copy stays on the server in
  `~/holzobjekte-backup-20261023/`.
- `_lib/`: all of `public_html/holzobjekte.org/_lib` (uploads ≈ 2.0 GB, 8,491 files, the home
  tiles in `_lib/front`, the generated album PDFs).
- `messages/`: the live `protected/messages` (the live site adds translation keys at runtime,
  so these differ from the repo and are the source of truth for UI texts).

## Inventory

### Stack today
Yii 1.1.29 (`yiilite`), PHP 8.2 on the server (`.htaccess` handler), YiiBooster 3.1.1
(Bootstrap 2 widgets) mixed with Bootstrap 3.3.6 from a CDN, jQuery + slick, perfect-scrollbar,
unveil, jquery.cookie, pace, SweetAlert2 and AddToAny from CDNs, Font Awesome 4.7. Uploads:
Bulletproof 5 + Intervention Image 2 (GD); derivatives via a Kohana `Image` class. Mail:
SwiftMailer over SMTP with credentials inline in `GbGlobals.php::tam()`. No build tooling, no
migrations, no schema file, no console commands. Theme `themes/holzobjekte_new`.

### Data (live row counts)
| Table | Rows | Becomes |
|---|---|---|
| `containr_objectsEntry` | 436 | `object` (MySQL): ~90 columns (see below) |
| `containr_objectsCategory` | 27 | `category` (tree: groups → types) with `visibleFields`, `visibleFieldsForm`, `requiredFields` |
| `containr_media` | 2,145 | Folio assets |
| `containr_user` | 413 | members (companies) + 2+ staff accounts |
| `containr_objectVoting` | 399 | likes |
| `containr_topobject` (+ `_i18n`) | 1 (+3) | `top_object` type (de/fr) |
| `containr_adEntry` | 8 | `ad` type (sponsoring per category, date range) |
| `containr_wood` / `containr_woodArea` | 17 / 3 | CO₂ factors |
| `containr_productEntry` / `orderEntry` / `orderItem` | 3 / 10 / 53 | shop |
| `containr_contentImage` (+ `_i18n`) | 8 (+25) | page blocks |
| `containr_page` (+ `_i18n`), `template`, `elementLib`, `elementPageRef`, `*Plugin` | 16 (+47) … | Folio pages and blocks (the CMS layer itself is not ported) |
| `containr_navigation`, `language`, `newsPost`, `newsPlugin` | 0 | dropped |

Charsets are mixed (`latin1` on several tables including `objectsEntry` and `user`, `utf8mb3`
elsewhere; MyISAM and InnoDB). The import must detect UTF-8 stored in latin1 columns
(mojibake) and convert per table.

**Object fields** (`objectsEntry`): type → category, owner → user, state, likes, created/updated
(Unix time); flags `isTopProject`, `isCertified`, `isSwissWood` (VGQ), `isPrixLignum`, `isHSH`
(Label Schweizer Holz), `isLignumBulletin`; title, description (≤ 600), zip, city, street/number,
constructor (Bauherr), year completed, wood types, wood source, Swiss wood products, web, weight;
wood share per area (foundation/outer/window/inner, each with a "Swiss" variant); CO₂ entered
and CO₂ calculated; dimensions for buildings (floor area, volume), bridges (spread, length,
width), towers (height, ground area) and furniture (l/w/h); five parties (architect, engineer,
timber builder, designer, manufacturer), each with name/city/email/web; a contact person
(name, company, address, phone, email, web); images one to six, files one and two (never
handled server-side), image copyright, AGB accepted. Which of these show, are required, and
appear on the detail page is driven per category by the three CSV fields. The code hard-codes
category IDs 6–35 (27–33 furniture, shown in kg; 34/35 products).

### Visitor features
- **Home:** search box, company picker (Select2 over *all* published objects on every page),
  top object of the year, "Top-Objekte und Auszeichnungen" tiles (ranking, Schweizer Holz, VGQ),
  category tiles per group (Bauten, …).
- **Gallery** `/{de|fr}/galerie?cat=N` and filters `?filter=chh|vgq|lig|ranked`: big slider of an
  object's images on the left, the side panel lists objects with their info (only the category's
  visible fields), CO₂ badge (t, or kg for furniture), HSH/VGQ icons, like button, share links.
  Search: keyword over title and party names (plus zip/city), magic words (vgq, hsh,
  "schweizer holz", lignum), category and section filters.
- **Detail URL** `/{lang}/galerie/{id}/{slug}`.
- **Company gallery** (`firmengalerie`, only for companies with `payedProfile`).
- **Favourites "Mein Album"**: kept in the session, downloadable as a PDF (mPDF).
- **Top object** page, **ads** per category, **shop** (brochures/flyers: cart, checkout, order
  mails to the customer and fixed Lignum / CO2-Institut addresses), **contact** (reCAPTCHA),
  **über uns**, **AGB**.
- **CO₂ calculator** inside the submission form: quantity per wood product × factor, grouped by
  area, total written to "CO₂ calculated"; a manual value can be entered too.
- **Public JSON API** `api/list?model=chh|vgq|cat&category=…|top|company&id=…` with
  `Access-Control-Allow-Origin: *` (absolute image URLs, personal fields stripped). Probably
  consumed by partner sites. `api/errorReport` mails upload errors to the developer.

### "Objekt erfassen" today, and why it fails
1. Guests see login and registration side by side. Registration: name, company, address,
   phone, email, web, password (confirmation never compared; required rules never run because
   of a scenario mismatch), no e-mail verification, auto login.
2. One big form in six JS tabs: type → object data → parties → CO₂ → AGB/copyright → images.
   Fields shown and required per category.
3. **Images:** six file inputs. Each "Bild hochladen" button submits the *whole* form with
   *every* selected file. The client rejects anything that is not JPEG/PNG or is over **2 MB**,
   while the help text asks for at least 1600×1200 px. The server: `post_max_size` and
   `upload_max_filesize` are **16 MB**, GD at 256 MB memory, six images processed synchronously
   in one request, each overwritten with a burnt-in vertical copyright line and letterboxed to
   1280×960 on #444, no EXIF orientation, errors swallowed (`getError` commented out). On a new
   object the id is still null when images are saved (`_lib/uploads//`).

   Likely causes of "it did not work, no explanation", in order (the first two reproduced in
   the reference copy, step 0):
   - A modern phone photo is 3–8 MB: the 2 MB check clears the input. The red hint sits under
     the input, easily missed, the upload button stays disabled, and nothing else happens.
   - iPhone HEIC (`image/heic`) is not on the allow-list: input cleared, same.
   - Several images together exceed 16 MB: PHP discards the *entire* POST. The page reloads
     with an empty form and no message. (Less likely since the 2 MB check: six images stay
     ≤ 12 MB, unless a browser reports a wrong size or JS fails to run.)
   - A large JPEG exhausts GD memory or times out mid-request: a blank or error page.
   - Company proxies and browsers that block CDN scripts (SweetAlert2, jsdelivr) or strip large
     multipart bodies.
4. Saving mails "Ein neues Objekt wurde erfasst" to info@. The owner never gets a confirmation.
   Admins could approve by setting `state`, but an owner's save sets `state = 1`: in the
   reference copy a brand-new object was public ("Online") right after its first image upload,
   so there is no moderation in practice. The ticked AGB box is not stored (`agbAccepted` NULL)
   and the creation date is 0.
5. Password reset mails a new 8-character `rand()` password in clear text.

### Admin today ("containr")
One admin role (5). Page tree with templates and element placement, media library (crop to 4:3
+ watermark), objects list (filter by name, company, state, type) and edit (flags, state,
recreate images), category tree editor (visible/required fields), top objects, CO₂ factors,
ads, shop orders and products, users (incl. `payedProfile`, role), templates, logs, clear
caches. No exports.

### Odd or dead
Dead gallery and upload views (Dropzone, FilePond, xupload), duplicated crop logic, search
logic copied three times, junk message files, a nested `uploads/uploads/` (512 entries),
≈ 659 loose files at the uploads root plus three older folder schemes (`ddmmyy/`, `mmddyyyy/`,
`{objectId}/`), Google Analytics UA (shut down by Google in 2023), Rollbar commented out.

---

## Decisions (my calls unless marked "Steffen")

- **New repo** `~/Sites/gbits/holz-objekte.org` (GitHub `smeyerme/holz-objekte.org`), Preflow +
  Folio like smart-stack.works, local vhost `holzobjekte`, PHP 8.4.
- **Deploy** with Deployer to giantbits.com, path `~/public_html/holz-objekte.org` (the old
  `holzobjekte.org` folder stays untouched until cutover). A stage subdomain first; at cutover
  Steffen repoints the document root to `…/current/public` (as for smart-stack.works).
- **Storage:** objects, categories, likes, members, staff, orders and the Folio asset index on
  **MySQL** (a new database Steffen creates; credentials only in the server `.env`). Pages,
  blocks, singletons, top object, ads, CO₂ factors, products stay Folio content (JSON) and travel
  with `folio:export` / `folio:import`.
- **Framework first** (standing rule: a site workaround for a missing framework feature means
  fixing the framework now): MySQL migrations, the members package, Folio staff roles. Each is a
  release; the site uses released versions only.
- **Images become Folio assets.** The original is kept untouched (EXIF-rotated); display
  variants are generated from it (AVIF/WebP/JPEG via `folio_image()` presets). The copyright line
  moves from a burnt-in overlay to a caption under the image. Letterboxing goes: CSS
  `aspect-ratio` + `object-fit`. (Steffen: confirm the copyright caption instead of the overlay.
  If the overlay must stay, it goes on the variants only, never on the original.)
- **Imported images:** for the 2,145 old files only the watermarked, letterboxed 1280×960
  versions exist (one `originals/` folder). The importer trims the #444 bars where it finds
  them and keeps the burnt-in line as it is.
- **Passwords:** of 413 accounts, 134 have bcrypt hashes and 279 still have MD5-crypt (`$1$`)
  from older code. Both carry over unchanged: `password_verify` accepts both, and the login
  rehashes to the current algorithm on success (`password_needs_rehash`). If the members package
  can't verify foreign hashes, that is part of step 2. MD5-crypt accounts that never sign in
  again keep the weak hash; the prune policy for long-inactive accounts is a step 8 question.
- **Accounts today:** role 2 = company (401), role 5 = admin (6), plus 4 with role 0 and 2 with
  role 1 (meaning unknown, checked in step 5). Two logins appear twice: the import reports them
  for a manual decision instead of merging.
- **Interactivity:** htmx everywhere a server round trip is fine (filters, search, likes,
  favourites, wizard steps, moderation). One ES module `public/js/holzobjekte.js` (no build, no
  CDN, CSP-clean) for the gallery slider, the image picker (preview, client-side downscale,
  progress, retry) and the CO₂ calculator. jQuery, slick, Select2, SweetAlert2 and Bootstrap go.
- **No third-party scripts.** Share links become plain links (no AddToAny script), the GA tag
  goes, reCAPTCHA goes (throttle + honeypot, as on the other sites).
- **Sessions/cookies:** the members cookie only as wide as the account area needs, as on
  smart-stack.works.

## The new submission flow (outline; step 7 gets the full spec)

1. **Account:** register (company, name, email, password; the rest optional) → e-mail
   confirmation link → signed in. Login, reset by token link. Existing accounts sign in as
   before.
2. **Draft first:** choosing the object type creates a draft object right away (it has an id
   from the first second). Each wizard step saves on "Weiter" via htmx (and on leaving a field),
   so nothing typed is lost; a draft can be continued later from "Meine Objekte".
3. **Steps:** Typ → Objekt → Beteiligte → CO₂ → Bilder → Prüfen & absenden. Fields shown and
   required per category, as today, but validated on the server per step with messages at the
   field (errors summary at the top, focus moves to it).
4. **Images, one at a time:** each image uploads on its own the moment it is picked
   (one file per request, well under any limit), with a progress bar and a thumbnail when done.
   The JS downscales large photos in the browser to ≤ 3,000 px / ≤ 8 MB JPEG before sending
   (HEIC is converted where the browser can decode it; otherwise the server accepts HEIC via
   Imagick, or the message says how to export as JPEG). Every failure shows *which* image, *why*
   (too big, wrong format, connection lost, server refused) and a "nochmals versuchen" button;
   the other images and the form are unaffected. Reorder and remove are buttons (keyboard
   accessible), not drag-only. Without JS: a plain one-file form per image that still works.
5. **Every failure is logged** server-side (step, object id, browser, file size/type, error) and
   shown to admins in Folio, so "it did not work" can be looked up.
6. **Submit:** AGB checkbox required. The owner gets a confirmation mail; content admins get a
   moderation badge in Folio and a mail. Publishing is an admin decision (approve / send back
   with a note, which mails the owner).
7. **Edits after publishing:** (Steffen) today they go live immediately. My default: they stay
   live as today and admins get a notice; image changes included.

## Steps

Each step ends deployable and is verified in the browser with screenshots.

| # | Step | Where | Done when |
|---|---|---|---|
| 0 | **Reference instance** of the old site locally on the backup (MariaDB import, local test company account), and a screenshot baseline of every visitor and account screen in de/fr | local | **Done 2026-10-23:** `~/Sites/gbits/holzobjekte-ref` at https://holzref.dev.smyr (PHP 8.5, deprecations hidden; mail spooled locally, never sent), test company + test admin, 30 public + 16 account + 13 admin screenshots in `baseline/`, findings in `REFERENCE.md` |
| 1 | **MySQL in the framework:** migrations on MySQL (dialect quoting, key lengths for utf8mb4, introspection for `migrate:check`), Folio SQL storage + `TableSync` on MySQL, throttle/codes upserts on MySQL, test suite against a MySQL service | framework → v0.25 | **Done 2026-10-23: v0.25.0 released** (spec/plan `2026-10-23-mysql-support*`): grammars, Folio tables, strict sessions, ids `utf8mb4_bin`, 73 MySQL tests on MariaDB; follow-up 0.25.1: exact `record_id` match in revisions |
| 2 | **Members package:** lift smart-stack's members (registration, e-mail confirmation, login, remember-me, reset, account page, mails, prune) into a framework package with the site-specific parts (book codes, screening, approval) as options; smart-stack.works switches to it | framework → v0.26, smart-stack | smart-stack.works runs on the package unchanged for its members |
| 3 | **Folio staff roles:** staff accounts as a Folio user type on SQL, roles super admin / content admin, per-section and per-type permissions, staff management for super admins only, the delegation rule (nobody grants more than they have) | framework → v0.27 | Two roles enforced in the admin UI and on every admin route |
| 4 | **Site skeleton:** repo, Preflow + Folio on MySQL, theme ported (CSS from `holzobjekte_new`, Polaris webfont licence checked), header + side panel layout, de/fr routing with the old URL scheme, a11y baseline, deploy to stage | site | Stage shows the empty shell, Lighthouse a11y ≥ 95 |
| 5 | **Data model + importer:** categories (tree, visible/required fields), objects, likes, members from `containr_user`, images → assets (trim letterbox, dedupe the four folder schemes), charset repair; idempotent `holz:import` run against the backup | site | All 436 objects, 413 accounts, 2,145 images imported locally; spot checks match the old site |
| 6 | **Visitor site:** home, gallery with filters and search (htmx), detail with slider and info panel, likes, share links, company gallery, top object, ads, AGB/über uns/contact, old-URL redirects | site | Side-by-side screenshots against step 0, de and fr |
| 7 | **Submission flow** (above) + its failure log; tested on iPhone (HEIC), Android, Windows Edge with 20 MB photos, a throttled connection and with JS off | site | Every failure case shows its message; zero lost drafts |
| 8 | **Account area:** my objects (drafts, in review, published), edit, profile, password, favourites album + PDF | site | Old account features all present |
| 9 | **Admin:** moderation queue with badge, object edit with flags and state, category editor (visible/required fields), CO₂ factors, top object, ads, companies (`payedProfile`), failure log, staff for super admins | site | Content admin and super admin each walked through |
| 10 | **Shop, API, contact:** product list + order form with order mails; `api/list` with the same response shape and CORS | site | Partner calls answer as before |
| 11 | **Cutover:** freeze submissions on the old site, final dump + `_lib` sync + import, document root switch, smoke test, upload-failure log watched for a week, old folder archived | live | Old site retired |

Steps 1–3 are framework work and can run while step 0 and the theme part of step 4 happen.

## Open points (Steffen; my default applies until you say otherwise)

1. **Shop:** 10 orders in total. Keep as a simple order form (default), or drop?
2. **API consumers:** who calls `api/list` (Lignum, VGQ, CO2-Institut sites)? Default: keep it
   identical.
3. **Copyright on images:** caption (default) or burnt-in overlay on the variants?
4. **Edits after publishing:** stay live + notice (default), or back to review?
5. **Favourites PDF:** keep (default), or a print stylesheet instead?
6. **Polaris webfont:** the licence must cover the new domain setup; otherwise a close free
   alternative.
7. **The two super admins** and the content admins: who, with which e-mail addresses.

## Review focus (risks a person would hit)

- A 25 MB HEIC from an iPhone picked in Safari, and a 20 MB JPEG in a company Edge that blocks
  `blob:` workers: must upload or say exactly why not.
- Double click on "Weiter" or "Absenden", or two tabs on the same draft: no duplicate objects,
  no lost fields.
- Session expiry in the middle of the wizard: the draft is still there after signing in again.
- The two logins that appear twice in `containr_user`, and names with mojibake: the import must
  not merge or garble them.
- A company that last signed in in 2015 (MD5-crypt hash) signs in with its old password: it
  works, and the hash is upgraded.
- Old shared links (`/de/galerie/123/titel`, `?cat=6`, `?filter=vgq`) after cutover.
