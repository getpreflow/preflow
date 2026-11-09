# Preflow starters: a job board, with and without Folio

Owner conversation 2026-11-09. This is the first of three linked projects:

0. The model bridge (`2026-11-09-model-bridge-design.md`): typed PHP models that Folio can edit. The starters depend on it.
1. **The two starters (this document)**
2. The preflow.dev website: upgrade to current Preflow; Folio for the home page, the new Folio page and the references; an up-to-date package list
3. The docs rewrite: file-based, written for people, using the starters' code as examples

The website and the docs come later, each with its own design. This document records what they need from the starters.

## Intent

Someone evaluating Preflow for a new project is a lead developer or principal architect. They already know Laravel and Symfony, and they judge a framework by running it and reading its code. The starters are what they run.

The starters prove the website's claims in real code:

- **Developer experience comes first.** One command installs a working app with data in it, and its code is short enough to read in an afternoon.
- **Pick the layers you need.** The same app shows pages and controllers, components with HTMX, and a REST API serving the same data. Deleting the HTML frontend leaves a working API, which is how a headless frontend would use Preflow.
- **Folio is an adapter, not a takeover.** The Folio starter is the same app with Folio added. The app's own model stays the app's. Folio gives editors both ways of working, as the owner put it:
  - *page-first*: compose a page from blocks;
  - *repository-first (outside-in)*: create an item in its list, and it shows up on the pages that present it, which have parts editors can change.

The owner's own direction for the domain was a job board, and they asked for my idea. The job board below is mine, built on theirs.

## Goals

- `composer create-project preflow/starter myapp`, then `php preflow serve`, gives a working, seeded app with no configuration and no prompts. The same holds for `preflow/starter-folio`.
- Each starter requires released Preflow versions (`^0.62`), never `dev-main`.
- Each starter is something a developer can keep building on, not a throwaway demo. Its code follows the conventions the docs will teach.
- `composer test` passes on a fresh install.
- The README of each starter is its tour: what to open, what to try, which file does what.

## Non-goals

- A public live demo of Folio's admin. The owner said screenshots and recordings are enough for now; a demo can be revisited later.
- The website and the docs. Separate projects, see above.
- Token management in Folio's admin (create, list, revoke). It stays in the ROADMAP's "Protected APIs" entry; the starter issues tokens from the console.
- Translations of the starters' own text. English only. i18n is wired the way the skeleton has it, so adding a language works, but the starters ship one.
- Payments, applications by job seekers, e-mail alerts. A job board has them in real life; the starter does not need them to make its point.

## Anti-goals (failure even if it works)

- **Needs a build step.** Node, npm or a bundler in either starter.
- **Clever demo code.** Code that is impressive but not what a developer should copy. Every file is meant to be copied.
- **Folio owns the jobs.** If the Folio starter turns the job model into a Folio content type, the "adapter, not takeover" claim is lost. The job model stays an app model in both starters.
- **A broken first run.** Anything between `create-project` and a working page that asks a question, fails on a clean machine, or needs a database server.

## Starter 1: `preflow/starter` (without Folio)

### Data

- **Models** are typed PHP classes (`app/Models`), the way the model bridge makes Folio-editable:
  - `Company`: name, slug, place, website, short description.
  - `Job`: title, slug, company, place, employment type (full time, part time, contract, internship), remote (no, hybrid, fully), salary range (optional), description (Markdown), status (draft, published), published at.
  - `User`: employer accounts; a user belongs to one company.
- **Storage:** SQLite in `storage/`, created and seeded by `create-project`.
- **Seed:** about 40 jobs from 6–8 fictional companies, with varied places, types and remote options so every filter has something to show. The data is deterministic, so screenshots and tests stay stable. Companies are fictional; no real company names.
- **Demo login:** one demo employer account; its login is printed at the end of `create-project` and written in the README.

### Pages and components

- **`/` (home):** a short intro to the starter, the latest jobs as cards, and a link to `/jobs`.
- **`/jobs` (search):**
  - a search box (search as you type), filter chips for place, employment type and remote, and a card/list toggle;
  - each is a component; the result list swaps without a full page load;
  - the URL follows every filter, so the back button and shared links work, and a page loaded from such a URL renders the same result without JavaScript;
  - paging.
