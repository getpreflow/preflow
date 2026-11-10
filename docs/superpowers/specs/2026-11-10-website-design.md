# preflow.dev website — design

Status: draft for the owner's review (2026-11-10).

This is step 2 of the preflow.dev series agreed on 2026-11-09:

0. Model bridge (0.61), REST pieces (0.62): released.
1. The two starters `preflow/starter` and `preflow/starter-folio` (0.63): released.
2. **The website (this document).**
3. The docs rewrite: file-based, written for people, using the starters' code as examples. Its own design, right after this one.

The starters spec (`2026-11-09-starters-design.md`) already settled, for this project: preflow.dev runs Folio for the home page and the Folio page; the docs stay file-based; the production sites appear as references in a list that is easy to extend; screenshots and recordings instead of a live demo; the website's quickstart moves to the starters.

## Intent

The visitor is a lead developer or principal architect evaluating Preflow for a new project. They know Laravel and Symfony. When they leave preflow.dev they should have understood (owner's words): the core concepts, the answers Preflow has for typical cases, what sets Preflow apart, and examples that give a feeling for how it is actually used.

Today the site cannot do that: it runs Preflow 0.19 (44 releases behind), its home page lists 18 of 22 packages, its quickstart installs the abandoned skeleton, and Folio, the biggest difference to other frameworks, has no page of its own.

## What sets Preflow apart (the four ideas)

Agreed with the owner. The home page and the Folio page are built around these; the order is the order on the page.

1. **Your data, your way.** Each type picks its own storage (SQLite, MySQL, JSON files, …) and is read the same way. A type is a PHP class (code completion) or a JSON file, switchable per type, and Folio edits both. The same data serves pages, HTMX components and a REST API; delete the HTML frontend and a working headless API remains (the starter proves it with a test).
2. **Pages that don't break, and stay fast.** Components keep template, logic and HTMX actions together, no build step. Error boundaries: a failing part shows its fallback, the rest of the page renders. The page cache rebuilds affected pages when content is published.
3. **Folio, when you want it** (owner's addition). Start without it, add it later, and it works with your types out of the box and is easy to extend. No typical framework provides this.
4. **Safe and ready to ship.** Strict CSP, CSRF on every form, signed component state, sanitized rich text. SEO, GEO (`llms.txt`), redirects, languages with AI translation, fast test suites.

Under all four, one line: **runs on ordinary shared hosting** — no Node, no Redis, no always-on workers. The references prove it.

## Goals

- **Navigation:** Home · Docs · Folio · Packages · GitHub. Folio is a top-level item (owner, 2026-10-26).
- **Home page**, top to bottom (owner approved the wireframe; refinements may come later):
  1. Hero: one headline, one line "runs on shared hosting, no Node, no build step", the `composer create-project preflow/starter` command, a link down to the four ideas.
  2. The four ideas, as four cards.
  3. "How it's used": real code from `preflow/starter` in tabs — a model, a page, a component with HTMX, an error boundary, the API — each next to what it renders, each linked to its docs page.
  4. "How do I…": the typical cases an architect asks about (sign users in, a REST API, translations, background work without workers, deploy on shared hosting, …), each a two-line answer and a docs link.
  5. The two starters, side by side: `preflow/starter` (job board without a CMS) and `preflow/starter-folio` (same app with Folio).
  6. References: the sites running Preflow.
  7. Packages: all packages, grouped, one line each.
- **Folio page** (`/folio`), top to bottom (owner approved):
  1. Hero: the moment — the starter's `Job` class on the left, a short recording of that same class as a list and a form in Folio on the right. "Your models, editable. Without writing an admin." / "Start without Folio. Add it later. Your types stay yours."
  2. The two ways editors work, one recording each: page-first (build a page from blocks, edit in the preview, publish) and list-first / outside-in (add a job in its list, it appears on the job pages).
  3. What comes with it, a screenshot grid: drafts/preview/publish, roles and permissions, media library, languages with AI translation and its cost, SEO and GEO checks, redirects.
  4. Make it yours: a short Folio extension next to how it looks. The principle: Folio is only the UI; features live in the framework behind interfaces.
  5. From starter to starter-folio: the steps from the starter README, and what stays unchanged.
  6. Try it: `composer create-project preflow/starter-folio`, demo logins printed by the install, links to the reference sites that run Folio.
- **References:** holz-objekte.org, BG Genius (spielegesellschaft.ch), languagecorner.ch, smart-stack.works, smeyer.me, plus preflow.dev itself. Each: name, link, one line, a screenshot, which parts of Preflow it uses (e.g. Folio, members, AI translation). A list the owner extends in Folio without touching code.
- **Packages:** every published `preflow/*` package except the starters and the abandoned skeleton (today 22), grouped, each with its one-line description and a link to its docs page or, where none exists yet, its GitHub repository. The list must not fall behind again (see Constraints).
- **Upgrade** to Preflow `^0.63` with Folio, and keep the site current after every release like the other five sites (it becomes the sixth).

## Non-goals

- **The docs rewrite.** The docs keep their current text and structure until step 3. This project only keeps them rendering on 0.63 and changes what would otherwise be wrong on day one: the installation page points to the two starters instead of the skeleton, and the skeleton page is removed from the docs navigation. Missing package pages (ai, codes, media, members, rbac) come with the docs rewrite; until then the packages list links those to GitHub.
- A public live demo of Folio's admin (owner: screenshots and recordings are enough for now).
- A blog, news, newsletter, or contact form.
- Translations. The site stays English only.
- A new visual identity. The current look (colours, type, dark mode, components such as the code view and the theme toggle) carries over to the new sections.

## Anti-goals (failure even if it works)

- **Marketing claims the code doesn't back.** Every claim on the home page and the Folio page is something the starters or the reference sites actually do, and code shown on the site is real code from the starters, never a simplified variant that would not run.
- **Code examples that drift.** A code tab showing code the current starter no longer has.
- **Pictures that can't be retaken.** Screenshots and recordings come from `preflow/starter-folio` with its demo data, so they can be reproduced when Folio changes.
- **A heavy page.** The site is the proof that Preflow is fast; it must not ship a large script bundle, autoplaying heavy video, or a build step.
- **Folio for things that are code.** Code examples and the packages list come from their sources, not typed into Folio by hand.

## Constraints

- Hosting stays as it is: giantbits.com (`/home/containr/public_html/<application>`), deployed with Deployer from `getpreflow/website`. Same rules as the other sites: backup before deploys and migrations, `composer test` green before deploying, page cache on, background work by cron only.
- Framework rules (owner): features live in the framework's packages behind public interfaces; Folio is only the UI adapter; project-specific things stay in the project. Anything general the website needs and the framework lacks goes into the framework (in a framework release), not into the website.
- PHP 8.4+, no Node, no npm, no bundler.
- Strict CSP stays (nonces); every script and style the new sections need must work under it.
- The site gets `composer test` like the other sites (preflow/testing).

## How it gets made (my calls; the owner can overrule)

- **What Folio owns:** the home page and the Folio page are Folio pages built from blocks, so the owner can edit headlines, texts, order and the "How do I…" answers. References are a Folio type. Docs stay Markdown files in `docs/`.
- **What comes from sources, not Folio:**
  - Code examples are read from `preflow/starter`'s files at the version the site requires (the starter is a dev or regular dependency of the website, or its files are copied in by a console command at upgrade time — builder's choice). A block names the file and an optional line range; nothing is pasted.
  - The packages list is generated from Packagist's list of `preflow/*` packages (name, description) by a console command, stored in the repository, and refreshed as part of the website's upgrade after each release. The grouping is a small map in the website's code.
- **Which starter the code comes from:** the home page's tabs show `preflow/starter` (the app without Folio). The Folio page shows `preflow/starter-folio`'s classes, because that is what Folio actually edits. The Folio hero's `Job` is starter-folio's, and section 5 ("From starter to starter-folio") shows the real differences between the two `Job` classes from the starter-folio README's "changed, same interface" table, not a claim that nothing changed. "Your types stay yours" means: still a plain PHP class in `app/Models`, owned by the app; it does not mean "byte for byte unchanged". The website reads code from both starters.
- **"Next to what it renders" means a picture**, not live output. preflow.dev does not run the starter's app inside itself. Each code tab has a screenshot (or a short recording for the HTMX search) of the starter running with its demo data.
- **The Folio extension example:** `starter-folio` has none today. A small, real one is added to `starter-folio` first (for example a dashboard panel "jobs per company" or a custom field), with its own test and a README section, released with the framework's next tag. The Folio page shows that code. It is project code in the starter, so the framework rules are kept.
- **Screenshots and recordings are part of this project and made by the builder** with a capture script kept in the website repository (a browser driven against a local `starter-folio` and `starter` with demo data, plus the six reference sites' home pages). Running the script again retakes every picture; that is how "pictures that can't be retaken" is avoided. The Folio page ships complete, not with placeholders.
- **Models:** typed PHP classes (the model bridge), like the starters: `Reference` at least. Storage SQLite in `storage/`, like the starters.
- **Recordings:** short, muted, looping, with a poster image, under about 2 MB each, paused for visitors who prefer reduced motion, never autoplaying with sound. Format (video vs. GIF) is the builder's choice within those limits.
- **Folio access:** a staff account for the owner, created with `staff:create`-style console command on the server; I never type or store its password.
- **Existing pieces:** the current components (code view, theme toggle, navigation, docs sidebar, search, view source) are kept and upgraded, not rewritten, unless 0.63 makes one obsolete.
- **Where this spec lives:** in the framework repo with the other preflow.dev specs, because the website repo's `docs/` folder is the site's content.

## Left to the builder

- The block types for the home page and the Folio page, and how coarse they are (one block per section is fine).
- The exact headline and copy; the placeholder headline "Server-rendered PHP. Pages that don't break. A CMS when you want one." is a starting point the owner will edit in Folio.
- Which "How do I…" questions, as long as each answer is true today and links to an existing docs page or starter file.
- How code examples get from the starter into the site (dependency vs. copy command), within the "no drift" anti-goal.
- The upgrade path from 0.19 to 0.63 (in steps or at once), as long as the site never deploys broken.

## Success

- preflow.dev runs Preflow 0.63 with Folio, page cache on, `composer test` green, deployed like the other sites.
- A visitor finds the four ideas, real starter code, the two starters, the references and all 22 packages on the home page, and the Folio story on `/folio`.
- The owner can change any home-page or Folio-page text and add a reference in Folio without code.
- Nothing on the site still points to the skeleton.
