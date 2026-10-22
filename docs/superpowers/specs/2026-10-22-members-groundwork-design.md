# Members Area, Step 1: Framework Groundwork — Design

**Date:** 2026-10-22
**Site:** smart-stack.works (`~/Sites/gbits/smart-stack.works`)
**Packages:**
- `preflow/folio`: extension core, live settings, per-type transfer switch
- new `preflow/rbac`: roles and permissions edited in Folio
- new `preflow/codes`: codes and invites
- `preflow/core` or a small new package: throttling
**Builds on:** [2026-10-21-folio-extensions-design.md](2026-10-21-folio-extensions-design.md)
(called "the extensions spec" below). Its delivery step 1, the access contract plus
simple roles, is done and released.
**Status:** Draft, for review.

## Intent

The printed book *Unspoken Tradeoffs* sends readers to `smart-stack.works/tools`, and
promises an interactive Force Map there. Today that address shows "coming soon". The
members area makes good on the promise:
- a small, closed group of readers;
- a personal welcome;
- a library of the book's tools;
- the first interactive one, the Force Map.

It is also deliberately the project that puts Preflow and Folio under real load:
- extensions;
- live settings;
- roles edited in the admin;
- a second user population stored in SQLite;
- codes and invites.

Each gap it finds is fixed in the framework at once, never worked around in the site.

This document covers **step 1 of 6**, the groundwork in the framework. It ends with
smart-stack.works running that groundwork in Folio, without any public members feature
yet.

## The whole members area (context for every step)

What the user decided, across the conversation:

- **Where:** under `/tools`. English at the root, German under `/de`, as on the rest of
  the site.
- **Who gets in:**
  - Readers register.
  - With the **book code** (printed in the book) they get in at once.
  - Without one, they fill in a **screening field** ("what are you looking for?") and
    wait until the owner approves them.
- **What members see:**
  - A personal thank-you note for reading the book.
  - A resource library that can be filtered by type and force; more filters may follow.
    A resource may exist in one language only. Types: PDFs, pages, interactive tools.
- **Day-one resources:**
  - **Force Map** (interactive, Chapter 14): ten axes scored 1–5, a radar chart, a
    balance score, the book's reading of the shape, and a result to save and share.
  - **Tool Landscape:** the book's per-force tool tables as one searchable, filterable
    list (force, category, free or paid), kept current in Folio.
  - **Pitfall Map:** the ten AI pitfalls, one per force, as a page and as a printable
    one-pager.
  - **Later:** templates and checklists from the chapters. Possibly a calculator for the
    Technology Evaluation Scorecard and tickable "First 30 days" lists.
- **Team Force Map:**
  - A member starts a round ("Platform team, Q4") and gets an invite link with a hash,
    which they share themselves. The site sends nothing.
  - Teammates score the team. The result overlays everyone's maps and highlights where
    the scores diverge.
  - New rounds each quarter show the shape over time.
  - **An invite link gives access to filling in that one round, nothing else.** It is
    not a way into the members area.
- **No talking for now:** no forum, no messaging. Later: download counters, "how helpful
  was it" ratings, comments on resources, and book feedback with a testimonial opt-in.

**The steps, in the agreed order.** Each gets its own design and plan.
1. **Framework groundwork** (this document).
2. **Members area core:**
   - registration (with code, or with the screening field);
   - approval queue in Folio and the approval email;
   - login and password reset;
   - the welcome note;
   - the resource library with its filters.
3. **Force Map** for one person.
4. **Team rounds:** invite links, guest scoring, overlay, quarters.
5. **Tool Landscape and Pitfall Map** in the library; templates over time.
6. **Later:** counters, ratings, comments, book feedback.

**Noted for later, outside these steps:** a backup package. It would make server-side
backups of what Folio's backups leave out, such as SQLite files, for example nightly
copies with rotation run by cron. Until then the hoster's backups cover it.

## Goals of this step

1. **Extension core** as in the extensions spec (its delivery step 2):
   - levels 2 and 3;
   - the Extensions screen;
   - the committed `config/folio/extensions.json`;
   - the `folio:extensions:*` commands.
2. **Live settings** as in the extensions spec (its delivery step 3).
3. **`preflow/rbac`:** a roles and permissions screen in Folio, built as a Folio
   extension. It is the first real user of the extension system, as the extensions spec
   intends.
4. **`preflow/codes`:** a generic mechanism for codes that grant something, with expiry,
   usage limits, revocation and a record of redemptions. It ships a Folio screen for
   managing codes.
5. **Throttling:** a small, generic way to limit attempts (failed code redemptions now,
   logins later). It does not exist in Preflow today.
