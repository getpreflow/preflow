# New permissions reach roles — design

Owner request, 2026-11-04. Roadmap: "Grant-once role seeding" (Later). Project 3 of 3 (after CSRF in 0.51 and
redirects in 0.52).

## Why

Roles are seeded with `RoleStore::ensure()` (packages/rbac), which creates a role only when it is missing and never
changes it afterwards. Two things follow:

1. **A site's own seed list grows, the role doesn't.** When a site's developer adds a permission to a role they seed
   (config/roles.php, an extension's `install()`, a site seed command), existing installs never get it.
   holz-objekte.org already works around this with its own `grantOnce()` in `app/Console/SeedCommand.php` (a hidden
   `seeded_permissions` field on `rbac_role`, added by `config/models/rbac_role.json`).
2. **A framework update brings a new permission, nobody notices.** 0.52 added `redirects.manage`. Roles with `*`
   (Administrator) have it; roles with an explicit list (holz-objekte's Content admin, BG Genius's Editor) don't, and
   nothing tells anyone it exists.

It has not bitten yet; the owner wants the flaw gone before it does.

## Goals

1. **Grant once for a site's own seed lists (automatic).** When a role that a site seeds lists a permission the role
   was never given by its seed, the next seed run (deploy) adds it once. The role remembers what its seed granted, so a
   permission an editor removed stays removed, and a permission an editor added stays.
2. **Announce new permissions, never grant them (framework and packages).** A permission that appears with an
   update is shown to the people who manage roles, with a way to add it to a role or dismiss it. Nothing is granted
   automatically; roles with `*` keep covering everything as today.
   - Folio's Roles screen shows a notice listing the new permissions (label, group, the roles that already cover it).
   - The Roles item in Folio's sidebar shows a count badge while there are new permissions (owner's idea).
   - The dashboard shows a short hint linking to the Roles screen (my call, to be hard to miss).
   - `preflow migrate` (which every deploy runs on the SQL sites) prints the new permissions, so whoever deploys
     sees them too.
3. holz-objekte.org can drop its own `grantOnce()` and keep its remembered grants.

## Non-goals

- Granting a framework permission to any role automatically (owner: "assigning automatically seems not secure").
- Sites without Folio's role storage (preflow/roles, roles only in config): their roles are the config file itself.
- Revoking permissions a seed list no longer lists: a seed only ever adds.
- Changing a role's label or name through the seed.

## Anti-goals

