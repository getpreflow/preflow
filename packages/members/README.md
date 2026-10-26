# preflow/members

Member accounts for a Preflow + Folio site: the member type in Folio with a waiting badge,
a state column and a Decision panel (accept or decline with an editable message), the
member roles, and a pluggable admission rule.

## Install

```
composer require preflow/members
php preflow folio:extensions:enable rbac
php preflow folio:extensions:enable members
php preflow migrate
php preflow folio:extensions:apply
```

- Add `Preflow\Members\MembersServiceProvider` to `config/providers.php` (after
  `FolioServiceProvider` and `RbacServiceProvider`).
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
    'layout' => '@folio/frontend/_site.twig',   // the layout the member pages extend
    'mail' => ['from_name' => null, 'layout' => '@members/mail/layout.twig', 'accent' => '#d42a34'],
    'admission' => Preflow\Members\Admission\OwnerApproval::class,
    'throttle' => ['login' => [10, 900], 'register' => [5, 3600], 'forgot' => [5, 3600], 'resend' => [3, 3600]],
    'password_min' => 10,
    'confirm_ttl' => 86400,
    'reset_ttl' => 3600,
    'retention' => ['unconfirmed_days' => 7, 'declined_days' => 30],
    'privacy_url' => '',                        // the consent's privacy link: one URL or ['en' => '/privacy', 'de' => '/de/datenschutz']
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

- **The members home (`GET {path}`) is the site's own page** (a toolbox, "my objects"); the
  package only sends people there: after sign-out, and staff who open the account page.
  Sign-in, reset and an accepted confirmation go to `landing`.
- After a confirmation `Decision::Accept` makes a member, signs them in (remembered) and
  redirects to `landing`; `Decision::Pending` gives the `pending` role, shows the thanks page
  and mails the owner (Members settings `notice_email`, when set). The admission's
  `confirmNotice()` (when not null) is shown on that thanks page, or, for an accepted
  member, flashed as `members.notice` for the landing to show.
- A sign-in's `next` is kept only when it is a member page (`{path}` in any locale);
  anything else lands on `landing`.
- Every form needs the CSRF token (`csrf_field()` in the templates); every page is
  `private, no-store`.
- Flash notices: `members.notice` (e.g. "Your account is deleted.") is shown by the package
  pages of the register controller; a site whose `register` is its own page reads it there.
  `members.account` is the account page's own.
- Throttles (`throttle` config): sign-in per client and per address, registration per
  client, forgot per client and per address, resend per address (over the limit the page
  stays the same, only the mail is skipped).

## Admission

After a member confirms the e-mail, the configured `Preflow\Members\Admission\Admission`
decides: `AcceptOnConfirm` (a member at once) or `OwnerApproval` (the owner decides in
Folio, the default). A site's own rule implements the interface:

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

## Overriding templates

The pages are `@members/{register,confirm,login,forgot,reset,resend,check-inbox,thanks,closed,account}.twig`
plus the shared `_card.twig`, all extending the configured `layout`. `@members/decision.twig` is
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

Run `members:prune` nightly (the package registers the command through
`extra.preflow.commands`, `preflow list` shows it). That needs preflow/devtools 0.26; with an
older devtools only the command is missing, the member pages and Folio work (the package
conflicts with auth, data, folio and i18n before 0.26, not with devtools):

```
17 3 * * * cd /path/to/site/current && php preflow members:prune
```

Unconfirmed accounts go after `retention.unconfirmed_days` (7, from registration), declined
ones after `retention.declined_days` (30, from the decision); expired tokens, remember rows and
throttle hits are pruned. It prints counts only (no addresses or names) and a lock file in
`storage/locks` keeps two runs from overlapping.

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
  `{reset_url}`, `{validity}`, `{email}`, `{details}`, `{folio_url}`.
- `{validity}` (and `:validity` in `inbox.hint`) is how long the link works, from `confirm_ttl` /
  `reset_ttl`: `LinkValidity::confirm($locale)` / `reset($locale)` give "24 hours", "eine Stunde"
  (lang keys `validity.hours` / `validity.minutes`, plural forms).
- The HTML part renders `mail.layout` (default the neutral `@members/mail/layout.twig`) with
  `lang`, `subject`, `accent` and `paragraphs` (escaped text, URLs linked in the accent colour).
- The sender is `mail.from_name` with the address of `config/notify.php` `channels.email.from`;
  without a from name the channel's own sender applies.
- When a new member's confirm mail fails, remove the account with
  `Members::removeIfUnconfirmed($id)`: it keeps a member who confirmed meanwhile.
