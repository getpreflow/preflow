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

- Add `Preflow\Codes\CodesServiceProvider` to `config/providers.php`. It binds `Codes` and registers the `code_redemption` migration.
- Codes live in the storage alias `codes` when the site has it, else in `default`. To keep
  them apart, add the alias to `config/data.php`:

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
- `Result`: `ok`, `reason` (`unknown`, `expired`, `used_up`, `revoked`, `throttled`),
  `payload` (on success) and `codeId`.
- With a client (`Throttle::client($request)`), every failed redeem counts against it under
  `codes.{purpose}`; after `throttle.max` failures within `throttle.window` seconds, even a
  valid code is refused as `throttled`. Without a client nothing is throttled.
- `revoke($codeId)` stops a code for good.
- With Folio, codes are the type `code` (*Library → Codes*, Folio's built-in `codes` extension); neither codes nor redemptions are
  exported or imported. The `purpose` field (`codes_purpose`) offers the configured purposes.

## Aliases

The extension stores codes and redemptions in the storage alias `codes` (`config/data.php`
`aliases`); it must name a SQL driver. A missing alias is an error at boot. The failure
counters of the throttle live in the table `preflow_throttle` of the driver named `throttle`
when one is configured (an optional alias), otherwise in the default storage.

## Admin

*Library → Codes* lists the codes with a *Redemptions* column: the number of redemptions, or
`revoked` / `expired`. The list filters by *Expires at* (from/until) and *Form* (readable /
random).

- **Create:** the admin creates readable codes only, for purposes with `readable: true`.
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
