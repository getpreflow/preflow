# Members Area Step 2, Site Side (smart-stack.works) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Readers reach `smart-stack.works/tools` and can:
- register with a book code or a screening answer;
- confirm their email;
- get approved in Folio;
- log in and use a toolbox of protected resources.

All of it is in EN and DE.

**Architecture:**
- **Folio config and session**
  - The site adopts Preflow 0.21: a second session guard `members` on the `member` record type (RecordUserProvider plus a 30-day remember cookie).
  - One session shared with Folio staff, started only on `/folio` and `/tools`.
  - Folio private assets served at `/tools/files`, and a gated `resource` type at `/tools/r/{slug}`.
- **Site controllers** under `/tools` own the entry page, registration, confirmation, login, reset and account pages.
  - They render Twig templates in `resources/folio/frontend/tools/`.
  - The `tools` Folio page still supplies the owner-editable welcome copy.
- **Approval** lives in a `#[FolioType('member')]` customization in the Members extension: nav badge, state column, decision panel, accept/decline actions.
- **Retention** is a `members:prune` CLI command run by cron.

**Tech Stack:** PHP 8.4, Preflow 0.21 (core, auth, data, folio, codes, rbac, notify, htmx, i18n), Twig, SQLite for member data, PHPUnit 11.

**Spec:** `/Users/smyr/Sites/gbits/preflow-framework/docs/superpowers/specs/2026-10-22-members-area-step2-design.md`. It includes "Decided after the builder check" and "Calls made by the designer".

Framework notes for adopting sites: `docs/superpowers/plans/2026-10-22-release-0.21-checklist.md`, section "For plan D / sites adopting 0.21".

**Repo:** `/Users/smyr/Sites/gbits/smart-stack.works`. Branch `feat/members-step2`, created from `main` in the main checkout. `vendor/preflow/*` are symlinks into the framework checkout, which is on main at v0.21.0.

## Global Constraints

- **Cookies:**
  - Public pages (everything outside `/folio…` and `/tools…`, `/de/tools…`) send no `Set-Cookie`.
  - `/tools` pages may set the session cookie and, after a member login, the remember cookie. Nothing else.
- **No content without login:**
  - The toolbox, resource pages and resource files require `Access::canAny('members.access')`.
  - Staff with `*` pass.
  - Pending, rejected and unconfirmed accounts never do.
- **No account enumeration:**
  - register, forgot and resend always answer the same way, whether the email exists or not;
  - a failed login uses one generic message;
  - a rejected member gets the same message as a wrong password.
- **Throttles,** from `config/members.php`:
  - login: 10 failed attempts per 15 min, per client and per email;
  - register: 5 per hour per client;
  - forgot: 5 per hour, per client and per email;
  - resend: 3 per hour per email;
  - book-code checks: through `Codes::check(..., $client)`, with config/codes.php max 10 per 900 s.
- **Passwords:** at least 10 characters, hashed with `Preflow\Auth\PasswordHasherInterface`.
- **Tokens and links:**
  - Confirmation and reset tokens come only from `Preflow\Auth\Tokens\Tokens`: purposes `members.confirm` (TTL 86400) and `members.reset` (TTL 3600).
  - Mail links are absolute and built from `config('app.url')` (env `APP_URL`, default `https://smart-stack.works`). They are never built from the request Host.
- **Every POST carries `_csrf_token`** (`{{ csrf_field() }}`). Every `/tools…` response sends `Cache-Control: private, no-store`.
- **Personal data stays in SQLite** (`member` type and the `auth_*` tables). It is never exported, never committed, and never in mail logs outside `storage/logs`.
- **Language:**
  - EN lives at `/tools…`, DE at `/de/tools…`.
  - A member's mails use the member's `language`.
  - The owner's info mail is in English.
- **Copy voice:** first person, from Steffen, short. All copy is a draft for the owner to approve before release (Task 9).
- **Mail:**
  - `MAIL_DSN` and `MAIL_FROM` (`hi@smeyer.me`), sender name "Steffen Meyer", set the same way as smeyer.me.
  - Without `MAIL_DSN`: the log driver in debug, the memory driver under `APP_ENV=testing`.
- **Framework gaps go to the framework.** If a step needs a missing Preflow or Folio feature, stop and report BLOCKED with specifics. Never work around it in the site.
- **Admin UI:**
  - matches Folio's look;
  - every admin-visible task ends with light and dark screenshots from `https://smart-stack.dev.smyr`, saved to the task's workspace.
- **Never push, deploy, or touch the live server or live content.** Never read or import `~/Sites/gbits/smart-stack.works-import/`.
- **Local data:** running `vendor/bin/preflow migrate` and `folio:extensions:apply` on the local site copy is allowed. Use only test credentials invented for local dev.

## Review Focus

1. **Mail scanners prefetch confirmation links.** A GET of `/tools/confirm/{token}` must not consume the token. It renders a page whose form auto-submits (nonce script) and has a visible button as a fallback. Only the POST consumes the token. A second POST of the same token shows "this link was already used, log in" and is not an error page. Tests are in Task 3.
2. **Open redirect through `next`.** The login `next` accepts only paths starting with `/tools` or `/de/tools`. These must fall back to `/tools`:
   - `//evil.test`, `https://evil.test`, `/\evil.test`;
   - `/tools/../folio`;
   - `javascript:`.

   Tests are in Task 4.
3. **Shared or cached member pages.** Every `/tools…` response (entry, toolbox, resource, file, account, error) sends `Cache-Control: private, no-store`. Tests are in Tasks 3, 6 and 7.
4. **Email case and whitespace.**
   - `" Ada@Example.COM "` is stored as `ada@example.com`.
   - Logging in with `ADA@example.com` works.
   - Registering again with a different case counts as a duplicate.

   Tests are in Tasks 2 and 3.
5. **Remember cookie after a decision changes.** A member logged in with a remember cookie who is then declined in Folio sees no toolbox on the next request (they get the login page). Access is checked on every request, never cached in the session. The test is in Task 5.

---

## File Structure

