# Members Split — Design

**Date:** 2026-10-26
**Status:** Draft for the owner's review (builder check done, owner's answer in).
**Context:** step 1 of the BG Genius framework order in `ROADMAP.md` ("Next Up"), Phase 2 of
`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`. Current version: v0.37.0.

## Why

- BG Genius gets learner accounts (its Phase 4). Hosts invite people by email to a game; the invited
  person should get a friendly welcome, register fast (name and password only, no confirmation mail,
  because the invitation proves the address) and land directly in the game's learning path (owner).
- `preflow/members` today needs Folio to run: its member pages use Folio's user class, settings store,
  URL patterns and content language, and `composer.json` requires `preflow/folio`. The umbrella design
  decided the split: a members core usable without Folio, with Folio carrying its admin.
- holz-objekte.org (porting) and smart-stack.works use members. holz-objekte.org built site-local
  workarounds for missing members features (`docs/framework-followups.md` there); the generic ones
  belong in the framework now.

## Standing rules (owner, 2026-10-26)

- **The framework holds abstractions, interfaces, hooks and generic mechanisms only.** A feature one
  project needs is built in that project on top of them. BG Genius's invitations, hosts, groups, welcome
  page, "land in this game" and "expired link" page are BG Genius app code; holz-objekte.org's company
  admission and checkout return are its own. The framework gives them what they need to be easy to build.
- **A package's official admin UI lives in Folio,** the way media's library does: the package is a core
  without Folio, Folio carries its admin and switches it on when the package is installed. Custom admin
  needs use userland Folio extensions (`app/Folio/…`), not Folio.
- **Folio is only a UI adapter** (earlier rule): every capability is in the core behind public interfaces.

## Goals, non-goals, anti-goals

**Goals:**
- `preflow/members` runs without Folio: registration, email confirmation, sign-in and sign-out, forgot
  and reset password, the account page with self-deletion, member mails. It depends on auth, data,
  routing, view, notify, validation and i18n, not on Folio.
- Folio carries the members admin (moved from the package): the Members list and member edit, the
  approval panel, Settings → Members, the menu section, permissions, staff deletes through the delete
  plan.
- The generic mechanisms below, each proven by a project using it (BG Genius's invitation path,
  holz-objekte.org's workarounds removed).
- An audit of the members package against the standing rule: anything project-specific becomes a hook,
  an option or an example, or moves out.

**Non-goals:**
- BG Genius's invitations, hosts, groups, member area and progress (app code, its Phase 4).
- The codes redemption hook (the next step on the roadmap, its own design).
- Social sign-in, two-factor authentication, payments.

**Anti-goals:**
- A BG Genius- or holz-objekte.org-specific feature inside the framework.
- smart-stack.works or holz-objekte.org breaking in ways the release notes don't name.
- A member's own deletion leaving records that point at nobody, or half-deleted data.

## Design

### 1. The split

- **`preflow/members` (core):** the member pages, `Members` (the record API), `MemberMailer`, admission,
  the hooks, `config/members.php`. It no longer requires `preflow/folio` or `preflow/rbac`.
  - Where it uses Folio today, it uses an interface or config instead: the signed-in member through
    `preflow/auth` (a record user of the member type from the data layer), URL patterns through
    `preflow/routing`, the content language through `preflow/i18n`, settings through a
    `MembersSettings` interface whose default reads `config/members.php` (Folio's implementation reads
    Settings → Members).
  - Member pages render with the site's layout (`config/members.php` `layout`); the default no longer
    points at a Folio template.
- **Folio (members admin):** everything staff see, moved from the package's Folio extension into Folio,
  switched on when `preflow/members` is installed (as Folio does for media): the member type's admin,
  the approval panel, Settings → Members, permissions (`members.access`, `members.decide`), the menu
  section, list defaults, Folio's `MembersSettings` implementation.
- **Projects** add what is theirs through the core's interfaces and userland Folio extensions.

### 2. Admission (who gets in)

- The `Admission` interface stays (validate, record values, after confirm, notices, details).
- **Default changes to automatic admission** after the email is confirmed (`AcceptOnConfirm`); today the
  default is owner approval.
- **Moderated registration stays shipped and opt-in** (owner): `'admission' => OwnerApproval::class` in
  `config/members.php`. The core keeps pending/approved/declined and the mails; the approve/decline
  panel is in Folio's members admin; a site without Folio approves through `Members::decide()` or a
  console command.
- **The admission may compose the name** (holz-objekte.org asks for first and last name): a new optional
  method or interface (e.g. `ComposesName::name(array $input): string`) lets an admission supply the
  stored `name`; a site's form may then leave out the single name field.

### 2a. Member state and access without rbac (my call, from the builder check)

- The member record's `roles` field keeps holding `pending`, `member` or `rejected`, as today, so
  neither site needs a data migration (holz-objekte.org's importer writes `['member']`; smart-stack.works
  gates resources and private files on `members.access`).
- Without rbac, the core's own access rule gives an admitted member (`member`) the permission
  `members.access` (and nothing else); pending and rejected members get nothing. With rbac installed,
  rbac's roles decide as today, and finer permissions (BG Genius hosts) stay rbac's.
- The field type without rbac is a plain list field; with rbac it stays `rbac_roles` (the Folio
  members admin uses it). Seeding the `member`/`pending`/`rejected` roles moves to Folio's members
  admin install (rbac present) — the core needs no seeding.

### 3. A checked destination (`next`)

- Registration, email confirmation and sign-in all carry a `next` destination: kept with the pending
  account (so it survives the confirmation mail) and followed once after the step completes.
- It is checked the same way everywhere, with today's `SafeNext` rule as the **default: only paths under
  the members path** (in any locale), never a scheme, `//` or backslash (owner: keep it narrow).
- **A project allows more paths explicitly** (owner): `config/members.php` `next.allow` (a list of path
  prefixes, e.g. `['/games/']` for BG Genius), or a callback for anything finer. Existing sites keep
  today's behaviour (smart-stack.works' `MemberLoginTest::nextCases` stays green); holz-objekte.org's
  `/konto…` rule is the default.
