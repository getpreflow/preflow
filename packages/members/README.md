# preflow/members

Member accounts for a Preflow site: registration with a confirmed address, sign-in and
sign-out, forgot and reset password, the account page with self-deletion, the member mails, a
pluggable admission rule and hooks for a project's own steps. It needs neither Folio nor rbac
(it requires auth, core, data, routing, i18n, notify, validation, view and devtools). On a
Folio site, Folio adds the admin: the
member list with a waiting badge, a state column and a Decision panel (accept or decline with
an editable message), Settings → Members and the member roles (rbac).

Without rbac, an admitted member (role `member`) has `members.access` and nothing more; with
rbac, rbac's roles decide. The member type (`member`) is the package's own
(`models/member.json`); a site adds fields with a model file `member.json` that says
`"extends": true`.

## Install

Without Folio:

```
composer require preflow/members
php preflow migrate
```

On a Folio site, with the members admin:

```
composer require preflow/members
php preflow folio:extensions:enable rbac
php preflow folio:extensions:enable members
php preflow migrate
php preflow folio:extensions:apply
```

- Add `Preflow\Members\MembersServiceProvider` to `config/providers.php` (on a Folio site after
  `FolioServiceProvider` and `RbacServiceProvider`).
- Without Folio the owner's settings come from `config/members.php` (see Configuration), the
  owner decides waiting members with `php preflow members:decide <email> accept|decline`, and
  the member pages use the package's own layout unless `layout` names the site's.
- The members admin (the `members` extension: member list, Decision panel, Settings →
  Members) is Folio's, there once this package is installed. Its member roles (`pending`,
  `member`, `rejected`) need the `rbac` extension; booting without it is an error. `folio:extensions:apply` creates the roles that are missing and
  never changes one that exists.
- Members live in the storage alias `members` when `config/data.php` has it, else on
  `default`:

  ```php
  'aliases' => ['members' => 'sqlite'],
  ```

- Members sign in through their own guard. In `config/auth.php`:

  ```php
  'guards' => [
      'members' => [
          'class' => Preflow\Auth\SessionGuard::class,
          'provider' => 'members',
          'population' => 'members',
          'remember' => ['cookie' => 'site_member', 'days' => 30, 'path' => '/'],
      ],
  ],
  'providers' => [
      'members' => [
          'class' => Preflow\Auth\Record\RecordUserProvider::class,
          'type' => 'member', 'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles',
      ],
  ],
  // Sessions (and their cookie) only where they are needed: Folio and the member pages.
  'session' => ['cookie' => 'site_session', 'paths' => ['/folio', '/members']],
  ```

- The session `paths` must cover the members `path` (`/members` above); public pages then
  get no session and no cookie. Locale prefixes are stripped first, so `/de/members/login`
  counts as `/members/login`.
- A password change or reset ends the member's other sessions: the member type's
  `auth_epoch` field is the session epoch the guard checks on every request (provider key
  `epoch`, default `auth_epoch`), and the remember rows are revoked.
- The members provider must keep the default epoch field `auth_epoch` (leave `epoch` out):
  the package writes that field.

## Configuration

`config/members.php`, every key optional:

```php
return [
    'path' => '/members',                       // base path of the member pages
    'landing' => null,                          // where sign-in lands; null = path
    'register' => null,                         // where the pages link for registering; null = path/register
    'layout' => '@members/_layout.twig',        // the layout the member pages extend (a Folio site: @folio/frontend/_site.twig)
    'mail' => ['from_name' => null, 'layout' => '@members/mail/layout.twig', 'accent' => '#d42a34'],
    'admission' => Preflow\Members\Admission\AcceptOnConfirm::class,
    'throttle' => ['login' => [10, 900], 'register' => [5, 3600], 'forgot' => [5, 3600], 'resend' => [3, 3600]],
    'password_min' => 10,
    'confirm_ttl' => 86400,
    'reset_ttl' => 3600,
    'retention' => ['unconfirmed_days' => 7, 'declined_days' => 30],
    'privacy_url' => '',                        // the consent's privacy link: one URL or ['en' => '/privacy', 'de' => '/de/datenschutz']
    'next' => ['allow' => []],                  // more places "next" may lead besides the member pages: ['/games/']
    // The owner's settings, without Folio (on a Folio site: Settings → Members):
    'registration_open' => true,
    'notice_email' => null,                     // told about a member waiting for the owner's decision
    'accept_message' => null,                   // one text, or ['en' => …, 'de' => …]; null = the lang files'
    'decline_message' => null,
];
```

