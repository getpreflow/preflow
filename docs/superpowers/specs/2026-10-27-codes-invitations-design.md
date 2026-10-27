# Codes: Personal Invitations — Design

**Date:** 2026-10-27
**Status:** Draft for the owner's review (builder check done).
**Context:** step 2 of the BG Genius framework order in `ROADMAP.md` ("Codes: a redemption hook and email
delivery for invitations"), Phase 2 of `bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`.
Builds on 0.38 (members core: `next`, verified accounts, `SignIn`, `MemberEvents`). Current version: v0.38.0.

## Why

BG Genius hosts invite people by email to a game (umbrella design, decision 3). The owner's picture:

- The host writes a message that can be customised; the person gets an email with a link.
- Registered or new makes little difference: link → sign in (or register: name and password) → a welcome
  message for that game.
- The member's profile page lists every game they are part of: **invited** (not yet accepted) and **open to
  learn** (accepted).
- **The link is personal** (owner): only the invited address can use it.
- **An invitation is valid 30 days; the host can withdraw it and send it again** (owner).

## Standing rules

- The framework holds abstractions, interfaces, hooks and generic mechanisms only (owner, 2026-10-26).
  BG Genius's invitation screen, the invitation route, the welcome page, the profile page, hosts and groups
  are **BG Genius app code**; the framework gives it what makes them easy.
- A package's official admin UI lives in Folio; the package is a core without Folio (as media, members).
- Folio is only a UI adapter.

## Goals, non-goals, anti-goals

**Goals:**
- `preflow/codes` runs without Folio (the same split as members in 0.38); Folio carries the codes admin.
- A code can be **bound to a recipient address**; only that address redeems it.
- The app can **list a recipient's open codes** (the "invited" list) and its redeemed ones.
- A **redemption hook**: the app learns that a code was redeemed, by whom, with its payload.
- **Delivery:** send a code as a link by email, with the sender's own message, in the recipient's language.
- **Withdraw and resend.**
- BG Genius's invitation path is buildable from these pieces plus 0.38's members core (checked below).

**Non-goals:**
- The invitation route, welcome page, profile page, groups, hosts (BG Genius).
- Bulk invitations, invitation reminders, tracking whether the mail was opened.
- Changing how readable codes (book codes) work for smart-stack.works.

**Anti-goals:**
- An invitation accepted but the person not attached to the group (half-done redemption).
- A forwarded link letting someone else in.
- An invitation feature shaped for BG Genius inside the framework.
- smart-stack.works' book codes breaking.

## Design

### 1. The split

- **`preflow/codes` (core):** `Codes`, `Result`, purposes, the throttle, redemptions, the new pieces below,
  `config/codes.php`. It no longer requires `preflow/folio`. The `code` type is registered with the data
  layer by the core (as members does with its member type in 0.38) and its table comes from the core's own
  migration, so `Codes` works without Folio.
- **Folio (codes admin):** the Library → Codes list and edit, the *Revoke* button, the redemptions list,
  the `codes_purpose` field, permissions (`code.*`, `codes.revoke`), the admin texts (`codes` translations).
  Switched on when `preflow/codes` is installed. Folio keeps the extension name `codes` and the type key
  `code`, so existing sites keep their data and menus.
- The admin shows the new fields (recipient, read-only) and lets staff withdraw (revoke) as today.

### 2. Codes bound to a recipient

- `issue(…, recipient: 'anna@example.org')` stores the address, normalised (trimmed, lower case; no
  further folding of dots or `+` parts). Only random codes take a recipient (a readable code is shared by
  design), and **a bound code has exactly one use** (`maxUses` is forced to 1; my call: one person, one
  acceptance, so the listener never runs twice for the same invitation).
- `redeem()` and `check()` take the redeemer's address as a new **named, last** parameter (`recipient:`),
  so today's positional calls (smart-stack.works' `BookCodeOrScreening`) keep working unchanged. Where a
  break and "book codes keep working" collide, book codes win.
- `redeem()` of a bound code succeeds only when the address matches; otherwise `ok: false`, reason
  **`wrong_recipient`** (also without an address). Unbound codes behave as today.
- `check()` of a bound, otherwise valid code **without** an address answers `ok: true` with `recipient`
  set: "valid, for this address" (the app needs it to send a visitor to sign-in or to the register form
  with the address locked). With a different address it answers `wrong_recipient`. The address is only
  ever returned to someone holding the code, who received it at that address (my call).
- A `wrong_recipient` failure counts against the throttle like any other failure. It needs a valid code,
  so telling it apart from `unknown` gives a guesser nothing (my call).
- `Result` gains the code's `recipient` (on `check()`, so the app can say "this invitation is for another
  address" and offer to sign out). What the app shows of it is the app's choice.
- The address is checked at redemption. If a member later changes their email, codes already redeemed
  stay theirs; open codes stay with the old address and no longer show in their invited list (my call,
  final).

### 3. Listing a recipient's codes

- `Codes::forRecipient(string $email, ?string $purpose = null, bool $open = true): list<CodeRecord>`:
  open = not redeemed up, not revoked, not expired. With `open: false`, all of them (newest first).
- BG Genius's "invited" list is the open codes of the purpose `bggenius.invite` for the signed-in member's
  address, with the game in the payload. Its "open to learn" list is BG Genius's own group data.
- A small read model (`CodeRecord`: id, purpose, code, recipient, payload, expiresAt, uses, maxUses,
  revokedAt, createdAt) instead of handing out `DynamicRecord`s (my call).

### 4. The redemption hook