- A permission an editor removed coming back on the next deploy.
- A permission reaching a role without a person deciding it, except through that site's own seed list.
- The first upgrade flooding the notice with every permission the site already has.
- Getting around delegation: the notice's "add to role" obeys the same rule as the role editor
  (`PermissionCoverage`: only someone who holds a permission may grant it; a role above you can't be changed).

## How it works (decided)

### Grant once (rbac)

- `ensure()` keeps its signature. For a new role it behaves as today and records the permissions it granted.
- For an existing role it adds the listed permissions the role's seed has never granted, then records them as
  granted. Permissions the seed granted before are not added again, whether the role still has them or not.
- Where the record lives: a hidden field `seeded_permissions` on `rbac_role` (the name holz-objekte already uses, so
  its records carry over; holz-objekte then removes its model extension and `grantOnce()`).
- *My call:* **an existing role without a record** (every role on today's sites) gets its current permissions
  **plus everything the seed lists now** recorded as already granted on the first run, and nothing is added. A
  permission a seed listed but an editor removed before this release would otherwise come back (on this run or the
  next). Cost: a permission added to a seed list before this release but never reached the role stays missing; it
  shows up nowhere (it is not new to the registry). The upgrade note tells site developers to check their seeded
  roles once.
- `*` roles: nothing to add; recorded as is.
- Install seeding of rbac (`Extension::install()`, Administrator only when no staff role exists; config/roles.php),
  Folio's member roles and site code all go through `ensure()`, so all get grant-once.
- *My call (builder check):* **role seeds run on every deploy.** Today extension seeds run in `install()`, which
  `folio:extensions:apply` calls only when an extension's version changes, so a permission added to
  config/roles.php or to BG Genius's Editor list would reach nobody until a version bump. Extensions get a seed step
  that `folio:extensions:apply` runs on every run for each enabled extension (alongside the version-gated
  `install()`), and the role seeding of rbac (Administrator, config/roles.php), Folio's members and BG Genius moves
  into it. `folio:extensions:apply` already runs on every deploy of smart-stack.works, holz-objekte.org and
  BG Genius; site commands like `holz:seed` keep calling `ensure()` themselves. (smeyer.me's deploy runs neither;
  the builder checks whether it has seeded roles at all.)

### New permissions (rbac + Folio)

- **What counts as new:** a name in `PermissionRegistry::all()` that is enabled (not `disable()`d, so a
  switched-off extension's permissions wait until it is switched on) and not in the site's list of known permissions.
- **The known list** is stored once per site (Folio's internal storage, SQL or JSON like its other settings;
  `preflow migrate` creates it on SQL sites). *My call:* on the first run (no list yet) every current permission is
  recorded as known and nothing is announced; the 0.52 permission `redirects.manage` is the one exception announced
  on that first run, since it is why this exists. (Builder: a small "announce on first run" list in Folio is fine.)
- **Every new permission is announced**, also one a role already covers through a pattern (`assets.*` covers a new
  `assets.export`): the notice says which roles already have it and how (`*`, `assets.*`), so whoever manages roles
  learns that a role got something new through its pattern. *My call (builder check), from the owner's wish to be
  informed.*
- **A permission leaves the notice** when someone dismisses it or adds it to a role from the notice; then it is
  written to the known list for good (no live re-check, so it never comes back). Granting it through the normal role
  editor does not remove it from the notice; the notice then shows that role as having it, and a Dismiss clears it.
  *My call.*
- **Grouping:** a new content type brings five permissions; they are shown as one line per group (the registry's
  group, e.g. the type's label) with its permissions, so a new type is one entry.
- **The notice (Roles screen):** "New permissions since the last update" with, per permission or group: label,
  group, which roles already cover it (via `*` or a pattern), an "Add to role…" picker (roles the signed-in user may
  change, delegation applies) and "Dismiss". Shown to users who may edit roles (`rbac_role.edit`).
- **The badge:** the Roles sidebar item shows the number of new permissions (Folio's `#[NavBadge]` on the role
  type's customization). Only for users who may edit roles.
- **The dashboard hint:** one line with the count and a link to the Roles screen, same audience.
- **`preflow migrate` output:** "New permissions, not granted to a role yet: redirects.manage (Redirects) … — see
  Settings → Roles." Printing does not mark them known.

## Existing roles today (recon, 2026-11-04)

- rbac `Extension::install()`: staff Administrator `*` (only when no staff role), then config/roles.php.
- Folio members: `pending`, `member` (`members.access`), `rejected`.
- holz-objekte.org SeedCommand: `super_admin` `*`, `content_admin` (long list, already grant-once via
  `seeded_permissions`).
- BG Genius Extension: `admin` `*`, `editor` (`game.*`, `bggenius.flows`, `assets.*`, `contact_message.view`).
- smeyer.me, smart-stack.works: Administrator via install only (check during the build).

## Left to the builder

- Storage layout of the known list, class names, how the notice's actions post (Folio's form patterns, CSRF).
- How "a role names it explicitly or by a pattern below `*`" is computed (Pattern / PermissionCoverage).
- Translations (en/de/fr like the rest of Folio's admin) and the exact notice layout (follow Folio's existing
  notices).
- Tests: grant once (new role, added permission, editor-removed permission stays out, editor-added stays, `*`, the
  first run on a role without a record), known list first run (no flood, the 0.52 exception), disabled permissions,
  dismiss, add to role with delegation refused, badge count and audience, migrate output, holz-objekte's existing
  records carried over.
- READMEs and the ROADMAP item with upgrade notes (SQL sites run `preflow migrate`; holz-objekte removes its
  `grantOnce()` and model extension; check seeded roles once).