- `path` must be below the site root (`''` or `'/'` is a boot error): the member pages own
  everything under it.
- `privacy_url` as a map: a locale without an entry gets the default locale's; empty means the
  consent reads without a link.

Fixed names (stored data depends on them; `MembersConfig` lists them as constants): type
and table `member`, guard `members`, permissions `members.access` and `members.decide`,
roles `pending`, `member` and `rejected`, token purposes `members.confirm` and
`members.reset`, throttle purposes `members.login`, `members.register`, `members.forgot` and
`members.resend`.

Links in mails use `app.url` and the locale prefixes of `config/i18n.php` (the default
locale at the root): `https://site.test/de/members/login`.

## Pages and routes

The provider registers these routes under `path` (in every locale: `/de/members/login`, the
default locale without a prefix):

| Method | Path | Page |
|---|---|---|
| GET | `{path}` | the members home: a default landing (greeting, account, sign out) unless the site has its own page there |
| GET, POST | `{path}/register` | register form; POST registers (same answer for known addresses); GET is a 303 to `register` when that is another page |
| GET | `{path}/confirm/{token}` | the confirm form (a mail scanner's GET uses nothing) |
| POST | `{path}/confirm` | confirms; then the admission decides |
| GET, POST | `{path}/login` | sign in (30-day remember cookie) |
| POST | `{path}/logout` | sign out, to `{path}` |
| GET, POST | `{path}/forgot` | ask for a reset link |
| GET | `{path}/reset/{token}` | the new-password form |
| POST | `{path}/reset` | sets the password, signs in, to `landing` |
| GET, POST | `{path}/resend` | POST sends the confirm link again; GET goes to the login |
| GET | `{path}/account` | the member's account page |
| POST | `{path}/account/password` | change the password |
| POST | `{path}/account/delete` | delete the account, to `register` |

- **The members home (`GET {path}`):** a site that answers that path itself (a page
  `app/pages/members/index.twig`, a controller route, a dynamic `/{slug}` route that matches it)
  keeps its page; otherwise the package serves `@members/landing.twig` (a greeting, the account
  link and sign out; signed out or not admitted: to the login). Only routes registered before
  members boots count: a provider that adds the same path must be listed before
  `MembersServiceProvider`. Sign-out and staff who open the account page go to `{path}`;
  sign-in, reset and an accepted confirmation go to `landing`.
- After a confirmation `Decision::Accept` makes a member, signs them in (remembered) and
  redirects to `landing`; `Decision::Pending` gives the `pending` role, shows the thanks page
  and mails the owner (Members settings `notice_email`, when set). The admission's
  `confirmNotice()` (when not null) is shown on that thanks page, or, for an accepted
  member, flashed as `members.notice` for the landing to show.
- `next` (where to go afterwards) is kept only when it is a member page (`{path}`) or under a
  path of `next.allow` (`'/games/'` allows `/games` and `/games/catan`, not `/gamesfoo`), each
  in any locale; anything else lands on `landing`. A scheme, `//`, a backslash or a dot
  segment is never kept, percent-encoded neither. An `allow` entry that is no site path is an
  error.
- `next` works for sign-in (`?next=` on the login page) and for registration: the register
  page carries `?next=` as a hidden field (the site's own register page posts a `next` field
  too), the member keeps it (the hidden member field `next`; run `preflow migrate` once for
  its column) and an accepted confirmation goes there once instead of `landing`. The
  confirmation clears it, also when the owner decides.
- Every form needs the CSRF token (`csrf_field()` in the templates); every page is
  `private, no-store`.
- Flash notices: `members.notice` (e.g. "Your account is deleted.") is shown by the package
  pages of the register controller; a site whose `register` is its own page reads it there.
  `members.account` is the account page's own.
- Throttles (`throttle` config): sign-in per client and per address, registration per
  client, forgot per client and per address, resend per address (over the limit the page
  stays the same, only the mail is skipped).

## Accounts a project creates

A project that has proven the address itself (an invitation mailed to it) creates the
account confirmed, and may sign it in:

```php
$members = $container->get(Members::class);
if ($members->findByEmail($email) !== null) {
    // the address has an account already: send the person to the sign-in instead
} else {
    $member = $members->register($email, $name, $password, $locale, emailVerified: true);
    if (MemberState::fromRecord($member) === MemberState::Member) {
        $container->get(SignIn::class)->in($member, $request); // the members guard, remembered
    }
}
```

An address has one account: `register()` throws `Preflow\Members\EmailTakenException`
(its `email` property holds the normalised address) when one exists already.

`emailVerified: true` sends no confirmation mail; the admission decides at once as after a
confirmation (`AcceptOnConfirm`: a member; `OwnerApproval`: pending, and the owner is told).
The package never assumes an address is proven: only the caller says so. `SignIn::in()`
refuses a member who is not admitted (a `LogicException`).

## Admission

After a member confirms the e-mail, the configured `Preflow\Members\Admission\Admission`
decides: `AcceptOnConfirm` (a member at once, the default) or `OwnerApproval` (opt-in:
`'admission' => OwnerApproval::class`; the owner decides in Folio's Decision panel, with
`members:decide`, or in code with `Members::decide()`). A site's own rule implements the
interface:

```php
interface Admission
{
    public function validate(array $input, ServerRequestInterface $request, array $baseErrors): array; // field => message
    public function recordValues(array $input): array;                                // stored on the member
    public function afterConfirm(DynamicRecord $member, ServerRequestInterface $request): Decision; // Accept or Pending
    public function confirmNotice(DynamicRecord $member): ?string;                   // a line after confirming, or null
    public function details(DynamicRecord $member, string $for): array;              // label => value
}
```

`validate()` is asked on every post, so its errors show together with those of the form's own
fields (name, email, password, consent). `$baseErrors` are those (field => key): skip a costly
or throttled check (a code lookup) when `$baseErrors !== []`, the post fails anyway.

`details($member, Admission::PANEL)` appear in the Decision panel (`Registered … · label: value`),
`details($member, Admission::NOTICE)` in the owner's notice mail, one paragraph each; an entry
with an int key is shown without a label. The keys of
`recordValues()` must be fields of the member type; a site adds fields with its own
`config/models/member.json`:

```json
{ "key": "member", "extends": true, "fields": { "company": { "type": "string" } } }
```

Site fields come after the package's in the edit form; `"after": "name"` on a field puts it
right after that field instead. The same works for the Members settings (`members_settings.json`
with `"extends": true`).

An admission that also implements `Preflow\Members\Admission\ComposesName` supplies the stored
name from the site's own fields (first and last name): `name(array $input): string`. The
register form then has no name field, a posted `name` is ignored, and `validate()` sees the
composed name as `$input['name']`; an empty one fails like a missing name.

## Hooks

`Preflow\Members\MemberEvents` (a container singleton) lets a project add its steps without
watching routes. Register listeners in a provider's `boot()`:

```php
$events = $container->get(MemberEvents::class);
$events->on(MemberEvents::REGISTERED, fn (array $p) => $invitations->accept($p['member'], $p['request']));
$events->on(MemberEvents::DELETING, fn (array $p) => !$certificates->pendingFor($p['member'])); // false refuses
```

| Event | Payload | When |
|---|---|---|
| `REGISTERED` | `member`, `request` (null when none was given) | a new account (the form, or `Members::register()`) |
| `CONFIRMED` | `member`, `request` | the address is proven (the link, or `emailVerified`); before the admission decides |
| `DECIDED` | `member`, `role` (`member`, `pending`, `rejected`) | the admission or the owner decided |
| `SIGNED_IN` | `member`, `request` | the member pages or `SignIn::in()` signed the member in |
| `DELETING` | `member`, `plan`, `by_member` | before an account is deleted; `false` refuses |
| `DELETED` | `id`, `member` (as it was), `plan` (applied), `by_member` | after |
| `DELETION_REFUSED` | `member`, `plan`, `reason` (`restricted`, `listener`), `notice_sent` | the member's own deletion was refused; return `true` when you told the site |

`on()` with an unknown event name throws. Listeners run in registration order. An exception in
a `DELETING` listener propagates (nothing has changed yet); in every other listener it is logged,
the remaining listeners run and the member action stands. `by_member` is false for a clean-up
no one asked for (`members:prune`, an account removed after its confirm mail failed); those
raise no `DELETION_REFUSED` and mail nobody.

Interfaces a package or site may bind (all optional except the settings, which have a default):

- `MembersSettings`: `registrationOpen()`, `noticeEmail()`, `acceptMessage($locale)`,
  `declineMessage($locale)`. The default reads `config/members.php`; Folio's members admin
  binds Settings → Members.
- `MemberLinks::decisionUrl($member)`: the page where the owner decides, linked from the owner's
  mails. Folio's members admin binds the member's edit page; without a binding the mails are
  text only.
- `MemberDeleteCopies::of($definition)`: tables holding copies of records (Folio's published
  snapshots), counted by a `restrict` self rule.
- `PageLocale::set($locale)`: told a member page's locale before it renders (Folio sets its
  content locale, so the site layout renders in the page's language).
- `MembersDefaults`: a package's defaults under the site's `config/members.php` (Folio names its
  frontend layout as `layout`).

## Deleting an account

A member deletes their own account on the account page; `members:prune` removes unconfirmed and
declined ones. Both go through the data layer's delete plan with the **self rules**: a relation
pointing at `member` may say what happens when the member deletes themselves, apart from what
staff deletes do:

```json
"owner": { "type": "relation", "to": "member", "onDelete": "restrict", "onSelfDelete": "detach" }
```

Without `onSelfDelete` the relation's `onDelete` applies to both. `Members::delete($member)`
returns a `DeletionResult` (`deleted`, `refused`, `told`, `plan`, `reason`):

- a `restrict` self rule, or a `DELETING` listener returning `false`, refuses: nothing is
  deleted, the owner gets a notice mail at `notice_email` (when set) and `DELETION_REFUSED` is
  raised;
- the account page then answers 409 and tells the member either that the site has been told
  (the notice went out, or a listener returned `true`) or to contact the site; the member stays
  signed in;
- otherwise detaches and cascades are applied, then the member is deleted, signed out and their
  tokens and remember rows revoked.

On a Folio site the deletion also reaches published snapshots (cleared pointers, unpublished
cascaded pages). Folio's own record hooks (`#[AfterDelete]` and the like on admin types) do not
run for the records a member's deletion detaches or deletes.

A staff delete in Folio's admin raises no `MemberEvents`: a project that cleans up on every
member deletion, by the member or by staff, also listens to Folio's `#[AfterDelete]` on `member`
(a `#[FolioType('member')]` class) besides `DELETED`.

## Overriding templates

The pages are `@members/{landing,register,confirm,login,forgot,reset,resend,check-inbox,thanks,closed,account}.twig`
plus the shared `_card.twig`, all extending the configured `layout` (default `@members/_layout.twig`,
a standalone page; a Folio site's default is `@folio/frontend/_site.twig`). `@members/decision.twig` is
the Decision panel of Folio's members admin (a fragment in the admin, not a page). A site overrides one by
putting a file of the same name in `resources/members/` (searched before the package; a new
directory needs a fresh request to be picked up).

To change only a part of a page, extend the original through `@members-package` (extending
`@members/register.twig` from the override of the same name would recurse into itself):

```twig
{# resources/members/register.twig #}
{% extends '@members-package/register.twig' %}

{% block admission_fields %}
  <label for="f-code">{{ t('members.code.label') }}</label>
  <input id="f-code" name="code" value="{{ old.code|default('') }}">
{% endblock %}
```

`register.twig` has the blocks `intro`, `admission_fields` (the admission rule's own fields),
`extra_fields` (fields you added to the member type) and `consent_text`; set `privacy_url` in
`config/members.php` for the privacy link, or override `consent_text` (e.g. for a link a Folio
setting chooses). The posted values are in `old` (`old.code`), the errors in `errors`
(`errors.code`). The mail layout (`mail.layout`) is a template
too. Words are changed in the lang files (see Copy and mails), not in templates.

## Cron

The package brings two console commands, `members:prune` and `members:decide`. It lists both in
`composer.json` (`extra.preflow.commands`), so they need no setup and `preflow list` shows them.
Run `members:prune` nightly. The commands need preflow/devtools 0.26. With an older devtools only
the commands are missing, while the member pages and Folio keep working (the package conflicts with
auth, core, data, folio, i18n and routing before 0.38, not with devtools):

```
17 3 * * * cd /path/to/site/current && php preflow members:prune
```

Unconfirmed accounts go after `retention.unconfirmed_days` (7, from registration), declined
ones after `retention.declined_days` (30, from the decision); expired tokens, remember rows and
throttle hits are pruned. It prints counts only (no addresses or names) and a lock file in
`storage/locks` keeps two runs from overlapping. An account a self rule or a hook keeps is
counted (`; kept N (refused by a self rule or a hook)`, only when N > 0).

`members:decide <email> accept|decline` decides a member waiting for the owner (`OwnerApproval`
without Folio's panel): the accepted or declined mail goes out first (the Members settings'
message, else the lang files'), and only when it was sent the role changes.

## Locales

Locales and URL prefixes come from `config/i18n.php`; the package lang files ship `en`, `de`
and `fr`. Without `config/i18n.php` the pages run in a single locale with no prefix.

## In Folio

- Members (sidebar section Members) with a waiting badge and a State column, newest first,
  filterable by Registered, Email confirmed and Decided dates (from/until).
- The Decision panel offers Accept from Waiting or Declined and Decline from Waiting or
  Member; the server refuses anything else. The mail goes out first; when it fails,
  nothing changes. Declining ends the member's remembered sessions.
- Settings → Members settings: `registration_open`, `notice_email`, `accept_message`,
  `decline_message` (translatable; `{name}` and `{login_url}` are filled in). Left empty,
  the panel takes `mail.accepted.body` / `mail.declined.body` of the lang files; the settings
  form shows that standard text, in the language being edited, as the field's placeholder. A
  text stored only for another locale is not used (the member gets the lang default in their
  language).

## Copy and mails

The words of the member pages and mails are in `lang/{en,de,fr}/members.php`. A site changes
single keys in its own `lang/<locale>/members.php`; the rest stays the package's. German is
in the formal "Sie" form; a site that says "du" overrides the German keys.

```php
// lang/de/members.php
return ['mail' => ['confirm' => ['subject' => 'Bitte bestätige deine Adresse']]];
```

- `MemberMailer` sends `confirmLater`, `existingLater`, `resetLater` and `noticeLater` after the
  response (a failure goes to the optional `$onFailure`), and `accepted` / `declined` at once
  (a `TransportException` aborts the decision).
- Each mail is in the member's language (the site default when the site lacks it); the owner's
  notice is in the site default locale. Mail bodies use `{name}`, `{link}`, `{login_url}`,
  `{reset_url}`, `{validity}`, `{email}`, `{details}`, `{folio_url}`. `{folio_url}` is the
  decision page a bound `MemberLinks` names (Folio's members admin: the member's edit page);
  without one the notice uses `mail.notice.body_without_link`, text only.
- `{validity}` (and `:validity` in `inbox.hint`) is how long the link works, from `confirm_ttl` /
  `reset_ttl`: `LinkValidity::confirm($locale)` / `reset($locale)` give "24 hours", "eine Stunde"
  (lang keys `validity.hours` / `validity.minutes`, plural forms).
- The HTML part renders `mail.layout` (default the neutral `@members/mail/layout.twig`) with
  `lang`, `subject`, `accent` and `paragraphs` (escaped text, URLs linked in the accent colour),
  and for the confirm, reset and existing-account mails `action` (`{label, url}`, the primary
  link; label from `mail.{confirm,reset,existing}.action`). The shipped layout shows it as a
  button with the URL below; a site's layout may ignore it. The text part is unchanged.
- The sender is `mail.from_name` with the address of `config/notify.php` `channels.email.from`;
  without a from name the channel's own sender applies.
- When a new member's confirm mail fails, remove the account with
  `Members::removeIfUnconfirmed($id)`: it keeps a member who confirmed meanwhile.

## Upgrading to 0.38

The full list is in the framework's 0.38 release checklist; what a site with members checks:

- **The default admission is `AcceptOnConfirm`** (was owner approval). A site that wants the
  owner to decide sets `'admission' => Preflow\Members\Admission\OwnerApproval::class`.
- **The default layout** is the package's `@members/_layout.twig` on a site without Folio; a
  Folio site keeps `@folio/frontend/_site.twig`. Set `layout` to keep a layout of your own.
- **No Folio or rbac needed.** The package no longer requires them; Folio carries the members
  admin when this package is installed (it still needs the `rbac` extension).
- **`config/auth.php`:** the provider class is `Preflow\Auth\Record\RecordUserProvider`.
  `Preflow\Folio\Users\RecordUserProvider` keeps working for one release (a deprecation is
  logged); `new` with the old constructor (a `TypeCatalog`) no longer works.
- **`preflow migrate`** on SQL storage: the member type gains the hidden field `next`; without
  the column a registration that carries a `next` fails.
- **`next.allow`:** `next` keeps today's rule (member pages only) unless you add prefixes.
- **`GET {path}`** now has a default landing where the site had none (a 404 before). A site route
  on that path wins.
- **`Members::delete()`** returns a `DeletionResult` and follows the self rules (`onSelfDelete`,
  else `onDelete`): a `restrict` relation now keeps the account instead of leaving a dangling id.
- **`MemberEvents`, `ComposesName`, the mail `action`** are new; a mail layout that ignores
  `action` is unchanged.
- **Owner notice without Folio:** without a `MemberLinks` binding the notice uses
  `mail.notice.body_without_link`; a site that overrode only `mail.notice.body` gets the package's
  text without the link there.
- **`Links::folioMember()`** is deprecated (unused by the package) and goes in the next release.
