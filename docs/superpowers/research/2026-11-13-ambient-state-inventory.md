# Ambient per-request state in Preflow: inventory and context proposal

Repo: `/Users/smyr/Sites/gbits/preflow-framework` (develop, at `01edeaa4`). Paths below are relative to `packages/`.
Scope: `packages/*/src` only (`folio/Claude outputs/` skipped). Every claim cites file:line; anything not read directly in code is marked **(inferred)**.

Method: rg sweeps for superglobals, `getenv`, `session_*`, `header*`, `set_time_limit`/`ini_*`/`ignore_user_abort`, `time()`/`date()`/`new DateTime*`/`microtime`, static mutable properties, setter/scope methods (`set*`, `use*`, `scoped`, `isolate`, `restoring`, `enter/leave`), `InternalRender::is`, `RenderScopes::add`, then reading every hit that affects request behaviour.

---

## 0. Headline counts

| Category | Behaviour-relevant sites | Notes |
|---|---|---|
| superglobal (per request) | 17 runtime lines in 5 files (`$_SESSION` in NativeSession: 13; `fromGlobals`: 1; `Members::currentRequest` `$_SERVER`: 1; devtools `router.php`: 3) plus 2 swap helpers (AnonymousGlobals, SessionDataGuard) | 15 more `getenv`/`$_ENV` reads are boot or deploy config, not per request. The testing package has 22 more. |
| identity | 8 mutation sites (`setUser`/`addUser`) and about 35 readers (AuthManager user/users/guardName: 10; Access: 13; `Authenticatable` request attribute: 12) plus 3 template helpers (`auth_user`, `auth_check`, `can`) | AuthManager is a container singleton that each middleware sets |
| locale | Translator `setLocale` ×7, `getLocale` ×11, `useOverrides` ×3; ContentLocale `set` ×3, `get` ×9; InterfaceLocales `setCurrent` ×1; static `FolioText::use` ×2; `ValidatorFactory::useTranslator` ×2; engine global `interface_locale` ×2; `LocaleMiddleware::$urlPrefix` | Two locales that move apart (the translator's locale and the content locale), plus admin state on top |
| read-mode | `LiveContent::showWorkingCopies` ×2 callers, `isolate` ×1, mode reads ×8; memo keys in Collections and Singletons | Already one switch, but every memo has to remember to read it |
| site | `app.url` read 52 times (immutable config); request-host fallback ×2 | No multi-site or tenant concept exists (rg for `tenant`, `currentSite` and `SiteContext` found nothing) |
| clock | 112 raw hits. About 42 behaviour-relevant: 22 already take a constructor clock seam (in 4 different shapes); about 20 call `time()` or `new DateTimeImmutable()` directly | No shared clock service |
| outbox / side effects | 24 `sendLater` sites, 1 immediate `Mailer::send`, 1 shared CookieQueue, native headers (Emitter, GeoMiddleware, session), 7 `InternalRender::is` gates on writes, `afterResponse` queue, page-change log flush, events | Mail is the only side effect a scope discards |
| limits | `LongRequest::allow` ×3; `set_time_limit` ×1, `ignore_user_abort` ×1, `ini_set` ×1 (CLI); `fastcgi_finish_request` ×1; Refresher time budget | Process-wide, never restored |
| other mutable per-request services | RequestContext rebinds ×3; SeoContext; AssetCollector; GeoCollector; NonceGenerator; DependencyRecorder and PageCache frames; DataManager captures; DebugCollector; HtmxAssets `$required`; static Pdf `Blocks::$renders`; CookieQueue | Render collectors: these should become a per-render frame, not context fields |
| scope / wrapper mechanisms | 12 distinct (§2) | Each internal render path stacks a different subset of them |

---

## 1. Inventory per package

Category key: **id** identity · **loc** locale · **rm** read-mode · **site** · **clk** clock · **out** outbox/side-effect · **sg** superglobal · **lim** limits · **oth** other mutable request state.

### core (largest: the kernel edge and every internal render)

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| core/src/Application.php:697 | `ServerRequestCreator::fromGlobals()`, the only place that turns superglobals into PSR | sg | `run()`; the natural edge for building a context |
| core/src/Application.php:483-485 | runs `onRequest` hooks for every `handle()`, internal renders included | oth | Folio `ensureAdmin` (folio/src/FolioServiceProvider.php:1689) |
| core/src/Application.php:487-492 | `InternalRender::is` → clears `afterResponse`/`rebuildsScheduled` only for non-internal requests | out | rebuild scheduling |
| core/src/Application.php:495, 2009-2016 | `container->instance(RequestContext, …)` rebinds the container on every handle; rebound again after the locale strip (anonymous middleware at 1996-2004) | oth | PageResponder (Application.php:3087-3092), live parts, `request.path` |
| core/src/Application.php:500, 513-517, 591-599 | NonceGenerator `adopt()`/`release()` for fragment requests | oth | CSP, page-cache refusal |
| core/src/Application.php:509, 524-527, 556-561, 656-664 | flushes the Notifier queue only when the request is not internal and not emitting | out | mail |
| core/src/Application.php:543 | `pageChanges->flush()`, which also runs at the end of every internal render | out | page-cache invalidation |
| core/src/Application.php:550-558 | DebugCollector gets the asset stats | oth | debug |
| core/src/Application.php:601-627, 1315-1331 | `afterResponse` queue; rebuilds are scheduled there | out | Refresher |
| core/src/Application.php:670-685 | `fastcgi_finish_request`/`litespeed_finish_request`/flush | lim | after-response work |
| core/src/Application.php:1348-1375 | `renderRebuild`: AnonymousGlobals + `recorder->outside` + `assets->restoring` + internal render scopes; refuses when a PHP session is open (1353) | sg/oth | page-cache rebuilds |
| core/src/Application.php:1460-1474 | `visitorScope` for verify: swaps RequestContext and runs `assets->restoring` | oth | PageCacheMiddleware verify |
| core/src/Application.php:2204, 2270 | `now: fn () => new DateTimeImmutable()` handed to RedirectMiddleware and SeoMiddleware (a seam, but built ad hoc) | clk | redirects, SEO |
| core/src/Application.php:2250-2252 | adds `SeoContext::scoped` as a render scope | oth | GEO, audit |
| core/src/Application.php:2410-2455 | `bootAudit`: PageRenderer closure resets DebugCollector (2425) and wraps AnonymousGlobals (2431) | sg/oth | SEO audit |
| core/src/Application.php:2458-2466 | `internalRenderScopes()`: core scope outermost, then the package RenderScopes | — | audit, GEO, rebuild |
| core/src/Application.php:2478-2531 | `anonymousRenderScope`: RequestContext(path, GET, request = null) (2484-2485); translator locale saved (2487-2490) and restored (2524-2526) but **not set**; AuthManager users nulled and restored (2492-2496, 2511, 2517-2523); Notifier `discardingQueued` (2501-2507); `SessionDataGuard::hide` (2509) | id/loc/out/sg | every internal render |
| core/src/Application.php:2795-2796 | one shared `CookieQueue` instance for every guard | out | ResolveUserMiddleware, Kernel error path (Application.php:470-472) |
| core/src/Application.php:2816 | `getenv('APP_SECURE')` | (boot) | remember-me cookie flags |
| core/src/Application.php:2915-2921 | AuthExtensionProvider gets the **boot-time** NativeSession, not the request's session attribute | sg/id | `flash()`, `csrf_token()` in templates; this is why SessionDataGuard exists |
| core/src/Application.php:362-366 | OldInput reads the container SessionInterface | sg | `old()` |
| core/src/Application.php:907-912, 1504 | DB_*/DATA_CACHE_PATH env | (boot) | — |
| core/src/PageCache/AnonymousGlobals.php:32-61 | swaps `$_COOKIE`, `$_SERVER` (REMOTE_ADDR, HTTP_COOKIE, forwarded headers), `$_REQUEST`, `$_SESSION`; with a query also `$_GET`, `$_POST`, `$_FILES` | sg | verify, rebuild, audit |
| core/src/Http/Session/SessionDataGuard.php:26-55 | hides and restores `$_SESSION`; registers a shutdown hook | sg | anonymousRenderScope |
| core/src/Http/Session/NativeSession.php:51-168 | `session_name`/`session_start`/`regenerate_id`/`write_close`; reads and writes `$_SESSION` (13 lines) | sg/out | every session read |
| core/src/Http/Session/SessionMiddleware.php:42, 67 | `WITHOUT_SESSION` attribute; `session_name()` | sg | internal renders |
| core/src/Http/Csrf/CsrfMiddleware.php:210 | `session_name()` | sg | CSRF |
| core/src/Http/Csrf/StatelessCsrfToken.php:24, 68 | clock seam (`time()`), 2-hour slots | clk | csrf on sessionless pages |
| core/src/Http/Emitter.php:22-33 | `header()`, `header_remove()`, `headers_sent()` | out | `run()` |
| core/src/Http/CookieQueue.php:16-50 | mutable queue, pulled and applied | out | auth |
| core/src/Http/InternalRender.php:17-31 | request attribute marker | (proto-context) | 7 gates (below) |
| core/src/Http/RequestContext.php:21-28 | readonly path/method/request, **rebound** in the container | (proto-context) | PageResponder, live parts |
| core/src/PageCache/PageCacheMiddleware.php:188 | `headers_list()` (native Set-Cookie detection) | out | store refusal |
| core/src/PageCache/PageCacheMiddleware.php:189, 337, 373 | clock seam (`microtime`) | clk | staleness |
| core/src/PageCache/PageCacheMiddleware.php:201 | `InternalRender::is` → bypass | rm | GEO/audit renders never stored |
| core/src/PageCache/PageCacheMiddleware.php:381-383 | `cache->isolated(...)` around a miss render | rm | Folio publishedReads |
| core/src/PageCache/PageCacheMiddleware.php:526, 676 | `session_status()` | sg | verify/rebuild refusal |
| core/src/PageCache/PageCacheMiddleware.php:532 | verify's second render: AnonymousGlobals → `recorder->outside` → `cache->isolated` → `visitorScope` | sg/rm/oth | verify |
| core/src/PageCache/PageCache.php:60, 196 | clock seam; `expiresAt` from now | clk | freshness |
| core/src/PageCache/PageCache.php:160-174, 278 | `enter`/`leave`/`begin` render frames (mutable stack) | oth | ComponentRenderer freshness |
| core/src/PageCache/PageCache.php:334-381 | `isolate()`/`isolated()`/`refuseWhen()` registry | rm | Folio |
| core/src/PageCache/DependencyRecorder.php:27, 54-69, 160, 203-214 | frames stack; `outside()` | oth | page cache tags |
| core/src/PageCache/ChangeLog.php:49, 62, 75 | clock seam; shutdown hook | clk/out | invalidations |
| core/src/PageCache/{Refresher.php:87, RefreshQueue.php:52, TagIndex.php:82, PageStore.php:70, PageCacheSource.php:109} | clock seams (`microtime`) | clk | cache |
| core/src/PageCache/TagIndex.php:140, EnabledMarker.php:39, Boot/ManifestBuilder.php:106 | `date()` for file names and stamps | clk (benign) | — |
| core/src/Throttle/Throttle.php:47, 72 | clock seam; `REMOTE_ADDR` from server params | clk/id | login throttles |
| core/src/Error/ErrorHandler.php:39 | `InternalRender::is` → internal error renderer | oth | audit |
| core/src/Debug/DebugCollector.php:19, 49-51 | `hrtime` start; `reset()` | oth | debug |
| core/src/Container/Container.php:275 | `#[Env]` → `getenv` | (boot) | DI |

### auth

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| auth/src/AuthManager.php:12-17, 56-75, 132 | mutable `resolvedUser`/`resolvedGuard`/`resolvedUsers`; `setUser`, `addUser`, `user()`, `users()` | id | Access, Viewer, templates, page cache `signedIn` (core/src/Application.php:1449-1450) |
| auth/src/Http/ResolveUserMiddleware.php:41 | **pulls (drops) every guard's queued cookies on entry** | out | — (leak L1) |
| auth/src/Http/ResolveUserMiddleware.php:71-76 | `setUser(null)` or `setUser($user)` plus `addUser`; never restored | id | everything downstream |
| auth/src/Http/ResolveUserMiddleware.php:84-88 | applies the pulled cookies only when the request has a session | out | remember-me, marker |
| auth/src/Http/AuthMiddleware.php:67, TokenAuthMiddleware.php:31 | `setUser` | id | REST and protected routes |
| auth/src/SessionGuard.php:79-82, 133-158, 268-295 | queues marker and rotated remember cookies into the shared CookieQueue | out | browser |
| auth/src/Access/Access.php:93-111, 164 | reads `auth->user()`/`guardName()` live | id | `can()`, FrontendGate, Folio |
| auth/src/AuthExtensionProvider.php:27-55, 64-82 | `auth_user`, `auth_check`, `can`, `csrf_token`, `flash` read AuthManager and the boot session | id/sg | every template |
| auth/src/Remember/RememberMe.php:50, auth/src/Tokens/Tokens.php:39 | clock seams (`time()`) | clk | token expiry |

### i18n

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| i18n/src/Translator.php:26, 224-233 | `overridesOn` flag (`useOverrides`/`usesOverrides`) | loc | Folio admin, `valueIn` (375) |
| i18n/src/Translator.php:343-351 | `getLocale`/`setLocale`, a mutable current locale | loc | `t()`, `current_locale()` (i18n/src/TranslationExtensionProvider.php:41-42), DefaultBlockLocale, validation messages |
| i18n/src/LocaleMiddleware.php:40-49, 85 | `urlPrefix` (saved and restored); `translator->setLocale` (not restored) | loc | page cache `localePrefix`, RedirectMiddleware |
| i18n/src/Machine/{FileReviewIndex.php:19, FileRecordLease.php:23, TranslationWorker.php:140, JobRunner.php:73, FileJobStore.php:25} | clock seams, with bare fallbacks | clk | AI translation jobs |

### view

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| view/src/Seo/SeoContext.php:10-17, 79-84, 144-155 | mutable meta, defaults and resolver; `reset`; `scoped` (clears on entry) | oth | SeoMiddleware (view/src/Seo/SeoMiddleware.php:82), templates' `seo()`, Folio FrontendController |
| view/src/Seo/SeoMiddleware.php:279-287 | native headers; `session_name()` | sg/out | — |
| view/src/AssetCollector.php:10-28, 432-466 | registries; `isolate` (empties) versus `restoring` (keeps the current state) | oth | every render |
| view/src/NonceGenerator.php | one nonce for the whole process, plus adopt/release | oth | CSP, assets, PerRequestValues |
| view/src/Geo/GeoCollector.php:39-49, 103 | enter, leave, reset | oth | GEO |
| view/src/Geo/GeoMiddleware.php:118-140 | internal render: `assets->isolate` + RenderScopes; **no AnonymousGlobals** | sg | GEO |
| view/src/Geo/GeoMiddleware.php:313-314 | `header_remove('Set-Cookie')` | out | — |
| view/src/Geo/GeoMiddleware.php:322-344 | sub-request copies the visitor's server params except `HTTP_*`, so REMOTE_ADDR is kept | sg | — |
| view/src/Audit/PageRenderer.php:80-96, 104-132 | clean GET, `WITHOUT_SESSION`, InternalRender, scopes, `assets->restoring` | — | audit |
| view/src/Audit/Audits.php:46 | clock seam | clk | report |
| view/src/Pdf/Blocks.php:28, 172-187 | **static** render stack | oth | PDF |
| view/src/Console/SeoAuditCommand.php:275-289 | session save handler that keeps nothing | sg | CLI audit |

### folio

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| folio/src/Http/AdminLocaleMiddleware.php:47-70, 119-125 | sets translator locale and overrides, `InterfaceLocales::setCurrent`, static `FolioText::use`, `ValidatorFactory::useTranslator`, engine global `interface_locale`; all restored in `finally` | loc | the whole admin |
| folio/src/Http/AdminLocaleMiddleware.php:90-108 | `asFrontend`: translator locale and overrides only | loc | PreviewController |
| folio/src/FolioText.php:18-29 | **static** translator | loc | field types, notices |
| folio/src/Http/InterfaceLocales.php:71 | `setCurrent` | loc | admin |
| folio/src/Http/AdminAuthMiddleware.php:48 | `auth->setUser` | id | admin |
| folio/src/Http/WorkingCopyMiddleware.php:20 | `live->showWorkingCopies()` with no restore | rm | admin routes |
| folio/src/Http/FrontendController.php:129 | `contentLocale->set` | loc | frontend |
| folio/src/Http/FrontendController.php:153-157 | `seo->set(...)` | oth | SEO |
| folio/src/Http/FrontendController.php:200 | `live->showWorkingCopies()` in a share preview, **never restored** | rm | — |
| folio/src/Http/PreviewController.php:63, 75 | `contentLocale->set`; `asFrontend` | loc | admin preview |
| folio/src/Members/FolioPageLocale.php:19 | `contentLocale->set` | loc | members pages |
| folio/src/Content/ContentLocale.php:17-49 | mutable locale; `scoped` restores only (does not reset on entry) | loc | RecordRenderer (folio/src/Content/RecordRenderer.php:45), RelationFieldType (391, 406), Singletons (55), ExtensionSettings (103), media LocaleProvider (FolioServiceProvider.php:260) |
| folio/src/FolioServiceProvider.php:206-216 | binds ContentLocale and BlockLocale; adds the ContentLocale render scope | loc | GEO/audit |
| folio/src/FolioServiceProvider.php:1001-1047 | `isolatePageCacheRenders`/`publishedReads`: LiveContent isolation plus memo isolation; `refuseWhen('working-copy')` | rm | page cache and internal renders |
| folio/src/Content/Collections.php:38-46, 57, 89-92 | memo keyed by type, locale and working flag; FrontendGate checked on every call | rm/loc/id | frontend lists |
| folio/src/Content/Singletons.php:42-50, 55, 83-86 | memo keyed by type, `#working` and content locale | rm/loc | site settings |
| folio/src/FolioServiceProvider.php:1488, 1689-1693 | engine global `interface_locale`; `onRequest` hook | loc/oth | admin |
| folio/src/Http/LongRequest.php:26-36 | closes the session, `ignore_user_abort(true)`, `set_time_limit` | lim/sg | AiTranslateController.php:205, 395; SeoAuditController.php:118 |
| folio/src/Http/SeoAuditController.php:116-125 | runs `Audits::step` **inside an admin request** | — | audit from Folio |
| folio/src/FolioServiceProvider.php:1358; Extension/Settings/ExtensionSettings.php:99; Field/Types/EnvFieldType.php:32 | `getenv` | (deploy config) | — |
| folio/src/Http/{AdminController.php:242, WorkerWatch.php:19, RedirectsController.php:165, PublishingController.php:71} | bare now | clk | stale jobs, worker health, dismiss stamps |
| folio/src/{Transfer/Exporter.php:63, Extension/ExtensionState.php:90, Translations/StoredOverrides.php:174, Users/StaffPasswords.php:148, Permissions/KnownPermissions.php:164, Redirects/RedirectEntries.php:234, Field/Types/AssetFieldType.php:353, Transfer/Backups.php:19, Transfer/TarWriter.php:58} | bare now for write stamps and file names | clk (stamp) | — |
| folio/src/Users/StaffPasswords.php:162, Users/StaffMailer.php:48 | `sendLater` | out | — |

### publishing

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| publishing/src/LiveContent.php:20-52, 92+ (`readsWorkingCopies`) | mutable `workingCopies` flag; `isolate()` | rm | Folio relations, matrix, `folio_url`, singletons, PublishedModels |
| publishing/src/PublishingServiceProvider.php:60 | binds it "request-scoped" | rm | — |
| publishing/src/{Publisher.php:58, RecordWriter.php:60, PreviewLinks.php:25} | clock seams | clk | publish stamps, share-link expiry |
| publishing/src/Scheduler.php:24-32; Console/PublishScheduledCommand.php:34 | `runDue($now)` takes now explicitly; the CLI passes a bare now | clk | cron |
| publishing/src/Revision.php:11-18 | full copies of published records with `createdAt` (relevant to "as of a date") | — | — |

### notify

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| notify/src/Notifier.php:17-20, 47-58, 72-80, 83-100 | deferred queue; `discarding` counter; `flushDeferred` | out | every `sendLater` |
| notify/src/Mail/Mailer.php:57 | `send()` delivers at once, so it is **not** covered by `discardingQueued` | out | callers that need delivery confirmation |
| notify/src/Mail/Mailer.php:67; Mail/TemplateMailLayout.php:22-30 | composed at queue time with the **shared** engine (ambient translator, AssetCollector, `auth_user`) | out/loc | mail templates |
| notify/src/Transport/LogTransport.php:43 | `date()` | clk (log) | — |

### members, rbac, routing, media, htmx, components, data, blocks, codes, ai, devtools, testing

| file:line | Read / mutated | Cat | Depends on it |
|---|---|---|---|
| members/src/MembersView.php:51-56 | `translator->setLocale`, `pageLocale->set` (not restored) | loc | member pages |
| members/src/Members.php:58; Members.php:153-165 | clock seam; `$_SERVER` → a synthetic request | clk/sg | `register(emailVerified)` without a request |
| members/src/Viewer.php:24-55 | reads `auth->users()` | id | templates |
| members/src/MemberMailer.php:50-114; Http/AuthController.php:210; Http/RegistrationController.php:148, 229 | `sendLater` (lang passed explicitly) | out | — |
| members/src/Console/PruneMembersCommand.php:114 | bare now (CLI edge) | clk | — |
| rbac/src/PermissionCoverage.php:66-90 | reads Access | id | Folio delegation |
| rbac/src/Staff/{StaffAccounts.php:146, 328, 418; StaffMail.php:58} | bare now; `sendLater` | clk/out | — |
| routing/src/Redirect/RedirectMiddleware.php:71, 83-85, 93-95 | `InternalRender::is` gates the not-found record and the hit counter (writes) | out | redirects |
| routing/src/RouteCompiler.php:14 | `date()` in a generated file | clk (benign) | — |
| media/src/Http/VariantController.php:56, 73 | `InternalRender::is` → no variant generation (write) | out | audit |
| media/src/MediaLibrary.php:149; Cleanup/AssetCleanup.php:40 | clock seams | clk | bin and cleanup |
| media/src/Preview/{PdfPreviewRenderer.php:102, 129; VideoPreviewRenderer.php:73} | deadlines; `getenv('PATH')` | lim | previews |
| htmx/src/ComponentToken.php:72 vs 86 | `decode` uses `time()` directly although a `currentTime()` seam exists | clk | component token expiry |
| htmx/src/HtmxAssets.php:23, 49-63 | `$required` flag per request | oth | htmx include |
| htmx/src/HtmxDriver.php:16, 64; LazyHypermediaDriver.php:73 | `useAssets`, `pushUrl` (mutable response hints) | out | hypermedia |
| components/src/ComponentRenderer.php:23-26, 120-127, 196-286 | static freshness cache; `pageCache->within`; `hrtime` timings | oth | page cache |
| data/src/DataManager.php:43-48, 66, 79-100 | observer; `captures` stack (`captureReads`/`replayReads`) | oth | page-cache dependency tags, memos |
| data/src/DataManager.php:568; Driver/JsonFileDriver.php:191; Migration/Migrator.php:96 | bare now | clk | timestamps, cache settle |
| data/src/Driver/PdoDriver.php:89-92, 435-437 | `hrtime` → DebugCollector | oth | debug |
| blocks/src/DefaultBlockLocale.php:20-38; BlockLocale.php:30 | reads the translator locale; `scoped()` is a no-op | loc | block rendering |
| codes/src/Codes.php:73 | clock seam | clk | expiry |
| ai/src/Ai.php:45, 72-75; LoggedAiClient.php:56; Console/AiUsageCommand.php:20 | env key; bare now in the usage log | (config)/clk/out | AI cost log |
| devtools/src/router.php:8-15 | `$_SERVER` (built-in dev server only) | sg | dev server |
| devtools/src/Http/DebugToolbarMiddleware.php:27 | `InternalRender::is` | oth | — |
| devtools/src/Command/{PagesWorkCommand.php:60, NotifyTestCommand.php:51-54, AuthTokenCommand.php:107, MakeMigrationCommand.php:23} | CLI clocks; immediate `send` | clk/out | CLI |
| testing/src/WebApplication.php:26-29 | `unset($_SESSION)` before boot | sg | web tests |
| testing/src/AuthTestHelpers.php:19-27 | `actingAs` = request attributes (already context-like) | id | tests |
| testing/src/Fast/TestDataPaths.php:28-41; Fast/SetupSnapshots.php:63-89, 306-363 | `putenv`/`$_ENV` | (process) | fast suites |
| testing/src/MysqlTestDatabase.php:92 | `getenv` | (config) | — |

Process-level static caches that are harmless (keep as they are): core/src/Config/ParsedJsonFiles.php:14, core/src/Composer/InstalledPackages.php:27, data/src/ModelMetadata.php:17, data/src/Model.php:31, view/src/AssetCollector.php:87, auth/src/SessionGuard.php:28, ai/src/Ai.php:45, media/src/Preview/PdfPreviewRenderer.php:67, core/src/PageCache/TagIndex.php:55, folio/src/Field/LazyHtmlSanitizer.php:20. The exceptions, which carry state per render or per request, are `FolioText` (folio/src/FolioText.php:18) and `Pdf\Blocks::$renders` (view/src/Pdf/Blocks.php:28).

---

## 2. Existing scope and wrapper mechanisms

| # | Mechanism (file:line) | What it swaps | Used by | What it forgets |
|---|---|---|---|---|
| S1 | `anonymousRenderScope` (core/src/Application.php:2478-2531) | RequestContext; AuthManager users (null); `$_SESSION` contents (SessionDataGuard); queued mail (discard); translator locale (restore only) | audit, GEO, rebuild (via `internalRenderScopes`, 2458) | Does not *set* a locale: it only puts the old one back. Leaves translator `overridesOn`, `FolioText`, ValidatorFactory translator, InterfaceLocales and the `interface_locale` global alone. Leaves CookieQueue alone. Leaves NonceGenerator alone. Does not drop `Mailer::send`. |
| S2 | `RenderScopes` (view/src/Geo/RenderScopes.php:16-38), the public extension point | whatever packages add: SeoContext (core/src/Application.php:2250), ContentLocale (folio/src/FolioServiceProvider.php:214), publishedReads (folio/src/FolioServiceProvider.php:1005) | audit, GEO, rebuild | **Not** used by verify's second render. ContentLocale scope restores only. |
| S3 | `AnonymousGlobals::run` (core/src/PageCache/AnonymousGlobals.php:32) | `$_COOKIE`, `$_SERVER` subset, `$_REQUEST`, `$_SESSION`; optionally `$_GET`, `$_POST`, `$_FILES` | verify (PageCacheMiddleware.php:532), rebuild (Application.php:1369), audit (Application.php:2431) | **Not used by GEO** (GeoMiddleware.php:126). Verify passes no query, so the visitor's `$_GET`/`$_POST` stay; the visitor is the same, so that is harmless. The audit and rebuilds pass the render's query, so they are reset. |
| S4 | `SessionDataGuard` (core/src/Http/Session/SessionDataGuard.php) | `$_SESSION` data | S1 | — (exists only because template helpers hold the boot NativeSession: Application.php:2915-2917) |
| S5 | `Notifier::discardingQueued` (notify/src/Notifier.php:72) | drops `sendLater` | S1 | `send()` (notify/src/Mail/Mailer.php:57); events, data writes, AI calls, logging |
| S6 | Folio `publishedReads` (folio/src/FolioServiceProvider.php:1031-1047) | LiveContent → published; Collections and Singletons memos emptied | PageCache `isolate` (page-cache miss, verify, rebuild) and RenderScopes | Any memo another package adds; a site's own memos |
| S7 | `PageCache::isolate`/`isolated`/`refuseWhen` (core/src/PageCache/PageCache.php:334-381) | whatever is registered (only S6) | PageCacheMiddleware render/differs | — |
| S8 | `DependencyRecorder::outside` (core/src/PageCache/DependencyRecorder.php:160) | recording frames | verify, rebuild | the audit does not use it (harmless: admin requests do not record) |
| S9 | `AssetCollector::isolate` vs `restoring` (view/src/AssetCollector.php:432, 456) | asset registries | GEO (`isolate`); audit, verify, rebuild (`restoring`) | `restoring` starts from whatever is registered **now**, not from what boot registered |
| S10 | `SeoContext::scoped` (view/src/Seo/SeoContext.php:144) + `PageCache::restoreState` (PageCacheMiddleware.php:427) | page meta | S2; verify uses `restoreState` afterwards | — |
| S11 | `ContentLocale::scoped` (folio/src/Content/ContentLocale.php:41), `BlockLocale::scoped` (no-op in DefaultBlockLocale.php:34) | content locale (restore only) | S2 | does not reset on entry |
| S12 | `AdminLocaleMiddleware::process`/`asFrontend` (folio/src/Http/AdminLocaleMiddleware.php:47-108) | translator locale and overrides, InterfaceLocales, FolioText, validator translator, engine global | admin requests; preview | `asFrontend` swaps only locale and overrides (not FolioText, validator or global); nothing undoes admin state for S1 |
| S13 | `LocaleMiddleware` urlPrefix save/restore (i18n/src/LocaleMiddleware.php:40-49) | URL prefix | every request | the translator locale it sets is not restored (85) |
| S14 | `LongRequest::allow` (folio/src/Http/LongRequest.php:26) | closes the session; time limit; ignore abort | AI translate, SEO audit step | never restored (by design); later session writes are lost silently |
| S15 | `InternalRender` marker (core/src/Http/InternalRender.php) | request attribute | 7 gates: core/src/Application.php:487, core/src/PageCache/PageCacheMiddleware.php:201, core/src/Error/ErrorHandler.php:39, media/src/Http/VariantController.php:56, 73, routing/src/Redirect/RedirectMiddleware.php:71, devtools/src/Http/DebugToolbarMiddleware.php:27 | verify's second render is not marked (by intent: it should be a visitor render) |

### Leak candidates (concrete)

**L1. An internal render drops the outer request's queued Set-Cookie headers. High.**
`ResolveUserMiddleware::process` pulls every session guard's cookies on entry (auth/src/Http/ResolveUserMiddleware.php:41) from the one shared queue (core/src/Application.php:2795-2796). The sub-request has `WITHOUT_SESSION`, so they are not applied either (85).
Scenario: a staff member's session expired, but they have a remember-me cookie. They click "Continue" on the SEO audit. In the outer request, ResolveUserMiddleware restores them and rotates the token, queueing the new cookie (auth/src/SessionGuard.php:133-158). `SeoAuditController::advance` (folio/src/Http/SeoAuditController.php:118-121) then renders pages through `Application::handle`. The inner ResolveUserMiddleware throws the rotated cookie away, and the outer middleware finds an empty queue at line 84. The browser keeps a token that has already been rotated, so the next restore fails and the user is signed out (or theft detection fires, **inferred**). The signed-in marker cookie (SessionGuard.php:79-82) is lost the same way, so the page cache may later serve the user anonymous pages. An internal render that errors can also get the outer cookies applied to its own 500 response by the Kernel (core/src/Application.php:470-472).
None of S1 to S15 swaps CookieQueue.

**L2. Admin translator state leaks into audit renders started from Folio. Medium.**
S1 saves and restores the translator locale but never sets it (core/src/Application.php:2487-2490, 2524-2526). It does not touch `overridesOn` (i18n/src/Translator.php:226), `FolioText::$translator` (folio/src/FolioText.php:18), the ValidatorFactory translator, or the `interface_locale` global (folio/src/Http/AdminLocaleMiddleware.php:119-124).
Scenario A: a site has no `lang/` directory and no `config/i18n.php`, so LocaleMiddleware is never added (core/src/Application.php:1957, 1981). An editor whose Folio interface is German runs the audit, and every audited page renders `t()`, package texts (members, validation) and DefaultBlockLocale in `de`. Visitors get `en`. The audit then reports a wrong title, description or language.
Scenario B (any site): overrides stay on during the audit render, so overridable keys show the admin override texts instead of what visitors see (**inferred**: this depends on which keys `isOverridable` accepts).

**L3. Verify's second render sends mail (and other side effects) for real. Medium.**
`PageCacheMiddleware::differs` (core/src/PageCache/PageCacheMiddleware.php:532) and `visitorScope` (core/src/Application.php:1460-1474) do not run inside `Notifier::discardingQueued` or RenderScopes. The second render's request is not marked InternalRender (by design), and the surrounding request is a real visitor request, so `handle()` flushes the queue (Application.php:509, 556-558).
Scenario: a page that queues a notification on GET (a "someone viewed your offer" mail, or a site hook) sends it twice on every verified miss: always under `WebApplication` tests (testing/src/WebApplication.php:20) and with `verify: always`. Other effects of the second render that are not gated (data writes, events, AI usage log) also happen twice.

**L4. The GEO internal render keeps the visitor's PHP superglobals and address. Medium-low.**
`GeoMiddleware::render` (view/src/Geo/GeoMiddleware.php:118-126) does not use AnonymousGlobals. Its sub-request copies the visitor's server params, including REMOTE_ADDR (322-331). S1 hides only `$_SESSION`.
Scenario: a template or site code that reads `$_COOKIE` (consent banner, A/B test), or `Members::currentRequest()` (members/src/Members.php:153-165), sees the visitor during the Markdown render. For the `llms-full.txt` path (`$cached = true`) that render is not internal and may be **stored** in the page cache (GeoMiddleware.php:113-117, 343). PerRequestValues placeholders only cover registered values (**inferred**: the risk depends on site code).

**L5. `Mailer::send` and other outbox effects are not covered by any "discard". Medium-low.**
`discardingQueued` covers `sendLater` only (notify/src/Notifier.php:47-50). `Mailer::send` (notify/src/Mail/Mailer.php:57) still delivers inside an internal render. So do MemberEvents dispatch, `pageChanges->flush()` (core/src/Application.php:543, which runs mid-request for each audit page), DataManager writes, Throttle hits and the AI usage log (ai/src/LoggedAiClient.php:56).
Scenario: a component that sends a confirmation with `send()` (because it wants to know whether delivery worked) sends it during audit and GEO renders.

More candidates, lower severity:

- **L6. `AssetCollector::restoring` starts from the current registry, not from what boot registered.** Cited at view/src/Audit/PageRenderer.php:92 and core/src/Application.php:1365. A rebuild runs after a request that rendered HTML (an htmx action fragment that wrote data, then scheduled rebuilds at core/src/Application.php:1315-1331). The rebuilt page then inherits that fragment's component CSS/JS and stores it. The doc comment at core/src/Application.php:1338-1341 claims "the assets boot registered". **Inferred** (not reproduced).
- **L7. Restore-only locale scopes.** ContentLocale (folio/src/Content/ContentLocale.php:41-49) and S1. An internal render of a controller route that never calls `contentLocale->set` inherits the outer request's content locale. Example: a Folio preview request has set the content locale to `fr` (folio/src/Http/PreviewController.php:63) and that same request later renders an internal page.
- **L8. Share preview never resets its read mode.** `showWorkingCopies()` in a share preview (folio/src/Http/FrontendController.php:200) and `WorkingCopyMiddleware` (folio/src/Http/WorkingCopyMiddleware.php:20) are never undone. Cached and internal renders are protected by S6 and S7. Anything else later in the same process (mail composed after it, an `afterResponse` closure that is not a rebuild) reads working copies.
- **L9. Folio preview renders as the admin.** It renders the frontend template with the admin signed in: AuthManager, `can()` and `auth_user()` are not reset (folio/src/Http/PreviewController.php:66-75; `asFrontend` swaps only the translator). Member-only and staff-only parts show, so the preview is not what a visitor sees.
- **L10. Mail layouts render with the shared engine at queue time.** notify/src/Mail/TemplateMailLayout.php:24 and Mailer.php:67 render with the request's translator locale (only `lang` is passed as a variable) and the shared AssetCollector. A site mail layout that uses `t()` or a component follows the admin's or visitor's locale, not the recipient's (**inferred**).
- **L11. One nonce for the whole process.** core/src/Application.php:513-517; view/src/NonceGenerator.php. Internal renders reuse the outer request's nonce. This blocks independent and parallel renders, though it is not a correctness bug today.
- **L12. The audit wipes the admin request's debug collector.** `DebugCollector::reset()` per audit page (core/src/Application.php:2425). Debug mode only.
- **L13. The component-token clock seam is bypassed.** htmx/src/ComponentToken.php:72 calls `time()` although `currentTime()` (86) exists, so token expiry cannot be tested or time-travelled.

---

## 3. Internal render paths and what each one must currently remember

| Path | Entry | Resets today | Still must remember / missing |
|---|---|---|---|
| Visitor request | core/src/Application.php:691-727 (`run`) → `handle` (477) | — (baseline; every middleware sets state and does not restore it) | After-response work runs with the visitor's state still in place |
| SEO audit, CLI | view/src/Console/SeoAuditCommand.php → Audits → PageRenderer (view/src/Audit/PageRenderer.php:80) | session storage off (SeoAuditCommand.php:275); S3 with an empty query; S1 + S2; S9 `restoring`; DebugCollector reset; InternalRender; `WITHOUT_SESSION` | clock for the report (Audits.php:46) |
| SEO audit from Folio | folio/src/Http/SeoAuditController.php:116 | the CLI resets plus S14 | **L1** (CookieQueue), **L2** (translator overrides/locale, FolioText, validator, `interface_locale`), L6, L12 |
| GEO Markdown view / llms-full | view/src/Geo/GeoMiddleware.php:118 | S9 `isolate`; GeoCollector reset/capture; S1 + S2; InternalRender (not for llms-full) | **L4** superglobals; native headers (`header_remove`, 313) |
| Page-cache miss (store) | core/src/PageCache/PageCacheMiddleware.php:371 | S7 (S6 published reads); recorder frame; carried state | — (the visitor's own state is wanted) |
| Page-cache verify (second render) | PageCacheMiddleware.php:510-545 | S3 (address only, no query); S8; S7; `visitorScope` (RequestContext + S9 `restoring`); SeoContext via `restoreState` | **L3** mail and side effects; ContentLocale/translator (same URL, so this mostly holds, **inferred**) |
| Page-cache rebuild after the response | core/src/Application.php:1315-1331 → Refresher → `renderRebuild` (1348) | S3 with the query; S8; S9 `restoring`; S1 + S2; session-open refusal | L6 assets; L8 read mode outside S6 |
| CLI `pages:work` | devtools/src/Command/PagesWorkCommand.php:60 | the rebuild resets; `rebuildOnly` (core/src/Application.php:1478) | — |
| Live parts on a cache hit | core/src/PageCache/LiveParts.php:95; components/src/Live.php:12 | reads RequestContext for the visitor | — |
| Folio preview | folio/src/Http/PreviewController.php:49 | `asFrontend` (translator locale + overrides only) | **L9** identity; ContentLocale not restored (63); SeoContext/AssetCollector of the admin request; FolioText and validator stay in admin mode |
| Share preview | folio/src/Http/FrontendController.php:177-207 | — | L8 |
| Mail rendering | notify/src/Mail/Mailer.php:32-41, 67; members/src/MemberMailer.php:198 | `lang` passed explicitly for the copy | L10 layout locale and assets |
| PDF rendering | view/src/Pdf/PdfRenderer.php:29-41; Blocks.php:172 | static PDF config stack | shares the AssetCollector, translator and auth of the surrounding request (**inferred**) |
| Error pages | core/src/Error/ErrorHandler.php:39; AssetCollector `reprinting` (view/src/AssetCollector.php:477) | internal renderer when marked | CookieQueue applied by the Kernel (L1 variant) |
| htmx component endpoint | components ComponentRenderer; htmx/src/ComponentToken.php | — | clock (L13) |
| REST/JSON | auth/src/Http/TokenAuthMiddleware.php:31; core/src/Http/Api/ApiConfig.php | — | AuthManager set and never restored (fine in FPM; a problem for long-running or in-process callers) |
| Members pages | members/src/MembersView.php:51 | — | `setLocale` and PageLocale never restored |
| Tests | testing/src/WebApplication.php:23-31 (boot per request, `unset($_SESSION)`); AuthTestHelpers `actingAs` (attributes); TestDataPaths `putenv` | the boot per request *is* the reset | each in-process `handle()` relies on middleware overwriting state; ResolveUserMiddleware.php:71 sets `setUser(null)` to undo the previous test |

---

## 4. Draft proposal

### 4.1 The value

Evolve the existing `Preflow\Core\Http\RequestContext` (core/src/Http/RequestContext.php:21-28, already `final readonly`, already "the request being handled"). Do not invent a second type. Its new fields:

| Field | Type | Replaces |
|---|---|---|
| `request` | `?ServerRequestInterface` (null = internal or CLI) | the current field; superglobal reads |
| `purpose` | enum `Visitor\|Internal(Audit\|Geo\|Rebuild\|Verify)\|Preview\|Console\|Test` | InternalRender marker (S15); the 7 gates read `purpose->leavesNoTrace()` |
| `viewer` | `Viewer` (immutable: users by guard + primary guard; `Viewer::anonymous()`) | AuthManager resolved state (S1 part), the Authenticatable attribute |
| `locale` | `string` (the content/page locale; the translator follows it unless told otherwise) | Translator `setLocale`, ContentLocale, BlockLocale, LocaleMiddleware result, PageLocale |
| `surface` | `Public\|Admin(uiLocale)` | translator `overridesOn`, FolioText, validator translator, InterfaceLocales, the `interface_locale` global |
| `reads` | `ReadMode::published()\|working()\|asOf(DateTimeImmutable)` | LiveContent flag (S6), WorkingCopyMiddleware, share preview |
| `site` | `Site` (origin from `app.url`, locales, default locale) | 52 `app.url` reads (optional: convenience only) |
| `now` | `DateTimeImmutable` fixed when the context is built, plus a `ClockInterface` (PSR-20) for durations | about 20 bare `now`/`time()` calls; the 22 seams converge on one clock |
| `outbox` | `Outbox::deliver()\|discard()\|capture()` | `discardingQueued` (S5), `InternalRender` flush logic (core/src/Application.php:509) |
| `limits` | `Limits` (deadline, may-extend, ignore-abort) | `LongRequest` (S14), Refresher budget, preview deadlines |

The context stays immutable; withers return a new value (`$ctx->as($viewer)`, `->in('de')`, `->reading(ReadMode::working())`, `->at($date)`, `->discarding()`).

**Not in the context:** per-render mutable *collectors*. Put them in a separate `RenderFrame` that the switch creates fresh for each render and that the response carries out: AssetCollector registries, SeoContext meta, GeoCollector, DependencyRecorder frame, PageCache freshness stack, the nonce, CookieQueue, response hints (htmx `pushUrl`), the `afterResponse` list. The context answers "under which assumptions do we render"; the frame answers "what did this render produce".

### 4.2 Where it is built

- **HTTP edge:** `Application::run()` → `RequestContext::fromRequest($psr, $site, $clock)` (core/src/Application.php:697). Middleware does not mutate services. It returns `$request->withAttribute(RequestContext::class, $ctx->as($viewer))` (ResolveUserMiddleware), `->in($locale)` (LocaleMiddleware), `->reading(working)` (WorkingCopyMiddleware), `->admin($uiLocale)` (AdminLocaleMiddleware).
- **One switch:** `Contexts::run(RequestContext $ctx, Closure $render)` (core) is the single place that makes a context "current" for code that has no request handed to it: services, template helpers, field types. Container bindings that read state become `fn () => Contexts::current()->…`. Kernel `handle()` enters the switch with the request's context, and every middleware step updates it with `Contexts::replace($request)` (the current rebind at core/src/Application.php:1996-2016 already does this for RequestContext).
- **CLI:** `RequestContext::console($site, $clock)` in the console kernel; commands that render (audit, `pages:work`, PDF, mail) derive from it.
- **Tests:** `RequestContext::forTest()->as($user)->at('2026-01-01')->reading(working)`; `actingAs` (testing/src/AuthTestHelpers.php:19) sets the context attribute; `WebApplication` keeps booting per request.
- **Internal renders:** `$ctx = RequestContext::anonymous($site, $clock)->for($path)->purpose(Audit)->discarding()`. PageRenderer, GeoMiddleware, `renderRebuild` and verify all call `Contexts::run($ctx, fn () => $app->handle($sub))` with a fresh RenderFrame.

### 4.3 Existing scopes as thin derivations

| Today | Becomes |
|---|---|
| S1 `anonymousRenderScope` | `RequestContext::anonymous()` + `Contexts::run` (deleted) |
| S2 `RenderScopes` | kept as a deprecated BC shim that wraps `Contexts::run` for sites; packages stop adding to it |
| S3 AnonymousGlobals | kept only for **site** code that reads superglobals: `Contexts::run` applies it when `purpose` is internal or verify. That also fixes L4 (GEO). Deprecated later. |
| S4 SessionDataGuard | gone once `flash()`/`csrf_token()`/`old()` read the session from the context's request (`request?->getAttribute(SessionInterface)`), not from the boot NativeSession (core/src/Application.php:2917, 362) |
| S5 `discardingQueued` | `Notifier::sendLater` asks `Contexts::current()->outbox`; `send()` also respects `discard` (fixes L5 for mail) |
| S6 publishedReads / S7 `isolate` | LiveContent reads `current()->reads`; Collections and Singletons put `reads` and `locale` in their memo key (they already partly do: folio/src/Content/Collections.php:57, Singletons.php:55, 83) |
| S9 `restoring`/`isolate`, S10, S8 | fresh RenderFrame per render (fixes L6) |
| S11, S12, S13 | `locale` and `surface` fields; ContentLocale becomes a view over `current()->locale` |
| S14 LongRequest | `limits` field; the switch applies `set_time_limit` once at the edge |
| S15 InternalRender | `purpose` field; `InternalRender::is($request)` kept as an alias that reads it |
| CookieQueue | moves into the RenderFrame; ResolveUserMiddleware pulls from *its* frame only (fixes L1) |

### 4.4 Migration order per package

Sizes are S (≤1 day), M (2-4 days) or L (≥1 week). Counts are the call sites to touch, from §0/§1.

1. **core: M-L.** Grow RequestContext and add the `Contexts` switch, a PSR-20 clock binding, RenderFrame, and the kernel edge in `run`/`handle`. Rewrite `anonymousRenderScope`, `renderRebuild`, `visitorScope`, `bootAudit` and `internalRenderScopes` into one path (5 sites, about 260 lines in core/src/Application.php). RequestContext refs: 8. InternalRender gates: 3. Clock seams in core: 11 (adapt to the clock). CookieQueue → frame (1).
2. **auth: M.** AuthManager reads `current()->viewer`, and `setUser`/`addUser` become deprecated withers on the context (8 mutation sites). ResolveUserMiddleware, AuthMiddleware and TokenAuthMiddleware return requests carrying the context. Access/AuthExtensionProvider: about 16 readers stay the same (they read through AuthManager). Session helpers read the request's session (3).
3. **notify: S.** Outbox on the context; `discardingQueued` becomes a wrapper; `send()` honours discard. 24 `sendLater` sites stay unchanged.
4. **i18n: M.** Translator locale follows `current()->locale` unless `get($key, [], $lang)` passes one. Callers: `setLocale` ×7 to remove or turn into context withers; `getLocale` ×11 stay. Overrides follow `surface`; LocaleMiddleware returns the context instead of calling `setLocale`.
5. **publishing: S-M.** LiveContent reads `reads` (mode reads ×8, mutators ×3). A new `asOf` mode needs a revisions query (§6).
6. **view: M.** SeoContext, AssetCollector, GeoCollector and NonceGenerator become RenderFrame members (SeoContext refs: 22, mostly twig/blade adapters reading through a service). PageRenderer and GeoMiddleware build contexts. RenderScopes becomes a shim. PDF and mail layouts render in a derived context with their own frame.
7. **folio: L.** ContentLocale (set ×3, get ×9). AdminLocaleMiddleware becomes `surface`, and FolioText static becomes `current()->surface` (2 + every `FolioText::get`, unchanged API). Remove publishedReads/isolate (1 site, about 45 lines). Preview and share preview build contexts (3 sites). WorkingCopyMiddleware (1). LongRequest (3). 9 bare clocks. 41 SessionInterface refs are mostly request attributes and stay.
8. **members: S.** MembersView `setLocale` + PageLocale (2); Members `currentRequest` → context (1); clock seam (1).
9. **rbac, routing, media, htmx, components, blocks, codes, ai, data: S each.** Gates → `purpose` (4); clocks (about 12); BlockLocale → context (2); ComponentToken clock (1).
10. **testing and devtools: M.** Context builders for tests, `actingAs`, an `at()` helper; contract tests for "every internal render path leaves no trace".

Total: roughly 4-6 weeks for one person **(inferred estimate)**. Core, auth and folio carry about 70% of it.

### 4.5 What stays a service (never in the context)

DataManager and drivers; template engines; the Translator *catalogue* and overrides store (only "which locale" and "overrides on" move); PageStore, TagIndex, Refresher, RefreshQueue, ChangeLog; Throttle and the token stores; transports; config and env; logger; DebugCollector; permission registry and resolver (Access becomes a pure function of the viewer); the session *store* (the session handle rides on the request); process caches (§1 list); MediaLibrary. Mutable collectors go into the RenderFrame, not the context. Secrets and the app key never go into the context, which may be logged or serialized.

---

## 5. Risks

**BC breaks for site projects**
- Public setters that sites may call: `Translator::setLocale` (i18n/src/Translator.php:348), `AuthManager::setUser`/`addUser` (auth/src/AuthManager.php:56, 64), `LiveContent::showWorkingCopies` (publishing/src/LiveContent.php:27), `ContentLocale::set`, `SeoContext::set`/`merge`. Keep them for one minor release as shims that write into the *current* context (`Contexts::replace`), with deprecation notices.
- `RenderScopes::add` and `PageCache::isolate`/`refuseWhen` are documented extension points (view/src/Geo/RenderScopes.php:11-14, core/src/PageCache/PageCache.php:319-333). Keep them as adapters.
- Sites that read `$_COOKIE`/`$_SERVER` in templates depend on AnonymousGlobals; keep it applied for internal purposes.
- `RequestContext` constructor: new fields need defaults, or `new RequestContext($path, $method, $req)` in site code breaks.
- Templates: `auth_user()`, `current_locale()`, `flash()` and `csrf_token()` keep their names, but their meaning in internal renders changes slightly (for the better).

**Third-party extension points**
- `GuardInterface::user($request)`; custom `BlockLocale`/`PageLocale`/`LocaleProvider` implementations (blocks/src/BlockLocale.php, members PageLocale, media LocaleProvider).
- Folio extensions that call `FolioText` or hold the Translator.
- Custom middleware that mutates services, which would silently stop affecting renders that run under a different context.
- Custom `MailLayout`s.

**Performance**
- One context per request plus withers is negligible.
- A `Contexts::current()` lookup on hot paths (`t()` per key, ContentLocale per field render) adds one indirection. Cache the locale in the Translator per context id.
- Memo keys that include `reads` and `locale` mean more misses in admin requests that mix modes.
- A RenderFrame per internal render costs a few arrays. The audit and rebuilds already pay for `restoring` copies.
- The page cache must key or refuse on any non-default context (`asOf`, view-as-role, preview). Otherwise it stores time-travelled or role pages; make `refuseWhen` derive from `!ctx->isPublicDefault()`.

---

## 6. Feature unlocks

| Feature | After the migration | What still needs work |
|---|---|---|
| **View site as of a date** | The context plumbing is trivial: `->at($date)->reading(ReadMode::asOf($date))->discarding()`, and the clock follows for templates that compare dates. | **Needs work (M-L).** Rendering does not depend on "now" for publishing: the scheduler publishes by cron (publishing/src/Scheduler.php:24-32), so renders read snapshots. Time travel therefore needs LiveContent to read revisions (publishing/src/Revision.php: full copies with `createdAt`) as "latest revision ≤ T per record". It needs queries and list order over revisions (Collections). It needs a record of unpublish and delete events (not checked whether they are in revisions, **inferred** missing). Non-publishing types, media, redirects and settings have no history. Pending `_publish_at` values in the future need "would be published by T" logic. The page cache must be bypassed. |
| **View as role** | Near-trivial for permission checks: `->as(Viewer::synthetic(roles))` makes `can()`, FrontendGate (folio/src/Content/FrontendGate.php), Access and `auth_user()` follow. | **S-M.** A synthetic Authenticatable per population (staff or member); `Viewer::member()` (members/src/Viewer.php:24) expects a member record; it needs a read-only guard so a render cannot write as that role (outbox discard covers mail but not data); page-cache refusal. |
| **Whole-page draft preview** | Easy (S): render the real route through the pipeline with `->reading(working)->as(anonymous)->discarding()->purpose(Preview)`, like PageRenderer, instead of PreviewController's template-only render (folio/src/Http/PreviewController.php:66-75). This fixes L9 and the layout/SEO gap. | **M** for *unsaved* edits: DraftRecordFactory output (folio/src/Http/PreviewController.php:62) must overlay LiveContent reads (a `ReadMode::working()->with($draft)` overlay) so relations and lists see the in-form draft. |
| **Parallel internal renders** | Not trivial. PHP has no threads; "parallel" means Fibers (or `pcntl`/subprocesses, which work today through the CLI). | **L.** Fibers need *zero* shared mutable state: every service listed in §1 under "oth" must read the context or frame through a fiber-local `Contexts`; statics (FolioText, Pdf Blocks), superglobals (AnonymousGlobals cannot be per-fiber) and the process nonce must go; DataManager captures and DependencyRecorder stacks must be per frame; PDO connections and the session lock are shared. Becomes feasible only after every step of 4.4; subprocess fan-out (audit in N workers) is the cheaper unlock and needs only the context to be serializable. |
