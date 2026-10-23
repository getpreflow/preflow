# smart-stack.works members area: release checklist

Site branch: `feat/members-step2` (smart-stack.works). Every step is gated by the owner; nothing here runs on its own. Owner notes for the day-to-day live in the site repo at `docs/members.md`.

## 1. Approve the copy

The owner reads and approves, in EN and DE:

- [ ] the pages: `/tools` entry, register, confirm, login, forgot, reset, account, toolbox, error and notice pages
- [ ] the mails: confirmation, reset, accept, decline, and the owner's info mail
- [ ] the defaults in Settings, Members: screening question and help, accept and decline messages, welcome note
- [ ] the privacy text: `app/Content/PrivacyText.php`, sections Cookies, Contact & email and Members area (draft written 2026-10-23)

Changes from this review are made on the branch before step 2.

## 2. Merge the site branch

- [ ] `feat/members-step2` merged into `main` of smart-stack.works (tests green: `vendor/bin/phpunit`).

## 3. Server environment

The owner edits `shared/.env` on the server (`~/public_html/smart-stack.works/shared/.env`):

- [ ] `MAIL_DSN` (the hosting's mail server, as for smeyer.me)
- [ ] `MAIL_FROM=hi@smeyer.me`
- [ ] `APP_URL=https://smart-stack.works` (mail links are built from it, never from the request Host)

`storage/private-media` is already a shared dir in `deploy.php`; the first deploy creates it. Nothing to do.

Before the deploy, locally (the content import in step 5 replaces the live **Members settings** and the **rbac roles** with the local copies, so what is local now is what goes live):

- [ ] Fill the Members settings locally: notice email, screening question and help, accept and decline messages, welcome note, the German texts. Set **registration open to off**, so that registration stays closed until the book code exists (step 6). A deployed site with registration open and no book code would let readers register before the owner can answer them.
- [ ] Check the rbac roles locally (Folio, Roles): they are replaced on live too.
- [ ] If the book code can be issued on live before the deploy, issue it first (Library, Codes, purpose `members.book`); otherwise do it in step 6.
- [ ] `symfony/mailer` is installed: after the deploy, on the server, `cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 composer show symfony/mailer` (or `ls vendor/symfony/mailer`) lists it. Without it every mail fails: readers who register are removed again after the response and no decision can be saved.

## 4. Deploy

- [ ] Deploy with Deployer. It runs `migrate` and `folio:extensions:apply` (creates the member tables and applies the Members extension). Registration is open by default after this deploy, so do step 5 straight away, in the same sitting: its import brings the closed registration live.
- [ ] `symfony/mailer` is installed on the server: `cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 /usr/local/bin/composer show symfony/mailer` (or `ls vendor/symfony/mailer`).
- [ ] **Web SAPI probe** (owner; the mails go out after the response only where the web SAPI can release the client). Put a temporary PHP file in the document root containing `<?php var_dump(PHP_SAPI, function_exists('litespeed_finish_request'), function_exists('fastcgi_finish_request'));`, open it in a browser, then delete it. The CLI equivalent proves nothing about the web. At least one function must be `true`; if both are `false` the after-response send is best effort only and response times can still tell an address apart. (Same step as in `docs/superpowers/plans/2026-10-23-release-0.24-checklist.md`, step 4.)
- [ ] Check that `shared/storage/private-media` exists and the site answers.

## 5. Update the live content

Content is data, so it goes through pull, a local edit, export and a server import. Dry run first at each step. Do not touch the live content any other way. On the server every command is `cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 preflow …` (the CLI default `php` may be older).

The import replaces the live **Members settings** (`folio_settings_members`) and the **rbac roles** (`rbac_role`) with the local copies. It does not touch member records or the `auth_*` tables. For later content updates: pull first, and do not edit those settings on live between the pull and the import.

- [ ] Pull: `php preflow folio:pull production --dry-run`, then `php preflow folio:pull production` (locally, as before) (writes a backup first).
- [ ] Local edit, on the `tools` page: replace the `coming_soon` block with `members_intro`.
- [ ] Local edit, on the privacy page: replace the blocks (EN and DE) with the text of the new `PrivacyText` (the Cookies and Contact & email blocks change, a Members area block is added).
- [ ] Look at both pages locally (`/tools`, `/de/tools`, `/privacy`, `/de/privacy`).
- [ ] Export locally: `php preflow folio:export content.tar.gz`.
- [ ] Copy it to the server and run `cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 preflow folio:import content.tar.gz --dry-run`. Read the output: the Members settings show as replaced (registration open is off), the `rbac_role` diff is empty or expected, and no member record or `auth_*` table appears (member data is personal data and never part of an export).
- [ ] Run the same command without `--dry-run`. It writes a backup first.

## 6. Settings and the book code

The owner, in Folio on live:

- [ ] Settings, Members: check what the import brought (notice email, texts, screening question).
- [ ] Library, Codes: issue the book code with the purpose `members.book` (if not done before the deploy).
- [ ] Settings, Members: switch **registration open** on, now that the book code exists.
- [ ] Resources: add the first resources (optional for the release, the toolbox is empty without them).

## 7. Cron

- [ ] In my.cyon add the cron job:

  ```
  15 3 * * * cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 preflow members:prune >> storage/logs/cron.log 2>&1
  ```

  The output goes to `storage/logs/cron.log`, a shared directory, so the owner can read it.

## 8. Smoke test on live

With a test address the owner controls:

- [ ] `/tools` shows the entry page (not "coming soon"). A public page such as `/` sends no `Set-Cookie`.
- [ ] Register. The confirmation mail arrives from hi@smeyer.me, the link works (GET shows the page, the form posts, the account is confirmed).
- [ ] The owner's info mail arrives with name, email and answer.
- [ ] Log in: the account is waiting, no toolbox yet. In Folio accept the member. The accept mail arrives, the login now shows the toolbox.
- [ ] A resource opens; the PDF downloads. The same URLs without login show the login page.
- [ ] Forgot password: the reset mail arrives, the link works.
- [ ] Register a second test account with the book code: it skips the screening.
- [ ] Delete the test accounts: under `/tools/account` as the member, or in Folio. Check that no test member is left in Folio, Members.
- [ ] Next morning: the cron ran without error: `storage/logs/cron.log` on the server has a fresh entry and no error (members:prune prints little or nothing; a missing file after the first run means the job did not run).
