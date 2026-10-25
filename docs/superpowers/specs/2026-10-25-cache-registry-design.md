# Cache Registry — Design

**Date:** 2026-10-25
**Status:** Approved by the owner 2026-10-25 (contracts in core, no separate package).
**Context:** Phase 2, step 4 of the BG Genius on Folio plan
(`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`).
Current framework version: v0.32.0 (checked 2026-10-25: 0.31 CSRF expired sessions and 0.32
mail `redirect_to` touch no cache code).

## Why

Caches are spread over packages and sites, each with its own path and its own way to clear:
compiled Twig/Blade templates, the compiled routes file, the JSON storage cache
(`storage/cache/data`), Folio's extension and block registries
(`storage/cache/folio-*.cache`), site caches (BG Genius: images, flows), and OPcache.
The only shared tool is `cache:clear` (devtools), which deletes everything under
`storage/cache` blindly. BG Genius has a Settings panel with one row per cache (count, size,
clear) plus OPcache stats; Folio has nothing like it. The owner wants one mechanism every
cache plugs into, usable from the command line and from the web, with a Folio screen like
BG Genius's.

## Goals

- One registry. Anything that keeps a cache registers it once: a key, a label, how to measure
  it, how to clear it. Packages register their own caches; a site registers its own.
- CLI: list caches with size, clear one, clear all.
- Folio: a Cache screen in Settings with a table row per cache (label, entries, size, clear) and
  "Clear all", plus OPcache status and reset.
- Clearing is always safe: every registered cache rebuilds itself on next use.

Non-goals: a key/value cache API (PSR-6/16) for application data; cache warming; remote
caches (Redis, APCu — not on the hosting).

## Design

### Where it lives (my call)

The contracts and the registry go into **`preflow/core`**, not a new package: every package
already depends on core, so Twig, routing, data and Folio can register their caches without a
new dependency, and sites get it with no install step. The CLI commands go into
`preflow/devtools` (where `cache:clear` already is), the screen into `preflow/folio`.

### Contracts (core, namespace `Preflow\Core\Cache`)

```php
interface CacheSource
{
    public function key(): string;      // stable id, e.g. "twig", "routes", "data.json", "folio.extensions"
    public function label(): string;    // "Compiled Twig templates"
    public function stats(): CacheStats;
    public function clear(): CacheCleared;
}

final readonly class CacheStats   { public function __construct(public int $entries, public int $bytes, public ?string $note = null) {} }
final readonly class CacheCleared { public function __construct(public int $entries, public int $bytes) {} }

final class CacheRegistry
{
    public function register(CacheSource $source): void;   // duplicate key = LogicException
    /** @return list<CacheSource> in registration order */
    public function all(): array;
    public function get(string $key): ?CacheSource;
    /** @return array<string, CacheCleared> */
    public function clearAll(): array;
}
```

Ready-made sources so most registrations are one line:

- `DirectoryCache(key, label, string $dir)` — counts and sizes files recursively, clear
  empties the directory (keeps the directory itself and any `.gitkeep`).
- `FileCache(key, label, string $file)` — a single file (routes, registries); clear deletes it
  and `opcache_invalidate`s it when it is a `.php` file.

`CacheRegistry` is a container singleton built in `Application`; providers get it in
`register()`/`boot()`.

### What registers itself

| Key | Label | Owner | Source |
|---|---|---|---|
| `twig` | Compiled Twig templates | twig (when a cache path is configured) | `DirectoryCache` |
| `blade` | Compiled Blade templates | blade | `DirectoryCache` |
| `routes` | Compiled routes | routing (when a cache path is set) | `FileCache` |
| `data.json` | JSON storage query cache | data / core (the `json` driver with a cache dir) | `DirectoryCache` (only `json-*` files) — via `JsonFileDriver::clearCache()` |
| `folio.extensions` | Folio extensions | folio | `FileCache` |
| `folio.blocks` | Folio blocks | folio | `FileCache` |

What the code does today (checked against v0.32.0):

- `Application` builds `TwigEngine` **without** a cache path, so Twig compiles in memory and
  `twig` registers nothing out of the box. Twig registers only when a site gives it a cache path.
  Turning on a Twig cache in production is not part of this work.
- `BladeEngine` without a cache path falls back to `sys_get_temp_dir()/preflow_blade_cache`.
  That folder is shared by every site on the machine, so `blade` registers it labelled
  "(shared temp folder)" in `CacheStats::$note`. A per-site Blade path is not part of this work either.
- Nothing in core passes `cachePath` to `Router`/`RouteCompiler` today, so `routes` registers
  only when a site wires a route cache.
- The JSON cache (`storage/cache/data`, set in `Application`) and Folio's two registry files
  (`FolioServiceProvider`) are always on, so they always register.

Sites register theirs in a provider, e.g. BG Genius: `images` (Glide cache), `flows`.
Image variants written next to originals are not a cache (they are the media itself) and
are left to the media package (step 5), which may register a "Derived images" source.

OPcache is not a `CacheSource` (it isn't files, and the CLI has its own OPcache): it gets its
own small class `OpcacheStatus` with `enabled()`, `stats()` (memory used/free, cached
scripts, hit rate) and `reset()`, used by the Folio screen only.

### CLI (devtools)

- `php preflow cache:list` — table: key, label, entries, size.
- `php preflow cache:clear <key>…` — clears the named caches; unknown key = error, exit 1.
- `php preflow cache:clear --all` — clears every registered cache.
- `php preflow cache:clear` with no argument keeps today's behaviour for one release: it
  clears all registered caches **and** prints a deprecation note that `--all` is the explicit
  form. It no longer deletes unregistered files under `storage/cache`.
- The commands boot the application (like Folio's commands do) so packages' providers have
  registered their caches. OPcache is not touched from the CLI (it is a separate cache there);
  the deploy step that resets OPcache through a one-off web script stays as it is.

### Folio screen

- `Settings → Cache` at `{prefix}/cache`, permission `folio.cache` (declared; staff with `*`
  have it).
- One table row per registered cache: label, entries, size (human-readable), a Clear button.
  "Clear all" at the top. An OPcache card: enabled, memory used/free, cached scripts, hit rate,
  "Reset OPcache".
- POST actions with CSRF; after an action a flash names what was cleared ("Compiled Twig
  templates: 214 files, 3.1 MB").
- Built as a regular Folio screen (it can use the screen assets from 0.30 if it needs JS; it
  should need none — plain forms).

### Labels and languages

Labels are English strings for now, like the rest of Folio's interface. The roadmap item
"Folio Admin in Other Languages" (added 2026-10-25) will put Folio's UI strings behind keys.
`label()` stays a plain string so that work can switch it to keys without changing this interface.

## Tests (for the builder)

- Registry: register/duplicate/get/all order/clearAll results.
- `DirectoryCache`: counts nested files and bytes, clear keeps the directory and `.gitkeep`,
  missing directory = 0/0 and no error. `FileCache`: present/missing, clear deletes.
- Each package registration appears when the package is booted with its cache configured,
  and not otherwise (e.g. Twig without a cache path registers nothing).
- CLI: list output, clear one, clear unknown (exit 1), `--all`, no-arg deprecation note.
- Folio screen: permission, rows render, clear one/all, OPcache card when enabled and a
  plain "OPcache is off" when not, CSRF required.

## Size

Medium-small: core contracts + two sources, ~6 registrations across packages, two CLI
commands, one Folio screen. One plan of ~5 tasks; release as 0.33.0. Then BG Genius's
Settings panel can move onto this in Phase 3 (capability 11 of the umbrella design).
