# Preflow Jobs with Folio

The job board of `preflow/starter`, with Folio added: a content admin for your staff. Jobs and companies are Folio content, the home page is built from blocks, and there are news posts, SEO fields and a media library. Employers still post jobs in their own area, and the JSON API still works. It runs on SQLite, so there is no database server to set up. It has no Node, no npm and no build step.

If you want the job board without an admin, use `preflow/starter`. This one is the same app plus Folio. "From starter to starter-folio" below lists exactly what changed.

## Quick start

```bash
composer create-project preflow/starter-folio myapp
cd myapp
php preflow serve
```

Open `http://localhost:8080`. Use that exact address: `APP_URL` in `.env` and the CSRF origin check both expect it. If you open the app as `127.0.0.1:8080`, the login forms will refuse you.

`create-project` copies `.env`, makes an app key, creates the database, installs the Folio extension `jobs` (`folio:extensions:apply`) and fills the database with demo data. At the end it prints three demo logins:

```
Demo employer: employer@example.com / demo-employer (sign in at /employer)
Demo admin: admin@example.com / demo-admin (sign in at /folio)
Demo editor: editor@example.com / demo-editor (sign in at /folio)
```

These passwords are public demo data. `APP_DEBUG=0` only stops the seeder from running; it does not remove accounts that already exist. The accounts live in the SQLite file in `storage/data`, so never upload that file from a dev install (see "Deploy on shared hosting").

The demo seed adds logos and news images, which needs the PHP `gd` extension. Without it (or with `STARTER_NO_IMAGES=1` in `.env`) the seed skips the images and says so; everything else works.

A known limit: Folio's own login at `/folio/login` compares the email case-sensitively, so type it in lower case.

## Developer tour

### Pages and routes

| Open | What you see | Where it lives |
|---|---|---|
| `/` | The home page, built from blocks in Folio | the `page` record "Home", `app/Components/Blocks/` |
| `/jobs` | Search and filter, 12 jobs per page, with an intro edited in Folio | `app/pages/jobs/index.twig`, `app/Components/JobSearch/`, `app/Jobs/JobFilters.php` |
| `/jobs/<slug>` | One job, with more jobs from the same company | `app/Controllers/Site/JobController.php`, `app/pages/_views/job.twig` |
| `/companies/<slug>` | A company and its open jobs | `app/Controllers/Site/CompanyController.php`, `app/pages/_views/company.twig` |
| `/news`, `/news/<slug>` | The news list and a post | `config/models/news.json`, `app/pages/news/index.twig`, `resources/folio/frontend/news.twig` |
| `/<slug>` | Any other page staff add under Pages | Folio serves it with `resources/folio/frontend/page.twig` |
| `/about-error-boundaries` | A demo of a failing component | `app/pages/about-error-boundaries.twig`, `app/Components/ErrorDemo/` |
| `/employer` | Employers sign in, then add, edit, publish and unpublish their jobs | `app/Controllers/Site/Employer*Controller.php`, `app/pages/_views/employer/` |
| `/folio` | The admin for staff | Folio, set up by `config/folio.php`, `config/roles.php` and `config/models/` |
| `/api/jobs` | The same jobs as JSON | `app/Controllers/Api/JobsController.php` |
| `/llms.txt`, `/index.md`, `/sitemap.xml`, `/robots.txt` | Pages for AI assistants and search engines | `config/geo.php`, `config/seo.php` |

`php preflow routes:list` lists the routed paths. What middleware answers (`/sitemap.xml`, `/robots.txt`, `/llms.txt`, redirects) isn't listed, and Folio's pages, `/` among them, are one catch-all row, `/{...path}`.

### Where the models are, and how Folio sees them

`Job` and `Company` are ordinary model classes in `app/Models/`. Two things make each of them a Folio type:

1. **The class says so.** `#[Entity(table: 'jobs', storage: 'default', type: 'job')]`. Folio reads the fields (`#[Field]`, `#[Relation]`, `#[Validate]`) from the class, so the admin form and the app use the same rules. `Job::company` is a `#[Relation]`, so Folio shows a pick from the companies. `Company::logo` is a `type: 'asset'` field, so Folio shows the media library's picker.
2. **An admin file says how Folio lists it.** `config/models/job.json` and `config/models/company.json` hold the label, the place in the sidebar, the list columns and filters, and `"publishing": true`. The fields are not repeated there.

