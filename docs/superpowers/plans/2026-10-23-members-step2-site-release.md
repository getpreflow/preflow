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

## 4. Deploy

- [ ] Deploy with Deployer. It runs `migrate` and `folio:extensions:apply` (creates the member tables and applies the Members extension).
- [ ] Check that `shared/storage/private-media` exists and the site answers.

## 5. Update the live content

Content is data, so it goes through pull, a local edit, export and a server import. Dry run first at each step. Do not touch the live content any other way.

- [ ] Pull: `php preflow folio:pull production --dry-run`, then `php preflow folio:pull production` (writes a backup first).
- [ ] Local edit, on the `tools` page: replace the `coming_soon` block with `members_intro`.
- [ ] Local edit, on the privacy page: replace the blocks (EN and DE) with the text of the new `PrivacyText` (the Cookies and Contact & email blocks change, a Members area block is added).
- [ ] Look at both pages locally (`/tools`, `/de/tools`, `/privacy`, `/de/privacy`).
- [ ] Export locally: `php preflow folio:export content.tar.gz`.
- [ ] Copy it to the server and run `php preflow folio:import content.tar.gz --dry-run`. Read the output. Check that it does not touch member records or the `auth_*` tables (member data is personal data and never part of an export). Today the live site has no members, so the first import is safe; for later content updates this check is the one that matters.
- [ ] Run the import without `--dry-run`. It writes a backup first.

## 6. Settings and the book code

The owner, in Folio on live:

- [ ] Settings, Members: notice email, accept and decline texts, welcome note, the German texts, screening question.
- [ ] Library, Codes: issue the book code with the purpose `members.book`.
- [ ] Resources: add the first resources (optional for the release, the toolbox is empty without them).

## 7. Cron

- [ ] In my.cyon add the cron job:

  ```
  15 3 * * * cd ~/public_html/smart-stack.works/current && /usr/local/bin/php84 preflow members:prune > /dev/null 2>&1
  ```

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
- [ ] Next morning: the cron ran without error (members:prune; no output expected).
