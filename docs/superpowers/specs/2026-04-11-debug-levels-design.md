# Debug Levels & .env Loading

**Date:** 2026-04-11
**Scope:** Replace boolean `debug` flag with integer-backed `DebugLevel` enum; add `.env` file loading so `config/app.php` reads from environment variables.

## Problem

1. Debug mode is binary (`true`/`false`). There's no way to force dev error panels for components that define custom fallbacks — useful when debugging a component whose fallback hides the actual error.
2. `config/app.php` hardcodes values that duplicate `.env.example`. The `.env` file is never loaded, so environment variables are ignored.

## DebugLevel Enum

**Location:** `packages/core/src/DebugLevel.php`

```php
enum DebugLevel: int
{
    case Off = 0;
    case On = 1;
    case Verbose = 2;

    public function isDebug(): bool
    {
        return $this !== self::Off;
    }
}
```

- Backed by integer for config compatibility (`'debug' => 1`).
- `isDebug()` helper for subsystems that only need binary dev/prod distinction.
- `DebugLevel::from()` throws `ValueError` on invalid integers — fail loud at boot.

### Level Semantics

| Level | ErrorBoundary | Twig | AssetCollector | ErrorHandler |
|-------|--------------|------|----------------|-------------|
| `Off` (0) | Custom fallback if defined, else hidden div | Cache on, strict off | Production mode | ProdErrorRenderer |
| `On` (1) | Custom fallback respected; dev panel for no-fallback components | Cache off, auto_reload, strict_variables | Dev mode | DevErrorRenderer |
| `Verbose` (2) | Dev panel forced for ALL components (custom fallback suppressed) | Same as On | Same as On | DevErrorRenderer |

Levels 1 and 2 only diverge at the ErrorBoundary. All other subsystems treat both as "debug on" via `isDebug()`.

## .env Loading

**Location:** `packages/core/src/EnvLoader.php`

A simple, zero-dependency `.env` parser that:

- Reads `$basePath/.env` if the file exists; silent no-op if missing.
- Parses `KEY=value` lines. Handles double-quoted and single-quoted values. Strips inline comments. Skips blank lines and `#` comment lines.
- Calls `putenv("KEY=value")` and sets `$_ENV['KEY']` for each entry.
- Does **not** overwrite variables already set in the real environment (`getenv('KEY') !== false` means skip). System env takes precedence over `.env` file.
- Runs early in `Application::create()`, before config files are loaded.

### .env.example

```
APP_NAME="Preflow App"
APP_DEBUG=1
APP_KEY=change-this-to-a-random-32-char-string!
APP_TIMEZONE=UTC
APP_LOCALE=en

DB_DRIVER=sqlite
DB_PATH=storage/data/app.sqlite
```

### config/app.php

```php
<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    // 0 = production, 1 = development, 2 = verbose (forces dev panels for all components)
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
];
```

## Propagation Through Application::boot()

`Application::boot()` converts the config integer to the enum once:

```php
$debug = DebugLevel::from((int) $this->config->get('app.debug', 0));
```

Then passes `DebugLevel` explicitly to each subsystem constructor (same pattern as today, type changes from `bool` to `DebugLevel`):

- **bootViewLayer($debug):**
  - `AssetCollector(isProd: !$debug->isDebug())` — binary
  - `TwigEngine(debug: $debug->isDebug())` — binary
- **bootComponentLayer($debug, $secretKey):**
  - `ErrorBoundary(debug: $debug)` — receives full enum (only consumer of all three levels)
  - `ComponentRenderer` — unchanged, doesn't touch debug directly
- **ErrorHandler:**
  - `$debug->isDebug()` selects DevErrorRenderer vs ProdErrorRenderer — binary

## ErrorBoundary Decision Logic

Current (boolean):
```
1. Component has fallback() → show custom fallback
2. No fallback + debug=true → dev panel
3. No fallback + debug=false → hidden div
```

New (DebugLevel):
```
1. Verbose → dev panel (custom fallback suppressed, with note)
2. Component has fallback() → show custom fallback
3. No fallback + On → dev panel
4. No fallback + Off → hidden div
```

When `Verbose` suppresses a custom fallback, the dev panel includes a note: *"This component defines a custom fallback (suppressed by debug level 2)."* This tells the developer that the component won't be broken in production.

Constructor changes from `bool $debug` to `DebugLevel $debug`. The `renderDev()` and `renderProd()` methods stay as-is — only the decision logic in `render()` changes.

## Boot Sequence (revised)

`Application::create($basePath)`:
1. `EnvLoader::load($basePath . '/.env')` — populate environment from file
2. Load `config/app.php` — reads `getenv()` with sane defaults
3. Wrap in `Config` object

`Application::boot()`:
4. `DebugLevel::from((int) $this->config->get('app.debug', 0))` — convert to enum
5. Pass to subsystems as described above

## Tests

### ErrorBoundary tests (update existing + add new)

**Existing 8 tests:** Replace `new ErrorBoundary(debug: true)` with `DebugLevel::On` and `false` with `DebugLevel::Off`. All existing assertions unchanged — levels 0 and 1 match today's false/true.

**New test cases for Verbose:**
- Component with custom fallback + `Verbose` → dev panel shown, not the fallback
- Dev panel includes "custom fallback suppressed" note
- Component without fallback + `Verbose` → dev panel (same as `On`)
- All dev panel content (exception class, message, phase, props, stack trace) present

### EnvLoader tests (new)

- Parses `KEY=value`, `KEY="quoted value"`, `KEY='single quoted'`
- Skips comments (`# ...`) and blank lines
- Strips inline comments (`KEY=value # comment`)
- Does not overwrite existing environment variables
- Silent no-op when `.env` file doesn't exist
- Handles edge cases: empty values, `=` in values, multiline (not supported — single-line only)

### DebugLevel enum tests (new)

- `DebugLevel::from(0|1|2)` returns correct cases
- `DebugLevel::from(3)` throws `ValueError`
- `isDebug()` returns false for `Off`, true for `On` and `Verbose`

### Other subsystems

No new tests needed. Signature changes from `bool` to `DebugLevel` at call sites, but behavior is identical via `isDebug()`.

## Skeleton / Demo

- **config/app.php**: reads from env as shown above.
- **.env.example**: updated with integer debug value.
- **.env** (in test project): users create from `.env.example`.
- **ErrorDemo / ErrorDemoRaw**: no code changes. Behavior varies by configured level — ErrorDemo's custom fallback is suppressed only at level 2.
- **Feature cards on home page**: describe error boundaries generically, don't claim specific visible behavior tied to a level.

## Out of Scope

- Per-component debug overrides (e.g., `{{ component('Foo', {debug: 2}) }}`)
- `.env.local` / `.env.production` / environment-specific files
- Variable interpolation in `.env` (`KEY=${OTHER_KEY}`)
- Encrypted `.env` support