- **`/jobs/{slug}` (detail):** the job, its company, and "more jobs from this company" underneath.
- **`/companies/{slug}`:** the company and its open jobs.
- **Error boundary:** one component on the home page shows the boundary's preview (`ErrorBoundary::preview()`), as the skeleton's home does today. The owner calls error boundaries a differentiating feature, so they stay visible.
- **Employer area** (signed in, `/employer`):
  - sign in and out;
  - the company's jobs;
  - post or edit a job: a form with validation and CSRF, saved as a draft, then published.
- **Page cache:** on. The second visit to a page is a cache hit, and publishing a job refreshes the list, the detail page and the company page without anyone clearing a cache. The employer area is never cached (signed-in visitors bypass the cache).
- **Look:** plain, clean CSS co-located in the components; light and dark mode; works on a phone. No CSS framework.

### REST API

All under `/api`, JSON in and out, sharing the models, validation and auth with the pages.

| Method and path | What it does | Auth |
|---|---|---|
| `GET /api/jobs` | Published jobs, with the same filters and paging as `/jobs` (`q`, `place`, `type`, `remote`, `page`) | none |
| `GET /api/jobs/{id}` | One published job | none |
| `GET /api/companies`, `GET /api/companies/{slug}` | Companies and their open jobs | none |
| `POST /api/jobs` | Create a job for the token's company | bearer token |
| `PATCH /api/jobs/{id}` | Update the company's own job | bearer token |
| `DELETE /api/jobs/{id}` | Delete the company's own job | bearer token |

- **Errors are JSON:** 404, 401, 403, 405 and 422 come back as JSON, never as HTML. A failed validation is a 422 that lists each field's errors.
- **Tokens:** a console command issues a personal access token for a user and prints it once. The README shows it with curl.
- **CORS:** off by default; a config key allows given origins, so a headless frontend on another host can call the API.
- **README:** every endpoint with a curl example.

### "API only"

The README has a section showing that the API stands on its own: delete `app/Components` and `app/pages`, and the API and its tests still pass. The starter's tests are split so this can be shown: API tests do not depend on components or pages. A test in the starter itself runs the API tests with the HTML frontend's routes absent, so the claim is checked and not only written.

### Tests

`composer test` runs a fast suite, with at least:
- a component test: filters and the card/list toggle;
- a page test: search, detail, the employer flow from sign-in to a published job;
- an API test: list with filters, the JSON error shapes, creating a job with a token, refusing another company's job;
- a page cache test: publishing a job refreshes the list.

## Starter 2: `preflow/starter-folio` (with Folio)

The same job board with Folio added. Starting from starter 1's code, it differs only where Folio adds something, so the difference between the two is the honest answer to "what does Folio add?".

- **Repository-first:**
  - editors manage jobs and companies in Folio's admin, with lists, search, filters, forms, drafts and publishing;
  - `Job` and `Company` stay the app's PHP model classes; Folio edits them through the model bridge, with its admin settings (labels, list columns, publishing) kept out of the classes;
  - a published job appears on the job list, its detail page, the home page teaser and its company page;
  - each of those pages has parts editors can change: an intro text above the list, a call to action on the detail page, the company page's text around its jobs.
- **Page-first:** the home page and a small news section are Folio pages built from blocks, with live preview, click-to-edit and drafts.
- **Around both:** the media library (company logos, news images), SEO and GEO fields, and roles: an editor account and an employer account show what each may do.
- **Employers:** keep their own area as in starter 1; staff work in Folio.
- **API:** the same as starter 1; it serves what editors publish.
- **Seed:** the same jobs and companies, plus the home page, three news posts and two staff accounts (admin, editor), printed at the end of `create-project`.
- **README:** a tour for the developer and one for an editor. It also has a section "from starter to starter-folio" listing what is added and what stays unchanged, so an existing starter-1 app can follow it.

These are also where the website's Folio page will take its screenshots and recordings from.

## Constraints

