# Codes: Personal Invitations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `preflow/codes` becomes a core without Folio (Folio carries the codes admin) and gains what personal
invitations need: codes bound to a recipient address, a recipient's open codes, a redemption hook that a
failing listener undoes, renew, a per-purpose ttl and an invitation mailer. Released as v0.40.0.

**Architecture:**
- **Codes core:** defines the `code` type with the data layer itself (`CodeType`, the pattern of members'
  `MemberType` in 0.38) and creates/completes its table through `RecordTableSync` after migrate. It loses
  every `Preflow\Folio` use; `CodeRecord`, `CodeEvents`, `CodeMailer` are new.
- **Folio:** carries the codes admin as its built-in extension `codes` (`packages/folio/src/Codes/`), switched
  on when `preflow/codes` is installed (`ExtensionRegistry::BUILT_IN`), like the members admin.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig, `preflow/notify`.

**Spec:** `docs/superpowers/specs/2026-10-27-codes-invitations-design.md` — every section binds, including the
"my call" points the owner approved.

## Global Constraints

- **Standing rules (owner):** framework = abstractions/hooks/generic mechanisms only; BG Genius's invitation
  route, welcome page, profile page, hosts and groups are app code. Package admin UI lives in Folio; Folio is
  only a UI adapter.
- **`preflow/codes` must not require `preflow/folio`.** A `NoFolioDependencyTest`: grep `packages/codes/src`
  for `Preflow\Folio` → no hits; its core tests run without Folio's service provider.
- **Names stay:** Folio extension `codes`, type key `code`, table `code`, storage alias `codes`, internal table
  `code_redemption`, permissions `code.view|create|edit|delete` and `codes.revoke`, purpose field type
  `codes_purpose`, translation group `codes` (admin).
- **Compatibility:** `issue()`, `check()`, `redeem()`, `revoke()`, `redemptions()` keep their positional
  parameters; new parameters are appended and named. smart-stack.works' `BookCodeOrScreening`
  (`check($code, $purpose, $client)`, `redeem($code, $purpose, $id, $client)`) must work unchanged. Where a break
  and book codes collide, book codes win.