Staff accounts follow the same pattern: `app/Models/Staff.php` is a plain model (table `staff`) that the `staff` guard reads. Its fields have the names of `preflow/rbac`'s staff accounts (`password_hash`, `active`, `auth_epoch`, `last_login_at`, `created_at`), so the table stays when you move the roles into Folio (see [Roles edited in Folio](#roles-edited-in-folio)). Folio's revisions show a staff account by its name.

### Publishing goes through Folio

There is no `status` field on `Job`. A job is public only when Folio has published it. The pages and the API read through `app/Jobs/JobSearch.php`, which uses Folio's `PublishedModels`, so a draft, or an edit nobody has published yet, never leaves that class. The list sorts by the `postedOn` field ("Posted on"), newest first, and then by the time Folio published the copy.

Employers do not bypass this. `app/Jobs/JobWriter.php` is the one place that changes a job for the employer area and the API:

- `create()` and `update()` change the working copy only.
- `publish()` calls Folio's `Publisher`, as the Publish button in Folio does. The revision names the employer, so staff see who published it. It sets `postedOn` on the first publish. Publishing again, or unpublishing and publishing again, never moves a job to the top of the list.
- `unpublish()` and `delete()` go through the `Publisher` too, so no published copy is left behind.
- `state()` is `draft`, `published`, or `changed` (published, with edits not yet published). The employer's job list and the API's owner view show it.

Publishing needs the permission `job.publish`, which `config/roles.php` gives the `employer` role (`employer:create` and the demo seed give every employer that role). The employer area and the API ask before they publish; an account without the role posts drafts. When it tries to publish, the employer area goes back to the job list with a notice and the API answers 403.

A job that staff publish straight from Folio without a "Posted on" date is listed after the dated ones, until an editor sets the date.

### Singletons

Some texts belong to a page that the app renders, not to a record. These are Folio singletons (`"singleton": true`), listed under Pages in the sidebar:

| Type | Fields | Shown |
|---|---|---|
| `jobs_page` ("Job list page") | `intro` | above the search on `/jobs` |
| `job_page` ("Job page") | `cta_title`, `cta_text` | below every job description |
| `company_page` ("Company page") | `before_jobs` | above the open jobs on every company page |

A template reads one with `folio_get('jobs_page')`; see `app/pages/jobs/index.twig`. `app/Seeds/ContentSeeder.php` gives each a first published text.

### Blocks

The `page` type (`config/models/page.json`) has a `sections` field of type `blocks`. Its blocks are classes in `app/Components/Blocks/`, each with a template next to it:

| Block | Fields | Note |
|---|---|---|
| `hero` | headline, intro, image, button label and link | the button link accepts only a path like `/jobs` or an `https://` address |
| `text` | body (rich text) | |
| `latest_jobs` | title, count | the newest published jobs |
| `latest_news` | title, count | the newest published news posts |

A block is a `BlockComponent` with a `#[Block(key: ...)]` attribute and `#[BlockField]` properties. To add one, copy `Hero` and add its key to `allowed` in `config/models/page.json`. In a block template, `folio_editable('headline')` marks an element so that editors can click it in the live preview.

### The API

Unchanged from `preflow/starter`: the same routes, fields, paging (12 per page), tokens and errors. See its README. The differences come from Folio:

- The owner's `status` can also be `changed`: the job is published and has edits that are not published yet. Public reads never show a status.
- Writing `"status":"published"` publishes through `JobWriter`, so a draft that staff saved in Folio with a broken field answers 422 instead of going live.
- `published_at` is the job's "Posted on" date, or the time Folio published it.

### Make Folio yours

The starter ships one Folio extension, `app/Folio/Jobs`: an "Open jobs" column on the company
list (the jobs the public sees, counted from the published copies) and a notice on the
dashboard ("2 jobs are drafts", linking to the job list) while jobs wait unpublished. It needs no
new permission and no seed. The code is in
[`app/Folio/Jobs/Types/CompanyAdmin.php`](app/Folio/Jobs/Types/CompanyAdmin.php).

To add your own, you write two files and a config entry:

1. `app/Folio/<Name>/Extension.php`: a class extending `Preflow\Folio\Extension\Extension` with
   `#[FolioExtension(name: ..., label: ..., description: ...)]`. Folio finds it there.
2. `app/Folio/<Name>/Types/<Type>Admin.php`: a class with `#[FolioType('<type key>')]` whose
   methods carry `#[ListColumn]`, `#[DashboardNotice]`, `#[FormPanel]`, `#[RecordAction]` or a
   save hook. Its constructor is autowired.
3. Enable it in `config/folio/extensions.json`:
   `{"enabled": {"<name>": {"disabled_parts": []}}}`.

