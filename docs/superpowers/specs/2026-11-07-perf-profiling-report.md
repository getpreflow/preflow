# Where a Preflow request spends its time (Preflow 0.57, 2026-11-07)

Profiling report: excimer (sampling) and xdebug (traces), with A/B experiments in a scratch router. The router behaves
like Apache mod_php to within 0.3 ms. Raw data and scripts are in this folder: `out/` holds languagecorner, `outh/`
holds holz-objekte.

## Method
- **Server:** `php -S` with `-d opcache.enable_cli=1`, matching Apache within 0.3 ms:

  | Page | php -S | Apache |
  |---|---|---|
  | /home | 15.2 ms | 15.5 ms |
  | 404 | 9.8 ms | 9.9 ms |
  | robots.txt | 3.7 ms | 3.8 ms |

- **Experiments,** each switched on by a request header in the scratch router only:
  - a Twig FilesystemCache;
  - a classmap-first loader;
  - OPcache preload;
  - `zend.assertions=-1`.
- **Sampling:** excimer EXCIMER_REAL every 0.1 ms, 400–600 requests per page. It adds about 15 %, so real figures are
  the profiled ones × 0.85.
- **Traces:** xdebug in a fresh CLI process, for exact call counts.
- **Uncertainty:** medians ±0.2 ms. Estimated figures are ±30 %. About 0.5 ms per request is spent outside PHP.

## languagecorner /home: 15.2 ms (14.7 ms in PHP)

Phases:

| Phase | ms |
|---|---|
| boot | 3.4 |
| handle | 11.1 |
| autoload, create, emit together | about 0.15 |

By cause:

| Cause | ms | % |
|---|---|---|
| Twig compiles 7 templates, 104k calls | 7.2 | 49 |
| CSS/JS minify | 1.2 | 8 |
| installed.json decoded twice | 1.0 | 7 |
| Data layer | 0.9 | 6 |
| Twig load/environment | 0.8 | 5 |
| HTML sanitizer (config built at boot, plus sanitizing) | 0.7 | 5 |
| PSR-4 `file_exists` (local only; production has a classmap) | 0.7 | 5 |
| Folio registries | 0.6 | 4 |
| Twig runtime | 0.4 | 3 |
| Includes from OPcache (578 files) | 0.2 | 1.5 |
| Other | 1.0 | 7 |

With the Twig cache, handle drops from 11.1 ms to 3.1 ms.

### /no-such-page: 9.8 ms
- Boot: 3.4 ms.
- Compiling the 3 layout templates: 3.5 ms.
- Minify: 0.75 ms.
- `NotFoundEntries::write` does a JSON read-modify-write on every 404 (0.32 ms) and invalidates that data cache.

### /robots.txt: 3.7 ms (the floor)
Handle takes 0.08 ms; everything else is boot, which loads 407 files (75 of them Folio, 30 media, 25 auth, 10 sanitizer, 6 AI).

| Boot cost | ms |
|---|---|
| installed.json, decoded twice | 0.96 |
| Sanitizer config, built eagerly via `registerFieldKinds` (`FolioServiceProvider.php:545`) | 0.52 |
| PSR-4 lookups | 0.54 |
| Folio registries | 0.57 |
| Rest of core | about 0.6 |

The Folio registries cover:
- the ExtensionRegistry signature;
- the ModelExtension and TypeCatalog scans;
- Customizations, with 384 `getAttributes` calls;
- `FolioRoutes::admin` and its PatternCompiler, with 1,373 `preg_quote` calls.

After boot:
- the container holds 77 eager instances, including PDO, Throttle, Tokens, RememberMe and Notifier;
- 34 singletons are already resolved, among them AdminNav, Ai, UsageLog, Pricing, ScreenRegistry, Customizations,
  Publisher, ReviewIndex, TranslationStatus and FieldTypeRegistry;
- /home then resolves only 8 more.

### Other subsystems on /home
- **Config:**
  - `config/folio.php` is required 21 times (`FolioServiceProvider.php:1872`); i18n.php 7 times, auth.php 7 times.
  - Total about 0.1 ms.
- **Routing:** `Router::match` takes under 0.02 ms; `FrontendResolver::match` 0.31 ms.
- **Middleware:** 13 layers, under 0.1 ms of their own time. No session is started on a public GET.
- **Data:**
  - `JsonFileDriver::loadRecords` runs `glob` plus `filemtime` and `filesize` on every record file, then decodes the
    31 KB cache file.
  - Each model JSON is read and decoded 4 times (TypeRegistry twice, TypeCatalog, ModelExtension).
  - `LocaleValue::resolveTree` runs 865 times.
- **i18n:** about 0.05 ms, apart from the installed.json scan, which costs 0.54 ms.
- **Twig:**
  - ExtensionSet initialisation takes 0.15 ms.
  - Component templates go through `createTemplate(file_get_contents())`.
- **Components:** `resolveState` 0.46 ms; component scan 0.09 ms.
- **SEO/GEO:**
  - head tags 0.10 ms;
  - ETag 0.06 ms;
  - `FolioPageMeta` 0.30 ms.

  The 304 answer works, but only after the full render.
- **Emitter:**
  - It sends no Content-Length.
  - The status line is always HTTP/1.1, so HTTP/1.0 clients get chunked replies (this is why `ab` breaks).

## Hot spots on /home today

### Top exclusive real time (ms)