```
config/auth.php            members guard, remember, session cookie path / and paths [/folio, /tools]
config/data.php            alias auth => sqlite
config/notify.php          copy of smeyer.me (MAIL_DSN, MAIL_FROM)
config/members.php         throttles, password_min, TTLs, retention days
config/folio.php           assets.private_url /tools/files, private_permission members.access, private_login
config/app.php             url (APP_URL)
config/htmx.php            include on-demand
config/commands.php        + App\Console\PruneMembersCommand
app/Members/               site-specific member logic (no HTTP)
  MemberState.php          enum: Unconfirmed, Waiting, Member, Declined
  Members.php              lookup, register, confirm, decide, password, delete
  MemberMailer.php         the six mails; renders text and HTML
  MailCopy.php             subjects and bodies, EN/DE
  Links.php                absolute URLs for mails
  Limits.php               reads config/members.php
  Viewer.php               who is looking at /tools (member or staff), canAccess()
  ToolsView.php            renders a tools template as a no-store HTML response
app/Controllers/ToolsController.php        /tools, /tools/register, /tools/confirm…
app/Controllers/MemberAuthController.php   /tools/login, logout, forgot, reset, resend
app/Controllers/AccountController.php      /tools/account (password, delete)
app/Components/MembersIntro/               Folio block: welcome copy and the two paths
app/Folio/Members/Extension.php            + settings accept_message, decline_message, welcome_note; members.decide
app/Folio/Members/Types/MemberAdmin.php    NavBadge, ListColumn state, FormPanel decision, accept/decline
app/Folio/Members/templates/decision.twig  the decision panel
app/Folio/Members/models/member.json       + language, email_confirmed_at, book_code; readonly roles, answer
app/Folio/Members/models/resource.json     the toolbox resource type
app/Console/PruneMembersCommand.php        retention
resources/folio/frontend/tools/*.twig      entry, check-inbox, confirm, thanks, login, forgot, reset, toolbox, account, cards partial
resources/folio/frontend/resource.twig     resource page
resources/mail/layout.twig                 HTML mail wrapper
lang/{en,de}/members.php                   interface strings
app/Content/PrivacyText.php                members paragraph (draft)
deploy.php, .gitignore, .env.example       private-media shared dir, APP_URL, MAIL_*
docs/members.md                            owner notes: cron line, settings, content steps
```

---

### Task 1: Adopt 0.21, session scope, members guard, config

**Files:**
- Modify:
  - `composer.json`: add `preflow/notify`; set every `preflow/*` constraint to `^0.21 || @dev`. Then run `composer update "preflow/*"`, which re-links the path repositories.
  - `tests/Unit/ServerComposerTest.php`: the expected constraint becomes `'^0.21'`.
  - `config/auth.php`, `config/data.php`, `config/app.php`, `config/folio.php`, `config/htmx.php`.
  - `.env.example`: add `APP_URL`, `MAIL_DSN`, `MAIL_FROM`.
  - `.gitignore`: add `/storage/private-media/`.
  - `deploy.php`: add `storage/private-media` to `shared_dirs` and `writable_dirs`.
  - `app/Folio/Members/models/member.json`.
  - `tests/Integration/MembersGroundworkTest.php`.