### Roles edited in Folio

The roles live in `config/roles.php`. To edit roles and staff accounts in Folio instead (Settings →
Roles and Settings → Staff, with invitations and password links), switch to `preflow/rbac`. The
`staff` table already has the columns rbac's staff accounts use, so the accounts you have keep
signing in:

1. `composer require preflow/rbac`, and add `\Preflow\Rbac\RbacServiceProvider::class` after
   Folio in `config/providers.php`.
2. Enable the extension with its staff accounts in `config/folio/extensions.json`:
   `"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}`.
3. In `config/auth.php`, let the `staff` provider read those accounts:
   `'staff' => ['class' => Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active']`.
4. Run `php preflow migrate` and `php preflow folio:extensions:apply`. The extension creates the
   role `admin`; create `editor` (and any other) under Settings → Roles.

`staff:create` keeps working: it accepts the roles that exist, from `config/roles.php` or from Folio.

**A project made from starter-folio before 0.65** has the old staff columns (`passwordHash`,
`createdAt`). Copy `migrations/2026_11_11_005_upgrade_staff_columns.php` into your project's
`migrations/` and run `php preflow migrate`: it renames the two columns, adds `active`,
`auth_epoch` and `last_login_at`, and makes every existing account active at epoch `0`. Then take
the new `app/Models/Staff.php`, `app/Console/StaffCreateCommand.php` and
`app/Seeds/DemoSeeder.php` together (the model must match the table). On a fresh install the
migration finds the new shape and does nothing.

### Tests

```bash
composer test
```

The suites are the same as in `preflow/starter` (`Unit`, `Site`, `Api`, `ApiOnly`). `Site` adds Folio's access rules, publishing, the home page blocks, news and the seeds. Every test boots the real app on a temporary copy with its own SQLite file, so `storage/data` is never touched.

## Editor tour

This part is for the person who writes the content. You do not need to know any code. To follow along, sign in as `editor@example.com` with the password `demo-editor` (only on a copy that was made for trying things out).

### Sign in

Open `/folio` and sign in with your email (in lower case) and your password. On the left you see the menu:

- **Dashboard**: where you start.
- **Pages**: the home page and other pages, plus the texts of the job list, the job page and the company page.
- **Content**: Jobs, Companies and News.
- **Library**: Assets, the images and files.

Employers sign in somewhere else (`/employer`) and cannot open Folio.

### Add a job

1. Open **Content > Jobs**. You see the list of all jobs. You can filter it by company, type and remote work.
2. Click **New Job**.
3. Fill in the form:
   - **Title**, **Place**, **Employment Type**, **Remote**, and, if you like, **Salary From** and **Salary To** (whole numbers, euro per year).
   - **Company**: pick it from the list.
   - **Description**: write it in Markdown, which is plain text with a few marks: a blank line starts a new paragraph, `**bold**` makes bold text, and a line that starts with `- ` is a bullet point. It needs at least 30 characters.
   - **Slug**: the address of the job. Write the title in lower case with dashes, like `support-engineer`. The job's page will be `/jobs/support-engineer`. Folio will not publish a job without one, and only lower case letters, digits and single dashes are allowed.
   - **Posted on**: today's date. The list shows the newest first, so a job without a date appears after the ones that have one.
4. Click **Save draft**. Nobody outside Folio sees the job yet.
5. Click **Publish**. Now the job shows on the job list (`/jobs`), on its own page, on the home page (if it is among the newest six) and on its company's page.

To take a job down, open it and click **Unpublish**. Your text stays as a draft. After you change a published job, the public page keeps showing the old text until you click **Publish** again. Folio shows the job as **Changed** until then. **Discard changes** throws your edits away.

Employers can post jobs too, from their own area. You see their jobs in the same list, and the same rules apply.

### Change the text above the job list

Open **Pages > Job list page**. Edit the **Intro**, then click **Publish**. It shows above the search on `/jobs`. **Job page** holds the call to action under every job. **Company page** holds the text above each company's jobs. They work the same way.

### Edit the home page

Open **Pages > Pages**, then **Home**. The page is a stack of blocks: a big headline with a button, some text, the newest jobs, and the newest news.

1. Use the **Split** or **Preview** view at the top of the form to see the page as visitors see it. It updates while you type.
2. Click a headline or a text in the preview to jump to that field and change it there.
3. Drag a block by its handle to change the order. Use **Add block** to add one, for example another Text block.
4. Click **Save draft** to keep your work without showing it. The public home page stays as it was.
5. Click **Publish** when you are happy. **Publish at** lets you choose a time instead.

