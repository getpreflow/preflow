# Folio Extensions, Access and Users — Design

**Date:** 2026-10-21
**Packages:**
- `preflow/folio` (extension system, admin building blocks)
- `preflow/auth` (permission question, guards)
- new packages: simple roles, RBAC, users
**Status:** Draft, for review.

## Intent

Folio became a good editor. What it lacks is the thing that made Crelish worth having: a
site can bring its own admin features, and they feel like part of Folio. A developer
adds user management, invoicing, PDF tools or a board-game QR panel without touching
framework files and without registration boilerplate. The people who use the admin
then see one consistent tool, not a CMS with bolted-on pages.

The two goals, in this order:
1. **Developer ease.** The obvious thing works. Conventions over configuration, one
   source of truth per thing, and loud errors instead of silent skips.
2. **Admin UX.** Extensions look and behave natively. Editors only see what they may
   use.

Preflow's own rule still holds: the framework stays the centrepiece for building apps.
Folio, and everything in this design, is a package on top that a project can leave out.

## What the Crelish model taught (summary of the review)

**Keep from Crelish:**
- a folder convention in the project;
- no framework files touched;
- a sidebar entry without code.

**Avoid what goes wrong there:**
- Magic namespaces that fail silently when misspelt.
- Per-controller boilerplate: a view path, a header-bar switch, access rules.
- A JSON menu that drifts from the code. Its items only highlight when their `id` equals
  the controller id, `children` never render, and items have no roles.
- Field types and plugins that can only live in the vendor package.
- Dashboard config written into `vendor/`.
- A single hard-coded "role ≥ 9" check that does not even stop the action.
- Static hooks keyed by type name.
- Documented APIs that do not exist.

## Goals

- **Four extension levels:**
  1. **Just data:** a model JSON gives list and form. This exists today; it is the
     default answer for blogs, news, board games and members.
  2. **Data with customization:** a class extends one type's admin with form panels,
     record actions, list actions, list columns and save/delete hooks.
  3. **Own screens:** controllers that appear in the admin as native pages.
  4. **Capability packages:** Composer packages that bring any of levels 1–3 plus
     services (PDF, invoicing, tournaments, users, RBAC).
- **The same structure in two places:** project extensions live in a fixed folder, and
  packages ship the same structure. Moving a project extension into a package changes
  only its namespace.
- **Opt-in, Craft-style:**
  - Installed is not enabled. An Extensions screen shows what exists, and each extension
    or part is switched on separately.
  - The choice is a committed file, applied on deploy, and read-only on the live site.
- **Live settings:** an extension declares a settings form, editors with permission
  change it on the live site, and its values are stored like content.
