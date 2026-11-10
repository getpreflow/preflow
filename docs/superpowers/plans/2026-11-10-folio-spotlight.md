# Folio Spotlight Slider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** an "In detail" spotlight slider on preflow.dev/folio: seven slides, text left and an animation right, highlighting Folio features that come out of the box.

**Architecture:** one new Folio block `spotlight` with data-only child `spotlight_slide` in the website repo (`/Users/smyr/Sites/gbits/preflow-website`, branch main). A slide's visual is either a recording made by `tools/capture` (existing `Recording` component) or one of three drawn SVG/CSS animations (new components). The seeder adds the section to an existing `folio` page once, so live and local get it without a reseed.

**Tech Stack:** PHP 8.4+, Preflow 0.63.1 with Folio, Twig, CSS, a little nonce'd JS; tools/capture (chrome-php + ffmpeg) for recordings.

**Spec:** owner-approved in chat (2026-11-10): slides 1–7 below, manual slider (arrows, dots, keyboard, swipe; never auto-advances), animations play only while visible and respect reduced motion, no-JS fallback stacks the slides, editable in Folio, view-the-code button like every block. Placed on /folio after the screenshot grid. "USP" framing for slide 1: not "nobody does it" but "it comes out of the box".

## Global Constraints

- No Node/npm/build step in the site. Strict CSP: scripts only via `{% apply js %}` or nonce'd tags; styles via `{% apply css %}`.
- Public pages cookie-free and page-cacheable. The current look (tokens, dark/light themes) carries over. Responsive to 375 px with no horizontal scroll.
- Every claim on a slide is true for Preflow 0.63.1 — sources: `/Users/smyr/Sites/gbits/preflow-framework/packages/folio/README.md` ("Drafts and publishing", "Preview"), `packages/media/README.md` (Editing images, Variants, Box presets, Replace and versions, Colour management, Colours), `packages/rbac/README.md`, `packages/core/README.md` (Page cache).
- Every editor-entered link through `safe_link()`.
- Recordings: muted, looping, poster, ≤ 2 MB, never autoplay under reduced motion (reuse `app/Components/Recording`).
- Animations: SVG/CSS in the page, no video files; paused when out of view; a static, complete frame under `prefers-reduced-motion: reduce`; `aria-hidden` decoration plus the slide text carrying the meaning.
- Never run two test suites at once. Commit locally; the controller pushes and deploys.

## The slides (copy source of truth for Task 1)

1. **Publishing, out of the box** — working copy vs. published copy (Draft / Published / Changed); autosave after 3 s; conflicting saves are refused, not lost; publish validates and a failed check never touches the live page; "Publish at" schedules; every publish keeps a revision (date, person, changed fields) that can be restored; live preview with click-to-edit; a share link shows the unpublished draft to someone without an account (7 days by default). Visual: recording `spot-publishing`.
2. **Editing an image** — crop, rotate and mirror without touching the upload; a focal point that preset cut-outs follow. Visual: recording `spot-crop`.
3. **One upload, every size** — AVIF and WebP in several widths, preset boxes cut around the focal point, made at upload time; `media_image()` writes the `<picture>`. Visual: animation `variants`.
4. **Replace, with history** — replace a file under the same address, earlier versions kept, restore one. Visual: recording `spot-replace`.
5. **Colours handled for you** — CMYK and Adobe RGB / Display P3 images converted to sRGB (needs Imagick with lcms); each image gets its dominant colour, a palette and a readable text colour. Visual: animation `colours`.
6. **Roles managed in Folio** — with preflow/rbac: roles and their permissions edited in Folio, staff accounts, an editor sees only what they may touch, no lock-out of the last admin. Visual: recording `spot-rbac`.
7. **A page cache that rebuilds on publish** — visitors who aren't signed in get finished HTML; publishing rebuilds exactly the pages that show that content, in the background by cron (`pages:work`), no workers needed. Visual: animation `page-cache`.

Each slide also links to its docs page or the package README on GitHub (`https://github.com/getpreflow/<package>#…`).

---

### Task 1: The spotlight block, slider and seeded content