To add a new page, click **New Page**. Its **Slug** is its address: the slug `about` makes `/about`. The page with the slug `home` is the home page, so do not change that one.

### News

Open **Content > News** and click **New News post**. Fill in the **Title**, the **Slug** (the address, `/news/<slug>`), the **Date**, an **Image**, a **Summary** (one or two sentences, shown in lists) and the **Body**. Save a draft, then click **Publish**. The newest three posts show on the home page, and all of them on `/news`.

### Logos and images

Company logos and news images live in the library under **Library > Assets**. You can upload a file there, or from the picker while you edit a company or a post. To set a logo, open the **Company**, click **Choose an asset** under **Logo**, pick the image, then click **Publish**. Square images with a white or transparent background look best. Public pages show only what is published.

### What search engines and sharing see

Pages and news posts have a section called **SEO & sharing**. It controls how a page looks when someone shares it, and what search engines show:

- **Share title** and **Share description**: leave them empty and the page uses its own title and text.
- **Share image**: the picture on a shared link.
- **Hide from search engines**, **Leave out of sitemap**, **Leave out of AI index (llms.txt)**: for a page that should stay out of those.

Write a **Share description** of one or two sentences for each page. The home page has no text of its own to take one from, so this is where its description comes from.

### What an editor may not do

- Create or change staff accounts. A developer or an admin does that from the command line.
- Open the settings screens: Extensions, Cache, Redirects, Translations. They are for admins, and an editor does not see them. If you open one by its address, you get a "forbidden" message.
- Delete files in the library. An editor can view, upload and edit them.
- Delete a company that still has jobs.

Ask an admin when you need more. Admins are the staff with the `admin` role in `config/roles.php`.

## From starter to starter-folio

`packages/starter-folio` is `packages/starter` plus the pieces below. The list comes from `diff -r` of the two packages' `app/` and `config/`.

### Added