| # | Function | ms |
|---|---|---|
| 1 | Twig `traverseForVisitor` | 0.95 |
| 2 | `ClassLoader::findFileWithExtension` | 0.83 |
| 3 | `Environment::loadTemplate` | 0.71 |
| 4 | `TranslationRegistry::fromInstalled` | 0.54 |
| 5 | `ExtensionRegistry::builtIn` | 0.49 |
| 6 | Twig `Node::getIterator` | 0.48 |
| 7 | `HtmlSanitizerConfig::allowElement` | 0.47 |
| 8 | `Minify\CSS::pregReplace` | 0.29 |
| 9 | `JsonFileDriver::loadRecords` | 0.27 |
| 10 | Twig `Lexer::lexExpression` | 0.25 |

The rest of the top 25 is the Twig compiler and minify internals.

### Calls
- 168,720 calls per request, or 33,710 with the Twig cache.
- **Twig compile:**
  - `preg_match` 8,170;
  - `getIterator` 7,087;
  - `traverseForVisitor` 6,885.
- **Folio:** `preg_quote` 1,734.
- **Autoload:** `file_exists` 583.
- **Biggest callers left once the Twig cache is on:**
  - `resolveTree` 865;
  - `Locales::has` 844;
  - autoloads 467;
  - `getAttributes` 429;
  - `Container::get` 266.

### Packages by inclusive time

| Package | ms |
|---|---|
| Twig | 10.2 (70 %) |
| components | 4.3 |
| minify | 1.2 |
| composer | 1.1 |
| data | 0.9 |
| sanitizer | 0.7 |

## holz-objekte (APP_DEBUG=0)

| Page | Today | Twig cache | Twig cache + classmap |
|---|---|---|---|
| / | 38.3 | 23.8 | 22.7 |
| list | 38.6 | 18.7 | 16.5 |
| /album | 20.6 | 9.9 | 9.0 |
| /robots.txt | 5.4 | 5.4 | 4.9 |

**List page:**
- Twig compile: about 19 ms (15 templates).
- SQL: 6.2 ms, of which 3.9 ms are 25 single-row `folio_assets` lookups (`MediaLibrary::record`, an N+1).
- Minify: 2.6 ms.

**Home:** `App\Gallery\Catalog::names` takes 8.4 ms (project code).

**Every request** connects to MySQL eagerly, plus 2 SET statements: about 0.5 ms.

## The gap to Laravel
Preload saves only 0.6 ms and assertions change nothing, so loading and linking classes is not the issue.

- **Incidental fixes:** Twig cache, minify cache, installed.json and sanitizer bring /home to about 4.5 ms and
  robots.txt to about 1.7 ms.
- **Structural fixes:** deferred providers, a config cache, a route cache, a package manifest and cheaper discovery
  caches together save about 2 ms more, to /home about 2.5–3 ms and robots.txt under 1 ms.

## Ranked opportunities

### Cheap wins

| # | What | Saving | Effort / risk | Where |
|---|---|---|---|---|
| 1 | Twig cache. `Application::createTemplateEngine` never passes `cachePath`, so `TwigEngine` runs with `'cache' => false` | Measured −7.5 ms (−49 %); holz list −19.9 ms | S / low | core + twig |
| 2 | Cache minify output by content hash (`AssetCollector`) | −1.1 ms; holz −2.5 ms | S / low | view |
| 3 | Generated manifest, or an mtime-keyed cache, instead of parsing installed.json (`TranslationRegistry::fromInstalled`, `ExtensionRegistry::builtIn`/`packageClasses`) | −1.0 ms on every request | S–M / low | core, i18n, folio |
| 4 | Lazy sanitizer and static field-kind metadata | −0.5 ms on every request | S / low | folio |
| 5 | Throttle or batch the 404 hit writes and cap new paths | −0.3 ms per 404, and no writes under scanners | S / low | folio, routing |
| 6 | Emitter sends Content-Length and the request's protocol version | correctness | S / low | core |
| 7 | Batch media lookups (fix the N+1) | holz list −3.5 ms | M / low–med | media |
| 8 | Optimised autoloader; consider `--classmap-authoritative` | −0.6 ms | S | docs, deploy |
| 9 | Parse each model JSON once | −0.2–0.3 ms (holz −0.9 ms) | S–M | data |
| 10 | Memoise config reads | −0.1 ms | S | core, folio |
| 11 | Connect to the DB lazily | holz −0.5 ms on requests with no query | S–M | core, data |

### Structural

| # | What | Saving | Effort / risk | Where |
|---|---|---|---|---|
| 12 | Deferred or lazy providers; build Folio admin services, routes, nav and permissions only under the admin prefix | −1.0–1.5 ms; robots.txt under 1 ms | M–L / medium | core API + folio |
| 13 | Route cache for Folio patterns | −0.15 ms | M | — |
| 14 | Cheaper `JsonFileDriver` validation: generation file plus directory mtime, and an OPcache-friendly PHP-array cache | −0.3–0.5 ms, growing with content | M / medium | data |
| 15 | One compiled boot manifest (config, packages, component, block, extension and model catalogs) | 1–2 ms together with 3, 9, 10 and 13 | L / medium | core |
| 16 | Sanitize rich text on save, or cache it by hash | −0.2 ms | S–M | — |
| 17 | Component templates via a FilesystemLoader namespace | under 0.1 ms | S | — |
| 18 | OPcache preload where the host allows it | −0.6 ms | ops | — |

**Project-level:** holz `Catalog::names` (8.4 ms) needs a cache or an index.

## Side observations
- languagecorner's `storage/cache/data` holds 31,550 stale `json-*` cache files (220 MB). They carry other data
  paths' keys, so they most likely come from test runs since 0.56.1's cache per data path. Nothing cleans them up.
- The profiling raised the /no-such-page 404 counter in the local data.
