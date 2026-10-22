# preflow/codes

Codes that grant something for one purpose: readable codes (a book code a visitor types)
and random codes (an invite link), with expiry, usage limits, revocation and a throttled
redeem.

## Install

```
composer require preflow/codes
php preflow folio:extensions:enable codes
php preflow migrate
```

- Add `Preflow\Codes\CodesServiceProvider` to `config/providers.php` (after
  `FolioServiceProvider`). It binds `Codes` and registers the `code_redemption` migration.
- Codes live in the storage alias `codes`. With the extension enabled, booting without it
  is an error, so add it to `config/data.php`:

  ```php
  'aliases' => ['codes' => 'sqlite'],
  ```

- `preflow migrate` creates the redemptions table (package migration) and the `code`
  table (Folio's table sync). It migrates the `sqlite` driver unless `--storage=` names
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
  it in any case, with spaces or dashes.
- A random code is 16 random bytes, base64url without padding (22 characters). Lookups trim
  it, nothing more.
- Codes are unique within their purpose; an unknown purpose throws. Codes are bearer tokens:
  they are compared with `hash_equals`.
- `check()` says whether a code would be accepted and counts no use. `redeem()` counts a use
  and records a redemption (`redemptions($codeId)`: id, redeemer, time; no IP address or
  user agent).
- `Result`: `ok`, `reason` (`unknown`, `expired`, `used_up`, `revoked`, `throttled`),
  `payload` (on success) and `codeId`.
- With a client (`Throttle::client($request)`), every failed redeem counts against it under
  `codes.{purpose}`; after `throttle.max` failures within `throttle.window` seconds, even a
  valid code is refused as `throttled`. Without a client nothing is throttled.
- `revoke($codeId)` stops a code for good.
- Codes are the Folio type `code` (*Library → Codes*); neither codes nor redemptions are
  exported or imported. The `purpose` field (`codes_purpose`) offers the configured purposes.
