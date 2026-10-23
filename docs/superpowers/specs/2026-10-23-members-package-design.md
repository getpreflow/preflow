# preflow/members — Member Accounts as a Package — Design

**Date:** 2026-10-23
**Release:** v0.26.0
**Why now:** step 2 of the holz-objekte.org port
(`docs/superpowers/plans/2026-10-23-holzobjekte-port-overview.md`). holz-objekte needs company
accounts (register, confirm email, sign in, reset, account page) exactly like smart-stack.works'
members, but with company fields, French, another URL and no book codes. Today all of that lives
in smart-stack's app code (~1,500 generic lines, ~2,400 lines of tests).
**Status:** written without a question round (Steffen asked to go straight on); the calls are mine.
**Inventory:** smart-stack `app/Members/*`, `app/Folio/Members/*`, `app/Controllers/{MemberAuth,Account,Tools}Controller.php`,
`app/Console/PruneMembersCommand.php`, `config/members.php`, `lang/*/members.php`,
`resources/folio/frontend/tools/*`, `resources/mail/layout.twig`, `tests/Integration/Members*|Registration|MemberLogin|MemberApproval|Account|PruneMembers`.

## Goals

1. A framework package `preflow/members` that gives any Preflow + Folio site member accounts:
   registration with e-mail confirmation, sign-in with remember-me, sign-out, password reset,
   resend confirmation, an account page (change password, delete account), the member type in
   Folio with a decision panel and waiting badge, mails, and `members:prune`.
2. **Admission is pluggable:** after confirmation a member is accepted at once, waits for an
   owner decision, or a site's own rule decides (smart-stack: a valid book code accepts at once,
   otherwise a screening answer goes to the owner).
3. **The member type is extensible by the site** (holz-objekte: company, address, phone, web;
   smart-stack: book code, screening answer) through a generic Folio feature, not a members
   special case.
4. smart-stack.works switches to the package with **no data migration and no visible change**:
   same type key, table, token purposes, guard, cookies, URLs, mails, copy.
5. URL base path, languages, mail sender and layout, page layout and copy are configuration or
   template/lang overrides, not code.

## Non-goals

- Staff accounts and roles (step 3). Members stay a separate population from Folio staff.
- Social login, 2FA, member profiles beyond the account page, member-to-member features.
- holz-objekte's object submission (a project extension in that site).

## Anti-goals (carried over from the step 2 members design; the package must keep them)

- No cookie on public pages (the session cookie only on the configured paths).
- No account enumeration: register, forgot and resend answer the same for known and unknown
  addresses, and unknown logins cost the same hashing time.
- A failed decision mail aborts the decision.
- Personal data never leaves the server (member types are `transfer: false` via `users`).

## Decisions

### Package shape
`packages/members` (namespace `Preflow\Members`), laid out like `preflow/codes`: composer
`extra.folio.extension` = `Preflow\Members\Extension`, `src/models/member.json`,
`src/templates/` (Twig namespace `@members`), `lang/{en,de,fr}/members.php`, a
`MembersServiceProvider` the site lists in `config/providers.php`. Requires `preflow/folio`,
`preflow/auth`, `preflow/notify`, `preflow/rbac` (`^0.26 || @dev` where new API is used).

### Configuration `config/members.php` (all optional; defaults are smart-stack's current values)
- `path`: base path of the member pages, default `/members` (smart-stack sets `/tools`,
  holz-objekte e.g. `/konto`). Locale prefixes follow `config/i18n.php` (`/de/tools`, `/fr/konto`).
- `type` `member`, `guard` `members`, `permission` `members.access`, token purposes
  `members.confirm` / `members.reset` (constants; listed so a site can see them, not to rename).
- `landing`: where sign-in lands, default `{path}` (the members home); `safeNext` accepts only
  paths under `{path}`.
- `layout`: the page layout templates extend, default `@folio/frontend/_site.twig`.
- `mail`: `from_name`, `layout` (default `@members/mail/layout.twig`), `accent` (link colour).
- `admission`: class name implementing `Preflow\Members\Admission\Admission`; shipped:
  `AcceptOnConfirm` and `OwnerApproval` (default `OwnerApproval`).
- `throttle`, `password_min`, `confirm_ttl`, `reset_ttl`, `retention` — today's keys and values.
- `privacy_url`: the register consent's privacy link, one URL or locale => URL (default `''`, no link).
- `path` may not be `''` or `/` (boot error).