- **Existing `code` tables** (from Folio's sync, TEXT columns) are adopted as they are; the numeric CAST in
  `countUse()` stays.
- Codes are bearer tokens (`hash_equals`); redemptions store no IP/user agent; recipients are never exported
  (codes aren't).
- German public mail texts use "Sie" (as members' public texts).
- **Gates:** `vendor/bin/phpunit` (baseline v0.39.0: 3903 tests; add no deprecations), `composer test:mysql`,
  smeyer.me and smart-stack.works suites; don't touch holz-objekte.org; no merge, push or tag without the
  owner's go-ahead; never stop processes by pattern (`pkill -f`).

## Rulings made in this plan (for the owner to check)

1. **Storage:** the `code` type uses the alias `codes` when configured, else `default` (as members does). The
   boot error "alias codes not configured" goes away. Existing sites have the alias, so nothing moves.
2. **`issue()` keeps returning the code string.** `Codes::record(string $code, string $purpose): ?CodeRecord`
   and `Codes::get(string $codeId): ?CodeRecord` give the record (for `CodeMailer`, `renew`, the app).
3. **The admin texts (`codes` group) move to Folio** with the admin (`packages/folio/lang/{en,de,fr}/codes.php`);
   the invitation mail's texts are a new public group `codes-mail` in the codes package.
4. **`CodeMailer`'s default layout** is the codes package's `@codes/mail/layout.twig` (same variables as
   members' layout: `lang`, `subject`, `paragraphs`, `action`); `config/codes.php` `mail.layout` replaces it.

## Review Focus

- **smart-stack.works' book code at registration after the upgrade** (readable, unbound, positional calls,
  throttled): check and redeem as before (Task 1 and Task 3 regression tests).
- **A bound code redeemed with the address in another case or with spaces** (`' Anna@Example.org '`): accepted;
  a different address: `wrong_recipient`, the use not counted (Task 3).
- **A listener throws on a one-use code:** `uses` back to 0, no `code_redemption` row, the exception reaches
  the caller, a second redeem then succeeds (Task 4).
- **The sender's message with HTML or a header-breaking newline** (`<script>`, `\r\nBcc:`): escaped in the HTML,
  plain in the text part, never in the subject or a header (Task 5).
- **An existing site whose `code` table came from Folio's sync:** `preflow migrate` adds `recipient` without
  losing rows; the admin still lists, creates readable codes and revokes (Task 1, Task 2).

---

### Task 1: Codes core defines its own type

**Files:**
- Create: `packages/codes/models/code.json` (core schema: key `code`, table `code`, id_field `uuid`, storage
  `codes`, `publishing: false`, `transfer: false`, fields `purpose` (string), `code`, `label`, `expires_at`
  (date, time), `max_uses` (number), `uses` (number), `revoked_at`, `payload` (string), `form`, **`recipient`**
  (string, nullable)). No admin keys (section, icon, list, readonly, `codes_purpose`).
- Create: `packages/codes/src/CodeType.php` — `schema(): array`, `prepare(array $schema, array $drivers): array`
  (storage `codes` if present, else `default`), `define(Container $c): void` (registry via
  `DataManager::ensureTypeRegistry()` when none; skip if `code` is defined; `MigrationRegistry::afterMigrate('codes.table', RecordTableSync->sync(...))`). Mirror `packages/members/src/MemberType.php` (without `extend()`).
- Modify: `packages/codes/src/CodesServiceProvider.php` — `boot()` calls `CodeType::define($c)` always; the
  alias-required error is removed (ruling 1). Folio extension handling stays for now (Task 2 moves it).
- Modify: `packages/codes/src/Codes.php` — the type comes from the data registry; error text no longer names
  Folio ("preflow/codes: the type \"code\" is not defined (no config/data.php?)").
- Modify: `packages/codes/src/models/code.json` (the Folio extension's file) — add `recipient` (string,
  readonly, label "Recipient") so Folio's sync and admin see it.
- Test: `packages/codes/tests/CodeTypeTest.php`

**Interfaces:**
- Produces: `Preflow\Codes\CodeType::{schema, prepare, define}`; the `recipient` column.

- [ ] **Step 1: Failing tests** in `CodeTypeTest`:
  - `test_define_registers_code_type_without_folio` — a test app with data (sqlite) and `CodesServiceProvider`
    only; after boot the registry has `code`; `preflow migrate` (or the afterMigrate callback) creates table
    `code` with column `recipient`; `Codes::issue('t.x')` works.
  - `test_storage_falls_back_to_default_without_codes_alias` — `prepare(schema(), ['default'])['storage'] === 'default'`; with `['default','codes']` → `'codes'`.
  - `test_existing_folio_synced_table_gets_recipient_and_keeps_rows` — create `code` with today's TEXT columns
    and one row; run the sync; row still there, `recipient` column exists.
- [ ] **Step 2:** `vendor/bin/phpunit packages/codes/tests/CodeTypeTest.php` → FAIL.
- [ ] **Step 3:** Implement as above.
- [ ] **Step 4:** `vendor/bin/phpunit packages/codes packages/folio` → PASS (CodesTest, CodesInFolioTest unchanged).
- [ ] **Step 5:** Commit `feat(codes): the core defines the code type itself`.

### Task 2: Folio carries the codes admin; codes drops Folio

**Files:**
- Move (git mv) into `packages/folio/src/Codes/`: `Extension.php` (namespace `Preflow\Folio\Codes`, name
  `codes`, same permissions), `Types/CodeAdmin.php`, `templates/redemptions.twig`, `Fields/PurposeFieldType.php`,
  `models/code.json` (now the admin additions only: label, section `library`, order 20, icon, list filters,
  field type `codes_purpose`, readonly/hidden flags, `recipient` readonly). `Extension::model()` merges the
  core schema (`CodeType::schema()`) with these additions the way `Preflow\Folio\Members\Extension::model()` does.
- Move: `packages/codes/lang/{en,de,fr}/codes.php` → `packages/folio/lang/{en,de,fr}/codes.php` (ruling 3); add
  `recipient` labels (en "Recipient", de "Empfänger", fr "Destinataire"). Register the group as Folio
  registers its own groups (scope `admin`).
- Modify: `packages/folio/src/Extension/ExtensionRegistry.php` — `BUILT_IN` gains
  `\Preflow\Folio\Codes\Extension::class => ['package' => 'preflow/codes', 'class' => 'Preflow\\Codes\\Codes']`;
  `MOVED` gains `'Preflow\\Codes\\Extension'`.
- Modify: `packages/folio/src/FolioServiceProvider.php` — when the built-in `codes` extension is enabled,
  register `PurposeFieldType` (moved from `CodesServiceProvider::boot()`).
- Modify: `packages/codes/composer.json` — drop `preflow/folio` from require and conflict, drop
  `extra.folio.extension` and the `codes` translations entry; require `preflow/notify` and `preflow/view`
  (used in Task 5). `packages/folio/composer.json` — suggest `preflow/codes`; conflict `preflow/codes: <0.40`.
- Modify: `CodesServiceProvider` — no `Preflow\Folio` use left.
- Move tests: `packages/codes/tests/CodesInFolioTest.php`, `ModelLabelsTest.php`, `CodesTranslationTest.php` →
  `packages/folio/tests/Codes/` (adjust namespaces). Update `ComposerConstraintsTest`.
- Create: `packages/codes/tests/NoFolioDependencyTest.php`.

**Interfaces:**
- Consumes: `CodeType::schema()` (Task 1).
- Produces: `Preflow\Folio\Codes\Extension` (built-in `codes`).

- [ ] **Step 1:** Failing tests: `NoFolioDependencyTest` (grep `packages/codes/src` and `composer.json` require
  for `Preflow\Folio` / `preflow/folio` → none); in `packages/folio/tests/Codes/CodesInFolioTest.php` add
  `test_codes_admin_is_built_in_when_codes_installed` (an `installed.json` naming the moved class
  `Preflow\Codes\Extension` still enables the built-in one) and `test_recipient_shown_readonly`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Move and adapt as listed.
- [ ] **Step 4:** `vendor/bin/phpunit packages/codes packages/folio` → PASS; the moved admin tests pass unchanged
  apart from namespaces.
- [ ] **Step 5:** Commit `refactor(codes,folio): Folio carries the codes admin; codes runs without Folio`.

### Task 3: Recipients, records, renew and ttl

**Files:**
- Create: `packages/codes/src/CodeRecord.php` — `final readonly class CodeRecord(string $id, string $purpose,
  string $code, ?string $recipient, array $payload, ?\DateTimeImmutable $expiresAt, int $uses, ?int $maxUses,
  ?\DateTimeImmutable $revokedAt, string $form)` plus `isOpen(\DateTimeImmutable $now): bool` (not revoked,
  not used up, not expired). Created-at: the id is random, so order by `rowid`/insertion is not available —
  add `created_at` (int, unix) to the core schema in `models/code.json` and set it in `issue()`; old rows have
  null and sort last.
- Modify: `packages/codes/src/Purpose.php` — `public ?int $ttl` (days; from `config/codes.php` purpose `ttl`).
- Modify: `packages/codes/src/Result.php` — reason type adds `'wrong_recipient'`; new `public ?string $recipient = null` (appended constructor param).
- Modify: `packages/codes/src/Codes.php`:
  - `issue(..., ?int $maxUses = null, ?string $recipient = null): string` — recipient normalised
    `strtolower(trim())`, must contain `@` (else `InvalidArgumentException`); only for random codes
    (readable + recipient → `InvalidArgumentException`); a bound code stores `max_uses = 1` whatever was passed.
    No `$expiresAt` and the purpose has `ttl` → now + ttl days.
  - `check(string $code, string $purpose, ?string $client = null, ?string $recipient = null): Result` and
    `redeem(..., ?string $client = null, ?string $recipient = null): Result` — rules in spec §2: bound + matching
    → as today (Result carries `recipient`); bound + no recipient → `check()` ok with `recipient`, `redeem()`
    `wrong_recipient`; bound + different → `wrong_recipient` (throttle counts it, use not counted). Order of
    checks: unknown → revoked → expired → used_up → recipient (so `wrong_recipient` is only reachable with a
    live code).
  - `record(string $code, string $purpose): ?CodeRecord`, `get(string $codeId): ?CodeRecord`.
  - `forRecipient(string $email, ?string $purpose = null, bool $open = true): list<CodeRecord>` — normalised
    match; newest first (`created_at` desc).
  - `renew(string $codeId, \DateTimeImmutable $expiresAt): void` — `InvalidArgumentException` when the code is
    unknown, revoked, used up, or `$expiresAt` is not in the future.
- Test: `packages/codes/tests/RecipientTest.php`, `packages/codes/tests/RenewTest.php`.

**Interfaces:**
- Produces: the signatures above; `Result::$recipient`; reason `wrong_recipient`; `CodeRecord`.

- [ ] **Step 1:** Failing tests:
  - `test_bound_code_redeems_for_its_address_in_any_case_and_spacing` — issue with `'anna@example.org'`;
    `redeem($c, 'p', 'm1', recipient: ' Anna@Example.org ')->ok === true`.
  - `test_other_address_is_wrong_recipient_and_counts_no_use` — reason `wrong_recipient`, `get($id)->uses === 0`,
    throttle failure counted (with a client).
  - `test_check_without_address_reports_recipient` — `check($c,'p')`: `ok === true`, `recipient === 'anna@example.org'`;
    `redeem($c,'p','m1')` → `wrong_recipient`.
  - `test_bound_code_has_one_use` — issue with `maxUses: 5, recipient: …` → `get()->maxUses === 1`.
  - `test_readable_code_cannot_be_bound` → `InvalidArgumentException`.
  - `test_wrong_recipient_not_reported_for_dead_codes` — revoked bound code with another address → `revoked`.
  - `test_for_recipient_lists_open_codes_newest_first` — three codes for the address (one revoked, one
    expired, one open) + one for another address → `forRecipient(...)` = [open]; `open: false` → all three,
    newest first; `$purpose` filter works.
  - `test_ttl_sets_expiry` — purpose `['ttl' => 30]`, clock fixed → `expiresAt` = now + 30 days; explicit
    `expiresAt` wins; purpose without ttl → null.
  - `RenewTest`: renews an expired open code (then `check()->ok`); refuses revoked, used-up, past date.
  - `test_book_code_positional_calls_unchanged` — readable unbound code, `check($c,'p',$client)` and
    `redeem($c,'p','m1',$client)` behave exactly as before.
- [ ] **Step 2:** `vendor/bin/phpunit packages/codes` → new tests FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/codes packages/folio` → PASS.
- [ ] **Step 5:** Commit `feat(codes): codes bound to a recipient, forRecipient, renew and a purpose ttl`.

### Task 4: Redemption hook with undo

**Files:**
- Create: `packages/codes/src/CodeEvents.php` — like `packages/members/src/MemberEvents.php`:
  `REDEEMED = 'codes.redeemed'`, `REVOKED = 'codes.revoked'`; `on(string $event, callable $listener, ?string $purpose = null): void`;
  `dispatch(string $event, array $payload): void` (payload keys: `code` (CodeRecord), `purpose`, `redeemer`,
  `payload`); unknown event → `InvalidArgumentException`. Bound as a singleton in `CodesServiceProvider`;
  `Codes` takes it as an optional constructor argument (appended).
- Modify: `Codes::redeem()` — after counting the use and storing the redemption, dispatch `REDEEMED` (only
  listeners for that purpose or for all). If a listener throws: decrement `uses` (SQL: one `UPDATE … SET uses
  = CAST(uses AS INTEGER) - 1`; JSON: under the same file lock), delete the redemption row, rethrow.
  `revoke()` dispatches `REVOKED` (exceptions there propagate, nothing undone — the revoke stands).
- Test: `packages/codes/tests/CodeEventsTest.php` (sqlite) and one JSON-driver case.

**Interfaces:**
- Consumes: `CodeRecord`, `Codes::get()` (Task 3).
- Produces: `Preflow\Codes\CodeEvents`.

- [ ] **Step 1:** Failing tests:
  - `test_redeemed_listener_gets_record_redeemer_and_payload`.
  - `test_listener_limited_to_purpose` — a listener for `a` does not run for `b`.
  - `test_throwing_listener_undoes_use_and_redemption` — one-use bound code; listener throws
    `RuntimeException('group')` once; `redeem()` throws it; `get()->uses === 0`, `redemptions($id) === []`;
    second `redeem()` → ok, listener ran twice in total.
  - `test_throwing_listener_undo_on_json_driver` — same on the JSON driver.
  - `test_revoke_dispatches_revoked`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/codes` → PASS.
- [ ] **Step 5:** Commit `feat(codes): CodeEvents; a failing redeemed listener undoes the redemption`.

### Task 5: CodeMailer

**Files:**
- Create: `packages/codes/src/CodeMailer.php` —
  `send(CodeRecord $code, string $to, string $url, array $options = []): bool`. Options: `subject`, `message`
  (plain text), `from_name`, `language`, `template`, `vars`. Returns false on `TransportException` (logged with
  `error_log`, no address in the line), true when sent; the code is untouched either way.
  - Path `url` (`/invite/x`) → absolute with `app.url`; absolute `http(s)` URLs as given; anything else →
    `InvalidArgumentException`.
  - Language: `options.language` when the site has it (`Locales`), else the site default.
  - Subject: `options.subject` or `codes-mail.invite.subject`; control characters collapse to one space
    (members' `oneLine`). `from_name` is text only (`{from_name}` in the copy), never the sender header; the
    sender is `config/codes.php` `mail.from` or the notify channel's own.
  - Text part: `codes-mail.invite.body` with `{from_name}`, `{message}`, `{link}`, `{expires}`; HTML part:
    `options.template` or `config/codes.php` `mail.layout` or `@codes/mail/layout.twig`, variables `lang`,
    `subject`, `paragraphs`, `action` (`{label: codes-mail.invite.action, url}`), `message`, `from_name`, plus
    `vars`. Twig autoescape covers HTML.
- Create: `packages/codes/templates/mail/layout.twig` (register `@codes` in `CodesServiceProvider`),
  `packages/codes/lang/{en,de,fr}/codes-mail.php` (scope `public`; German "Sie"):
  - en: subject "You're invited", body "{from_name} invites you.\n\n{message}\n\nOpen your invitation: {link}\n\nThe link is personal and valid until {expires}.", action "Open invitation".
  - de: subject "Sie sind eingeladen", body "{from_name} lädt Sie ein.\n\n{message}\n\nZur Einladung: {link}\n\nDer Link ist persönlich und gültig bis {expires}.", action "Einladung öffnen".
  - fr: subject "Vous êtes invité·e", body "{from_name} vous invite.\n\n{message}\n\nVotre invitation : {link}\n\nLe lien est personnel et valable jusqu'au {expires}.", action "Ouvrir l'invitation".
  Empty `from_name`/`message` drop their paragraph (no "invites you." with an empty name: use the
  `invite.body_anonymous` variant without the first line).
- Modify: `CodesServiceProvider` — bind `CodeMailer` (Notifier, TemplateEngineInterface, Translator, Locales,
  app.url, config), `composer.json` extra translations `codes-mail` (public).
- Test: `packages/codes/tests/CodeMailerTest.php` with a capturing transport.

**Interfaces:**
- Consumes: `CodeRecord` (Task 3).
- Produces: `Preflow\Codes\CodeMailer::send`.

- [ ] **Step 1:** Failing tests:
  - `test_sends_link_message_and_action_in_recipient_language` — de: subject "Sie sind eingeladen", text has
    the message and absolute link, HTML has the button label "Einladung öffnen" and URL.
  - `test_message_is_escaped_and_never_in_headers` — message `"<script>x</script>\r\nBcc: a@b.c"`: HTML has
    `&lt;script&gt;`, subject unchanged, no `Bcc` header; subject option with `\r\n` collapses to one line.
  - `test_relative_url_made_absolute_and_bad_url_refused` — `/invite/x` → `https://site.test/invite/x`;
    `javascript:x` → `InvalidArgumentException`.
  - `test_transport_failure_returns_false_and_code_stays_open`.
  - `test_app_template_and_vars_used`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `vendor/bin/phpunit packages/codes` → PASS.
- [ ] **Step 5:** Commit `feat(codes): CodeMailer sends a code as a personal link`.

### Task 6: The invitation path end to end, docs, consumers

**Files:**
- Test: `packages/codes/tests/InvitationPathTest.php` — spec §7 with a fake app listener: issue bound code with
  ttl 30 → mail captured with the link → `check()` without address → recipient → wrong address refused →
  right address redeems, listener "adds to group" → `forRecipient()` empty afterwards → a second invitation
  revoked shows `revoked` → renew of an expired one works.
- Modify: `packages/codes/README.md` (install without Folio, alias optional, recipients, `forRecipient`,
  `renew`, `ttl`, `CodeEvents` incl. the undo rule and "keep your own writes in your own transaction, no mails
  before you're done", `CodeMailer`, `config/codes.php` `mail.layout`/`mail.from`, an invitation-route example
  in app code), Folio's docs for the built-in codes admin, `ROADMAP.md` step 2 marked done (prepared),
  `docs/superpowers/plans/2026-10-27-release-0.40-checklist.md` (upgrade notes: providers order unchanged;
  `config/folio/extensions.json` keeps `codes`; the `codes` translations now from Folio; the alias error gone;
  `preflow migrate` adds `recipient`/`created_at`; holz-objekte.org nothing to change).
- smart-stack.works and smeyer.me: run their suites against the branch (path repos); no code change expected —
  record the result in the checklist.

- [ ] **Step 1:** Write `InvitationPathTest`; run → PASS (it uses Tasks 1–5; a failure is a defect there).
- [ ] **Step 2:** Docs and checklist.
- [ ] **Step 3:** `vendor/bin/phpunit` (full) and `composer test:mysql` → PASS, no new deprecations.
- [ ] **Step 4:** smart-stack.works and smeyer.me suites → PASS.
- [ ] **Step 5:** Commit `docs(codes): personal invitations; 0.40 checklist`.
