# Folio 0.76 inventory (read-only, 2026-11-14)

Repo: /Users/smyr/Sites/gbits/preflow-framework. Paths below are relative to packages/folio unless they start with packages/.
Spec: .superpowers/folio-redesign/folio-redesign-handoff/{SCREENS,INK,COMPONENTS}.md + reference/*.dc.html (board texts dumped to scratchpad b-*.txt).
Deviations rows labelled 0.76: docs/superpowers/notes/2026-11-13-folio-redesign-deviations.md lines 58 (AI failed badge), 82 (skip link), 94 (dashboard), 96 (translations), 97 (redirects), 98 (cache), 99 (roles), 100 (assets), 102 (asset detail), 104 (ink layer), 105 (removal dialog); line 53 (asset "Move to bin" text link); line 71 (brand tile glow kept for 0.76 ink places).

Legend: GAP = no public API today, needs new core/Folio API. OK = API exists.

---------------------------------------------------------------------------------------------------
## 0. Cross-cutting: what already exists in admin.css (3515 lines) vs what 0.76 must add

Exists (0.73-0.75): tokens incl. --ink, --ink-fg, --ink-line, --ink-accent, --ink-glow, --ink-deep, --mark-1..6 (l.106-114); `.folio-ink` base (l.237, glow bg, r-xl, overflow hidden); brand tile `.folio-brand-tile.folio-ink` / `.folio-rail-brand.folio-ink` (l.246); `.folio-type-icon[data-family]` + `-lg` (l.178-196, families page/reference/asset/member/shop/live; `.is-warning` l.1820); `.folio-sr` (l.198); `.folio-subtitle` (l.584); `.folio-card-grid/.folio-card*` (l.683-715, current dashboard tiles); `.folio-review*` (l.718-737); `.folio-empty/.folio-empty-icon` (l.844-861, plain icon+text); `.folio-toolbar*` (l.871); `.folio-segmented` anchor variant (l.890, `a[aria-current=page]`, `.folio-segmented-count`, `.is-attention`); `.folio-segmented.folio-segmented-choice` + `.folio-segment` radio variant (l.1422); `.folio-radio-cards/.folio-radio-card*` (l.1453); `.folio-pager*` (l.971); `.folio-bulk-bar*` (l.983, inverse, aria sticky); `.folio-menu*` (l.1017); `.folio-badge` incl. `.is-failed` (l.1656), `.is-draft/.is-scheduled/.is-published/.is-changed/.is-incomplete`; `.folio-save-state*` + `.folio-caret` (l.1668-1681, mono + caret; keyframes `folio-blink` is defined under the auth block l.2559); `.folio-count`; `.folio-tabs` (l.2295, a/button, aria-current/selected); drawer CSS `.folio-drawer*`; `.folio-asset-grid/.folio-asset-thumb/.folio-asset-label` (l.2220-2243), `.folio-asset-panel/-columns/-column/-preview/-usage/-versions/-replace/-version*` (l.2317-2381), `.folio-focal-picker/-marker` (l.2335), `.folio-swatches/.folio-swatch*` (l.2359), asset-field card parts `.folio-asset-item/-name/-warn/-focal` (l.2412-2445); `.folio-translations-save-all` (l.840, only margin) ; `.folio-auth*` (l.2473-2589).

Missing (all 0.76 ink work): no `mark-*` symbols in assets/icons.svg (the 8 `fg-*` symbols live inline in templates/admin/_auth_pattern.twig l.4-12); no `.folio-ink-marks`, `.mc-1..6`, `.folio-ink-line`, `.folio-ink-drift` (auth uses `.folio-auth-pattern .pc-N`, `.folio-auth-drift`, `.folio-auth-caret`, `.folio-auth-line`); no Twig macro `folio_ink_marks(seed,count,area)` (the 50-mark generator output is hard-coded in _auth_pattern.twig l.13-61; fixed seed, no generator code in repo); no skip link (grep "skip" in templates/css: nothing); no `.folio-stacked-bar`/size bars, no `.folio-stat` line, no sticky inverse "unsaved" bar class (only `.folio-translations-save-all` margin), no dashed "implied" tick, no `.folio-switch` row styling for matrix rows (switch exists generically l.1160-1419 `.folio-switch`, `.rbac-switch` in assets/rbac/rbac.css), no grid/list view-toggle segmented for assets, no `.folio-dropzone` for library.
Icons in icons.md missing from assets/icons.svg: ui-undo, ui-refresh, ui-arrow, ui-filter, ui-clock, ui-globe, ui-desktop, ui-shield, mark-caret/pilcrow/brace/block/cursor/squiggle/plus/ring (+ mark-cursor, mark-dot not listed in the diff because regex; dot exists only as fg-dot).
admin.css test constraints (tests/Assets/AdminCssTest.php): every `var(--x)` used must be defined (test_every_token_used_is_defined, l.34); l.56 requires `.folio-card` selector to still exist; l.217 requires `.folio-card`, `.folio-table-wrap`, `.folio-panel`, `.folio-asset-panel`, `.folio-assets-toolbar`, `.folio-revisions`, `.folio-share` each to keep `border:1px solid var(--c-border)` + radius/shadow; l.242 pins `.folio-auth-hello .folio-logo-f { stroke: var(--ink-deep); }`; l.111 pins `:where(.folio-shell, .folio-drawer-body, .folio-auth)` as control roots; l.202 pins badge colours; l.209-211 row-actions.

---------------------------------------------------------------------------------------------------
## 1. DASHBOARD

### Current
- Route GET {prefix} -> AdminController::index (src/Http/AdminController.php l.103-141). Renders templates/admin/dashboard.twig (24 lines): `.folio-page-notices` (raw `dashboard_notices`) l.6, `.folio-card-grid` of `.folio-card` (every type, identical `ui-file` icon, label + "N records") l.7-14, Assets card l.15-21, review include l.23.
- Counts: `count($this->dm->queryType($key)->get()->items())` per type (l.120-123) - loads every record (perf smell; `QueryBuilder::aggregate()->count()` exists: packages/data/src/QueryBuilder.php l.422, AggregateQuery::count l.81).
- Review section: reviewSection() l.156-226 -> partials/dashboard-review.twig (33 lines; `#folio-review`, hx-get `?review=1` every 5s, `.folio-review-jobs`, `.folio-review-type` + type_icon macro l.23, `.folio-review-list`, `ai-item-progress.twig`). Partial request path l.110-120.
- Notices: `#[DashboardNotice]` (src/Extension/DashboardNotice.php; Customizations::dashboardNotices) returns raw HTML. Only real user: src/Rbac/Types/RoleRules.php l.69 -> NewPermissions::dashboard() -> src/Rbac/templates/new-permissions-dashboard.twig (`.folio-notice.is-warning.rbac-new-dashboard`, link to {prefix}/rbac_role).
- Layout blocks available: `breadcrumb`, `title_lead`, `title_after`, `subtitle`, `actions` (templates/admin/_layout.twig l.129-148).

### Board shows, missing today
Dashboard.dc / HolzDashboard.dc:
1. Subtitle "What needs you, what changed, and where to start."; header actions "View site" (ghost, external icon) + "New page" (split/primary).
2. Ink band (150-170px): "Every word in its place." + mono line "preflow.dev · 2 pages · 6 references · 6 assets▍" with marks on the right half.
3. "Needs you · N open" cards: (a) "Folio page has changes that are not live - 2 revisions since the last publish" (b) "Not-found addresses to check" (c) Holz: "2 neue Berechtigungen in Rollen", "2 Einträge warten auf Prüfung". Each links to where to act; section hidden when empty.
4. "Content" compact list: type icon (family tint), label, count + meta, "+" quick create. Holz: grouped by nav section in cards (`{g.label}` -> items `{i.label} {i.n}`).
5. "Recent changes" (type icon, title, what changed, when) + "All activity" link.
6. Footer lines: "Caches hold 9 MB, mostly compiled Twig templates. Open cache" and tip "press G then P to jump to Pages".
7. Sidebar bits visible on boards (already 0.73-0.75 shell): search entry "Search or jump to… ⌘K" (proposal), unchanged.

### Data / API
| Piece | Source | Status |
|---|---|---|
| Site name, env | `folio_site` template global (already in _layout) | OK |
| N pages / references / assets | catalog counts per type: `TypeCatalog::all()` + `QueryBuilder::aggregate()->count()`; assets: `MediaLibrary::query(...)->total` (media/src/MediaLibrary.php l.681, AssetPage->total) | OK (swap items() loading for count) |
| Type icon/family/glyph | `TypeListing::$family`, `::glyph()` (src/Content/TypeListing.php l.43,51), macro partials/_type_icon.twig | OK |
| Quick create "+" | route {prefix}/{type}/new; permission `{type}.create`, singleton excluded (`TypeListing::$singleton`) | OK |
| Changes not live (N pages) | `Publisher::states($type)` (packages/publishing/src/Publisher.php l.147) -> PublishState; list screen already derives statusCounts (ListView.php l.30,125 ListQuery::STATUSES) | OK (one snapshot query per publishing type) |
| "2 revisions since last publish" | `Publisher::changes($working)` l.115 (changed fields) and `Publisher::revisions($type,$id)` l.461 (Revision: seq, changed[], createdAt, createdBy; publishing/src/Revision.php) | OK per record; per-record only |
| 404 addresses to check (count) | `Preflow\Folio\Redirects\NotFoundEntries::visible()` (src/Redirects/NotFoundEntries.php l.136, hit>=2 and not dismissed, most hits first). Note: `visible()` is NOT on the core `NotFoundList` interface (packages/routing/src/Redirect/NotFoundList.php has forget/forgetUnder only) | OK for Folio (count(visible())) |
| Needs-you: new permissions | `NewPermissions::count()` (src/Rbac/NewPermissions.php; only for rbac_role.edit; null otherwise) / `KnownPermissions::fresh()` (src/Permissions/KnownPermissions.php l.63) | OK |
| Needs-you: entries waiting for review (holz "von Mitgliedern eingereicht") | members approval: tests/Members/MemberApprovalTest.php exists; list "unchecked/awaiting" counts via nav badges (`AdminNav::badge`, NavBadge hook) | OK-ish: only through NavBadge/`AdminNav` counts; no generic "needs you" provider |
| Needs-you: review queue (unchecked machine translations) | existing `reviewSection()` / `Preflow\I18n\Machine\ReviewIndex` | OK |
| **Structured "Needs you" cards from extensions** | `#[DashboardNotice]` only returns raw HTML (tests/Extension/NoticeHooksTest.php l.84-86 pin raw HTML above `folio-card-grid`) | **GAP: needs a structured extension hook** (e.g. `#[DashboardItem]` -> {title, text, url, tone, icon}); keep DashboardNotice for compat |
| Recent changes feed (all types, who/what/when) | per-record only: Publisher::revisions (who/when/changed). Non-publishing types have no history. `DataManager` writes updated_at where the model has it (data/src/DataManager.php l.560 mentions updatedAt/updated_at) but there is no cross-type "latest N changes" query and no activity log. Audit/ ("AuditTargets") is the SEO audit, not activity | **GAP: needs new core API** (cross-type recent-changes source: union of ShadowTypes::revisions tables + updated_at; or an activity log) |
| Cache total for footer | CacheRegistry stats (`packages/core/src/Cache/Bytes`, `CacheSource::stats()`; see Cache section) | OK |
| Holz "Mitglieder/Bestellungen" stats | those are catalog types; counts OK | OK |
| View site link | `FrontendResolver`/app.url (`siteUrl` ctor arg AdminController l.95) | OK |
| G then P shortcut hint | admin.js initShortcuts (l.~3100 shortcutsDialog) exists; hint text only | OK |

### Tests pinning today's structure
- tests/Integration/FolioAppTest.php l.61-62 (`folio-card-grid`, `folio-card-label`), l.377 (`folio-empty`).
- tests/Extension/NoticeHooksTest.php l.84-86 (`strpos(crm-dash) < strpos(folio-card-grid)`), l.69/117/127; test_notices_render_on_the_types_list_and_the_dashboard, test_dashboard_notice_only_for_types_the_user_can_view.
- tests/Integration/PublishingAppTest.php l.69 (partial has no `folio-card-grid`), l.137 (`folio-review` follows `folio-card-grid`), l.63 (`<section id="folio-review"... hx-get="/folio?review=1" hx-trigger="every 5s"`), l.162 (`<ul class="folio-review-jobs"><li><strong>Posts · German</strong>...folio-badge">Waiting`), tests test_dashboard_* (l.40-200).
- tests/View/TypeIconTemplateTest.php l.87-92 (regex of `<h3 class="folio-review-type"><span class="folio-type-icon" data-family="reference" ...>`).
- tests/Rbac/NewPermissionsTest.php (dashboard line), RolesInFolioTest etc. go through `/folio`.
- tests/Assets/AdminCssTest.php l.56, l.217 (`.folio-card`).
- tests/Integration/AllScreensTranslatedTest.php (every new string needs de + fr; also AdminTranslationTest ENGLISH_* lists).

---------------------------------------------------------------------------------------------------
## 2. ASSETS: grid / list / bin

### Current files
- GET {prefix}/assets -> AssetLibraryController::index (src/Http/AssetLibraryController.php l.77) -> renderIndex l.603-679 -> templates/admin/assets/index.twig (82 lines) + _grid.twig (14) / _list.twig (17) / _pick.twig / _stack.twig; picker mode (`picker=1`) swaps layout to _drawer_layout.twig (index.twig l.1 `layout|default`), `_pick.twig` Select button carries data-* of AssetCard (src/Media/AssetCard.php).
- Toolbar today: GET form (search, kind `<select>`, extra filters, "Unused only" checkbox only if cleanup enabled, hidden view, Filter submit), view links as `btn-ghost` (l.43-47), Bin ghost link l.48, upload form with native file input + Upload (l.51-58). Count `<p class="folio-help folio-assets-count">` l.67; pagination as numbered `btn-ghost` links l.73-79 (not `.folio-pager`).
- Bin: GET {prefix}/assets/bin -> bin() l.226 / renderBin l.249 -> templates/admin/assets/bin.twig (47 lines; table, Restore button per row, `folio-asset-list`).
- Upload POST {prefix}/assets -> upload() l.82; JSON upload route (asset field drop) in UploadController.php (56 lines).
- JS: admin.js (bulk bar code at l.2714+ is list-only; no asset bulk; no library drop zone; `Folio.chooseAssets` picker l.222).
- Item shape: AssetLibraryController::item() l.1028-1054 (id,label,name,kind,mime,size,width,height,url,thumb,alt,card,title,focal_x/y,poster,created_at,copy) + `copies` bubbles (MediaLibrary::copyCounts l.406).

### Board shows, missing
Assets.dc: header "Assets" + subtitle "6 files", header actions Bin (secondary, with icon) + Upload (primary); toolbar: search, kind segmented (All / Images / Videos / Documents - today a `<select>`), "Unused only" (exists, behind cleanup), "Missing alt text · 6" (proposal), grid/list toggle as segmented with icons; 12px cards, 16:10 thumbs, mono filename, meta "1280×800 · 48 KB · used 1×", selected = accent border + check; last grid cell = dashed drop zone "Drop files to upload / or click to choose"; selection bar "1 selected - Edit alt text - Download - Move to bin". Bin screen not on a board (follow list pattern: table + Restore).

### Data / API
| Piece | Source | Status |
|---|---|---|
| File count subtitle | `AssetPage->total` | OK |
| kind filter | `MediaLibrary::query($kind,...)` | OK |
| Unused only | `AssetCleanup::isEnabled()/candidates()` (packages/media/src/Cleanup/AssetCleanup.php l.44,63) - page is slow on big libraries (controller docblock l.670+) | OK (cleanup-gated) |
| Dimensions, size, mono name | Asset::width/height/size/originalName | OK |
| **"used N×" per card** | `AssetUsage::of($id)` (packages/media/src/Usage/AssetUsage.php l.39) is one scan per asset (FolioRecordReferences walks every type with LIKE queries) | **GAP: needs batch usage counts API** (e.g. `AssetUsage::counts(list $ids)`), else N scans per page of 50 |
| **"Missing alt text · N" count + filter** | alt is a locale map on the asset record (`Asset::altFor($locale,$default)`); `MediaLibrary::query` filters only kind/search/extra config filters/private; `MediaLibrary::all()` l.658 exists (loads all) | **GAP: needs new core API** (query filter `missing_alt` + count, locale-aware) |
| Copy/stack bubbles | copyCounts | OK |
| Alt warning on card | `AssetCard::of()['warn']` | OK |
| Grid/list toggle persisted | currently URL `view=`; density uses localStorage (`folio.density`, layout l.24) | OK |
| Selection bar: Move to bin | per-asset POST `/assets/{id}/delete` (destroy l.178) only | **GAP: needs bulk endpoint** (bulk delete with usage refusal per asset) |
| Selection bar: Download (multiple) | none; single download = `MediaLibrary::originalUrl()` | **GAP: needs bulk download (zip) or per-file only** |
| Selection bar: Edit alt text (bulk) | none; single save POST `/assets/{id}`; `MediaLibrary::setAltIfUnchanged` l.852 exists (AI path) | **GAP: needs bulk alt editor endpoint/dialog** |
| Drop zone upload | upload() accepts multi-file form POST; UploadController JSON route exists for the field drop (`data-upload-url` in fields.js l.672-704) | OK for JS-driven drop (reuse JSON route); page without JS keeps file input |
| Bin: days left / binned_at | `MediaLibrary::binned()` l.720, `Asset::$binnedAt`, `bin_days` already passed | OK |
| Type icon for asset family | TypeFamily::Asset exists (admin.css `data-family="asset"`) | OK |

### Tests pinning today's structure
- tests/Integration/AssetLibraryAppTest.php l.117 (`<div class="folio-table-wrap">\s*<table class="folio-table folio-asset-list">`), tests of list view.
- tests/Http/AssetBinTest.php l.77 (same regex for bin), l.121/135/142 (`name="unused"`, `name="unused" value="1"`, `... checked`), Restore/empty/no-bin tests.
- tests/Http/AssetCopiesTest.php l.46-50 (`<span class="folio-asset-stack" title="2 copies">2</span>`), l.180, l.207-224 (`folio-asset-copy-mark`, `data-asset-id=...`).
- tests/Integration/AdminTranslationTest.php l.221 (`folio-asset-list` in list view), l.168 ENGLISH_PART2 list.
- tests/Assets/AssetCardClassesTest.php (asset-field card classes, keep), tests/Http/UploadControllerTest.php, tests/Integration/AssetLibraryRelease2Test.php.
- AdminCssTest l.217 requires `.folio-assets-toolbar` to stay a bordered surface (or drop it from the list in the same change).
- tests/js/choose-assets.test.mjs (picker contract: data-folio-pick, data-id/thumb/label/meta/warn/focal).

---------------------------------------------------------------------------------------------------
## 3. ASSET DETAIL (edit)

### Current files
- GET {prefix}/assets/{id} -> AssetLibraryController::panel l.135 -> renderPanel l.719-757 -> assets/panel_page.twig (11 lines: rail shell, back button action, `_tabs.twig`) -> assets/panel.twig (170 lines).
- panel.twig structure: `.folio-asset-panel` > notice/alert/note l.2-4; copy-of banner l.5-20 (Remake form with data-confirm); `.folio-asset-columns` l.21: left column = `.folio-asset-preview` (focal picker `data-folio-focal` l.23-29, file meta l.34, download-original l.35-37, `.folio-swatches` l.38-50) then `.folio-asset-versions` (file/version line, replace form with native file input l.56-60, earlier versions list with Open/Restore/Delete l.63-86); right column = AI translate dialogs l.91, `form.folio-asset-form` (alt per locale, title, poster for video, extra fields, Save) l.94-121; below: `.folio-asset-usage` l.124-139, `.folio-asset-copies` l.140-160, delete form l.161-169 (btn-secondary data-tone=danger when bin on; btn-danger when not).
- Tabs: assets/_tabs.twig (7 lines, `.folio-tabs` links: Details / Edit image only when editable and assets.edit). No Versions tab.
- Edit image tab: GET {prefix}/assets/{id}/edit -> editor() l.331, renderEditor l.473-505 -> assets/edit.twig (117 lines; `section.folio-image-editor`, rotate/flip forms, Cropper stage, ratio select, crop form, formats list with `cut` thumbnails and Adjust/Save cut/Reset). JS: assets/image-editor.js (127), image-editor.css (54), vendor cropper.
- Focal point JS: admin.js `[data-folio-focal]` (AdminJsTest l.195), hidden `focal_x/focal_y` inputs panel.twig l.97-98.
- Helpers: swatches() l.987 (uses packages/media/src/Color/ColorRules::data), versionRow() l.969, usageRow() l.849, item() l.1028, copyRow l.799, aiTranslate l.767.

### Board shows, missing (AssetDetail.dc, COMPONENTS §4, SCREENS "Asset detail")
1. Header: title "preflow.webp" with breadcrumb "Assets", actions Download (secondary w/ icon) + Save (primary); tabs Details / Edit image / **Versions (count badge "1")** as one `.folio-tabs` (Versions is a tab now, not a block in Details).
2. Left: large image on surface-2 panel with focal dot ("Click the image to set the focal point. It stays visible in every crop below."), **crop preview row (16:9 / 4:3 / 1:1 / 9:16) below it** (proposal).
3. Right panel: Alt text with warning line "Missing - the SEO audit flags this." (warn icon), Title, then file facts definition list: Type (image/webp), Size "48 KB · 1280×800", File "/media/2026/11/…webp" + copy button; **Colours · click to copy** swatches (hex chips); **Used in · N** list with type icons ("preflow.dev - Reference"); **Replace file** dashed drop zone "Keeps alt text, focal point and usage. The current file stays as version 1."; **Move to bin** as text-only danger link at the bottom (deviation row l.53).
4. Versions tab: version rows "2 Current · 1280×800 · 48 KB", "1 Replaced - Restore" (timeline-like; LibOverlays "asset-versions").

### Data / API
| Piece | Source | Status |
|---|---|---|
| Alt text empty warning | `AssetCard::of()['warn']`; SEO audit flag exists (audit alt checks in src/Audit/AuditTargets.php, seo-audit) | OK |
| File type/size/dimensions/path | Asset::mime/size/width/height/path; `MediaLibrary::url()` | OK |
| Delivered vs original size | `deliveryAsset()`, `isEdited()` (already in panel) | OK |
| Colour swatches | `ColorRules::data($asset)` dominant + palette hex/rgb (packages/media/src/Color/ColorRules.php; `MediaLibrary::extractColors` l.2479) | OK (already rendered) |
| Focal point | `Asset::focalX/focalY`, saved via POST /assets/{id} | OK |
| Crop previews 16:9/4:3/1:1/9:16 | `MediaLibrary::boxPlan/boxFile/formatRegion` (l.1016-1125) + formats list `formatRow()` l.507 (per-preset `cut` image); preset set comes from config formats, not fixed 4 ratios | OK-ish: board's fixed four ratios would need either config formats named accordingly or CSS object-position previews using focal point only (no new API needed for CSS crop) |
| Used in with type icons | `AssetUsage::of()` -> `AssetUsageEntry{label,url,source,note,blocksDelete}` (packages/media/src/Usage/AssetUsageEntry.php). It carries **no type key/label/family** (FolioRecordReferences builds entry from `$listing->key` but drops it, src/Media/FolioRecordReferences.php l.84-90,112-118) | **GAP: needs new core field** (e.g. `AssetUsageEntry::$kind`/`$type` = type key + `$typeLabel`) so the template can pick family/icon and print "Reference"; Folio resolves family from TypeCatalog |
| Versions (who/when/size) | `MediaLibrary::versions($id)` l.1568 -> AssetVersion{number,path,mime,size,width,height,meta,createdAt,createdBy} (packages/media/src/AssetVersion.php); controller versionRow() has no "by" name (createdBy is an auth id; name resolution for Revisions exists in editors: tests/Integration/RevisionAuthorTest.php) | OK for list; author name = reuse revision-author resolver |
| "Current · 1280x800 · 48 KB" row | current Asset + `$raw->version` | OK |
| Replace drop zone | POST /assets/{id}/replace (replace() l.281, `MediaLibrary::replace` l.1559, keeps alt/focal) | OK (JS drop is new UI only) |
| Replace keeps usage + versions | versions kept by replace(); `deleteVersion`, `restore` exist | OK |
| Download | `originalUrl()`/`url()`; panel has "Download original" only when edited | OK |
| Move to bin | POST /assets/{id}/delete; `blocked` computed from usage (panel l.163) | OK |

### Tests pinning today's structure
- tests/Integration/AssetDetailPageTest.php l.75 (list page has no `folio-asset-panel`), l.95-104 (`class="folio-tabs"`, `folio-asset-columns`, `data-folio-focal`, `folio-swatches`, order `folio-asset-columns` before `folio-asset-usage`), l.245/268 (tabs on edit page).
- tests/Integration/AssetLibraryRelease2Test.php l.72/151 (`folio-asset-panel`), l.95-96 (`folio-asset-versions`, `<li class="folio-asset-version">\s*<span class="folio-asset-version-number">v1</span>`), l.223 (`class="folio-asset-preview-image" src=...`), l.234/244 (`folio-swatches` present/absent).
- tests/Integration/AssetLibraryAppTest.php l.126 (`data-folio-focal`), l.145 (`folio-asset-panel`), l.157 (`Used in 1 record`).
- tests/Http/AssetCopiesTest.php l.76-82 (`folio-asset-stale`, `data-copy-id`, no "Used in"), l.193 (`<p>Used in 1 record:</p>`), l.199 (refusal text), l.219-224.
- tests/Integration/AdminTranslationTest.php l.222 (`folio-asset-version-list`).
- tests/Integration/AssetEditorTest.php (edit screen), tests/Http/AssetControllerTest.php.
- tests/Assets/AdminJsTest.php l.195 (`[data-folio-focal]`), tests/View/TokensTest.php (focal/swatch tokens).

---------------------------------------------------------------------------------------------------
## 4. TRANSLATIONS

### Current files
- GET {prefix}/translations -> TranslationsController::index l.43 -> screen() l.56-112 -> templates/admin/translations/index.twig (105 lines): `.folio-toolbar` GET form (group `<select>`, language `<select>`, search, Search submit) l.6-34; AI translate dialog include l.31-33; notes l.35-40 ("choose_target", "site_texts"); empty p l.41; hidden `form[data-translations-save-all]` (class `folio-row-actions folio-translations-save-all`) l.46-54; `table.folio-table.folio-translations` Key (`<code>`+ "changed" help) / Delivered / Override per-row `<form>` with its own Save + Reset buttons l.55-90; `.folio-pager` l.91-103. Subtitle block already used l.4.
- POST save/reset/saveAll -> TranslationsController l.145,154,166; store() l.201.
- JS: admin.js initTranslationsSaveAll l.2132-2171 (changed rows collected from `input[data-ai-path], textarea[data-ai-path]`; shows the save-all form; **native beforeunload** l.2163-2169); ai-translate*.js for the AI fill; CSS `.folio-translations input/textarea` full width l.1172, `.folio-translations-save-all` l.840.

### Board shows, missing (Translations.dc)
Subtitle "Change the admin's interface texts for one language. Reset brings the delivered text back." (exists as `folio.translations.intro`); toolbar "Group [select]" with label inside, language **segmented (DE/EN/FR)**, search, **"Changed only · 1" toggle**; fixed-layout table: Key (mono, group prefix subtle e.g. `folio.account.` + rest), Delivered (**`:min` placeholders as violet mono tokens**), Your text (inline input; **changed rows warning-soft + reset button inside**; no per-row Save); **sticky inverse bar "1 unsaved change · in English · Discard · Save all"**.

### Data / API
| Piece | Source | Status |
|---|---|---|
| Key / delivered / site file | `EditableTexts::rows($group,$locale)` (src/Translations/EditableTexts.php l.106: key, delivered, site) | OK |
| Placeholder tokens (`:min`) | `EditableTexts::placeholders($key,$locale)` l.70 (already public, used for validation) | OK (render only) |
| Changed rows + count | `StoredOverrides/OverridesCache::all($locale)` already merged as `row['override']` in screen() l.77 | OK (count and "changed only" = new GET param filter in controller) |
| Group prefix split | derive from key string | OK |
| Language list | `EditableTexts::locales($group, interface)` l.52 + `LocaleNames::native` | OK |
| Unsaved count / Discard | JS only (changed() in initTranslationsSaveAll) | OK (UI) |
| Unsaved leave dialog instead of native | `Folio.unsaved.onLeave/isDirty` hooks exist (admin.js l.1660-1666) and `askLeave()` -> `folioConfirm` l.1760; translations does not register | OK (wire into guard; needs JS test) |

### Tests pinning
- tests/Integration/TranslationOverridesTest.php l.149-150 (`action="/folio/translations/save-all"`, `~data-translations-save-all hidden>~`), l.183 (`name="text" data-ai-path="folio\.common\.save" data-unsaved value="Sichern &lt;b&gt;jetzt&lt;/b&gt;">`), l.186 (`data-translations-save-all hidden` absent when refused), l.436 (`<select id="folio-translations-locale"`), l.99-114 reset, l.281-294 permissions; tests at 235-263 (cache key `folio-translations`).
- tests/Assets/AdminJsTest.php l.274-282 (test_translations_save_all_posts_changed_rows_and_guards_them: strings `function initTranslationsSaveAll()`, `[['keys[]', el.getAttribute('data-ai-path')], ['texts[]', el.value]]`, `el.value !== el.defaultValue || el.hasAttribute('data-unsaved')`, `if (submitted === saveAll) { return; }`, `initTranslationsSaveAll();`) - a rewrite must keep or change these literals.
- tests/Integration/AllScreensTranslatedTest.php (de/fr screen walk), tests/Translations/StoredOverridesTextTest.php (unaffected).
- tests/Assets/AdminCssTest.php l.?? only generic.

---------------------------------------------------------------------------------------------------
## 5. REDIRECTS (list, not-found tab, form/drawer, removal dialog)

### Current files
- RedirectsController (src/Http/RedirectsController.php, 549 lines): index l.66 (tab via `?tab=not-found`), create l.87, store l.103, edit l.111, update l.133, destroy l.143, dismiss l.158, targets l.177 (JSON for page search `{prefix}/redirects/_targets?q=`), rows() l.430, missing() l.473, form() l.497.
- templates/admin/redirects/index.twig (111 lines): subtitle block l.5; `.folio-tabs` (2 links, no badge) l.11-14; not-found table (address `<code>`, language, hits, first/last hit, Redirect btn + Dismiss form) l.15-49; redirects tab: search toolbar + "New redirect" btn l.51-61, table (Address `<code>` + area note, Language, Target (label+type+url / "No target"/"Broken" badges `is-incomplete`), Kind, Answer, Hits, Created, Edit + Delete form with data-confirm danger) l.68-107.
- templates/admin/redirects/form.twig (47 lines, **full page**, extends _layout, not drawer): address input, area checkbox + one/prefix radios, target text input with `<datalist>` filled by admin.js (`data-redirect-targets`, initRedirectTargets), 301/302 plain radios (or "automatic status" help), Cancel/Save.
- Removal dialog: JS-built `dialog.folio-removal-dialog` in admin.js `removalPrompt()` l.1155-1300 (class `.folio-removal-line/-choices/-option/-search/-results/-picked/-url/-error/-footer`, data-removal-* hooks), strings `redirects.prompt.*` via folioT; used by delete plan (l.1087) and unpublish (l.1143). Confirm button is `.btn-danger`.
- CSS: `.folio-removal-*` rules in admin.css; no `.folio-drawer` use for redirects (drawer = `openDrawer(url)` iframe, `Folio.openDrawer`, `_drawer=1`, `_drawer_layout.twig`, `drawer_saved.twig` posts `{source:'folio-drawer', type, id}` to parent; used by relation "New" and asset picker).

### Board shows, missing (Redirects.dc, LibOverlays.dc, SCREENS)
1. Tabs "Redirects / Not found [new badge]" with count on Not found.
2. Table "From → to" in mono, language "Any language" under the address, Kind ("Handmade"/"Automatic"), Code (301), Hits, Created; header "New redirect" button with plus icon.
3. New/Edit **in a drawer**: title "New redirect"; "Old address" with `/` prefix affix and `*` hint; help "A path such as /old-page. Queries are ignored."; Language select ("Any language"); **"Send visitors to" segmented (A page / An address)** with page search (shows "Folio /folio" pick); **"Answer" as radio cards "301 · Moved for good - Search engines move the ranking over." / "302 · For now - The old address comes back later."**; footer Cancel / Save redirect. Error texts stay `redirects.errors.*` (lang/en/folio.php l.1065-1077).
4. Removal dialog restyle: 440px, title "Delete “Folio”?", subtitle "Send its old links to…", **radio cards** (Leave them as not found / A page on the site -> search + "Now: Home" / A URL or a path), footer Cancel / Delete (danger). LibOverlays also shows new `.folio-confirm` (data-confirm): "Delete this redirect?" + mono item "/docs/packages/blade", Keep / Delete (note: `folioConfirm` already exists, title/message/items/confirm/cancel/tone/icon, admin.js l.819-960).
5. Not-found rows: "→ add a redirect" action, Dismiss (exists).

### Data / API
| Piece | Source | Status |
|---|---|---|
| Entries, hits, created, kind, status, language | `RedirectEntries::all()` (src/Redirects/RedirectEntries.php; core packages/routing/src/Redirect/{ListedRedirectStore,Redirect}) | OK |
| Not-found count for tab badge | `NotFoundEntries::visible()` count (src/Redirects/NotFoundEntries.php l.136) | OK (Folio class; not on core interface) |
| "A page" target + page search | `RedirectsController::targets()` JSON, `LiveRecords`, `FolioRedirectTargets` (src/Redirects), `RemovalTarget::parse` | OK |
| Target "Now: Home" label/url | `LiveRecords::find`, `RecordLabeler`, resolver->urlFor (already in rows()) | OK |
| Visible address / area / mode | `OldAddresses`, `VisibleAddress`, `AddressTable`, `RedirectEntries` | OK |
| Language options | `LocaleNames`, `Locales` | OK |
| Drawer save flow | needs redirects form served with `_drawer_layout.twig` + `drawer_saved.twig` and an `openDrawer` trigger from the list; drawer mechanics currently keyed to relation fields ({type,id} message) | OK-but-new UI wiring (no core API) |
| 301/302 copy | existing keys `folio.redirects.permanent/temporary`; board adds explanations "Search engines move the ranking over." / "The old address comes back later." | new lang keys only |

### Tests pinning
- tests/Http/RedirectsControllerTest.php: l.142 (`href="/folio/redirects/new"`), l.143 area (`href="/folio/redirects?tab=not-found"`), l.178 (`href="/folio/redirects/new?not_found=<id>"`), l.176-180 (`/de/missing.*?Deutsch.*?<td>3</td>` - pins `<td>3</td>` hits cell), `action="/folio/redirects/not-found/<id>/dismiss"`, l.187-189 (`action="/folio/redirects"`, `data-redirect-targets="/folio/redirects/_targets"`), l.512 (`<code>preflow migrate</code>`), l.522, l.587, l.600 (nav active); texts 'Broken', 'No target', '/de/left'; ordering tests (strpos on rows).
- tests/Integration/RedirectsTest.php, AutomaticRedirectsTest.php (HTML only via flows).
- tests/js/removal-prompt.test.mjs (hooks: `dialog[data-removal-dialog]`, `input[data-removal-search]`, `button[data-removal-pick]`, `input[data-removal-url]`, `[data-removal-error]`, `button[data-removal-confirm]`, `button[data-removal-cancel]`) and tests/Http/RemovalPromptFormTest.php l.129 (`data-removal-addresses="2"` on the unpublish form) - radio-card restyle must keep these hooks.
- tests/Http/ListLanguagesTest.php l.75 (no `folio-badge is-incomplete` pills) unrelated.

---------------------------------------------------------------------------------------------------
## 6. CACHE

### Current files
- GET {prefix}/cache -> CacheController::index (src/Http/CacheController.php; POST clear/opcache-reset/pages/refresh/pages/refresh-all). Template templates/admin/cache.twig (76 lines): subtitle l.4; toolbar with Clear all form (`.folio-list-toolbar`) l.6-12; note l.13; table Cache (label + `<code>key</code>` + note) / Files / Size / per-row Clear form l.14-34; page cache section (only if `pages` Refresher bound; Refresh everything, refresh-by-URL form, queued note) l.35-54; OPcache section (reset button, table memory used/free/scripts/hit rate or off notice) l.55-75.

### Board shows, missing (Cache.dc)
Header action "Clear all…" (confirm first), **big total "9.0 MB / in 489 files" + stacked bar by cache (Twig templates / Page cache / CSS and JS / Everything else)**, table with **size bars** per row and per-row Clear (disabled look at 0 B), **Page cache and OPcache as two cards** with explanations; page cache card: "Refresh page" with `/` input + "Refresh everything in the background"; OPcache card "Reset OPcache".

### Data / API
| Piece | Source | Status |
|---|---|---|
| Per-cache entries + bytes | `CacheRegistry::all()` -> `CacheSource::stats()` (`entries`, `bytes`, `note`; packages/core/src/Cache) | OK |
| Total files / bytes | sum in controller; `Bytes::human()` | OK |
| Stacked bar segments, size bars | percentages from raw bytes (controller currently passes only the human string `size`; raw bytes available from `stats()->bytes`) | OK (pass raw bytes) |
| Disabled Clear at 0 | entries==0/bytes==0 | OK |
| OPcache stats | `OpcacheStatus::stats()` (memory_used/free, scripts, hit_rate) | OK |
| Page cache | `Refresher::refresh/refreshAll/queued` | OK |
| "Clear all…" asks first | `data-confirm` + `folioConfirm` (admin.js l.968-1050; no window.confirm) | OK |
| Footnote "mostly compiled Twig templates" (dashboard footer) | derived from largest source | OK |

### Tests pinning (tests/Http/CacheScreenTest.php)
l.77 (`<h1>\s*Cache\s*</h1>`), l.78, l.79 (`Blocks.*?<td>1</td>\s*<td>{human(2000)}</td>` - pins the Files and Size cells as consecutive `<td>`), l.80-85 (`<form method="post" action="/folio/cache/clear">`, hidden `key` and `all` inputs, `name="_csrf_token"`, 'Clear all'), l.87-90 (human sizes, `<td>42</td>`, `97.5%`), l.91-93 (`<form method="post" action="/folio/cache/opcache-reset">`, 'Reset OPcache', no 'OPcache is off'), l.95 (Settings nav active), l.104 ('OPcache is off'), l.130-134 ('Unknown cache' plain 404 body), l.159/170 (flash texts). Also tests/Integration/TranslationOverridesTest.php l.263 (`<code>folio-translations</code>` in the cache table).

---------------------------------------------------------------------------------------------------
## 7. ROLES (list + role form + permissions matrix)

### Current files
- Roles list = generic list.twig/_list_results.twig of type `rbac_role` (src/Rbac/models/rbac_role.json: fields name, label, population, permissions(rbac_permissions), seeded_permissions; section settings, icon ui-users). Notice above list: NewPermissions::notice() rendered from src/Rbac/templates/new-permissions.twig (`section.folio-notice.is-warning.rbac-new`, per-group "Add to role…" select + Dismiss; GrantOnce: src/Rbac/NewPermissionsScreen.php (169 lines; PATH, grant/dismiss POST)). Sidebar badge via NewPermissions::count().
- Role form = generic AdminController::form() / templates/admin/form.twig for type rbac_role; the permissions control is `PermissionsFieldType::renderEditor()` (src/Rbac/Fields/PermissionsFieldType.php l.46-139, **HTML built in PHP strings**, not Twig): `fieldset.rbac-permissions[data-rbac-permissions]` with legend, hidden empty value, "Everything" switch row (`label.rbac-everything` + `input.rbac-switch-input[role=switch][data-rbac-everything]`), unknown note, `div.rbac-rest[data-rbac-rest]`, kept hidden inputs `[data-rbac-kept]`, `table.rbac-matrix` (Type + 5 verbs View/Create/Edit/Publish/Delete + "All" column; section rows `tr.rbac-section`, rows `tr.rbac-row[data-rbac-row]`, cells `.rbac-cell`, `.rbac-cell-all`, "–" `.rbac-none`), other permissions as `.rbac-others` > `.rbac-group[data-rbac-group]` > chips `.rbac-chip`, group "All" `.rbac-group-all`. layout() l.182-237 builds rows by section from TypeCatalog + PermissionRegistry. Delegation: `PermissionCoverage::held()` disables boxes you cannot grant (`data-rbac-denied`).
- JS: assets/rbac/rbac.js (60 lines: lock/unlock when Everything or an All box is on; DOM hooks `data-rbac-everything/all/row/group/rest/kept`); CSS assets/rbac/rbac.css (140 lines, tokens only). Loaded through `FieldType::assets()` = ['rbac.css','rbac.js'].
- Staff/Roles field (assigning roles to staff): src/Rbac/Fields/RolesFieldType.php (`fieldset.rbac-roles`, chips).

### Board shows, missing (Roles.dc, SCREENS)
Topbar: breadcrumb "Einstellungen · Rollen", title "Member" + badge "Mitglieder" + badge "2 neue Berechtigungen", **"3 Änderungen ungespeichert"** + Abbrechen + Speichern. Left card: Bezeichnung, **Schlüssel (mono, muted; "Für Code und Importe; selten ändern.")**, Personenkreis (Mitglieder), **"Alles erlauben" switch** ("Jede Berechtigung, auch die künftiger Erweiterungen. Nur für Admins."), **Legende** (Erteilt / Folgt aus einer anderen Berechtigung (dashed tick) / "–" Gibt es für diesen Typ nicht). Right: "Berechtigungen" header with **"Nur neue (2)" filter**, matrix "Typ | columns… | Alle" grouped by nav section with group sums ("· 4"), **type icons** per row, **per-row switch replacing the "Alle" column**, **column headers toggle the whole column**, dashed implied ticks, "–" n/a, **"Neu" badge on new permissions**, footnote "Bearbeiten schliesst Ansehen ein; Veröffentlichen schliesst Bearbeiten ein. Spaltenköpfe schalten die ganze Spalte."

### Data / API
| Piece | Source | Status |
|---|---|---|
| New-permission detection for "Neu" + "Nur neue (N)" | `KnownPermissions::fresh()` (src/Permissions/KnownPermissions.php l.63), via `NewPermissions::fresh()`; PermissionsFieldType has no dependency on it (ctor: PermissionRegistry, TypeCatalog, ?PermissionCoverage, ?DisplayLabels) | OK (inject; gate on rbac_role.edit like NewPermissions::forManager) |
| Sections, type rows, labels, type icon/family | `TypeCatalog::all()`, `TypeListing::$family/glyph`, `DisplayLabels`, `AdminNav::sectionOrder/sectionLabel` (already used) | OK |
| Group sums ("· 4") | count of granted names per section | OK (compute) |
| Header unsaved count | JS only | OK (UI) |
| Role key (`name`) mono + help | existing field `name` (rbac_role.json) | OK (template) |
| Layout: left card + right matrix on one screen | role form uses the generic form.twig; needs a type-specific form template/override (`overrides->resolve(...)` / extension Screen mechanism: src/Override, src/Extension) | OK (Folio-side) |
| **"Implied" ticks (Edit includes View, Publish includes Edit)** | The permission engine has **no implication**: `Access::can()` and FolioPermissionMiddleware check exact names (rules map, e.g. 'AdminController@editForm' => '{type}.view', FolioPermissionMiddleware.php l.37-41), RoleList/PermissionCoverage match names/patterns only; nothing in packages/auth|rbac declares "edit implies view". The board footnote states a rule the framework does not enforce | **GAP: needs core decision/API** (either declare implications in PermissionRegistry and honour them in `Access`, or show only the checked state and drop dashed ticks / footnote) |
| Column-toggle / row-switch semantics | row switch = "{type}.*" pattern (`$row['pattern']`) already; column toggle = set all `{type}.{verb}` boxes (JS) | OK |
| Delegation locks | PermissionCoverage | OK |

### Native dialogs on Roles
No `window.confirm`; role form is a generic `.folio-form`, so in-admin leave uses the styled folioConfirm leave dialog (initUnsavedGuard l.1650-1651, askLeave l.1760); tab close uses native `beforeunload` (l.1633-1639). Staff account disable uses data-confirm (src/Rbac/templates/account.twig l.29 -> folioConfirm).

### Tests pinning (these assert exact PHP-built HTML - the heaviest pins in the release)
- tests/Rbac/PermissionsFieldTest.php: l.68 (`<div class="folio-table-wrap"><table class="folio-table rbac-matrix">`), l.76-77 (`scope="colgroup">Members</th></tr>\s*<tr class="rbac-row" data-rbac-row><th scope="row" title="member\.\*">Members</th>` ...), l.89 (`aria-label="Posts: Edit"></td><td class="rbac-cell"><span class="rbac-none" title="No publish permission for Posts">–</span>`), l.99 (`<tr class="rbac-row" data-rbac-row><th scope="row" title="<type>\.\*">`), l.110 (rbac-none Site create), l.120 (`<div class="rbac-others"><h3 class="rbac-others-heading">Other permissions</h3>`), l.135-137 (`<span class="rbac-group-label">Codes</span></div><div class="rbac-chips"><label class="rbac-chip" title=...`), l.144 (`^<fieldset class="form-group rbac-permissions" data-rbac-permissions><legend class="rbac-legend">Permissions</legend>...`), l.152 (`<p class="rbac-unknown">...<div class="rbac-rest" data-rbac-rest>`), l.169/181 (`<input type="checkbox" class="rbac-switch-input" role="switch" name="permissions\[\]" value="\*" data-rbac-everything[ checked]>`), l.200-201 (`<input type="hidden" name="permissions[]" value="page.publish" data-rbac-kept>`).
- tests/Rbac/RolesInFolioTest.php l.61 (everything switch regex), l.67-68 (`scope="colgroup">Settings</th></tr>\s*<tr class="rbac-row" data-rbac-row><th scope="row" title="rbac_role\.\*">Roles</th>`, `<span class="rbac-group-label">Assets</span><label class="rbac-group-all" title="assets\.\*">...`), l.109/137 (`folio-chip-label` roles chips).
- tests/Rbac/RolesFieldTypeTest.php l.27-38 (`<fieldset class="form-group rbac-roles has-error"><legend>Roles</legend>`).
- tests/Rbac/NewPermissionsTest.php l.124 (`<ul class="folio-notice-list rbac-new-list">`), `rbac-new` presence/absence (l.104,245,260,287,443), GrantOnceTest, DelegationTest (disabled boxes), TwoRolesTest, StaffLifecycleTest.
- tests/Assets/AdminCssTest.php l.233 (`.rbac-switch-input:focus-visible + .rbac-switch { box-shadow: var(--focus-ring); }` in rbac.css).
- No JS tests for rbac.js (no `data-rbac` in tests/js).

---------------------------------------------------------------------------------------------------
## 8. 404 / forbidden / unknown admin path

### Current
- Forbidden: src/Http/Forbidden.php (`response()` l.24-47: fetch() plain text, htmx toast header `HX-Trigger folio:error`, else HTML 403) -> templates/admin/forbidden.twig (10 lines: `.folio-empty` + generic `ui-file` icon + message + muted help). Layout switches to _drawer_layout for iframes/`_drawer=1`.
- Unknown admin path/type/record: **71 plain-text `new Response(404, [], FolioText::get('folio.errors.unknown_type'|'not_found'|'unknown_action'|...))`** in 16 files (AdminController l.278,588,645,650,671,694,712,766,770,803,812,858,864,917-1102…; also AssetController, AssetLibraryController, RedirectsController::unknown l.537, CacheController 'Unknown cache', ExtensionsController l.64, PublishingController, BlockEditorController, AccountController, FrontendController, PreviewController, AiTranslateController (JSON), StaffAdmin 'Not found'). No shared 404 page renderer (Forbidden::response is the only shared error page). Keys: lang/en/folio.php l.453-465 (`errors.not_found='Not found'`, `unknown_type='Unknown type'`, `forbidden_title`, `forbidden_help`).
- Router-level unmatched paths under {prefix}: core ErrorHandler (packages/core/src/Error/*Renderer) not Folio-styled.

### Board shows (Ink.dc "Errors and 404s")
Ink card with one large pilcrow in `--mark-3`, friendly title "This page wandered off", mono hint "/docs/packages/blade → add a redirect" (hint links to redirects/new?address=…). Forbidden: same ink card with one pilcrow, friendly title.

### Data / API
| Piece | Source | Status |
|---|---|---|
| Requested path for hint | request URI | OK |
| "→ add a redirect" link | `{prefix}/redirects/new` (needs `address=` prefill param; today only `?not_found=<id>` prefill, create() l.87-100) and `redirects.manage` permission | OK-ish: add `address` param (Folio) |
| Shared admin NotFound page | none | **needs new Folio class** (`NotFound::response()` mirroring Forbidden) and replacing the plain 404s that browsers see (GET html routes only; JSON/fetch/htmx keep text) |
| Ink marks macro/classes | see section 0 | needs new |

### Tests pinning
- tests/Http/AdminControllerTest.php l.157-161 (test_unknown_type_404: status only), l.205, l.530, l.673 etc. assert status codes/bodies of 404s (check bodies before changing to HTML: 'Unknown type', 'Not found' plain strings); tests/Http/CacheScreenTest.php l.130-131 ('Unknown cache').
- Forbidden page: no test pins the `forbidden.twig` markup (grep for 'No access'/forbidden_title in tests: none); tests/Http/FolioPermissionMiddlewareTest.php pins 403 statuses and rules; AllScreensTranslatedTest walks screens.

---------------------------------------------------------------------------------------------------
## 9. EMPTY STATES

### Current places (all `.folio-empty` generic icon + text; or plain `p.folio-help`)
- Lists: templates/admin/_list_results.twig l.21-26 (`has_records` false: `ui-file` icon, `empty_text|default(t('folio.list.empty', {label: folio_noun(label)}))`, primary "create one" button if `may.create`) and l.27-32 (`.folio-no-matches`, search icon, "clear search" link) - **search-with-no-results stays plain** per COMPONENTS.
- Assets: index.twig l.64-65 (`.folio-empty` text only: `no_unused|no_match|empty`), bin.twig l.14 (`bin_empty`).
- Extensions: templates/admin/extensions.twig l.20-22.
- Forbidden: forbidden.twig l.5-9.
- Plain help-text empties (not `.folio-empty`): translations/index.twig l.41-42 (`folio.translations.empty`), redirects/index.twig l.17 (`not_found_empty`) and l.66 (`empty`), dashboard review "Nothing is waiting for review" (dashboard-review.twig l.30), blocks (unknown/too_deep cards), seo-audit.
- Asset field empty (`.folio-asset-empty`, fields) is a field state, not a screen: leave.

### Board shows (Ink.dc, COMPONENTS §3)
Ink card, 6 marks, title "No references yet" / "No :label yet." (existing key `folio.list.empty`), mono line "Add the first one▍", primary action (accent on ink: `--ink-accent`) "New reference".

### Data / API
All present (key, `may.create`, create URL). Needs marks macro + `.folio-ink-line` + `.folio-caret` ink variant (admin.css has `.folio-caret` for save state; ink variant `--ink-accent` not defined).

### Tests pinning
tests/Integration/FolioAppTest.php l.377 (`folio-empty`); tests/Http/ListScreenTest.php (empty list text; check), tests/Http/ListLanguagesTest.php l.75; tests/Content/ListView tests; AdminControllerTest list tests by text ("No ... yet").

---------------------------------------------------------------------------------------------------
## 10. SIGN-IN (_auth_pattern.twig, .folio-auth-*)

### Current
- templates/admin/login.twig (64 lines): **its own full copy of the shell** (head + `.folio-auth` > `aside.folio-auth-art[aria-hidden]` with `_auth_pattern.twig` include + `.folio-auth-hello` (logo xl, "Folio", `.folio-auth-line` + `span.folio-auth-caret`) + `main.folio-auth-main` > `form.folio-auth-card` with `.folio-brand.folio-auth-brand`). Rendered by AuthController (src/Http/AuthController.php l.171).
- templates/admin/_auth_layout.twig (38 lines): the same shell as a base, used by forgot.twig and password.twig (blocks `title`, `card`); login does not extend it ("the login page has its own copy", l.1 comment).
- _auth_pattern.twig (61 lines): inline `<svg class="folio-auth-pattern" viewBox="0 0 640 960">` with 8 `<symbol id="fg-…">` defs (caret, pilcrow, brace, block, cursor, squiggle, plus, ring, dot) and 50 `<use class="pc-N" transform=…>` marks inside `g.folio-auth-drift`.
- CSS l.2473-2589: `.folio-auth*`, `.folio-auth-pattern .pc-1..6` (colours from --mark-N), `.folio-auth-drift` (animation folio-drift 48s), `.folio-auth-caret` (blink, `folio-blink` keyframes l.2559), reduced-motion l.2557-2559.

### Needed (INK.md)
Extract symbols to icons.svg as `mark-*`; generic `.folio-ink`, `.folio-ink-marks` (+ `.mc-1..6`), `.folio-ink-line`, `.folio-caret` (ink variant), `.folio-ink-drift`; Twig macro `folio_ink_marks(seed,count,area)`; keep `.folio-auth-*` as aliases until login/_auth_layout switch; dedupe login.twig onto `_auth_layout.twig`.

### Data / API
Pure front-end. Seed generator: only the pre-generated markup exists (no PHP/JS generator in repo) - **macro needs its own deterministic PRNG + placement** (reuse nothing).

### Tests pinning
- tests/Integration/FolioAuthTest.php l.64-65 (`<aside class="folio-auth-art" aria-hidden="true">`, `class="folio-auth-pattern"`).
- tests/Assets/AdminCssTest.php l.242 (`.folio-auth-hello .folio-logo-f { stroke: var(--ink-deep); }`), l.111 (control roots include `.folio-auth`).
- tests/Http/AuthControllerTest.php, tests/Integration/CsrfSignInTest.php (form fields), FolioStaffLoginTest (login flows).
- tests/View/ShellTest.php (brand/switcher selectors `a.folio-brand`, `aside.folio-sidebar` classes exact: `//aside[@class="folio-sidebar"]`).

---------------------------------------------------------------------------------------------------
## 11. AI RUN screen ("failed" renders is-incomplete)

- templates/admin/ai/run-progress.twig l.65: `<span class="folio-badge{% if item.status == 'done' %} is-ok{% elseif item.status == 'skipped' %}{% else %} is-incomplete{% endif %}">` -> statuses `pending|done|failed|conflict|skipped` (lang/en/folio.php l.331-337). Fix: `failed` -> `is-failed` (CSS exists l.1656), `conflict` stays `is-changed`/`is-incomplete`, `pending` -> `is-draft`. Other badges: ai/run.twig l.43 (`folio-badge` job_state plain), dashboard-review.twig l.13 (job state plain), seo-audit/index.twig l.28 (`is-incomplete`).
- Controller AiTranslateController (status logic l.640 treats failed/conflict as problems).
- Tests: tests/Http/AiRunTest.php (no badge-class assertion found: grep `is-incomplete|is-failed` only hits ListLanguagesTest l.75), tests/Http/AiTranslateControllerTest.php. Low risk; add a test.

---------------------------------------------------------------------------------------------------
## 12. SKIP LINK

- Not present. templates/admin/_layout.twig: `<body data-folio-prefix>` l.72, shell l.78: rail include l.79 (when rail), `aside.folio-sidebar` l.80 (site switcher, nav sections with `<details>`, foot/user menu), `div.folio-main` > `header.folio-topbar` l.129 > `main.folio-content` l.150 (no id). Insert `<a class="folio-skip" href="#folio-main">` as first body child + `id="folio-main" tabindex="-1"` on main; also drawer layout (_drawer_layout.twig `main.folio-drawer-content`) is a single main (no skip needed); _auth_layout/login have no nav to skip.
- Rail partial partials/_rail.twig adds another set of tab stops.
- Tests: tests/View/ShellTest.php (structure selectors) likely unaffected; add an XPath test. Needs `folio.layout.skip` string de/fr (AllScreensTranslatedTest).

---------------------------------------------------------------------------------------------------
## 13. Native dialogs / beforeunload / window.confirm left in Folio JS

Searched packages/folio/assets/*.js, packages/htmx/assets (htmx.min.js only), rbac assets:
| Where | What | Notes |
|---|---|---|
| `window.confirm`, `confirm(`, `alert(`, `prompt(` | **none left** in folio assets (grep clean). htmx's own `confirm` is intercepted: admin.js l.1034-1046 onHtmxConfirm -> folioConfirm. `data-confirm` forms: l.968-1031. | - |
| admin.js l.1633-1639, 1651 `onGuardBeforeUnload` (native beforeunload) | generic unsaved guard for `.folio-form form, .folio-side form` (record editors, role form, asset panel form); in-admin navigation first asks via styled `askLeave()` -> folioConfirm (l.1760-1780); native prompt is the fallback for closing the tab/reload (cannot be styled) | keep |
| admin.js l.2163-2169 initTranslationsSaveAll `beforeunload` | **native-only guard for the Translations screen** (no styled leave dialog for in-admin links) | replace by registering `Folio.unsaved.onLeave`/dirty hook so askLeave runs; keep native for tab close |
| admin.js l.3312 `onBusyBeforeUnload` | not a prompt: releases busy buttons when the leave was cancelled | keep |
| admin.js l.957, 1307; ai-translate.js l.110 | `dialog.showModal()` with attribute fallback (native `<dialog>`, styled) | fine |
| Roles screen | no own native dialog; generic guard only (see section 7) | nothing to remove; Roles "unsaved count" needs own JS |
| Removal prompt (l.1155) | JS-built `<dialog>` but `.btn-danger` confirm and checkbox-style radios; board wants radio cards | restyle only |

## 14. "M9 raw-key JS fallbacks in fields.js"

("M9" is not defined anywhere in docs/; reporting what exists.)
- assets/fields.js l.23-26: `function t(key, params) { if (typeof window.folioT === 'function') return window.folioT(key, params); return key; }` -> when admin.js is absent the UI text is the **raw key**.
- admin.js l.8-19 `folioT` itself returns the **key** when `#folio-i18n` JSON lacks it (`typeof folioTexts[key] === 'string' ? ... : key`).
- Keys fields.js passes to `t()`: `copied` (l.180), `at_position`, `position` (l.312), `no_matches` (l.445), `more` (l.454), `fields.drop_refused` (l.678), `fields.uploading` (l.692), `fields.upload_failed` (l.693), plus `fields.date_in` / `fields.date_ago` (l.118, Intl-less fallback). The first five are `folio.js.*`; the `fields.*` ones come from lang `folio.fields.*` which FolioServiceProvider::jsTexts (src/FolioServiceProvider.php l.1379-1394) exports under the key `fields.*` (prefixes exported: `folio.js.`, `folio.redirects.prompt.`, `folio.fields.`). I verified every key used by fields.js, admin.js (incl. the `redirects.prompt.*` ones built with a prefix helper) and editor-frame.js exists in lang/en today - so no raw key is visible now; the fallbacks are latent.
- Template-supplied overrides: `data-text-copied` (l.180), `data-text` counter (l.223), `data-text-empty` (l.445), `data-text-more` (l.454), `data-text-refused/-uploading/-failed` (AssetFieldType.php l.95-96) take precedence over t().
- Literal English in JS: `':count / :max'` counter default (l.223); `Intl` English fallback in relativeTime l.115-117 ("in 2 day").
- Other scripts with no folioT at all (blocks.js, ai-translate*.js, image-editor.js, preview-editor.js, editor-frame.js uses 4 keys, all present).
- Tests: tests/js/fields.test.mjs (fake-dom; check it stubs window.folioT), tests/Assets/AdminJsTest.php, tests/Integration/AllScreensTranslatedTest.php (raw-key regex over rendered HTML only, not JS).

---------------------------------------------------------------------------------------------------
## 15. Summary of GAPs (no source today -> new core/extension API or a scoping decision)

1. Dashboard "Recent changes" (cross-type who/what/when feed): no API.
2. Dashboard "Needs you" structured items from extensions: `#[DashboardNotice]` returns raw HTML only.
3. Assets "used N×" per card: no batch usage count (AssetUsage::of is per asset, expensive).
4. Assets "Missing alt text · N" filter/count: no query support (alt is a locale map).
5. Assets selection bar: no bulk bin / bulk download / bulk alt endpoints.
6. Asset "Used in" with type icons: AssetUsageEntry has no type/family.
7. Roles "implied" ticks: permission engine has no implication semantics (decision needed; footnote text would be untrue).
8. Admin 404 page: no shared NotFound renderer (71 plain-text 404 sites); redirects/new has no `address=` prefill.
9. Ink marks: no seeded generator for the macro (only pre-baked auth markup); marks not in icons.svg.
10. Dashboard counts load all records (replace with aggregate count).
11. Asset crop previews: only config "formats" exist, not fixed 16:9/4:3/1:1/9:16.