- PHP 8.4+, as the framework.
- Runs on ordinary shared hosting: no Node, no Redis, no always-on workers; background work by cron (`preflow pages:work`). Each README has a short deploy section for such hosting.
- Both starters live in the monorepo as `packages/starter` and `packages/starter-folio`, split to `getpreflow/starter` and `getpreflow/starter-folio` like the skeleton, released with the framework's tags.
- Framework rules (owner): features live in the framework's packages behind public interfaces; Folio is only the UI adapter; project-specific things stay in the project. So anything general a starter needs and the framework lacks goes into the framework, not into the starter.

## Framework gaps the starters need filled

Checked against the framework on 2026-11-09. The REST side of the starters needs things the framework doesn't have yet:

- **JSON responses:** no helper for a JSON response; sites build them by hand.
- **JSON errors:** the error handler renders HTML for every request. API routes need 404, 401, 403, 405 and 422 as JSON, decided by the route (API routes) or the `Accept` header.
- **Validation as 422:** a failed validation turns into a JSON 422 with the field errors on API routes.
- **Issuing a token:** `TokenGuard` and `PersonalAccessToken` exist, but no console command issues one.
- **CORS:** a configurable CORS middleware (allowed origins, methods, headers, max-age, preflight). It is already named in the ROADMAP's "Protected APIs" entry.

These ship in a framework release (0.62) after the model bridge (0.61) and before the starters, so the starters require `^0.62`.

## Decided by the owner

- The audience: lead developers and principal architects evaluating a framework.
- The positioning: developer experience first; pick the layers you need (pages, components, REST/headless); components with error boundaries optional; Folio gives editors the same quality.
- Models are PHP classes (the source of truth); Folio edits them through the model bridge, built first.
- A job board, showing components and pages and also REST, and how the app works purely as REST without the components.
- Two starters: one without Folio, one with.
- Folio's two ways of editing: page-first and repository-first (outside-in).
- No live demo for now; screenshots and recordings.
- For the later projects: preflow.dev runs Folio for the home page and the Folio page; the docs stay file-based; the production sites appear as references in a list that is easy to extend.

## My calls (the owner can overrule)

- **Names:** `preflow/starter` and `preflow/starter-folio`.
- **The skeleton:** retired. Both starters replace it: its blog demo duplicates the job board, and its `dev-main` requirements break a fresh install. On Packagist it is marked abandoned in favour of `preflow/starter`.
- **Framework version:** the model bridge ships as 0.61, the REST gaps as 0.62, and the starters require `^0.62`.
- **Storage:** SQLite only. MySQL is a config change the README mentions.
- **English only**, with i18n wired.
- **The API's shape:** plain JSON with a small, consistent envelope (`data`, `meta` for paging, `errors` for 422), not JSON:API or HAL. Easy to read, easy to copy.
- **Employers in the Folio starter:** keep their own area, not Folio's admin, so the starter shows both a members area and a staff admin.

- **Release order:** 0.62 holds the REST pieces (and anything the "API only" test needs at boot). The starters are developed in the monorepo against the local packages, like the skeleton today, and tagged with the framework; their composer.json requires `^0.62`.
- **The skeleton** keeps its last tagged release and is removed from the split in the release that ships the starters. None of the 5 production sites depends on it; the website's quickstart moves to the starters in the website project.
- **Signed-in visitors** get whole live pages, as the page cache already does; the starters need no `{% live %}` parts for the signed-in header.
- **Tokens** act as their user in full; scopes per token stay in the ROADMAP. Only employers get tokens; the Folio starter's staff work in the admin.
- **Drafts and publishing:** one source of truth per starter. Starter 1: the job's own `status` and `published_at`. Starter 2: Folio's publishing; the employer area and the API publish through Folio's public interface for it, so all three agree.
- **Seeds:** split in two. A required seed (safe in production, creates no accounts) and a demo seed (the jobs, companies and demo accounts with known passwords). `create-project` runs both; the demo seed refuses to run while `app.debug` is off. The README's deploy section runs `preflow migrate` and the required seed only, and says how to create the first real account.
- **"API only" test:** a separate test bootstrap pointing pages and components at empty paths is acceptable; if that needs a boot option the framework lacks, it goes into 0.62.

## Left to the builder

- The exact seed content, the CSS and the component breakdown.
- How the API tests run with the HTML frontend absent.
- The exact JSON shape of errors, within "field name → list of messages".
- Whether the framework's new JSON pieces are one package change or several, within the rule that they live in the framework.
