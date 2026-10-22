# Members Area, Step 2: Registration, Approval, Login, Toolbox — Design

**Date:** 2026-10-22
**Site:** smart-stack.works (`~/Sites/gbits/smart-stack.works`)
**Builds on:** [2026-10-22-members-groundwork-design.md](2026-10-22-members-groundwork-design.md)
(step 1, released as Preflow 0.20 and live on smart-stack.works). Follow-ups noted then:
`docs/superpowers/plans/2026-10-22-members-groundwork-a-followups.md`.
**Status:** Draft, for review.

## Intent

The printed book sends readers to `smart-stack.works/tools`. Today they find "coming soon".
After this step, they find:
- a short welcome;
- a minimal registration;
- for readers with the book code, the toolbox right after confirming their email.

Readers without the code can ask for access. The owner approves or declines them from
Folio. The toolbox shows the owner's personal thank-you note and a library of resources
as cards with filters.

Visitors without an account still see nothing but the entry page. The interactive tools
(Force Map) come in step 3; this step builds the door and the room they will live in.

## What the owner described (decided)

- **Entry page (`/tools`, not logged in):**
  - A small welcome ("One step away from access").
  - Two short paths side by side: "You have the book" (use the code inside the book,
    instant access after confirming the email) and "No book (yet)?" (say briefly what you
    are looking for; the owner reads every request and gets back personally).
  - A minimal registration form, with a link to the login form for registered readers.
  - Approved from the mockup: one form for both paths, with the book code field and the
    "What are you looking for?" field separated by an "or"; a code makes the question
    optional. Fields: name, email, password, book code or question, consent to the privacy
    policy.