6. **User types and the transfer switch** (from the extensions spec, "User data stays
   on the server"). A model JSON can mark a type as a user type of a population. User
   types are left out of `folio:export`, `import` and `pull` unless they opt in with
   `"transfer": true`.
7. **Folio on SQL storage:** Folio works fully on types stored in SQLite, the first
   time it runs outside the JSON driver. Tables come from the model JSON, and a
   production migrate step runs on deploy.
8. **smart-stack.works adopts all of it:**
   - SQLite storage;
   - a `Members` extension with its settings;
   - a member type in SQLite;
   - member roles in RBAC;
   - a book code in the codes screen.

## Non-goals of this step

- Anything a visitor sees. `/tools` stays "coming soon". Registration, login, approval
  and the library are step 2.
- The `preflow/users` package from the extensions spec. Step 2 decides whether the
  member features become that package or start in the site and move there; this step
  only provides the member type and the storage.
- Admin building-block documentation (delivery step 4 of the extensions spec). The
  blocks are extracted as far as levels 2–3 need them, as the extensions spec already
  decided.
- RBAC role inheritance, and per-record rules ("only their own round"). Code checks
  ownership directly until a real need shows up.
- Email sending. It comes with the approval email in step 2.

## Anti-goals (it fails even if it works)

- Any personal data (member records, redemptions with who redeemed) leaves the server
  through export, pull or the repository.
- A typo makes an extension, a permission or a setting silently disappear (from the
  extensions spec).
- The owner can lock themselves out of Folio by editing roles.
- An invite hash or a book code can be used for something other than its purpose.
- Public pages start setting cookies. The site has none today, and step 1 must not
  change that.
- A site workaround where the framework lacks something. That gap is fixed in the
  framework instead.

## Constraints

- **Preflow conventions:**
  - PHP 8.4, attributes, Twig;
  - strict nonce CSP, so no inline handlers or styles;
  - no build step, vanilla JS;
  - TDD, release per minor (next: 0.20).
- **Hosting:** giantbits.com shared hosting with PHP 8.4. `pdo_sqlite` and `sqlite3` are
  installed (checked 2026-10-22). Cron, no workers.
- **Data layer:** `preflow/data` already has `SqliteDriver`, migrations (`Migrator`,
  `Schema`), and a per-type `storage` key in model JSON (`TypeRegistry`). Build on those.
- **Access:**
  - `preflow/auth` has `Access::can()`, `PermissionResolver`, `PermissionRegistry`
    (declared names plus patterns) and populations (staff, members).
  - `preflow/roles` answers from `config/roles.php`.
  - RBAC is a second `PermissionResolver`; `can()` checks stay the same.
- **Deploy:**
  - Deployer keeps `storage/` in `shared/`. The SQLite file must live there, so
    releases share it and deploys don't replace it.
  - `deploy/server-composer.php` pins `preflow/*` to the released minor. New packages
    (`preflow/rbac`, `preflow/codes`) go through the same pin.
- **Security** (standing rules of the user):
  - Secrets live only in `.env`; settings may only reference env names.
  - The import dump's old user records are never imported or committed.

## The design

### 1. Extension core and live settings

As the extensions spec describes, with its "Calls made after the builder check". This
step adds no new decisions there, only an acceptance bar: everything below (RBAC, codes,
the site's Members extension) is built with the extension system and nothing else. If
one of them needs something the extension core lacks, the core gets it.

### 2. User types and the transfer switch

- **The marker:** a model JSON marks a user type with `"users": "<population>"` (for
  example `"users": "members"`).
  - The same marker is what the RBAC role panel attaches to (section 3).
  - Step 2's login can use it to find the provider's type.
  - It is one marker, not three mechanisms.
- **Transfer:**
  - The user decided (extensions spec): accounts are not carried by default. User
    types are therefore skipped by export, import and pull unless the type says
    `"transfer": true`.
  - Every other type keeps transferring, so nothing changes for existing types.
  - A non-user type can opt out with `"transfer": false`.
  - A skipped type is skipped entirely, both its records and its files. `pull` never
    brings such records down, and `import` refuses them if an old export contains
    them.
- **Not Folio content:** tables of packages (codes, redemptions, throttle) are not
  Folio content and are never exported.

### 2b. Folio on SQL storage

What exists today:
- `SqliteDriver` creates no tables, and the only migrate command is in the dev-only
  `preflow/devtools`.
- No Folio feature has run on anything but the JSON driver: lists, search, publishing
  shadows, localized values, array fields.
- `DataManager` silently falls back to the default driver when a type names an unknown
  storage.

This step changes that:
- **Tables from the model JSON:**
  - For a Folio type stored in SQL, Folio derives its table from the model JSON:
    columns for the fields, JSON columns for localized and structured values.
  - It creates the table and adds missing columns, and never drops anything.
  - This runs in `folio:extensions:apply` and in a migrate step.
  - Packages with their own tables (codes, throttle) ship normal Preflow migrations.
- **A production migrate command:** a migrate command that exists without
  `preflow/devtools`, running all packages' and the app's migrations plus Folio's
  table sync. Deploy runs it.
- **Loud failures:** a type naming an unknown storage is an error, not a silent fall
  back to the default driver.
- **Proof:** Folio's content tests (lists, search, publishing, localized values, array
  fields) also run against a SQLite-stored type.

### 3. `preflow/rbac`

- **What it is:** a Folio capability package (extension level 4) that provides a
  `PermissionResolver`. Installing and enabling it replaces `preflow/roles` as the
  answer to `can()`. A site uses one or the other.
- **The screen:** Settings → Roles (`#[FolioScreen]`, permission `rbac.manage`).
  - Roles are grouped by population (staff, members). Each role has a name, a label and
    a set of permissions.
  - Permissions come from `PermissionRegistry::all()`, grouped by their `group` (the
    type, extension or package that declared them), as checkboxes.
  - A whole family can be granted as a pattern (`page.*`), and `*` grants everything.
  - Undeclared names cannot be entered.
- **Assignments:**
  - A user has a list of role names, read through `getRoles()` as today. Users may have
    several roles.
  - The role list is the **only** source of truth for what a user may do; there is no
    separate state field that means the same thing.
  - The resolver allows a permission when any of the user's roles in their population
    has a matching permission or pattern, the same rule as `ConfigRoleResolver`.
  - Assigning roles to a member happens on the member's record, through a level-2 form
    panel the package adds to user types.
  - Staff users from `.env` (config provider) keep their `roles` there.
- **Storage:**
  - Role definitions are structure. They are a Folio "just data" type that transfers, so
    a role made on the live site comes down with `pull`.
  - Assignments stay on the user records, which do not transfer.
- **Lock-out protection:**
  - A save is refused when, after it, no staff user would still hold `rbac.manage`.
  - A save is also refused when it would take `rbac.manage` away from the user making
    it.
  - On install, a staff role `admin` with `*` is created when none exists.
- **Seed:** on `install()`, roles from `config/roles.php` are imported when that file
  exists, so switching from simple roles keeps the current behaviour.

### 4. `preflow/codes`

A code is a string that grants something to whoever redeems it, for one purpose.

- **A code has:**
  - **Purpose:** a name the app defines (`members.book`, `forcemap.round`). Redeeming
    only works for the purpose asked for, so an invite hash is never a book code.
  - **Payload:** a small array the app interprets, for example
    `{"round": "<uuid>"}`.
  - **Form:**
    - *readable*: chosen by a person, like a book code. It is case-insensitive and
      ignores spaces and dashes.
    - *random*: generated, at least 128 bits, URL-safe.
  - **Optional limits:** an expiry date, a maximum number of redemptions, and
    revocation (at any time, kept for the record).
  - **A label** for the admin ("Book, 1st edition").
- **Redemptions:**
  - Each successful redemption is recorded with time, purpose, code and an optional
    redeemer reference (a member id). There is no IP address and no user agent.
  - Counts per code are shown in the admin.
- **The API (shape, names open):**
  - `issue(purpose, payload, options)` returns the code.
  - `check(code, purpose)` says whether the code is valid, without using it up.
  - `redeem(code, purpose, redeemer?)` returns the payload or a reason: unknown,
    expired, used up or revoked.
  - Callers show visitors one generic "this code doesn't work" for every reason. The
    reasons are for logs and the admin.
- **Throttling:** failed redemptions are limited per client and purpose (see 5).
- **Storage:** its own SQLite tables through the package's migrations, on the driver
  the site names. Nothing in it transfers.
- **Admin:**
  - A Folio screen, Library → Codes, enabled separately in the Extensions screen.
  - It lists codes by purpose with their label, form, limits, redemption count and
    state.
  - You can create a readable code, revoke a code and see a code's redemptions.
  - Random codes are issued by app code (a round creates its invite), not created by
    hand.

### 5. Throttling

- **What it is:** a minimal limiter: N attempts per key per time window, stored in the
  same storage as the data (SQLite here), because shared hosting has no Redis or APCu
  guarantee.
- **The key:** made from a purpose and a client fingerprint. *Designer's call:* a hash
  of the IP address with a daily rotating salt, so no raw IP is ever stored.
- **Who uses it:** codes now. Member login and registration in step 2.
- **Where it lives:** in the framework, per the rule that gaps get fixed there. Exact
  package to be decided by the builder (`preflow/core` or a small `preflow/throttle`).

### 6. smart-stack.works

- **Storage:**
  - A `sqlite` driver in `config/data.php` with the file at `storage/data/site.sqlite`,
    which is in `shared/` on the server.
  - Deploy runs the production migrate command (section 2b).
  - **Backups:** the SQLite file is covered by the hoster's (cyon's) own backups for
    now. Folio's backups do not contain it, because user types are not exported.
    Decided by the user. A backup package is noted for later (see below).