| What | Files |
|---|---|
| Packages | `preflow/folio`, `preflow/media`, `preflow/roles`, `preflow/notify` in `composer.json`; `config/notify.php` and `MAILER_DSN`, `MAIL_FROM` in `.env.example` |
| Providers | `FolioServiceProvider` and `RolesServiceProvider` in `config/providers.php` |
| Folio config | `config/folio.php` (admin at `/folio`), `config/media.php` (the library, on SQLite), `config/image.php` |
| Admin files | `config/models/job.json`, `company.json`, `page.json`, `news.json`, and the singletons `jobs_page.json`, `job_page.json`, `company_page.json` |
| SEO and GEO | `config/geo.php` (`/llms.txt`), and the `types` entry in `config/seo.php` |
| Guards | `staff` in `config/auth.php`, the default guard, so Folio signs staff in through it (Folio's `guard` setting defaults to the default guard, and Folio refuses a guard whose population is not staff); `employers` is marked `'population' => 'members'`, and `config/roles.php` gives their `employer` role `job.publish` |
| Staff | `app/Models/Staff.php`, the `staff` table (`migrations/2026_11_09_004_create_staff.php`, with rbac's column names), `config/roles.php` (admin, editor), `app/Console/StaffCreateCommand.php` |
| Seeds | `app/Seeds/ContentSeeder.php` (required content: home page and page texts); `DemoSeeder` now also adds staff, logos and news |
| Blocks | `app/Components/Blocks/{Hero,Text,LatestJobsBlock,LatestNews}` |
| Folio templates | `resources/folio/frontend/page.twig` and `news.twig`, `app/pages/news/index.twig` |
| Demo images | `resources/seed/logos/`, `resources/seed/news/` |
| Other | `app/pages/about-error-boundaries.twig` (the error demo moved off the home page), the `app.indexes` after-migrate step in `AppServiceProvider` (SQLite only) |

### Changed, same interface

| File | What changed |
|---|---|
| `app/Models/Job.php`, `Company.php` | `type: 'job'` / `type: 'company'` on `#[Entity]`; `Job::slug` is now `#[Validate('required', 'slug')]`; `Job` loses `status` and `publishedAt` and gains `postedOn`; `Company` gains `logo`; `Job::company` is a `#[Relation]` |
| `app/Jobs/JobSearch.php` | Reads Folio's published copies (`PublishedModels`); `byId()` is new |
| `app/Jobs/JobWriter.php` | The constructor takes `DataManager` and Folio's `Publisher` (starter 1 takes `DataManager` only); `publish()` takes the user and goes through Folio's `Publisher`; `state()` and `states()` are new; `create()`, `update()`, `unpublish()` and `delete()` keep their signatures |
| `app/Jobs/JobResource.php` | `status` is the publish state; `published_at` is the posted date |
| `app/Controllers/Api/JobsController.php`, `Site/EmployerJobController.php` | Call the new `publish()`; a failed publish gives a 422, or the form with the errors |
| `app/Components/ErrorDemo/ErrorDemo.php` | The comment now points to the page it lives on |
| `app/Console/EmployerCreateCommand.php` | Also publishes the new company |
| `app/pages/_layout.twig`, `jobs/index.twig`, `_views/job.twig`, `_views/company.twig`, `_views/employer/index.twig` | News link and footer link, the singletons' texts, the publish state |
| `config/commands.php`, `lang/en/app.php` | Folio's commands and `StaffCreateCommand`; a few new texts |

### Unchanged

- The fields of the models: `title`, `place`, `employmentType`, `remote`, `salaryFrom`, `salaryTo`, `description` (Markdown) and the rest.
- The signatures of `JobSearch` and `JobWriter`, apart from the two additions above.
- The routes of the controllers, the employer area, the components (`JobSearch`, `JobCard`, `JobResults`, `FilterChips`, `Pager`, `ViewToggle`), `JobFilters`, `Slugs`, `Markdown`.
- The API: routes, keys, paging, tokens, errors, CORS (`config/api.php`).

### Gone

- `app/pages/index.twig` and `app/Components/LatestJobs/`: Folio serves `/`, and the `latest_jobs` block replaces the component.
- The `companies` and `jobs` migrations: Folio creates the tables of its types when you run `php preflow migrate`.

## The page cache

As in `preflow/starter`: pages for visitors who are not signed in are kept as finished HTML (`config/page_cache.php`), but not while `APP_DEBUG=1`. To watch it work, set `APP_DEBUG=0`, restart `php preflow serve`, and ask for a page twice:

```bash
curl -s -o /dev/null -D - http://localhost:8080/jobs | grep -i x-page-cache   # miss, then hit
```

Publishing in Folio, or an employer publishing a job, refreshes the pages that show it. Sessions start only under `/employer` and `/folio` (`auth.session.paths`), not for the search, so a visitor who searches keeps getting cached pages. To empty the cache: `php preflow cache:clear pages`.

## Deploy on shared hosting

1. Upload the project and run `composer install --no-dev`. Do not upload `storage/data/*.sqlite` from a dev install: it holds the public demo accounts. Production starts with a fresh database, made by the steps below (`migrate`, the `ContentSeeder`, then `staff:create` and `employer:create`).
2. Create `.env` from `.env.example`. Set `APP_DEBUG=0` and a real `APP_URL` with its scheme, like `https://jobs.example.com`.
3. Run `php preflow key:generate` and `php preflow migrate`.
4. Run `php preflow folio:extensions:apply`. Run it again after every deploy.
5. Seed the required content. Do not run the plain `db:seed`: it would try the demo seeder, which refuses with debug off, and its passwords are public.
   ```bash
   php preflow db:seed --class=ContentSeeder
   ```
   This creates the home page and the page texts. It creates no accounts, and a record that exists is left alone.
6. Create the first staff account. It prints a password once:
   ```bash
   php preflow staff:create you@example.com --role=admin
   ```
   Further staff are created the same way, with `--role=editor` for an editor (any staff role in `config/roles.php` works). For an employer: `php preflow employer:create you@example.com --company="Your Company"`.
7. Add these two cron jobs. The first rebuilds the pages that the page cache marked as outdated. The second publishes the content that staff scheduled with "Publish at":
   ```
   * * * * * cd /path/to/app && php preflow pages:work
   * * * * * cd /path/to/app && php preflow folio:publish-scheduled
   ```
8. Point the document root at `public/`. Keep `public/media` (the uploads) and `storage/` writable, and back them up together with the SQLite file in `storage/data`.
9. Run `php preflow optimize`. Run it again after every deploy.

The database is a SQLite file in `storage/data`. The `app.indexes` step adds its indexes on SQLite only. If you move to MySQL, change `config/data.php` and add the index on the `company` column by hand (the note in `AppServiceProvider` says why).

## Upgrading Preflow

```bash
composer update "preflow/*"
php preflow migrate
php preflow folio:extensions:apply
php preflow optimize
composer test
```

Read the upgrade notes in the Preflow release before you update.