- **Double opt-in for everyone.**
  - After sending the form, the reader sees "Check your inbox", and an email with a
    confirmation link arrives.
  - **With a valid book code:** clicking the link logs them in and lands them on the
    toolbox.
  - **Without a code:** clicking the link shows only a thank-you ("Thanks for your
    interest, I'll get in contact").
- **Approval:**
  - When someone without a code confirms their email, the owner gets an info mail. It
    contains the name, email and screening answer, plus a link straight to the member in
    Folio.
  - In Folio the owner accepts or declines from the member's page.
  - Both come with a default message, pre-filled, which the owner can add to or send as
    it is. One action sends the mail and changes the member's role.
  - Approved from the mockup:
    - the sidebar shows how many members are waiting;
    - the defaults live in Members settings in EN and DE, and the member gets the
      language they registered in;
    - a declined person can't log in, and the mail is their only answer.
- **Toolbox (logged-in member):**
  - The owner's welcome note on top, greeting the member by name.
  - Filters by type, force and language, then cards.
  - Clicking a card opens or downloads, depending on the resource: a PDF downloads, a
    page opens, an interactive tool opens.
  - Approved from the mockup:
    - Resources are managed in Folio: title, short text, type (PDF / page / interactive),
      forces, language, the file or the page content, and an order.
    - The welcome note is one text in Folio (EN/DE).
    - The language filter starts on the member's language, and "All" also shows resources
      that exist in one language only.
    - Nothing in the toolbox is reachable without login, PDF files included.
    - Counters, ratings and comments are not part of this step.
- **Copy voice:** first person, from the owner (approved in the mockup).
- **Mail:** the same sending setup and sender as smeyer.me (`MAIL_FROM=hi@smeyer.me`). The
  owner puts `MAIL_DSN` into the server's `shared/.env` himself.

The mockups the owner approved are in
`smart-stack.works/.superpowers/brainstorm/42306-1792694419/content/`: `flow.html`,
`approval.html` and `toolbox.html`. They are gitignored, kept for the builder's reference.

## Goals

1. Registration with a book code (purpose `members.book`, via `preflow/codes`, throttled)
   or with the screening question.
2. Email confirmation (double opt-in) before anything else happens.
3. Approval queue in Folio:
   - the info mail to the owner;
   - Accept and Decline with an editable default message;
   - a waiting count in the sidebar.
4. Member login, logout, and "forgot password" with a reset link.
5. The toolbox page: welcome note, filters, resource cards. Resource pages and protected
   file downloads.
6. EN and DE throughout: pages, emails, defaults. English at `/tools`, German at
   `/de/tools`, as on the rest of the site.
7. Close the step-1 follow-ups this step depends on:
   - atomic throttle increments;
   - member email validation and uniqueness;
   - `registered_at` and `decided_at` set by the flow;
   - visitor-facing code checks go through `redeem()` with a client.

## Non-goals

- The Force Map or any interactive tool. A resource of type "interactive" can exist and
  link to a page, but the tool itself is step 3.
- Team rounds and guest invites (step 4).
- Tool Landscape and Pitfall Map content (step 5). The owner may add them as ordinary PDF
  or page resources whenever he likes.
- Download counters, helpfulness ratings, comments, book feedback and testimonials
  (later).
- Social login, newsletters, member profiles beyond name and email.
- Any messaging between members.

## Anti-goals (it fails even if it works)

- **Public pages set cookies.** The site promises no cookies outside what the members area
  needs. A cookie may only appear once a visitor uses the members area or Folio.
- **Content is reachable without login.** Any resource, file or page under `/tools` that
  works without a member session, including a guessed or shared PDF URL.
- **Account enumeration.** The register, login and forgot-password responses reveal
  whether an email is already registered.
- **Personal data leaves the server.** This covers export, pull and the repository: member
  data, screening answers, tokens.
- **The book code can be guessed.** Unlimited attempts at the code or at logins.
- **The owner can't find who is waiting.** Or a decision silently fails to send its mail.
- **A declined or unconfirmed account gets into the toolbox.**
- **Framework workarounds in the site.** Generic needs (member auth, protected files, nav
  badges, atomic throttle) are built in Preflow or Folio, per the owner's standing rule.

## Constraints

- **Stack:** Preflow 0.20 + Folio as live:
  - PHP 8.4, Twig, strict nonce CSP, no build step, vanilla JS, htmx available;
  - EN/DE content i18n;
  - shared hosting with cron and no workers;
  - member data in SQLite (`member` type, `"users": "members"`, does not transfer).
- **From step 1:**
  - roles `pending`, `member`, `rejected` (members population) and permission
    `members.access`;
  - preflow/rbac answers `can()`;
  - preflow/codes with `members.book` (readable);
  - the throttle on alias `throttle` → sqlite;
  - Members settings: registration open, notice email, book code skips screening,
    screening question and help text;
  - hidden and read-only fields; `password_hash` is hidden.
- **Security:**
  - passwords hashed with Preflow's hasher;
  - confirmation and reset tokens stored only as hashes, single use, with expiry;
  - every form is CSRF-protected;
  - generic messages where an answer would reveal an account;
  - throttling on register, login, forgot-password and code attempts.
- **Owner rules:**
  - Secrets only in `.env`, which the owner sets himself.
  - Ask before any push, deploy or outward step.
  - Fix framework gaps in the framework, test-first, and release before the site uses
    them.
  - Admin UI follows Folio's look and is checked visually with screenshots; the owner is
    very visual.

## The design

### Pages and routes

Under the site's EN/DE prefixes (`/tools…` and `/de/tools…`):

| Route | Logged out | Member (role `member`) |
|---|---|---|
| `/tools` | Entry page with the registration form | Toolbox |
| `/tools/login` | Login form | Redirect to `/tools` |
| `/tools/logout` (POST) | — | Log out, back to `/tools` |
| `/tools/confirm/{token}` | Confirms the email. With a code: logs in and redirects to `/tools`. Without: the thank-you page. | Same |
| `/tools/forgot` | Form: email → "if an account exists, a link is on its way" | Same |
| `/tools/reset/{token}` | Form: new password twice → logged in, toolbox | Same |
| `/tools/r/{slug}` | Login required (redirects to the login form, then back) | Resource page |
| `/tools/files/{id}` | Login required | Streams the resource's PDF as a download |

Members whose role is `pending`, `rejected`, or none (unconfirmed) never see the toolbox.
- A **pending** member who logs in sees the thank-you or waiting message.
- A **rejected** member's login fails with the generic message.
- An **unconfirmed** member cannot log in. After a login attempt they are offered "resend
  confirmation", throttled and with a generic answer.

### Registration and confirmation flow

1. **Submit.**
   - Name and email are required. The email is validated for format, normalised to
     lowercase and must be unique; a duplicate gets the same "check your inbox" answer
     (see enumeration).
   - The password needs at least 10 characters.
   - Consent is required.
   - Either a book code or a screening answer must be given.
   - If the Members setting "registration open" is off, the entry page explains that
     registration is closed and shows no form.
2. **Book code.** If one is given, it is checked with `Codes::check()` (throttled per
   client through the codes throttle) before anything is stored.
   - An invalid code shows the generic "this code doesn't work" and keeps the form
     filled. The reader can clear it and use the question instead.
   - The use of the code is counted at confirmation (`redeem`), not at submit.
3. **Stored.** A member record with roles `[]` and `registered_at`. It also stores:
   - `language` (en or de, from the page);
   - whether a valid code was given;
   - the screening answer;
   - a confirmation token hash with a 24 h expiry.

   The confirmation mail is sent in the member's language.
4. **Confirm.** The link sets `email_confirmed_at` and clears the token.
   - **With a code:** `redeem()` the code with the member id as redeemer, set roles
     `['member']` and `decided_at`, log in, and redirect to the toolbox. If the code has
     become invalid since submit (revoked, used up), fall back to the no-code path and say
     so in one line.
     - When the Members setting "book code skips screening" is off, a code still counts
       but the member goes through approval. *Designer's call:* the existing setting keeps
       its meaning.
   - **Without a code:** roles `['pending']`, the thank-you page, and the info mail to
     the Members setting `notice_email`.
5. **Duplicates.**
   - Registering with an email that already exists sends no new account. Instead:
     - a confirmed account gets a "you already have an account" mail with a login and
       reset link;
     - an unconfirmed one gets the confirmation mail again.
   - The page always shows "check your inbox".

### Approval in Folio

- **Badge.** The sidebar item "Members" shows the number of members with role `pending`
  as a badge.
  - This needs a small framework addition: a model JSON `"badge"` naming a count
    provider, or an extension hook for a nav badge.
  - This is the screen `badge` deferred in plan A; build it in Folio, generic.
- **The member page** shows the screening answer and the registration facts read-only. It
  also has a **Decision** panel:
  - Accept and Decline tabs, each with a textarea pre-filled from the Members setting
    default for that decision in the member's language. Placeholders:
    - `{name}`;
    - `{login_url}` (accept only).
  - One button per tab ("Accept and send", "Decline and send"), as a record action with
    the message posted along.
  - **Accept:** roles `['member']`, `decided_at`, the mail.
  - **Decline:** roles `['rejected']`, `decided_at`, the mail.
  - The member list shows the state (waiting, member, declined) as a badge column.
- **New Members settings, EN/DE, translatable:**
  - `accept_message` and `decline_message`, the default texts with a sensible first draft
    in first person;
  - `welcome_note`, the toolbox greeting, rich text.
- **Mail failure.** If sending fails, the decision is not saved. The owner sees an error
  toast and the member stays waiting. *Designer's call:* a decision without its mail
  would leave the reader uninformed.

### Toolbox and resources

- **A `resource` type** in the site's Members extension (`app/Folio/Members/models/`).
  - Placed in the sidebar under Members (or its own "Toolbox" section; builder's call).
  - It transfers like content: resources are the owner's content, not personal data.
  - Fields:
    - title;
    - slug;
    - summary (short text);
    - type: `pdf`, `page` or `interactive`;
    - forces: relation to `force`, multiple;
    - language: `en` or `de`, a resource exists in one language;
    - file (asset, PDF; for type pdf);
    - body (blocks; for type page and interactive, the latter can hold an intro and a
      link until step 3);
    - publishing on;
    - order.
- **Toolbox page:**
  - The welcome note, with `{name}` filled in.
  - Filter chips for type, force and language. The language filter starts on the
    **member's** language (as approved); "All" shows both.
  - Cards ordered by order.
  - Filtering works without JavaScript through query parameters (`?type=pdf&force=s`).
    htmx swaps only the card grid when JS is on.
  - A card shows:
    - the type badge;
    - the language;
    - the title;
    - the summary;
    - force letters;
    - the action: "Download ↓" for PDF, "Open →" for page or interactive.
  - Only published resources appear.
- **Protected files (framework gap).**
  - Folio assets live in `public/media` and are public. A resource PDF must not be.
  - Add **private assets** to Folio: an asset flag or a private asset directory outside
    the document root (in shared storage), served only through a route that checks a
    permission or a callback. Image variants and the public media path stay as they are.
  - The resource PDF is a private asset. `/tools/files/{id}` checks `members.access` and
    streams it with `Content-Disposition: attachment`.

### Login, session, cookies

- **A member guard** (session) next to Folio's staff guard, on the `member` type:
  - email + password;
  - roles from the record, normalised with `RoleList`;
  - its own session key.
- **Session scope.** Sessions start only on `/tools…`, `/de/tools…` and `/folio…`
  (`session.paths`). Public pages keep sending no `Set-Cookie`.
  - The existing session config is one cookie. If staff and member sessions can't share
    it cleanly, the builder decides how. Folio's cookie path is `/folio` today.
  - Either way, no public page may set one.
- **Throttles** (atomic, see below):
  - login: 10 failed attempts per 15 minutes per client and per email;
  - registration: 5 per hour per client;
  - forgot-password: 5 per hour per client and per email;
  - resend confirmation: 3 per hour per email.

  The values are the designer's call and come from config.

### Emails

- **Sending.** Through `preflow/notify`, configured like smeyer.me (`config/notify.php`,
  `MAIL_DSN`, `MAIL_FROM`). The sender name is "Steffen Meyer". Mails are plain text plus
  simple HTML in the site's look.
- **Mails (EN/DE by the member's language; the info mail in English):**
  - **Confirm your email:** the link, valid 24 h.
  - **Info to the owner:** the name, the email, the screening answer and the Folio link.
  - **Accepted:** the owner's message, with the login link.
  - **Declined:** the owner's message.
  - **Reset your password:** the link, valid 1 h.
  - **You already have an account:** the login link and the reset link.
- **Local development:** without `MAIL_DSN`, mails are written to the log, as on
  smeyer.me.

### Framework work (fix gaps in Preflow/Folio, released before the site uses it)

1. **Atomic throttle increments.** Increment with one UPDATE on SQL drivers, with a
   documented best-effort fallback for JSON. This is the step-1 follow-up.
2. **Member authentication building blocks.** The extensions spec's `preflow/users`
   (working name) or an addition to `preflow/auth` (builder's call). It provides:
   - a user provider on a Folio user type (email lookup, case-insensitive);
   - single-use hashed tokens with expiry for confirmation and reset;
   - login, logout and session-key handling for a second guard.

   The site keeps everything specific: the pages, the book code, screening and approval.
3. **Private assets in Folio**, with a protected download route hook.
4. **Nav badges in Folio** for types, from a count provider.
5. **Small items as they come up,** for example a textarea in a record action form
   (posting extra fields with an action). Each gets its own test and is released.

### Privacy

- The site's privacy text (`app/Content/PrivacyText.php`) must cover:
  - member accounts;
  - the screening answer;
  - confirmation mails;
  - the session cookie under `/tools`.
- The builder drafts the paragraph (EN/DE); the owner approves the wording before
  release.
- A member can delete their account: a small "Account" area with change password and
  delete account (confirm by password). Deleting removes the member record. Code
  redemptions keep only the reference string.
  - *Designer's call:* a data deletion path is expected for accounts holding personal
    data.

## Decided after the builder check (by the owner, 2026-10-22)

- **Staff see the toolbox.**
  - A staff login to Folio also counts as a member on `/tools`: the toolbox, resource
    pages and file downloads accept either guard, staff with `members.access` or `*`.
  - Folio's live preview works for resource pages.
  - This needs a login gate for a Folio type's frontend routes, which is a framework item
    for 0.21: a type is reachable only with a permission. Resources require
    `members.access`.
  - Staff sessions keep their own cookie path. Staff stay recognised on `/tools`, either
    through a shared session or through a staff cookie that also covers `/tools`; the
    builder decides how, under the cookie anti-goal.
- **Retention**, run by a nightly cron task:
  - unconfirmed accounts are deleted 7 days after registration;
  - declined accounts are deleted 30 days after the decision;
  - waiting accounts stay until the owner decides;
  - members stay until they delete their account.

  The privacy text states these periods.
- **Member sessions** last 30 days unless the member logs out. They use their own cookie,
  set only under `/tools` and `/de/tools`. Staff sessions keep today's lifetime.

## Calls made by the designer (for review)

- **`/tools` stays the existing Folio page.**
  - Its welcome copy and the two paths stay editable EN/DE in Folio.
  - The registration form, the login form and the toolbox grid are blocks on that page
    (or the page's template switches on the member state).
  - The sub-routes follow the page's slug in each language.
- **Duplicates and forgot-password for a pending or declined email.** No mail is sent;
  the page shows the same "check your inbox" answer. Only active members get reset or
  "you already have an account" mails.
- **Decisions can be reversed.**
  - The Decision panel also appears on decided members: a declined member can be
    accepted, and a member can be revoked (declined), each with its mail.
  - The raw `roles` field is read-only on the member type; decisions go through the
    panel.
  - The member list's state badge has four states: unconfirmed, waiting, member,
    declined.
- **Submit-time code check.** `Codes::check()` gets an optional client parameter and is
  throttled like `redeem()` (framework, 0.21).

- **Throttle limits** as listed. Password minimum 10 characters. Confirmation link valid
  24 h, reset link 1 h.
- **When the code counts.** The code use is counted at confirmation, not at submit. A
  code that became invalid in between falls back to approval.
- **"Book code skips screening" off** means codes still go through approval.
- **A failed mail aborts the decision.**
- **Filtering** works without JS through query parameters, with htmx as an enhancement.
- **Resources** are single-language content that transfers. Their PDFs are private
  assets.
- **Account deletion and change password** are included.
- **Framework pieces** (member auth, private assets, nav badges, atomic throttle) are
  built in Preflow and Folio and released (as 0.21) before the site adopts them.

## Open for the builder

- The package boundary for member auth (`preflow/users` vs inside `preflow/auth`), and
  how a second guard shares or splits the session cookie, under the cookie anti-goal.
- The nav badge mechanism, and how the record action posts the message text.
- How private assets are stored and served (flag vs directory). They must stay outside
  the document root and survive deploys (shared storage).
- The exact copy of pages and mails (first person, short), drafted for the owner to
  approve.
- How many plans: framework 0.21 first, then the site.
