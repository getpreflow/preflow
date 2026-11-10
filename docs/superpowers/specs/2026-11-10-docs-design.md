# preflow.dev docs — design

Status: approved by the owner in conversation (2026-11-10). Step 3 of the preflow.dev series (model bridge → REST → starters → website → **docs**). The owner asked me to push through all phases without waiting, announcing each part when it can be checked locally (https://preflow.dev.smyr); they expect only small content changes afterwards.

## Intent

The owner's words: the docs should let people "understand the concepts, how to start and then dig deeper and understand how things tie into each other and then understand and have easy access to what is already there to be used (functions, methods, classes, apis etc). Like smooth getting into and then deeper and deeper so humans can understand and also have fun reading and discovering."

The owner's worst experience with framework docs (Yii2): **incomplete** — core concepts such as asset management and routing were not fully covered. Yii2's ordered guide and generated API reference were "not bad but also not great".

Readers: a lead developer or architect evaluating Preflow (as for the website), and developers building sites with it (like the owner on the five production sites).

## Goals

1. **A path from shallow to deep**, in six parts (the sidebar order):
   1. **Understand** — what Preflow is · the big ideas · how a request flows · glossary.
   2. **Start** — install a starter · tour of the starter · your first page · your first component · deploy on shared hosting.
   3. **Build** — one guide per concept: routing · pages & layouts · components & actions · HTMX · error boundaries · assets & CSP · data & storage · validation & forms · auth & tokens · roles & permissions · languages · page cache · SEO & GEO · media · email & notifications · REST APIs · testing · console. (The concept list is the minimum; anything the completeness check finds without a home gets a guide or a section.)
   4. **Folio** — add Folio to an app · types & fields · blocks & pages · publishing · media library · roles · extensions & screens.
   5. **Extend** — how extension works · template engine · hypermedia driver (e.g. Datastar) · storage driver (e.g. PostgreSQL) · guards · transports · AI providers · middleware · validation rules · Folio fields and blocks · contract tests.
   6. **Reference (generated)** — two ways in: **by task** ("working with images", "sending mail", … → the functions, classes, commands, config keys for it) and **by package** (each package's public API: classes, interfaces, public methods with signatures and docblocks; template functions; console commands; config keys; Folio field types and block attributes).
2. **Every guide page has the same rhythm:** In one sentence → See it work (real starter code + a picture or short recording) → How it ties in (a small diagram) → Step by step (common tasks) → Good to know (limits, edges, shared-hosting notes) → Go deeper (the reference entries used, the next guide, how to extend it).
3. **Completeness is tested, not hoped for.** A check fails when something public has no documentation: a package without a reference section, a public class/method, template function, console command or config key missing from the generated reference, or a concept (a group of reference entries) that no guide links to. The owner's Yii2 complaint is the failure this prevents.
4. **"Bring your own" is first-class:** each extension point documented the same way — what it does and its interface, the contract, a small real worked example, how to test it.
5. **Contract test suites (owner: "awesome idea, yes I want that")**: `preflow/testing` ships contract test suites for the big three adapters — template engine, hypermedia driver, storage driver — so an adapter author runs them against their implementation. The built-in adapters (Twig, Blade; HTMX; SQLite, MySQL, JSON) run them in the monorepo too. Released in a framework release.
6. **READMEs small, docs deep (owner):** each package README shrinks to a short intro (what it is, install, a minimal example) and a link to its docs. The depth lives on preflow.dev.
7. **Fun to read:** a friendly, plain voice (the owner's style rules for prose: short sentences, concrete, no jargon without explanation), something to look at on every guide page, examples from the starters, and links that invite the next step.

## Phases (each goes live when done; the current docs stay until replaced)

1. **Foundation:** new docs structure and page layout; the reference generator and the completeness check; Understand and Start.
2. **Build:** all concept guides.
3. **Folio and Extend:** the Folio part, the extension guides, the contract test suites (framework release).
4. **Cleanup:** package READMEs shrink; old docs pages retired (with redirects from old URLs).

## Non-goals

- Translations of the docs (English only).
- Versioned docs (one version: the current release; the reference states the version it was generated from).
- A docs search service; the existing in-page docs search is kept/extended.
- Video tutorials.

## Anti-goals

- **Gaps:** a core concept with no explanation, or a public API with no reference entry (see goal 3).
- **Claims the code doesn't back** (as for the website): every statement true for the current release; examples are real code from the starters or the packages, never invented variants that wouldn't run.
- **Drift:** hand-written pages that copy signatures that later change. Signatures and lists come from the generated reference; guides link to it instead of duplicating.
- **A wall of text:** a guide without code, picture or diagram.

## Constraints

- The docs stay Markdown files in the website repo (`/Users/smyr/Sites/gbits/preflow-website/docs/`), rendered by the site (DocsController, path-contained). The site runs on shared hosting: no build step at request time beyond what PHP does; generated files are committed.
- The reference is produced by a console command in the website repo, from the released packages at the site's version (same idea as `starters:sync`/`packages:refresh`), and refreshed in the release checklist.
- What counts as public: classes, interfaces, traits, enums and their public methods/constants in `packages/*/src`, minus anything marked `@internal` (24 files use it today) — the generator and the completeness check share that rule. Template functions, console commands, config keys (from each package's config defaults and README config sections) and Folio field/block attributes are separate reference kinds.
- Pictures and recordings come from `tools/capture` (retakeable); diagrams are inline SVG under the site's CSP.
- Framework rules (owner): features live in packages behind public interfaces; anything general the docs need from the framework (e.g. the contract suites) goes into the framework in a release.

## My calls (the owner can overrule)

- Phase order and the guide list above.
- Reference generator implementation (reflection or a parser) is the builder's choice within the constraints.
- Old URLs (`/docs/guides/*`, `/docs/packages/*`) redirect to their new homes (Folio redirects, like the skeleton page).
- Each phase is deployed when its review is clean; the owner reviews afterwards and expects small content changes.

## Decisions for the builder (my calls after the builder check)

1. **Topics (concepts) are a hand-curated map** in the website repo, `docs/reference/topics.php`: topic key ⇒ title, the guide that explains it, and its members (class/namespace patterns, template function names, command names, config files/keys). Every public entry must belong to at least one topic — an orphan fails the check. The by-task reference pages are generated from this map. No framework edits in phase 1.
2. **The completeness check** is a test in the website suite (`composer test`) plus a console command `docs:check` that prints what's missing. From phase 1 it enforces: every public entry is in the generated reference and in a topic. A topic's guide may be marked `pending` (with the phase that writes it); pending topics are listed, not failed, until the end of phase 3, when the allowance is removed and pending fails.
3. **The generator reads the released packages** at the site's Preflow version, installed into a temp dir by Composer (like `starters:sync`): all library packages from `resources/packages.json` (today 22; not skeleton, starter, starter-folio). `ai, auth, …, rbac, roles, members, codes` are all separate packages. A class marked `@internal` is hidden whole; an `@internal` method is hidden; private/protected members are not public API.
4. **Non-class kinds, read statically (no framework edits):** template functions from `TemplateFunctionDefinition` registrations (name, parameters, the comment or docblock above as description; missing descriptions are listed by `docs:check`); console commands from their command classes (name, description, options); config keys from the starters' config files (`packages/starter-folio/config/*.php`, `packages/starter/config/*.php`, plus package-shipped config like `ai/config`), with the comments in those files as descriptions; Folio field types and block attributes are classes in the API reference plus a generated "field types" list from the field type classes. A parser library (e.g. nikic/php-parser) may be a dev dependency of the website.
5. **Shape:** one generated Markdown page per package (sections per class/interface, then functions, commands, config) and one per topic (by task), committed under `docs/reference/` — tens of files. The sidebar's Reference part lists Topics and Packages. The existing docs search indexes the new pages, the reference included.
6. **Old and new together:** phase 1 replaces the sidebar with the six-part structure. New URLs: `/docs/understand/*`, `/docs/start/*`, `/docs/build/*`, `/docs/folio/*`, `/docs/extend/*`, `/docs/reference/*`. Old pages stay reachable and listed under a temporary "Older pages" group at the bottom; each old page gets a 301 to its replacement in the phase that replaces it (Folio redirects, seeded like the skeleton redirect). `getting-started/*` is replaced in phase 1.
7. **Page rhythm is enforced for guides** (Build, Folio, Extend): a test checks the six section headings and at least one code example plus one figure (picture, recording or SVG diagram). Understand and Start pages are tutorial-shaped and not enforced. New captures may be added to `tools/capture` in any phase.
8. **Starter code inside Markdown** comes through a small Markdown extension (e.g. a fenced block ```` ```starter starter app/Models/Job.php 16-40 ```` ) rendered by the existing `StarterSource`/`StarterCode` — never pasted. Start uses `preflow/starter`; the Folio part uses `preflow/starter-folio`. Deploy steps come from the starters' README deploy sections and the production sites' practice (giantbits/cyon shared hosting); no new environment.
