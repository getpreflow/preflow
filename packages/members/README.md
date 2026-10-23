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
- The member roles (`pending`, `member`, `rejected`) need the `rbac` extension; booting
  without it is an error. `folio:extensions:apply` creates the roles that are missing and
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
          'class' => Preflow\Folio\Users\RecordUserProvider::class,
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
];
```

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
| GET, POST | `{path}/register` | register form; POST registers (same answer for known addresses) |
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
  and mails the owner (Members settings `notice_email`, when set).
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
    public function validate(array $input, ServerRequestInterface $request): array;   // field => message
    public function recordValues(array $input): array;                                // stored on the member
    public function afterConfirm(DynamicRecord $member): Decision;                    // Accept or Pending
    public function details(DynamicRecord $member, string $for): array;              // label => value
}
```

`details($member, Admission::PANEL)` appear in the Decision panel (`Registered … · label: value`),
`details($member, Admission::NOTICE)` in the owner's notice mail, one paragraph each; an entry
with an int key is shown without a label. The keys of
`recordValues()` must be fields of the member type; a site adds fields with its own
`config/models/member.json`:

```json
{ "key": "member", "extends": true, "fields": { "company": { "type": "string" } } }
```

## In Folio

- Members (sidebar section Members) with a waiting badge and a State column.
- The Decision panel offers Accept from Waiting or Declined and Decline from Waiting or
  Member; the server refuses anything else. The mail goes out first; when it fails,
  nothing changes. Declining ends the member's remembered sessions.
- Settings → Members settings: `registration_open`, `notice_email`, `accept_message`,
  `decline_message` (translatable; `{name}` and `{login_url}` are filled in). Left empty,
  the panel takes `mail.accepted.body` / `mail.declined.body` of the lang files. A text stored
  only for another locale is not used (the member gets the lang default in their language).

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