### Admission hook
```php
interface Admission {
    /** Extra registration rules on the submitted input; field => message. Asked on every post; $baseErrors
     *  are the form's own (name, email, password, consent): skip costly checks when $baseErrors !== []. */
    public function validate(array $input, ServerRequestInterface $request, array $baseErrors): array;
    /** Values to store on the new member record from the input (beyond email/name/password/language). */
    public function recordValues(array $input): array;
    /** After the e-mail is confirmed: Decision::Accept (member now, signed in) or Decision::Pending (owner decides). */
    public function afterConfirm(DynamicRecord $member, ServerRequestInterface $request): Decision;
    /** A line shown after confirming (thanks page, or flashed `members.notice` for an accepted member), or null. */
    public function confirmNotice(DynamicRecord $member): ?string;
    /** Lines for the decision panel (`Admission::PANEL`) and the owner's notice mail (`Admission::NOTICE`): label => value. */
    public function details(DynamicRecord $member, string $for): array;
}
```
smart-stack keeps its rule as `App\Members\BookCodeOrScreening implements Admission` (code check
at submit only when the base fields pass, redeem at confirm, `book_code_skips_screening`, screening answer). Its form fields come
from a template block the site overrides.

### Extending an extension's type (Folio, generic)
A site model file whose key equals an enabled extension's type and which says
`"extends": true` adds to that type instead of colliding with it: its `fields` are appended after
the extension's fields, and its `list.columns` (if given) replace the extension's. Redefining a
field the extension defines, or changing `storage`/`table`/`users`/`id_field`, is a boot error
naming the field. Works for every extension type (members, codes, rbac). The TableSync step adds
the new columns. Without `"extends": true` a duplicate key keeps today's error.

### Pages, templates, copy
- Package templates: `@members/{login,forgot,reset,confirm,resend,check-inbox,thanks,closed,account,register}.twig`
  plus `_card.twig`, all extending the configured `layout`. A site overrides any of them by
  placing a file of the same name in `resources/members/` (searched before the package).
  `register.twig` has a `{% block admission_fields %}` and a `{% block extra_fields %}` for site
  fields.
- Copy lives in `lang/{en,de,fr}/members.php` under the keys smart-stack uses today; a site's
  `lang/<locale>/members.php` overrides keys. French is new (holz-objekte).
- Mails: subjects and bodies from the lang files (today's `MailCopy` text moves there),
  `{name}`/`{link}` placeholders, rendered in the mail layout.

### Routes and controllers
The package registers its routes under `{path}` for every locale: `GET/POST {path}/register`,
`GET {path}/confirm/{token}`, `POST {path}/confirm`, `GET/POST {path}/login`, `POST {path}/logout`,
`GET/POST {path}/forgot`, `GET {path}/reset/{token}`, `POST {path}/reset`, `GET/POST {path}/resend`,
`GET {path}/account`, `POST {path}/account/password`, `POST {path}/account/delete`.
The members *home* (`GET {path}`) stays the site's (smart-stack: the tools entry/toolbox;
holz-objekte: "Meine Objekte"); the package only redirects there. When `register` is another
page than `{path}/register`, `GET {path}/register` is a 303 to it (localized); the POST stays.

### Folio extension
Member type (`member.json`: email, password_hash, name, language, registered_at,
email_confirmed_at, decided_at, roles; `users: members`; storage alias `members`, falling back to
`default`), `MemberAdmin` (unique email, waiting badge, state column, decision panel showing
`Admission::details()`, accept/decline actions), permissions `members.access`, `members.decide`,
roles pending/member/rejected installed once, settings `registration_open`, `notice_email`,
`accept_message`, `decline_message`. smart-stack's own settings (`book_code_skips_screening`,
`screening_*`, `welcome_note`) move into its own small site extension.

### Fixed while extracting (from the smart-stack rulings)
- The confirm-mail `onFailure` delete re-checks the member is still unconfirmed (race).
- A password change or reset ends the member's other sessions (remember-me rows and a session
  generation check), not only the current one.
- The server enforces the decision state matrix (accept/decline only from allowed states) on
  crafted POSTs.
- Unused synchronous mailer methods and the unused `errors.mail` key go; the site URL default
  lives in one place (`app.url`); `private_login` is per locale.

### Migration for smart-stack
Same type key `member`, same table, same token purposes, guard `members`, cookies `ss_member` /
`ss_session`, `path` `/tools`, `admission` `BookCodeOrScreening`, its templates and copy as
overrides where they differ from the package defaults. Its generic tests move into the package
(as package tests on a scaffold app); its site tests keep the toolbox, book code and the full
flows through its own URLs. Live data needs nothing; the deploy runs `migrate` and
`folio:extensions:apply` as today.

## Review focus

- smart-stack after the switch: every existing members test passes unchanged in meaning; a
  side-by-side screenshot of each members page and mail shows no difference.
- A second site with `path` `/konto`, three locales and `AcceptOnConfirm`: register → confirm →
  signed in, French copy and URLs throughout.
- An extension type extended by a site field that later the extension also adds: clear boot error.
- Enumeration: identical responses and timing class for known/unknown e-mails on register,
  forgot, resend and login.
- No session cookie on a public page of either site.