- Create:
  - `config/notify.php` (smeyer.me's, verbatim apart from the comment);
  - `config/members.php`;
  - `tests/Integration/MembersConfigTest.php`.

**Interfaces:**
- Produces:
  - **Guard `members`:** `SessionGuard`, provider `members` = `Preflow\Folio\Users\RecordUserProvider` with `type` `member`, population `members`, `remember` = `['cookie' => 'ss_member', 'days' => 30, 'path' => '/']`.
  - **Staff guard:** stays the default guard, `session`.
  - **Session:**
    - `cookie` `ss_session`, `path` `/`, `paths` `['/folio', '/tools']`, `lifetime` 7200. Other keys stay as they are.
    - LocaleMiddleware strips `/de` before SessionMiddleware, so `/de/tools` is covered.
  - **`config/members.php`:**
    ```php
    return [
        'throttle' => ['login' => [10, 900], 'register' => [5, 3600], 'forgot' => [5, 3600], 'resend' => [3, 3600]],
        'password_min' => 10,
        'confirm_ttl' => 86400,
        'reset_ttl' => 3600,
        'retention' => ['unconfirmed_days' => 7, 'declined_days' => 30],
    ];
    ```
  - **`config/folio.php` assets:** `'private_url' => '/tools/files'`, `'private_permission' => 'members.access'`, `'private_login' => '/tools/login'`. The default `private_dir` (`storage/private-media`) stays.
  - **`config/app.php`:** `'url' => rtrim((string) (getenv('APP_URL') ?: 'https://smart-stack.works'), '/')`.
  - **`config/data.php` aliases:** add `'auth' => 'sqlite'`.
  - **member.json fields:**
    - Add `language` (select `{"en":"English","de":"Deutsch"}`, readonly), `email_confirmed_at` (string, readonly) and `book_code` (string, readonly; the normalised code given at registration, `''` if none).
    - Make `screening_answer` and `roles` readonly.
    - List columns: `email`, `name`, `registered_at`.

- [ ] **Step 1: Write the failing tests** in `MembersConfigTest`, using `SiteScaffold`:
  - `test_public_pages_set_no_cookie`: `/`, `/de` and `/privacy` have an empty `Set-Cookie` header.
  - `test_tools_sets_only_the_session_cookie`: `GET /tools` and `GET /de/tools` send exactly one `Set-Cookie`, starting with `ss_session=`, with `Path=/` and `HttpOnly`.
  - `test_staff_login_still_works` (`#[RunInSeparateProcess]`): `adminGet('/folio')` returns 200 with the dashboard.
  - `test_members_guard_is_configured`: `AuthManager::guard('members')` is a `SessionGuard`. The provider finds a saved member by `findByCredentials(['email' => 'ada@example.com'])` and returns a `RecordUser`.
  - `test_member_roles_field_is_readonly`: the member edit form at `/folio/member/{id}/edit` renders `roles` and `screening_answer` without an editable input.

  Move the old `test_public_pages_set_no_cookie` (it also asserted `/tools`) out of `MembersGroundworkTest`. Update `ServerComposerTest` to `^0.21`.

- [ ] **Step 2: Run, verify RED.** `vendor/bin/phpunit --filter 'MembersConfigTest|ServerComposerTest'`. Expected: the tools-cookie test, the guard test and the readonly test FAIL; the composer test FAILS on `^0.20`.

- [ ] **Step 3: Implement the config, composer and model changes listed above.** Then run `vendor/bin/preflow migrate` and `vendor/bin/preflow folio:extensions:apply` on the local site.
  - If `rbac_roles` ignores `readonly`, stop and report BLOCKED. That is a Folio gap.

- [ ] **Step 4: Run GREEN, then the full suite.** `vendor/bin/phpunit`. Expected: PASS. Fix the expected sidebar in `MembersGroundworkTest` only if it changed. It shouldn't, because no new nav items arrive in this task.

- [ ] **Step 5: Commit.** `feat: adopt preflow 0.21, members guard with 30-day remember cookie, session only on /folio and /tools`

---

### Task 2: Member domain services

**Files:**
- Create:
  - `app/Members/{MemberState,Members,MemberMailer,MailCopy,Links,Limits,Viewer,ToolsView}.php`
  - `resources/mail/layout.twig`
  - `tests/Integration/MembersServiceTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (bindings, only if autowiring isn't enough).

**Interfaces:**
- Consumes: Task 1 config, `Preflow\Data\DataManager`, `Preflow\Auth\PasswordHasherInterface`, `Preflow\Notify\Notifier`, `Preflow\View\TemplateEngineInterface`, `Preflow\Rbac\RoleList`, `Preflow\Auth\Access\Access`, `Preflow\Auth\AuthManager`.
- Produces:
  - **`enum MemberState: string`** with cases `Unconfirmed='unconfirmed'`, `Waiting='waiting'`, `Member='member'`, `Declined='declined'`.
    - `static fromRecord(DynamicRecord $m): self`, evaluated in this order:
      1. roles contain `rejected` → Declined;
      2. roles contain `member` → Member;
      3. `email_confirmed_at` is `''` or null → Unconfirmed;
      4. otherwise Waiting.
    - `label(): string` returns the English labels "unconfirmed", "waiting", "member", "declined".
  - **`final class Members`:**
    - `__construct(DataManager $dm, PasswordHasherInterface $hasher, ?\Closure $now = null)`; `$now` returns a `DateTimeImmutable` (UTC).
    - `static normalizeEmail(string $email): string` trims and lowercases (`mb_strtolower`).
    - `findByEmail(string $email): ?DynamicRecord` normalises first.
    - `find(string $id): ?DynamicRecord`.
    - `register(string $name, string $email, string $password, string $language, string $bookCode, string $answer): DynamicRecord`. Sets a new UUID v4 id, `roles` `[]`, `registered_at` = now in `DATE_ATOM` format, `email_confirmed_at` `''` and `decided_at` `''`.
    - `confirm(DynamicRecord $m): void` sets `email_confirmed_at`.
    - `decide(DynamicRecord $m, string $role): void`. `$role` is `member`, `pending` or `rejected`. It sets `roles` to `[$role]` and, for `member` or `rejected`, also sets `decided_at`.
    - `setPassword(DynamicRecord $m, string $password): void`.
    - `delete(DynamicRecord $m): void`.
    - `pendingCount(): int`.
  - **`final class Links`:**
    - `__construct(Config|array $appConfig)`. Use the way the site already reads config.
    - `tools(string $locale, string $path = ''): string` returns `{url}` + (`/de` if de) + `/tools` + `$path`.
    - `folioMember(string $id): string` returns `{url}/folio/member/{id}/edit`.
  - **`final class Limits`:** `throttle(string $name): array{0:int,1:int}`, `passwordMin(): int`, `confirmTtl(): int`, `resetTtl(): int`, `retentionDays(string $which): int`.
  - **`final class MailCopy`:**
    - `static subject(string $mail, string $lang): string`.
    - `static text(string $mail, string $lang, array $vars): string`, with placeholders `{name}`, `{link}`, `{login_url}`, `{reset_url}`, `{email}`, `{answer}`, `{folio_url}`.
    - Mails are `confirm`, `existing`, `notice`, `accepted`, `declined` and `reset`.
    - Use the English drafts below; write the German equivalents:
      - **confirm:** subject "Confirm your email for SMART STACK". Body: "Hi {name}, please confirm your email address: {link} — the link works for 24 hours. If you didn't sign up, ignore this mail. — Steffen".
      - **existing:** subject "Your SMART STACK account". Body: "Hi {name}, someone (hopefully you) tried to register with this address, but you already have an account. Log in: {login_url} · Forgot your password? {reset_url} — Steffen".
      - **notice** (EN only): subject "SMART STACK — {name} is waiting for access". Body: "{name} <{email}> confirmed their email and is waiting for approval.\n\n“{answer}”\n\nOpen in Folio: {folio_url}".
      - **accepted / declined:** the subject is "Your access to the SMART STACK toolbox" / "About your SMART STACK request". The body is the owner's message, which the panel posts already filled in.
      - **reset:** subject "Reset your SMART STACK password". Body: "Hi {name}, set a new password here: {link} — the link works for one hour. If you didn't ask for this, ignore this mail. — Steffen".
  - **`final class MemberMailer`:**
    - `__construct(Notifier $notifier, TemplateEngineInterface $engine, Links $links)`.
    - Methods: `confirm(DynamicRecord $m, string $token): void`, `existing(DynamicRecord $m, string $resetToken): void`, `notice(DynamicRecord $m, string $to): void`, `accepted(DynamicRecord $m, string $message): void`, `declined(DynamicRecord $m, string $message): void`, `reset(DynamicRecord $m, string $token): void`.
    - Each sends one `Email` with the text, plus HTML from `resources/mail/layout.twig` (escaped paragraphs and linked URLs, in the site's colours). The `from` is `Steffen Meyer <{MAIL_FROM}>` when MAIL_FROM is set, otherwise the channel default.
    - On failure the `TransportException` propagates.
    - Confirm links are `tools($lang, '/confirm/'.$token)`; reset links are `tools($lang, '/reset/'.$token)`.
  - **`final class Viewer`:**
    - `__construct(AuthManager $auth, Access $access, Members $members)`.
    - `member(): ?DynamicRecord` returns the record behind `users()['members']`.
    - `isStaff(): bool`.
    - `canAccess(): bool` = `$access->canAny('members.access')`.
    - `name(): string` is the member's name, else the staff email.
    - `language(string $fallback): string` is the member's language, else `$fallback`.
  - **`final class ToolsView`:**
    - `render(string $template, array $vars, ServerRequestInterface $request, int $status = 200): ResponseInterface`.
    - It renders `@folio/frontend/tools/{template}.twig`. It sets `ContentLocale` from the request attribute `locale`, the same way as `App\Http\NotFoundPage`, and passes `locale`, `alternates` (the `/tools` ↔ `/de/tools` equivalent of the current path) and `viewer`.
    - It injects assets and returns `text/html; charset=UTF-8` with `Cache-Control: private, no-store`.
    - `redirect(string $location): ResponseInterface` returns a 303 with the same cache header.

- [ ] **Step 1: Write the failing tests** in `MembersServiceTest`, using `SiteScaffold` with migrate and apply as in `MembersGroundworkTest::migrateAndApply`:
  - `test_register_normalises_email_and_sets_defaults`: registers `" Ada@Example.COM "`, then asserts the email is `ada@example.com`, roles `[]`, a non-empty `registered_at`, an empty `email_confirmed_at`, language `en`, and that the stored hash verifies the password.
  - `test_find_by_email_ignores_case`.
  - `test_state_from_record` covers all four states, including rejected-and-confirmed → Declined.
  - `test_decide_sets_roles_and_decided_at`.
  - `test_pending_count`.
  - `test_mail_confirm_is_in_member_language`: a de member's mail has the German subject, a link starting with `https://smart-stack.works/de/tools/confirm/`, and an HTML part. Read the mail from the in-memory transport, as smeyer.me's `tests/Integration/ContactTest.php` does.
  - `test_links_never_use_request_host`: the `APP_URL` env value wins.
  - `test_tools_view_sends_no_store`.

- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter MembersServiceTest`. Expected: FAIL, because the classes are missing.

- [ ] **Step 3: Implement the classes listed above.**

- [ ] **Step 4: Run GREEN, then the full suite.**

- [ ] **Step 5: Commit.** `feat(members): member state, registration storage, mails and links`

---

### Task 3: Entry page, registration, confirmation

**Files:**
- Create:
  - `app/Controllers/ToolsController.php`
  - `app/Components/MembersIntro/MembersIntro.{php,twig}`
  - `resources/folio/frontend/tools/{entry,check-inbox,confirm,thanks,closed,toolbox}.twig` (toolbox is a placeholder that Task 6 fills)
  - `lang/en/members.php`, `lang/de/members.php`
  - `tests/Integration/RegistrationTest.php`
- Modify: `config/models/page.json` (add `members_intro` to `sections.allowed`).

**Interfaces:**
- Consumes:
  - Task 2: `Members`, `MemberMailer`, `Limits`, `Viewer`, `ToolsView`, `MemberState`.
  - `Preflow\Codes\Codes::check(string $code, string $purpose, ?string $client): Result`, `Codes::redeem(string $code, string $purpose, ?string $redeemer, ?string $client): Result`, `Codes::normalizeReadable(string): ?string`.
  - `Preflow\Auth\Tokens\Tokens::issue(string $purpose, string $subject, int $ttl): string`, `consume(string $token, string $purpose): ?string`, `revokeAll(string $purpose, string $subject): int`.
  - `Preflow\Core\Throttle\Throttle::client(ServerRequestInterface)`, `tooMany(purpose, client, max, window)`, `hit(purpose, client, window)`.
  - `ExtensionSettings::get('members', key, locale)`.
  - `AuthManager::guard('members')->login($user, $request, remember: true)`.
- Produces:
  - **Routes:**
    - `ToolsController` with `#[Route('/tools')]`: `#[Get('/')] index`, `#[Post('/register')] register`, `#[Get('/confirm/{token}')] confirmPage`, `#[Post('/confirm')] confirm`.
    - The toolbox placeholder template `tools/toolbox.twig` gets the vars `viewer`, `note` and `resources`; Task 6 fills them.
  - **Block `members_intro`:**
    - `#[Block(key:'members_intro', label:'Members intro', group:'Content')]`.
    - Translatable fields: `heading` (string, required), `lead` (richtext), `book_title` (string), `book_text` (richtext), `nocode_title` (string), `nocode_text` (richtext).
    - The defaults in `lang` are the approved mockup copy, `.superpowers/brainstorm/42306-1792694419/content/flow.html`:
      - "One step away from access";
      - "You have the book" / "Use the code printed inside the book — you get access right after confirming your email.";
      - "No book (yet)?" / "Tell me briefly what you're looking for. I read every request and get back to you personally."
  - **Session flash key `members.notice`:** one line shown on the entry and login pages, used for the code fallback message.

Behaviour:
- **`index`**
  - `Viewer::canAccess()` → render `toolbox`. Task 6 replaces the body.
  - A signed-in member who is waiting → render `thanks`.
  - Otherwise render `entry`:
    - the `sections` of the Folio page `tools` (via `Collections::find('page', 'tools')` and `folio_blocks`);
    - then the form, unless `registration_open` is false, in which case `closed`;
    - and a link to `/tools/login` (locale prefixed).
- **`register`:**
  1. If registration is closed → 403 `closed`.
  2. The throttle `members.register` is checked per client; when it is exceeded → 429 entry with a generic "Too many attempts, try again later".
  3. Hit the throttle.
  4. Validate the fields:
     - name required;
     - email valid by `FILTER_VALIDATE_EMAIL` after normalising;
     - password ≥ `passwordMin()`;
     - `consent` present;
     - book code or answer present.

     Errors → 422 entry, with the old input kept and the password not kept.
  5. If a code is given: `Codes::check($code, 'members.book', $client)`. Not ok → 422 with the error `members.errors.code` ("This code doesn't work."), the other input kept.
  6. **Duplicate check:** `findByEmail`. If the account exists:
     - Member → `existing` mail (with a fresh reset token);
     - Unconfirmed → revoke the confirm tokens, issue a new one, `confirm` mail;
     - Waiting or Declined → no mail.

     Each case then renders `check-inbox` (200).
  7. Otherwise `register(...)` with `bookCode` = `normalizeReadable($code) ?? ''`, issue a `members.confirm` token, and send the `confirm` mail.
     - If the mail throws: delete the record, log, and return 503 entry with "I couldn't send the mail right now. Please try again in a few minutes."
     - On success → `check-inbox` (200) showing the address.
- **`confirmPage`:** renders `confirm`. It is a form posting `token` to `/tools/confirm` (locale prefixed), with `csrf_field()`.
  - A nonce `<script>` submits the form on load; a visible button stays as a fallback.
  - It does NOT consume the token.
- **`confirm`:**
  1. `consume(token, 'members.confirm')`.
     - null → 410 `confirm` with "This link was already used or has expired. You can log in, or register again." and a login link.
     - Otherwise load the member and call `Members::confirm`.
  2. If `book_code` is non-empty: `Codes::redeem(book_code, 'members.book', $id, $client)`.
     - If ok and the setting `book_code_skips_screening` is true → `decide('member')`, `guard('members')->login(RecordUser, remember: true)`, then a 303 to `/tools` (locale prefixed).
     - If redeem fails → flash `members.notice` "Your book code no longer works, so I'll look at your request personally." and continue as no-code.
     - If ok but the setting is off → continue as no-code.
  3. **No-code path:**
     - `decide('pending')` (roles `['pending']`, no `decided_at`).
     - Send the `notice` mail to the setting `notice_email`, if it is set. A failure is logged and doesn't break the page.
     - Render `thanks` (200): "Thanks for your interest. Your email is confirmed. I'll read your message and get in contact with you personally."
- **Templates:**
  - All extend `@folio/frontend/_site.twig`.
  - Form styles go in an `{% apply css %}` block in `entry.twig` and use the site's tokens (`--rule`, `--ink`, `--accent`, `.btn .btn-primary`).
  - The layout follows the approved mockup: one form, the book code and the question separated by "— or, without a code —", and the code hint "Inside the book, on the last page. Dashes and spaces don't matter."

- [ ] **Step 1: Write the failing tests** in `RegistrationTest`. Each test boots, migrates and applies. Tests that use the session are `#[RunInSeparateProcess]`. A code is issued with `Codes::issue('members.book', [], 'SMARTSTACK2026')`.
  - `test_entry_shows_intro_and_form_with_csrf`: `/tools` 200; `/de/tools` has German labels; the form posts to `/tools/register` or `/de/tools/register` and has `_csrf_token`; the header is `Cache-Control: private, no-store`.
  - `test_register_without_code_sends_confirm_mail_and_stores_member`: response 200 with "check your inbox"; one mail to the address; the member is stored with roles `[]`.
  - `test_register_with_invalid_code_keeps_input_and_stores_nothing`: 422; the name and email stay in the form; no member, no mail.
  - `test_register_requires_code_or_answer`, `test_register_requires_password_min_10`, `test_register_requires_consent`.
  - `test_duplicate_email_any_case_answers_the_same`: register `ada@example.com`, confirm, set the role to member, then register `ADA@Example.com`. The body matches a fresh registration, and an `existing` mail is sent.
  - `test_duplicate_of_waiting_sends_no_mail`.
  - `test_register_throttled_after_5_per_hour` → 429 on the 6th.
  - `test_closed_registration_hides_form_and_refuses_post`.
  - `test_confirm_get_does_not_consume_token`: GET twice, then POST → succeeds.
  - `test_confirm_with_code_logs_in_and_redirects_to_toolbox`: POST → 303 `/tools`. A following GET `/tools` in the same process shows the toolbox placeholder. The code's `uses` is 1, and `redemptions()` lists the member id.
  - `test_confirm_without_code_sets_pending_and_mails_owner`: `thanks`, roles `['pending']`, the notice mail to the `notice_email` setting containing the answer and `/folio/member/{id}/edit`.
  - `test_confirm_twice_shows_used_link_page`: 410 with a login link, not an error page.
  - `test_code_used_up_between_submit_and_confirm_falls_back_to_waiting`.
  - `test_code_with_skip_setting_off_goes_to_waiting_but_counts_use`.

- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter RegistrationTest`. Expected: FAIL (404 / missing classes).

- [ ] **Step 3: Implement the controller, block, templates and lang files.**

- [ ] **Step 4: Run GREEN, then the full suite.** Take screenshots of `/tools` and `/de/tools` (entry, errors shown, check-inbox, thanks) on the local vhost, at phone and desktop width.

- [ ] **Step 5: Commit.** `feat(members): entry page, registration with book code or question, double opt-in`

---

### Task 4: Login, logout, forgot and reset, resend

**Files:**
- Create:
  - `app/Controllers/MemberAuthController.php`
  - `resources/folio/frontend/tools/{login,forgot,reset,resend}.twig`
  - `tests/Integration/MemberLoginTest.php`
- Modify: the `lang/*/members.php` files.

**Interfaces:**
- Consumes: Task 2 and Task 3 (`members.notice` flash), the `RecordUserProvider` provider via `AuthManager::guard('members')`, `PasswordHasherInterface`, `Tokens`, `Throttle`, `Preflow\Auth\Remember\RememberMe::revokeAll('members', $id)`.
- Produces:
  - `MemberAuthController` with `#[Route('/tools')]`: `#[Get('/login')] form`, `#[Post('/login')] login`, `#[Post('/logout')] logout`, `#[Get('/forgot')] forgotForm`, `#[Post('/forgot')] forgot`, `#[Get('/reset/{token}')] resetForm`, `#[Post('/reset')] reset`, `#[Post('/resend')] resend`.
  - `public static function safeNext(?string $next, string $locale): string` returns `$next` only when it matches `#^(/de)?/tools(/[A-Za-z0-9._~%/-]*)?(\?[^\s]*)?$#`, does not contain `..` or `//` or `\`, and is not empty. Otherwise it returns `/tools` (or `/de/tools`). Task 6 and FrontendGate redirects rely on `?next=`.

Behaviour:
- **`form`:**
  - A viewer who `canAccess()` → 303 to `safeNext(next)`.
  - Otherwise render `login`: email, password, hidden `next`, links to forgot and to register.
- **`login`:**
  1. The client throttle and the email throttle (key `sha256(normalised email)`) on `members.login` with `[10, 900]`. Either one exceeded → 429 generic.
  2. Look up the user through the provider and verify with the hasher. Wrong password, unknown email or Declined → hit both throttles and return 422 with the generic "Email or password is wrong."
  3. Correct password:
     - Unconfirmed → render `resend`: "Please confirm your email first", with a button that posts `email` to `/tools/resend`.
     - Waiting → render `thanks`.
     - Member → `login(remember: true)`, clear both throttles, 303 to `safeNext(next)`.
- **`logout`:** `guard('members')->logout($request)`, then a 303 to `/tools`. The staff session stays signed in.
- **`forgot`:**
  - Throttle `members.forgot` per client and per email.
  - Only when the email belongs to a Member: issue a `members.reset` token with `resetTtl` and send the `reset` mail.
  - Always render `forgot` with "If an account exists for this address, a link is on its way."
  - Exceeding the throttle gives the same page with status 429.
- **`resetForm`:** renders `reset` with `token` hidden, password and repeat. It does not consume the token.
- **`reset`:**
  - Validate the password (≥ 10) and that both entries match (422).
  - `consume(token, 'members.reset')`; null → 410 "This link has expired".
  - Otherwise: `setPassword`, `RememberMe::revokeAll('members', id)`, `revokeAll('members.reset', id)`, then `login(remember: true)` and a 303 to `/tools`.
- **`resend`:**
  - Throttle `members.resend` per email, `[3, 3600]`.
  - Only for an Unconfirmed member: revoke the old confirm tokens, issue a new one, and send the `confirm` mail.
  - Always render `check-inbox` with the same text.

- [ ] **Step 1: Write the failing tests** (`#[RunInSeparateProcess]` wherever the session is used):
  - `test_member_logs_in_and_gets_remember_cookie`: 303 `/tools`; the response sets `ss_member=` with `Max-Age=2592000; Path=/; HttpOnly`.
  - `test_login_is_case_insensitive_on_email`.
  - `test_wrong_password_unknown_email_and_declined_give_identical_body`: compare the three response bodies with the CSRF token stripped.
  - `test_unconfirmed_with_correct_password_offers_resend`.
  - `test_waiting_with_correct_password_sees_thanks`.
  - `test_login_throttled_after_10_failures_per_email` → 429 even from a different client (`REMOTE_ADDR`).
  - `test_safe_next_rejects_foreign_and_traversal`: a data provider runs each of these, which must return `/tools`:
    - `//evil.test`, `https://evil.test`, `/\evil.test`;
    - `/tools/../folio`, `/toolsfoo`;
    - `javascript:alert(1)`, `''`.

    These must pass through unchanged: `/tools/r/force-map`, `/de/tools/r/x?y=1`.
  - `test_logout_keeps_staff_session`: admin login, then member login in the same process, then member logout → `/folio` is still 200.
  - `test_forgot_answers_the_same_for_unknown_and_member`; only the member gets a mail.
  - `test_reset_sets_password_logs_in_and_revokes_remember`.
  - `test_reset_token_single_use` → 410 on the second POST.
  - `test_resend_sends_only_for_unconfirmed_and_is_throttled_3_per_hour`.

- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter MemberLoginTest`. Expected: FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run GREEN, then the full suite.** Screenshots: login and its error, forgot, reset.

- [ ] **Step 5: Commit.** `feat(members): login with 30-day remember, logout, forgot and reset password, resend confirmation`

---

### Task 5: Approval in Folio

**Files:**
- Create:
  - `app/Folio/Members/Types/MemberAdmin.php`
  - `app/Folio/Members/templates/decision.twig`
  - `tests/Integration/MemberApprovalTest.php`
- Modify:
  - `app/Folio/Members/Extension.php`: version `1.1.0`; `#[DeclaresPermission('members.decide', 'Accept or decline members')]`; settings `accept_message` (text, translatable), `decline_message` (text, translatable), `welcome_note` (richtext, translatable).
  - `tests/Integration/MembersGroundworkTest.php`: only if the sidebar assertion changes.

**Interfaces:**
- Consumes: Task 2 `Members`, `MemberMailer`, `MemberState`, `Links`. Folio `#[FolioType]`, `#[NavBadge]`, `#[ListColumn]`, `#[FormPanel]`, `#[RecordAction]`, `ScreenView`; `ExtensionSettings`; `RememberMe::revokeAll`.
- Produces:
  - Settings keys `accept_message`, `decline_message` and `welcome_note`, read via `ExtensionSettings::get('members', key, $lang)`. Task 6 uses `welcome_note` and its `{name}` placeholder.
  - **Default copy:**
    - **accept_message (en):** "Hi {name},\n\nthanks for your interest — your access to the SMART STACK toolbox is ready. Log in here: {login_url}\n\nSteffen"
    - **decline_message (en):** "Hi {name},\n\nthanks for your interest in SMART STACK. The toolbox is meant for readers of the book, so I can't give you access right now. If you get the book, its code opens the door straight away.\n\nSteffen"
    - **welcome_note (en):** "<p>Welcome, {name}. Thank you for reading the book — here are the tools, templates and maps it mentions. I keep them up to date.</p><p>— Steffen</p>"
    - The DE drafts are written by the implementer in the same voice.

Behaviour (`#[FolioType('member')] final class MemberAdmin`):
- **`#[NavBadge] waiting(): ?int`** returns `Members::pendingCount()`, or null when it is 0.
- **`#[ListColumn('State', 5)] state(DynamicRecord $m): string`** returns `MemberState::fromRecord($m)->label()`.
- **`#[FormPanel('Decision', position: 'after')] decision(DynamicRecord $m): ScreenView`** renders `@members/decision.twig`.
  - The template has two tabs, Accept and Decline. Each tab is its own `<form method="post" action="{{ prefix }}/member/{{ record.uuid }}/_action/accept|decline">` with `_csrf_token` = `csrf`, a `<textarea name="message">`, and a button: "Accept and send" or "Decline and send".
  - Each textarea is pre-filled with the setting in the member's language:
    - `{name}` filled in;
    - for accept, `{login_url}` = `Links::tools(lang, '/login')`.
  - Above the tabs: the state, "Registered {registered_at} · book code: {yes/no}", and the screening answer.
  - The panel shows on members in every state:
    - Waiting: both tabs;
    - Member: only Decline (revoke);
    - Declined: only Accept;
    - Unconfirmed: the text "Not confirmed yet — nothing to decide." and no forms.
  - The styling uses Folio's admin tokens and components only: tabs, textarea and button classes from Folio's admin CSS. Check the screenshots in light and dark.
- **`#[RecordAction('accept', 'Accept', 'members.decide')]`** and **`#[RecordAction('decline', 'Decline', 'members.decide')]`**:
  1. Read the trimmed `message` from the parsed body; empty → flash error "The message is empty.", then a 302 back to the edit page.
  2. Send the mail first (`accepted` / `declined` with the message, in the member's language).
  3. `TransportException` → flash error "The mail could not be sent — nothing was changed.", then a 302 back. The roles stay unchanged.
  4. Success:
     - `decide('member'|'rejected')`;
     - on decline, also `RememberMe::revokeAll('members', id)`;
     - return null, so Folio's success flash is shown.

  The flash goes through `$request->getAttribute(Preflow\Core\Http\Session\SessionInterface::class)->flash('error', …)`; use the exact session interface name from core.

- [ ] **Step 1: Write the failing tests** (`#[RunInSeparateProcess]`, signed in with `adminGet`):
  - `test_sidebar_shows_waiting_badge`: two pending members → `/folio` has `.folio-nav-badge` with text `2` on the Members item; zero pending → no badge.
  - `test_member_list_shows_state_column` with the four labels.
  - `test_decision_panel_prefilled_in_member_language`: the textarea of a de member contains the German default with the name filled in.
  - `test_accept_sends_mail_with_message_and_sets_member`: POST `_action/accept` with `message` and the CSRF token → roles `['member']`, `decided_at` set, and the mail body equals the posted message.
  - `test_decline_sets_rejected_and_revokes_remember`.
  - `test_mail_failure_changes_nothing`: swap the mail transport for one that throws (a `failing` transport in the test container, or config override `transports.mail.driver` = a test double). Roles stay `['pending']` and the next page shows the error flash.
  - `test_declined_member_with_remember_cookie_loses_toolbox` (Review Focus 5): the member logs in with remember, is declined through the action, and a fresh request with only the `ss_member` cookie to `/tools` gets the entry or login page, not the toolbox.
  - `test_decide_requires_permission`: a staff user without `members.decide` → 403. Seed an `editor` staff role via `RoleStore::ensure('staff','editor','Editor',['member.edit','member.view'])` and a ConfigUserProvider user with that role, using a test-only `FOLIO_ADMIN_*` override. If this is not feasible with `config/auth.php`'s single config user, say so in the report and test it through `Access` directly.

- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter MemberApprovalTest`. Expected: FAIL.

- [ ] **Step 3: Implement.** Then run `folio:extensions:apply` locally, which applies version 1.1.0.

- [ ] **Step 4: Run GREEN, then the full suite.** Screenshots (light and dark): the sidebar with the badge, the member list, and the decision panel for waiting, member and declined members.

- [ ] **Step 5: Commit.** `feat(members): approval in Folio — waiting badge, state column, decision panel with editable message`

---

### Task 6: Resources and the toolbox

**Files:**
- Create:
  - `app/Folio/Members/models/resource.json`
  - `resources/folio/frontend/resource.twig`
  - `resources/folio/frontend/tools/_cards.twig`
  - `app/Members/Toolbox.php`
  - `tests/Integration/ToolboxTest.php`
- Modify:
  - `resources/folio/frontend/tools/toolbox.twig`
  - `app/Controllers/ToolsController.php` (`index` toolbox branch)
  - the lang files

**Interfaces:**
- Consumes: Task 2 `Viewer`, `ToolsView`; Task 5 setting `welcome_note`; `Collections::list('resource')`, `Collections::list('force')`; Twig `folio_asset_url`.
- Produces:
  - **`resource.json`:**
    - Header keys:
      - key `resource`, storage `json`, id_field `uuid`, label "Resources";
      - section `members`, order 20, icon `ui-file`;
      - `publishing` true;
      - list columns `title`, `kind`, `language`, orderable true.
    - `"frontend": {"route": "/tools/r/{slug}", "template": "resource", "permission": "members.access", "login": {"en": "/tools/login", "de": "/de/tools/login"}}`.
    - Fields:
      - `title` (string, required);
      - `slug` (string, required);
      - `summary` (text);
      - `kind` (select `pdf`/`page`/`interactive`, required);
      - `forces` (relation to `force`, multiple, labelField `letter`);
      - `language` (select en/de, required);
      - `file` (asset, `{"accept": "application/pdf", "private": true}`);
      - `body` (blocks, allowed `text_image`).
    - It is not translatable, and it transfers as content (no `users` key).
  - **`final class Toolbox`:**
    - `__construct(Collections $collections)`.
    - `filter(array $query, string $defaultLang): array{resources: list<array>, filters: array{type: string, force: string, lang: string}, forces: list<array>}`.
      - `type` ∈ `all|pdf|page|interactive`, default `all`.
      - `force` = a force uuid or `all`.
      - `lang` ∈ `en|de|all`, default `$defaultLang`.
      - Unknown values fall back to their defaults.
      - Results come in list order (Folio's `_order`).
- **Toolbox view:**
  - `welcome_note` in the viewer's language with `{name}` filled in. The name is escaped before substitution; the note itself is trusted rich text.
  - A small member bar: name · Account · Log out (a POST form). Staff see "Folio" instead of Log out.
  - Filter chips are links with query parameters (`?type=pdf&force=<uuid>&lang=de`).
  - Cards come from `_cards.twig`:
    - a type badge, the language, the title and the summary;
    - force letters;
    - the action:
      - pdf → `folio_asset_url(file)`, "Download ↓", with the `download` attribute;
      - page or interactive → `/tools/r/{slug}`, "Open →".
- **htmx:**
  - The chips carry `hx-get` with the same URL, `hx-target="#cards"`, `hx-push-url="true"`.
  - A request with `HX-Request: true` gets only `_cards.twig`, still with no-store.
  - Load htmx the way preflow/htmx's `on-demand` mode documents. Check `vendor/preflow/htmx/README.md`; if the page can't opt in, report BLOCKED with specifics.
  - Without JS, the links work as full-page loads.
- **`resource.twig`:** extends `_site.twig`. It shows the title, the summary, the body blocks via `folio_blocks`, the download button if there is a `file`, and a back link to the toolbox.

- [ ] **Step 1: Write the failing tests:**
  - `test_toolbox_requires_login`: logged out, `/tools` shows the entry page and none of the resource titles.
  - `test_toolbox_lists_published_resources_with_welcome_name` (member logged in): a draft resource doesn't appear, and the note contains the member's name escaped (a name like `<b>Ada</b>` appears as text).
  - `test_language_filter_defaults_to_member_language`: a de member sees only de resources by default, and `?lang=all` shows both.
  - `test_type_and_force_filters`.
  - `test_htmx_request_returns_only_cards`.
  - `test_resource_page_gated`:
    - logged out → 302 to `/tools/login?next=…` with no-store;
    - member → 200;
    - `/de/tools/r/{slug}` logged out → the login URL is `/de/tools/login`.
  - `test_private_pdf_gated`:
    - an upload via `AssetLibrary::upload` marked private;
    - logged out GET `/tools/files/{id}` → 302 to login;
    - member → 200 with `Content-Disposition: attachment` and `Cache-Control: private, no-store`;
    - the file is under `storage/private-media` and not under `public/`.
  - `test_staff_sees_toolbox_and_files` (adminGet, then `/tools`).
  - `test_pending_member_sees_no_toolbox`.

- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter ToolboxTest`. Expected: FAIL.

- [ ] **Step 3: Implement.** Then run `folio:extensions:apply` and `migrate` locally. Locally, create three sample resources by hand in Folio for the screenshots; do NOT commit content.

- [ ] **Step 4: Run GREEN, then the full suite.** Screenshots: the toolbox at desktop and phone width (EN and DE), and the resource page. In Folio: the Resources list and edit form, light and dark.

- [ ] **Step 5: Commit.** `feat(members): toolbox with welcome note, filters and resource cards; gated resource pages and private PDFs`

---

### Task 7: Account (change password, delete account)

**Files:**
- Create:
  - `app/Controllers/AccountController.php`
  - `resources/folio/frontend/tools/account.twig`
  - `tests/Integration/AccountTest.php`

**Interfaces:**
- Consumes: Task 2 `Members`, `Viewer`, `ToolsView`; `Tokens::revokeAll`; `RememberMe::revokeAll`; `guard('members')->logout`.
- Produces: `AccountController` with `#[Route('/tools/account')]`: `#[Get('/')] show`, `#[Post('/password')] password`, `#[Post('/delete')] delete`.

Behaviour:
- **Access:**
  - Only a signed-in member (not staff). Others get a 303 to `/tools/login?next=/tools/account`.
  - Staff opening the account page → a 303 to `/tools`.
- **`password`:**
  - Requires the current password, a new one (≥ 10) and a repeat.
  - A wrong current password → 422.
  - Success → `setPassword`, revoke the remember tokens, log in again with remember, 303 back to the account page with flash "Password changed."
- **`delete`:**
  - Requires the current password, and a checkbox "I understand this deletes my account".
  - Success → revoke both token purposes, `RememberMe::revokeAll`, `Members::delete`, logout, then a 303 to `/tools` with flash "Your account is deleted."
  - Code redemptions keep their redeemer string untouched.

- [ ] **Step 1: Write the failing tests:**
  - `test_account_requires_member`;
  - `test_change_password_requires_current_and_rotates_remember` (the old `ss_member` cookie no longer restores);
  - `test_delete_requires_password_and_removes_record_and_session`;
  - `test_delete_keeps_code_redemption_reference`;
  - `test_account_pages_no_store`.

- [ ] **Step 2: Run RED.** Expected: FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run GREEN, then the full suite.** Screenshot the account page.

- [ ] **Step 5: Commit.** `feat(members): account page to change the password or delete the account`

---

### Task 8: Retention command

**Files:**
- Create:
  - `app/Console/PruneMembersCommand.php`
  - `tests/Integration/PruneMembersTest.php`
- Modify: `config/commands.php`.

**Interfaces:**
- Consumes: Task 2 `Members`, `MemberState`, `Limits`; `Tokens::prune()`, `RememberMe::prune()`, `Throttle::prune()`; the boot pattern from `Preflow\Folio\Console\BootsApplication` (as in smeyer.me's `app/Console/PruneCommand.php`).
- Produces: the command `members:prune`, implementing `Preflow\DevTools\Command\CommandInterface`. `execute(array $args): int` returns 0, and prints `members:prune — deleted N unconfirmed, M declined; pruned tokens, remember, throttle`.
  - Optional `--now=<ATOM>` for tests. Alternatively, inject the clock through the `Members` constructor.

Behaviour:
- Deletes members that are Unconfirmed with `registered_at` older than `unconfirmed_days` (7).
- Deletes members that are Declined with `decided_at` older than `declined_days` (30).
- Compares dates in PHP, never in SQL (the TEXT columns).
- Waiting members and Members are never touched.
- Uses a file lock `storage/locks/members-prune.lock` (non-blocking `flock`) so overlapping cron runs exit 0 quietly.

- [ ] **Step 1: Write the failing tests:**
  - `test_prunes_unconfirmed_after_7_days_and_declined_after_30`, with records dated 6, 8, 29 and 31 days back;
  - `test_never_prunes_waiting_or_members`;
  - `test_command_is_registered` (`vendor/bin/preflow` lists `members:prune`, run via `proc_open` as in `MembersGroundworkTest`).

- [ ] **Step 2: Run RED.** Expected: FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run GREEN, then the full suite.**

- [ ] **Step 5: Commit.** `feat(members): members:prune deletes unconfirmed accounts after 7 days and declined ones after 30`

---

### Task 9: Privacy text, owner notes, release checklist

**Files:**
- Modify:
  - `app/Content/PrivacyText.php`: update "Cookies" (the session cookie `ss_session` on `/tools` and `/folio`; `ss_member` for 30 days after a member login) and "Contact & email". Add a section "Members area": the stored data, the screening answer, confirmation mails, and the retention periods of 7 and 30 days, plus "until you delete your account". EN and DE.
  - `tests/Integration/PagesTest.php`: only the count of privacy sections, if it changes.
- Create:
  - `docs/members.md`: the owner's notes.
  - `docs/superpowers/plans/2026-10-23-members-step2-site-release.md` in the framework repo (`/Users/smyr/Sites/gbits/preflow-framework/docs/superpowers/plans/`).

**`docs/members.md` covers:**
- the settings to fill: notice email, accept and decline texts, welcome note, DE texts;
- how to issue the book code;
- how to add resources;
- the cron line: `15 3 * * * cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 preflow members:prune > /dev/null 2>&1`;
- `MAIL_DSN` and `MAIL_FROM` in `shared/.env`;
- that `storage/private-media` is shared.

**The release checklist** has these steps, all gated by the user:
1. The owner approves the copy: pages, mails, defaults and the privacy text.
2. Merge the site branch.
3. The owner sets `MAIL_DSN`, `MAIL_FROM` and `APP_URL` in the server's `shared/.env`.
4. Deploy, which runs migrate and apply.
5. Update the live content through pull, a local edit, export and a server import, after a dry run:
   - the `tools` page: replace the `coming_soon` block with `members_intro`;
   - the privacy page blocks, from the new `PrivacyText`.
6. The owner fills in the Members settings and issues the book code.
7. Add the cron line.
8. Smoke test on live with a test registration, then delete it.

- [ ] **Step 1:** Update `PrivacyText` and adjust `PagesTest` if needed. Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 2:** Write both documents.
- [ ] **Step 3: Commit (site).** `docs(members): privacy text for the members area (draft for approval), owner notes`
- [ ] **Step 4: Commit (framework repo, docs only).** `docs: members step 2 site release checklist`