**Files:**
- Create: `app/Components/Blocks/Spotlight/Spotlight.{php,twig}` (`#[Block(key: 'spotlight', label: 'Spotlight', group: 'Sections')]`: `heading`, `intro`, `slides` (blocks, allowed `spotlight_slide`)).
- Create: `app/Blocks/SpotlightSlide.php` (`spotlight_slide`: `title`, `text`, `points` (text, one per line), `visual` (string: a capture id like `spot-crop`, or `animation:variants|colours|page-cache`), `link_label`, `link_url`).
- Create: `app/Components/Animation/Animation.{php,twig}` — prop `name`; renders the named drawn animation's partial or nothing for an unknown name. Task 2 fills the three partials; in Task 1 each is a simple static placeholder figure so the slider can be built and tested.
- Modify: `config/models/page.json` (`allowed` += `spotlight`), `app/Content/ContentSeeder.php`: the folio page's sections include the spotlight block after `screenshot_grid` with the seven slides above; plus an idempotent step (like `redirects()`) that, on an already-seeded site, inserts the spotlight section after the screenshot grid of the published `folio` page if the page has no `spotlight` block, and publishes it through `Publisher`. An editor's later removal of the section is not undone more than once — record that the upgrade ran (e.g. a marker key in the seeder's own record, or only insert when the page's revision count shows it was never there; pick the simplest truthful mechanism and test it).
- Test: `tests/Integration/SpotlightTest.php`

**Slider behaviour:** all slides in the HTML (`<section aria-roledescription="carousel" aria-label=…>`, each slide `role="group" aria-roledescription="slide" aria-label="n of 7"`). Without JS: stacked. With JS (`{% apply js %}`): one slide visible, previous/next buttons, dots (buttons with names), ArrowLeft/ArrowRight when focus is in the slider, touch swipe; never auto-advances; the visible slide's recording/animation plays, the others pause; `aria-live="polite"` on the slide counter. Layout: text left, visual right (stacked under 48rem).

- [ ] Failing tests: `/folio` shows the spotlight heading after the screenshot grid and the seven slide titles in order; each slide has its visual (a `<video>` for capture ids whose files exist, the animation container for `animation:*`), and a slide whose capture file is missing renders its text without a broken video; links go through `safe_link` (unsafe ones absent); the carousel roles/labels exist; the upgrade step adds the section to an already-seeded site exactly once, after the screenshot grid, published, and does nothing on the second run; the block has its view-the-code button.
- [ ] Implement; `composer test` green; headless-Chrome check (chrome-php, dev dependency) at 1440/375 in both themes: arrows, dots, keyboard, no horizontal scroll.
- [ ] Commit: `feat: spotlight slider on the Folio page`

### Task 2: Three drawn animations

**Files:** `app/Components/Animation/partials/{variants,colours,page-cache}.twig` (or the component's own structure from Task 1), CSS in the component.

- `variants`: one image tile fans out into labelled variant tiles (AVIF/WebP, widths like 480/960/1440, a square "thumb" box cut around a focal-point marker).
- `colours`: a photo tile labelled "CMYK" shifts to "sRGB" (colour change), then a palette of swatches and a "text colour" chip appear. Draw the "photo" as simple SVG shapes — no image file needed.
- `page-cache`: "Publish" → arrows to the affected pages lighting up → "rebuilt by cron" → visitor request served "HTML from cache".
- Loop gently (≈ 6–10 s), run only while the slide is visible (a class toggled by the slider/IntersectionObserver), static final frame under reduced motion, `aria-hidden="true"`, theme tokens for colours.
- [ ] Failing tests: each animation renders its SVG with `aria-hidden`, the page's CSS contains a `prefers-reduced-motion` rule disabling it, unknown names render nothing.
- [ ] Implement; visual check in both themes and at 375 px; `composer test` green.
- [ ] Commit: `feat: drawn animations for the spotlight`

### Task 3: Four recordings in the capture script

**Files:** `tools/capture/shots.php`, `tools/capture/capture.php` (only where a new step kind is needed), `tools/capture/README.md`.

- `spot-publishing` (starter-folio, admin): open a page in the editor with live preview, change a text, copy the share link and open it in a fresh signed-out browser context (shows the draft), publish, open the history and restore an older revision.
- `spot-crop`: open an image in the media library, crop and rotate, set the focal point, save; show a preset cut-out following it.
- `spot-replace`: replace an image file, show the versions list, restore the earlier version.
- `spot-rbac`: in the temporary starter-folio install only, `composer require preflow/rbac` (the released version matching the starter) and `php preflow folio:extensions:enable rbac` + `folio:extensions:apply` (see packages/rbac/README.md "Install" and "Switching from preflow/roles"); then in Folio create a role, tick permissions, assign it to a staff account, sign in as that account and show the reduced admin. If switching the starter from roles to rbac needs more than README steps, stop and report NEEDS_CONTEXT.
- Each ≤ 12 s, under 2 MB, poster frame; credentials only the starter's public demo logins read at run time; servers stopped by PID.
- [ ] Extend `CaptureShotsTest` so every capture id referenced by seeded content exists in `shots.php`.
- [ ] Run `php tools/capture/capture.php --only=spot-publishing` (etc.); look at the last frames; commit the outputs.
- [ ] Commit: `feat: spotlight recordings`

### Controller: deploy
Backup shared, push, `dep deploy`, check `/folio` (seven slides, recordings 200, view-code button), reseed check: the upgrade step added the section on live.