- `CodeEvents` in the core, the same mechanism as `MemberEvents` (0.38): `on(event, listener)` bound in the
  container. Events: **`codes.redeemed`** (code record, purpose, redeemer, payload), **`codes.revoked`**.
  Listeners can be limited to a purpose (`on(CodeEvents::REDEEMED, $listener, purpose: 'bggenius.invite')`).
- **A failing listener undoes the redemption** (my call, from the anti-goal): listeners run after the use
  is counted; if one throws, the codes package undoes **its own** state (the use and the redemption
  record) and the exception reaches the caller, so the person can click again. The core does not roll
  back the app's writes: listeners keep their own writes in their own transaction and are idempotent, and
  don't send mails before they are done (documented; BG Genius has one listener per purpose). No
  cross-driver transaction primitive is built for this.
- Between counting the use and undoing it, a second click on the same one-use code gets `used_up`;
  acceptable (it's the same person, a moment later it works).

### 5. Delivery

- `CodeMailer::send(CodeRecord $code, string $to, string $url, array $options)` sends one mail through
  `preflow/notify` (a hard dependency of codes, as for members):
  - `url` is the link the app built (BG Genius: `/invite/{code}`); the core never guesses routes. A path
    is made absolute with `app.url`.
  - The HTML layout and sender come from `config/codes.php` `mail.layout` / `mail.from`, with the same
    layout contract as members' `mail.layout` (subject, text, `action: {label, url}`), so a site points
    both at one layout file (my call; no shared mailer class is extracted).
  - options: `subject`, `message` (the sender's own text: plain text, escaped, line breaks kept),
    `from_name` (e.g. the host's name, shown in the text, never as the sender address), `language`
    (the recipient's; default the site's), `template` (the app's own mail template; the default is a
    short invitation mail with the message and the primary link `action`, the 0.38 mail layout slot),
    `vars` (more variables for the app's template).
  - The default texts come from the codes translations (en/de/fr; German public texts in "Sie", as members).
- Delivery returns whether the mail went out; a transport failure doesn't revoke the code.

### 6. Withdraw and resend

- **Withdraw:** `revoke($codeId)` (exists). The link then fails as `revoked`; the app shows its "no longer
  valid" page (the owner's earlier rule: just say it expired/is no longer valid).
- **Resend:** `Codes::renew($codeId, \DateTimeImmutable $expiresAt)` puts a new expiry (any time in the
  future) on a code that is neither revoked nor used up, expired or not; no event, the admin just shows
  the new expiry;
  the app then sends the same link again with `CodeMailer`. Same link, so an earlier mail also works again
  (my call; a new link would leave the first mail dead, which confuses people who find the older mail
  first). A revoked or used-up code can't be renewed; the app issues a new one.
- **30 days** is BG Genius's value, passed as `expiresAt` when it issues and renews. The core gets a
  per-purpose default (`'ttl' => 30`, in days, in `config/codes.php`) used when `issue()` gets no expiry (my
  call; purposes without `ttl` keep "never expires"; a purpose with `ttl` has no never-expiring codes).

### 7. BG Genius's invitation path (check, app code)

1. Host invites `anna@example.org` to game G: `issue('bggenius.invite', ['game' => G, 'host' => H],
   recipient: …, maxUses: 1)` and `CodeMailer::send(…, url: '/invite/'.$code, message: $hostText)`.
2. Anna clicks `/invite/{code}` (BG Genius route). It `check()`s the code:
   - unknown, expired, revoked, used up → "this invitation is no longer valid";
   - Anna signed in with another address → `wrong_recipient` → "this invitation is for another address,
     sign out?";
   - not signed in, address has an account → sign-in with `next` back to `/invite/{code}` (0.38 `next.allow`
     `['/invite/']`);
   - not signed in, new address → BG Genius's short form (name, password, address shown and locked) →
     `Members::register(…, emailVerified: true)` (0.38) → signed in at once (`SignIn`).
3. Signed in with the right address: `redeem(…, recipient: $member->email)`; BG Genius's `codes.redeemed`
   listener adds Anna to H's group for G; redirect to the welcome page for G.
4. Anna's profile page: invited = `forRecipient($email, 'bggenius.invite')`, open to learn = her groups.

## Constraints

- PHP 8.4. Breaking changes allowed in 0.x; every consumer migrated in the same release.
- smart-stack.works (book codes in its admission, `BookCodeOrScreening`) and holz-objekte.org (installed)
  keep working; anything they must change is in the upgrade notes.
- Codes stay bearer tokens compared with `hash_equals`; redemptions store no IP or user agent; recipient
  addresses are personal data: not exported with content (codes aren't), and removed with the code.
- The throttle rules of `check()` and `redeem()` stay.

## Verification

- The core's tests run without Folio installed.
- smart-stack.works and smeyer.me upgrade and stay green (holz-objekte.org in its own session).
- A test walks section 7 with a fake app listener: issue, mail captured, wrong address refused, right
  address redeems, listener runs, a throwing listener leaves the code unused, renew after expiry.

## Left to the builder

- Where the core's type registration and migration live, following members 0.38. Existing `code` tables
  (made by Folio's sync, TEXT columns) are adopted as they are: the core's migration creates the table only
  when missing and adds the `recipient` column (indexed with `purpose`); the numeric CAST stays.
- `CodeRecord`'s exact shape, the event payload keys.
- How "undo the use" works on SQL and JSON drivers (transaction or compensation).

## Size

Medium–large: one plan of about 5–6 tasks (core split; recipient + forRecipient + renew + ttl; events with
undo; CodeMailer; Folio codes admin; docs, consumers, release). One release.