- BG Genius uses it for "after registering, go to this game"; holz-objekte.org for "back to the
  checkout".

### 4. Accounts with an already-verified address

- `Members::register(…, emailVerified: true)` (or a separate method) creates an active account
  without a confirmation mail; admission still runs (`afterConfirm` at once).
- **Only the project decides when an address is proven** (e.g. BG Genius after a valid invitation code
  for that address). The core never infers it from user input.
- A helper signs the new member in at once (the same session handling as sign-in).

### 5. Hooks

Events (or listener interfaces) the core calls, so projects add their steps without watching routes:
- after registration (the new record, the request);
- after email confirmation and after admission decided;
- after sign-in;
- before and after self-deletion (see 6).

The exact mechanism follows what the framework already uses for extension points (container-bound
listeners or the planned lifecycle events), decided in the plan.

### 6. Deleting an account: staff rule and self rule

- A relation declares a second rule for deletions people make themselves (owner's idea):

  ```json
  "owner": { "type": "relation", "to": "member",
             "onDelete": "restrict", "onSelfDelete": "detach" }
  ```

  - `onDelete` applies when staff delete (Folio, as today).
  - `onSelfDelete` applies when a member deletes their own account. Without it, `onDelete` applies to
    both, so existing model files don't change.
- This lives in `preflow/data`: the delete planner is told who deletes (staff or self).
- The member's own deletion (`/account` "delete my account" and `members:prune`) goes through the
  delete planner with the self rules, then the hooks. It no longer calls `deleteType()` directly.
- **When a self rule says `restrict`** (the project's deliberate choice): nothing is deleted; the core
  sends a notice to the site's notification address (`notice_email`, which moves from Folio's settings
  into `MembersSettings`) and calls the hook. The member is told "the site has been told" only when a
  notice actually went out (an address is set, or a listener handled it); otherwise the message asks
  them to contact the site (my call).

### 7. Smaller generic follow-ups

- **Rehash on sign-in** in `preflow/auth` (so it covers members and Folio staff alike): after a
  successful verify, when `needsRehash()` is true, the provider stores a new hash (a small optional
  interface on the user provider, e.g. `UpdatesPasswordHash`). Never logged; a failure leaves the
  account as it is.
- **A landing page at `{path}`** (e.g. `/konto`): the core serves a small default account landing
  (greeting, links to account settings and sign-out); a site replaces it with its own template or
  controller (BG Genius's member area, holz-objekte.org's "Meine Objekte").
- **A primary link for mail layouts:** `MemberMailer` passes `action: {label, url}` to the mail layout
  for the confirmation, reset and "existing account" mails.

### 8. Audit of members against the standing rule

During the work, list every members feature and sort it: generic (stays), project-specific (becomes a
hook/option/example or moves to the project). Known candidates to check: list defaults and labels like
"Registered At", the decision panel's details format, retention defaults, the consent/privacy link.

## Constraints

- PHP 8.4, the existing packages; breaking changes are allowed in 0.x and every consumer is migrated
  in the same release (umbrella design's release policy).
- Members' public pages stay as they are for existing sites unless a release note names a change; the
  German public texts keep "Sie".
- Members' admin texts stay in `members-admin` (0.37), now delivered by Folio's members admin.
- smart-stack.works and holz-objekte.org set their admission explicitly, so the default change does not
  affect them; any other config change is in the upgrade notes.

## Verification

- The core's own tests run without Folio installed (a test app with auth + data only).
- smart-stack.works and holz-objekte.org upgrade to the release; holz-objekte.org removes its
  workarounds for `next`, rehash, the composed name, owner deletion and the `/konto` landing, and its
  tests stay green. (holz-objekte.org is upgraded in its own session; this design names what it can
  remove.)
- BG Genius's invitation path is buildable from these pieces (checked in its Phase 4 design).

## Upgrade notes the plan must cover (from the builder check)

- `config/auth.php` on both sites names `Preflow\Folio\Users\RecordUserProvider`; if it moves, a
  note (and a class alias for one release).
- smart-stack.works relies on the old default member layout `@folio/frontend/_site.twig`; the new
  default needs a note or the site sets `layout` explicitly.
- Both sites already serve `GET {path}`: a site's own route must win over the new default landing.
- Folio keeps the extension name `members` and the `members_settings` type key that smart-stack.works
  extends.

## Left to the builder

- The hook mechanism (section 5) and the exact method names.
- The default landing page's content and template.
- The file layout of the Folio members admin inside Folio.

## Size

Large: one design, one plan of about 7–8 tasks (data self rule; auth rehash; core decoupled from Folio;
Folio members admin; `next` and verified accounts; hooks and self-deletion; landing, mail action,
composed name; audit, docs, release). One release, 0.38.
