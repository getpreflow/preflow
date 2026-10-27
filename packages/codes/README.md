# preflow/codes

Codes that grant something for one purpose: readable codes (a book code a visitor types)
and random codes (an invite link), with expiry, usage limits, revocation and a throttled
redeem.

## Install

```
composer require preflow/codes
php preflow folio:extensions:enable codes   # only with Folio: its built-in codes admin
php preflow migrate
```

- Folio is optional: without it, skip the `folio:extensions:enable` line (the core defines the type `code`, and the
  table is created by `preflow migrate`).
- Add `Preflow\Codes\CodesServiceProvider` to `config/providers.php`. It binds `Codes` and registers the `code_redemption` migration.
- The alias is optional: codes live in the storage alias `codes` when the site has it, else in
  `default`. To keep them apart, add the alias to `config/data.php`:

  ```php
  'aliases' => ['codes' => 'sqlite'],
  ```

- `preflow migrate` creates the redemptions table (package migration) and the `code`
  table (the core's own sync, or Folio's with its admin). It migrates the `sqlite` driver unless `--storage=` names
  another, so point the alias at the driver you migrate.

## Purposes

`config/codes.php` (a missing file means no purposes):

```php
return [
    'purposes' => [
        'members.book' => ['label' => 'Book code', 'readable' => true],
        'forcemap.round' => ['label' => 'Force Map round', 'readable' => false],
    ],
    'throttle' => ['max' => 10, 'window' => 900],
];
```

## Codes

```php
$codes = $container->get(Preflow\Codes\Codes::class);

$codes->issue('members.book', ['edition' => 1], 'smart-stack 2026'); // "SMARTSTACK2026"
$codes->issue('forcemap.round', ['team' => 't1'], expiresAt: $in7Days, maxUses: 5); // random

$result = $codes->redeem($typed, 'members.book', $member->getId(), $throttle->client($request));
if (!$result->ok) {
    // One generic message for visitors, whatever $result->reason is.
}
```

- A readable code (given to `issue()`) is stored upper case without whitespace and dashes,
  4–64 characters `A-Z0-9`, and only for purposes with `readable: true`. Visitors may type
  it in any case, with spaces or dashes (also Unicode ones such as an en dash, a
  non-breaking space or a zero-width space, as word processors and phones insert them).
- A random code is 16 random bytes, base64url without padding (22 characters). Lookups trim
  it, nothing more.
- Codes are unique within their purpose; an unknown purpose throws. Codes are bearer tokens:
  they are compared with `hash_equals`.
- `check()` says whether a code would be accepted and counts no use. Give it a client
  (`$codes->check($typed, 'members.book', $throttle->client($request))`) when it answers a
  visitor, e.g. a live "is this code valid?" form: it is throttled like `redeem()` (same
  key, every failure counts), so it cannot be used to guess codes. `redeem()` counts a use
  and records a redemption (`redemptions($codeId)`: id, redeemer, time; no IP address or
  user agent).
- **Uses are counted atomically**, so `max_uses` holds under concurrent redeems: on SQL one
  conditional `UPDATE` (`DataManager::execute()`; `uses` and `max_uses` are compared as
  numbers, though Folio stores them as TEXT), on the JSON driver a file lock in
  `storage/locks`. The last use goes to one request; the others get `used_up`.
- `Result`: `ok`, `reason` (`unknown`, `expired`, `used_up`, `revoked`, `throttled`, `wrong_recipient`),
  `payload` (on success) and `codeId`.
- With a client (`Throttle::client($request)`), every failed redeem counts against it under
  `codes.{purpose}`; after `throttle.max` failures within `throttle.window` seconds, even a
  valid code is refused as `throttled`. Without a client nothing is throttled.