- **`app/Folio/Members/`,** the site's first extension:
  - **Its settings** (live settings, EN and DE where it's text):
    - registration open or closed;
    - the address that receives "someone is waiting for approval";
    - whether a book code skips screening;
    - the screening field's label and help text.
  - **A `member` type:**
    - Admin-only and stored in SQLite (`"storage": "sqlite"`, `"transfer": false`).
    - Marked `"users": "members"`, so it does not transfer.
    - Fields: email, password hash, name, screening answer, registered at, decided at,
      roles.
    - Admission is expressed by roles only (see below). There is no state field next to
      the roles.
    - The type exists so the approval queue in step 2 has data; nothing creates members
      yet.
- **Roles:**
  - `preflow/rbac` is enabled.
  - Staff: `admin` (`*`).
  - Members:
    - `pending` grants nothing; the approval queue is "members with role `pending`";
    - `rejected` grants nothing;
    - `member` grants the members-area permissions that step 2 declares.
    - Approving replaces `pending` with `member`, and rejecting replaces it with
      `rejected` (the flow itself is step 2). A book code registers directly as
      `member`.
- **Codes:** `preflow/codes` is enabled, and one readable code for purpose `members.book`
  exists. The owner chooses it in the admin; it is never committed.
- **Visitors see nothing new.** No public route sets a cookie. The `/tools` page is
  unchanged.

## Decided (by the user in conversation)

- The members area lives under `/tools` and is built in the six steps above, in that
  order.
- Day-one resources: Force Map, Tool Landscape, Pitfall Map. Templates follow.
- A book code gives instant access. Without one, people go through screening and wait
  for approval.
- An invite link only gives access to filling in that one round.
- No talking for now. Counters, ratings, comments and book feedback come later.
- A roles and permissions edit screen in Folio (the RBAC package) is part of this step.
- The members area should test what Preflow and Folio can do; gaps are fixed in the
  framework immediately.
- The site stays EN and DE.

## Calls made by the designer (for review)

- **Step 1 is framework only.** Visitors see nothing until step 2.
- **RBAC replaces simple roles** on smart-stack.works instead of running beside it.
- **No role inheritance and no per-record rules** in RBAC for now.
- **User types are marked in model JSON** (`"users": "<population>"`). One marker
  drives the transfer default, the RBAC panel and step 2's login.
- **Folio derives SQL tables from the model JSON** (create and add only, never drop),
  rather than requiring a hand-written migration per type. *Cost if wrong:* renames
  and type changes still need a hand-written migration.
- **Roles are the only admission truth:** `pending`, `member`, `rejected`. There is no
  state field.
- **Code storage:**
  - Codes are kept in plain text, not hashed. They grant low-value access (one round, or
    skipping screening), readable codes are printed in the book anyway, and a round's
    creator must be able to copy the link again.
  - *Cost if wrong:* a database leak would expose open invite links; revoking the codes
    fixes that.
- **No IP addresses** in redemptions; the throttle uses a salted hash of the IP that
  rotates daily.
- **The member type is defined now** so RBAC and the transfer switch have a real
  population to work on. Its fields may still change in step 2.
- **The settings for Members:** the four settings listed above. The user had no
  preference and accepted the proposal.

## Open for the builder

- Exact class, attribute, package and permission names. The working names above are
  placeholders, except purposes and roles as listed for the site.
- Whether throttling goes into `preflow/core` or its own package.
- How the RBAC form panel attaches to "user types": a marker in model JSON, or an
  interface.
- The order inside this step. Suggested:
  1. Folio on SQL storage and the migrate command;
  2. user types and the transfer switch;
  3. throttling;
  4. extension core;
  5. live settings;
  6. codes;
  7. RBAC;
  8. site adoption;
  9. release 0.20.
- Whether this step is one plan or two (for example the extension core and settings
  first, then RBAC, codes and the site). It is large; the builder may split it.