- **One permission question, two ways to answer it:**
  - Code asks `can('permission.name')`.
  - With nothing installed, any staff user may do everything (today's behaviour).
  - A **simple roles** package answers from config.
  - A **full RBAC** package answers from data managed in Folio.
  - Switching from one to the other changes no checks.
- **Two user populations:** staff (Folio) and members (the site), each with its own
  guard, provider and storage, both covered by the permission packages.
- **Native building blocks:** a documented set of admin UI pieces (page header, toolbar,
  table, form fields, panels, empty states, badges, toasts). Extensions use them instead
  of writing CSS.
- **Loud failures:** a misnamed class, a missing template or a duplicate route is an
  error on boot (debug) or in `folio:extensions:check`, never a silent skip.

## Non-goals

- A marketplace, remote installation, or installing from the admin UI. Code arrives with
  Composer and git.
- Running untrusted third-party extension code safely. Extensions are trusted code with
  full app access.
- Replacing Preflow's own routing or DI for public pages. Extensions add admin features;
  public pages remain normal Preflow routes and components (a package may ship those
  too).
- Database migrations as a Folio concern. Packages that need tables use Preflow's
  migration mechanism; Folio only triggers their install step.
- In this design: the billing, PDF and tournament packages themselves. They are examples
  that the system must make possible.

## Anti-goals (it fails even if it works)

- An extension that needs a framework file edited, or a vendor file overwritten.
- A typo that makes an extension silently disappear.
- Two sources of truth for one thing (for example a menu entry in JSON and a route in
  code).
- Live edits and deployed config overwriting each other.
- Editors seeing menu items, buttons or settings they are not allowed to use.
- Secrets (API keys, passwords) ending up in settings, exports, pulls or backups.

## Constraints

- **Preflow conventions:** PHP 8.4, attributes for declaration (`#[Route]`, `#[Block]`
  exist), Twig, strict nonce CSP (no inline handlers or styles), no build step, vanilla
  JS.
- **Existing Folio mechanisms to build on, not replace:**
  - model JSON types and field types;
  - `@folio` template overrides from `resources/folio`;
  - `App\Folio\Overrides\{Controller}\{Action}` (overridable core actions);
  - singletons;
  - publishing;
  - `folio:export/import/pull`.
- **`preflow/auth`** already has guards (`SessionGuard`, `TokenGuard`), providers
  (`ConfigUserProvider`, `DataManagerUserProvider`) and password hashing. Two user
  populations are two guards.
- **Hosting reality:** shared hosts, cron instead of workers, no Node at runtime.

## The design

### Where extensions live

- **Project extensions:** `app/Folio/{ExtensionName}/`, namespace `App\Folio\{ExtensionName}`.
  It sits next to the existing `App\Folio\Overrides`, which stays as it is.
  - An extension folder contains one `Extension.php`, which describes it, plus any
    classes it declares (type customizations, screens, field types, dashboard cards).
  - A project can have several extensions (`app/Folio/BoardGames`,
    `app/Folio/Tournaments`).
- **Packages:**
  - A Composer package declares its extension class in `composer.json`
    (`extra.folio.extension`), just as Preflow providers are found.
  - Its folder structure mirrors a project extension, and its templates live in the
    package under its own Twig namespace.
- **`Extension.php`:** one small class with an attribute.
  - The attribute carries the name, label, description, icon and version.
  - The class lists its parts (types, customizations, screens, permissions, settings,
    dashboard cards, field types), or lets Folio discover them by attribute in the
    extension folder. Discovery is the default; an explicit list is allowed.
  - Optional `install()` and `uninstall()` hooks run on enable and disable.

### Level 1: just data

This is unchanged and stays the default: a model JSON under `config/models` or inside the
extension folder (`app/Folio/Members/models/member.json`). It adds two things:
- **Admin-only types,** which are never routed publicly. This already works as "no
  frontend route" and only needs documenting.
- **Per-type standard permissions:** `{type}.view`, `{type}.edit`, `{type}.publish` and
  `{type}.delete`. Folio checks them, and the menu and actions follow.

### Level 2: data with customization

A class with `#[FolioType('boardgame')]` and attributed methods:
- **`#[FormPanel(label, position)]`:** returns template data or HTML for a panel on the
  edit form (the QR code to print).
- **`#[RecordAction(label, icon, permission, confirm)]`:** a button on the edit form, which
  receives the record and returns a response, a redirect or a toast message.
- **`#[ListAction(...)]`:** acts on the selected records ("Print labels").
- **`#[ListColumn(label, position)]`:** an extra column in the list, computed per record.
- **Hooks:** `#[BeforeSave]`, `#[AfterSave]`, `#[BeforeDelete]`, `#[AfterDelete]`. They
  are instance methods with dependency injection; the record is passed in and can be
  changed before saving (hashing a password, for example).

Folio places, styles, secures (CSRF, permission) and routes all of these. The developer
writes no controller.

### Level 3: own screens

A controller class with `#[FolioScreen(path, label, icon, section, order, permission,
badge?)]`, plus normal Preflow route attributes on its methods (relative to the screen).
- **One attribute** gives the URL (`/folio/{path}/…`), the sidebar entry, its active state
  (the URL prefix), its visibility (the permission), and the admin layout, auth and CSRF.
- **Methods** return a template name and data, like Preflow actions, or a response. The
  template extends Folio's screen layout and uses the building blocks.
- **Sections** group the sidebar (Content, Library, Settings, or an extension's own). An
  optional `badge` callable (for example "3 new messages") is evaluated lazily.

### Level 4: capability packages

These are levels 1–3 shipped together, plus services registered in the container. There
is nothing new to learn: a tournaments package is types, customizations, screens,
permissions and frontend components.

### Opt-in and the committed extensions file

- **Folio admin → Settings → Extensions:**
  - It lists project extensions and installed packages, with each one's parts.
  - Each part can be switched on or off: types, customizations, screens, dashboard cards,
    field types and permissions.
  - Switching on runs `install()` (migrations, default roles); switching off hides the
    parts and keeps the data.
- **The file:** the state is written to one committed file, `config/folio/extensions.json`.
  It holds what is on, plus structural overrides such as hiding or renaming a sidebar
  entry or a dashboard card.
- **The CLI:** `php preflow folio:extensions:apply` (run on deploy: installs and enables
  per the file), `enable`, `disable`, `list` and `check` (validates everything and reports
  problems loudly).
- **Who may change it:** a config flag, like Craft's `allowAdminChanges`, decides whether
  the Extensions screen may write. It defaults to on in local debug and off in production,
  where the screen is read-only and shows what is enabled.

### Live settings

- **The form:** an extension declares a settings form with Folio field types, including
  assets.
- **In the admin:** Folio shows it under **Settings → {Extension}**. Values are stored like
  content: as a singleton-style record per extension, live-edited, optionally published,
  and carried by export, import and pull.
- **Defaults:** they come from the extension, so it works before anyone fills the form in.
- **Permissions:** `{extension}.settings` guards the form, separately from enabling
  extensions.
- **Secrets:** a settings field can reference an environment variable by name. The form
  shows whether it is set, never its value, and never stores it.

### Access: one question, interchangeable answers

- **The contract:** the question is `can(string $permission, ?object $subject = null)` on
  the current user's guard, available in PHP (`Access::can()`, or injected) and Twig
  (`can('page.publish')`).
- **Declared permissions:** every permission is declared, by the type defaults, by
  extensions (`Extension.php` or attributes), or by the app. That gives the RBAC package
  a complete list to show, and a typo in a check is an error.
- **Providers:**
  - **None installed:** every authenticated staff user may do everything, which is today's
    behaviour.
  - **Simple roles** (working name `preflow/roles`): roles in `config/roles.php`, grouped by
    user population, as permission lists with wildcards (`editor: ['page.*', 'news.*']`,
    `admin: ['*']`). Each user has one role. There is no admin UI.
  - **Full RBAC** (working name `preflow/rbac`): roles, permissions and assignments stored
    as data. Users can have several roles, roles can inherit, and permissions can be
    scoped per controller, action or feature. It is managed in Folio: the package is
    itself a Folio extension, and the first real user of the extension system.
- **Where checks run:** in screens, actions, list columns, settings, menu visibility and
  the standard type permissions. Folio's own core actions use the same checks.

### Users: staff and members

- **Two populations, two guards:**
  - **`folio`** is staff: admins, editors. They are stored where a small team suits, for
    example JSON or SQLite. Today this is the config user from `.env`.
  - **A site guard** is members. They are stored where the site suits (MySQL for thousands)
    and have their own public login, registration and password reset.
  - Sessions and cookies are separate. A member can never reach `/folio`.
- **The users package** (working name `preflow/users`) is a capability package:
  - member and staff user types (level 1);
  - a password-hashing hook and login-as-user actions (level 2);
  - invite and reset screens (level 3);
  - public registration, login and reset components and routes for the member side, which
    a site may switch on or off;
  - email through `preflow/notify`.
- **Roles per population:** both permission packages group roles by population, so staff
  roles (`page.publish`) and member roles or tiers (`downloads.premium`) never mix.

### Admin building blocks

These are documented Twig components and macros for extension templates, so they look
native without CSS:
- the screen layout with page header (title, breadcrumb, actions);
- toolbar;
- data table (sorting, row actions, selection for list actions);
- form layout and fields (Folio's field types);
- panel;
- empty state;
- badge;
- toast and flash messages;
- confirm dialogs (`data-confirm`);
- tabs.

They are the components Folio's own screens use, so core and extensions cannot diverge.

## Decided (by the user in conversation)

- **Extensions** come at package level for broadly needed features, and through a project
  folder convention for project-specific ones. Both have the same shape.
- **"Just data"** covers most needs and stays the primary path.
- **The third space,** data with customization (the QR panel on board games), is a
  first-class level.
- **Real extensions** add capabilities: PDF, invoicing, tournaments, users.
- **Permissions:** both a simple-roles and a full RBAC option, offered early, covering both
  directions.
- **Staff and members** are separate, even in storage, and both are covered by the
  permission packages.
- **"Pick what you need, the rest stays out of your way":** opt-in per extension and part,
  with Craft-style committed config.
- **Extension settings** must be editable live by the client.
- **User data stays on the server:**
  - Staff and member accounts are not carried by `folio:export/import/pull` by default.
    A per-type switch (`"transfer": true`) includes a type.
  - Role definitions travel, as structure. Role assignments stay with the accounts.
  - Server-side backups keep everything.
  - Locally, developers sign in with their own local staff user and test with made-up
    members.

## Calls made after the builder check (marked as the designer's, for review)

- **Which guard `can()` asks:**
  - The staff session exists only under the Folio path (`session.paths`, as on
    smeyer.me), and preview renders run there.
  - So a request is in exactly one area. `can()` asks the guard of the current area
    (`folio` under the Folio path, the site guard elsewhere); `can($permission, guard:
    'site')` is explicit.
  - There is no second session in one request and no core session rework.
- **Undeclared or disabled permissions:** in debug, an undeclared permission throws.
  In production, it, like a permission of a disabled extension or part, is denied and
  logged once, never a 500.
- **Disabled extension types are off everywhere:** no admin entries, no public routes,
  no frontend resolution. The data is kept. `TypeCatalog` and `FrontendResolver` read
  the enabled state at boot.
- **Default without a permission package:** staff may do everything. Members are
  denied every named permission (fail closed); "is logged in" checks use the guard,
  not `can()`.
- **Lifecycle:**
  - *Enable/disable* only switch things on and off, and data is kept.
  - *`install()`* runs once per environment and version. Installed state is stored per
    environment in that environment's own data, not in the committed file, so
    `apply` runs on every deploy without repeating it.
  - *Removing* data is a separate, CLI-only `folio:extensions:remove <name>` with
    confirmation, which calls `uninstall()`.
- **Building blocks:**
  - They are extracted from Folio's current admin templates, and the list and edit
    screens switch to them in the same delivery step.
  - Assets, dashboard and the remaining screens follow later. Until then they keep
    their markup, which already uses the same tokens and classes.

## Open for the builder

- Exact class, attribute and package names (the working names above are placeholders).
- Discovery mechanics and caching. A boot cache like Folio's block cache is suggested;
  `folio:extensions:check` validates.
- The settings storage shape: one singleton per extension is suggested.
- How the RBAC UI scopes permissions (per screen, action, type, record).
- The order of delivery. Suggested:
  1. access contract plus simple roles;
  2. extension core (levels 2–3, the Extensions screen, the committed file);
  3. live settings;
  4. building-block documentation;
  5. the RBAC package;
  6. the users package.