- `revoke($codeId)` stops a code for good.
- With Folio, codes are the type `code` (*Library → Codes*, Folio's built-in `codes` extension); neither codes nor redemptions are
  exported or imported. The `purpose` field (`codes_purpose`) offers the configured purposes.

## Storage

Codes and redemptions live in the storage alias `codes` when `config/data.php` has one,
otherwise in `default`; either must name a SQL driver or the JSON driver. A missing alias is
no error. The failure counters of the throttle live in the table `preflow_throttle` of the
driver named `throttle` when one is configured (an optional alias), otherwise in the default
storage.

## Admin

The admin belongs to Folio (its built-in `codes` extension, texts in the translation group
`codes`); `preflow/codes` itself runs without Folio and defines the type `code` on its own.
*Library → Codes* lists the codes with a *Redemptions* column: the number of redemptions, or
`revoked` / `expired`. The list filters by *Expires at* (from/until) and *Form* (readable /
random).

- **Create:** the admin creates readable codes only, for purposes with `readable: true`. It cannot
  create codes bound to a recipient (those come from `issue(..., recipient:)`).
  The code is normalised (upper case, no spaces or dashes) and must be 4–64 characters
  `A-Z0-9` and unique within its purpose; otherwise the form shows the error. Random codes
  come from `Codes::issue()`.
- **Edit:** only *Label*, *Expires at* and *Max uses* change. Code, purpose, form, uses,
  revocation and payload keep their stored values, whatever the form posts. Uses,
  *Revoked at* and form are shown as read-only text; the payload is not shown (nor taken
  on create: an admin-made code has an empty payload).
- **Revoke:** the *Revoke* button on a code needs the permission `codes.revoke` and
  `code.edit`. It stops the code at once.
- **Redemptions:** the edit form lists who redeemed the code (the redeemer reference you
  passed to `redeem()`) and when.
- **Permissions:** `code.view|create|edit|delete` and `codes.revoke`.

## Privacy

A code is a bearer token. Redemptions store the redeemer reference you pass and the time,
never an IP address or user agent. The throttle counts failures per client key
(`Throttle::client($request)`, a daily-rotating salted hash, never the raw IP) in
`preflow_throttle`, not with the redemption. Codes and
redemptions are never exported or imported with content, so they do not travel between
sites.

## Recipients, renewal and ttl

- `issue(..., recipient: 'anna@example.org')` binds a random code to an address (lower-cased, trimmed;
  one use). `redeem($code, $purpose, $id, $client, recipient: $email)` then accepts only that address;
  otherwise the reason is `wrong_recipient` (counted by the throttle, no use spent). `check()` without an
  address passes and returns the address in `Result::$recipient`.
- `record()`, `get()` and `forRecipient($email, $purpose, open: true)` return `CodeRecord`s (newest first).
- `renew($codeId, $expiresAt)` gives a live code a new expiry. A purpose with `'ttl' => 30` (days) in
  `config/codes.php` expires codes issued without `expiresAt` after that time.
- Readable codes cannot be bound to a recipient (a typed code is meant to be shared), and a bound code
  holds exactly one use. The admin cannot create bound codes.
- `forRecipient($email, 'purpose')` lists a person's open codes (pass `open: false` to include used,
  revoked and expired ones). Recipients are never exported.

## Events

`Preflow\Codes\CodeEvents` is bound in the container; register listeners in a provider's `boot()`:

```php
$container->get(CodeEvents::class)->on(CodeEvents::REDEEMED, function (array $e): void {
    // $e: code (CodeRecord), purpose, redeemer, payload
    $groups->add($e['payload']['group'], $e['redeemer']);
}, 'bggenius.invite'); // optional: only this purpose
```

- `codes.redeemed` runs inside `redeem()`, after the use is counted and the redemption stored.
  If a listener throws, the package undoes its own state (the use is given back, the redemption
  deleted) and rethrows, so the person can try again. This is not a throttle failure.
- The package cannot roll back your writes: keep them in your own transaction, make the listener
  idempotent, and send no mails before you are done.
- `codes.revoked` runs after the revoke is stored; an exception propagates, the revoke stands.

## Mail

`CodeMailer::send(CodeRecord $code, string $to, string $url, array $options = []): bool` sends one
invitation through `preflow/notify`. `$url` is the link your app built (a path is made absolute with
`app.url`); only that link is linked in the mail. Options: `subject`, `message` (plain text, escaped),
`from_name`, `language` (default the site's), `template` and `vars` (your own mail template). It returns
`false` on a transport failure (logged without the address); the code stays valid. The words are in the
translation group `codes-mail` (en, de, fr; German public texts use "Sie").

`config/codes.php`:

```php
'mail' => [
    'layout' => 'resources/mail/layout.twig', // default @codes/mail/layout.twig (neutral)
    'from' => 'Club <club@example.org>',      // default: the mail channel's sender
    'accent' => '#0a7d33',                    // link and button colour; no default
],
```

A custom layout receives `accent` as an empty string unless `mail.accent` is set, and also `lang`,
`subject`, `paragraphs`, `action` (`label`, `url`), `message` and `from_name`.

## Example: an invitation route (app code)

The route, the group and the pages are yours; the package supplies check, redeem, hook and mail.

```php
// Invite: bind a code to the address, mail the link.
$code = $codes->issue('bggenius.invite', ['group' => $group], recipient: $email); // ttl from the purpose
$mailer->send($codes->record($code, 'bggenius.invite'), $email, '/invite/' . $code, ['message' => $text, 'from_name' => $host]);

// GET /invite/{code}
$check = $codes->check($code, 'bggenius.invite', $throttle->client($request));
if (!$check->ok) { return $this->invalidInvitationPage(); } // "this invitation is no longer valid"; stop here
// $check->recipient is the invited address:
//  - signed in with another address -> "this invitation is for another address, sign out?"
//  - not signed in, account exists   -> sign in, with ?next=/invite/{code} (members: next.allow)
//  - not signed in, new address      -> your short form, Members::register(..., emailVerified: true)
//  - signed in with that address     -> redeem, but only if that address is CONFIRMED: an unconfirmed
//                                       self-registered address must not redeem someone else's invitation
if ((string) $member->get('email_confirmed_at') !== '') { // the confirmed address
    $codes->redeem($code, 'bggenius.invite', $member->id, $throttle->client($request), recipient: $member->email);
}
// your codes.redeemed listener adds the member to the group; then redirect to the welcome page.
```

Withdraw with `revoke($codeId)`; resend with `renew($codeId, $newExpiry)` and the same link again.
