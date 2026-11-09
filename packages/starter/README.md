# Preflow Jobs

A small job board to read, run and copy. It shows three things on the same models: server-rendered pages, components that update parts of a page with HTMX, and a JSON REST API. It runs on SQLite, so there is no database server to set up. It has no Node, no npm and no build step.

## Quick start

```bash
composer create-project preflow/starter myapp
cd myapp
php preflow serve
```

Open `http://localhost:8080`. Use that exact address: `APP_URL` in `.env` and the CSRF origin check both expect it. If you open the app as `127.0.0.1:8080`, the login form will refuse you.

`create-project` copies `.env`, makes an app key, creates the database and fills it with demo data. At the end it prints the demo login:

```
Demo employer: employer@example.com / demo-employer (sign in at /employer)
```

This password is public demo data. It only works while `APP_DEBUG=1`, and the demo seeder refuses to run when debug is off.

## Tour

| Open | What you see | Where it lives |
|---|---|---|
| `/` | Home page with the latest jobs | `app/pages/index.twig`, `app/Components/LatestJobs/` |
| `/jobs` | Search and filter, 12 jobs per page | `app/pages/jobs/index.twig`, `app/Components/JobSearch/`, `app/Jobs/JobFilters.php` |
| `/jobs/<slug>` | One job, with more jobs from the same company | `app/Controllers/Site/JobController.php`, `app/pages/_views/job.twig` |
| `/companies/<slug>` | A company and its open jobs | `app/Controllers/Site/CompanyController.php`, `app/pages/_views/company.twig` |
| `/employer` | Sign in, then add, edit, publish and unpublish your jobs | `app/Controllers/Site/EmployerAuthController.php`, `EmployerJobController.php`, `app/pages/_views/employer/` |
| `/api/jobs` | The same jobs as JSON | `app/Controllers/Api/JobsController.php` |

A few more things to know:

- Both the pages and the API read through `app/Jobs/JobSearch.php` and write through `app/Jobs/JobWriter.php`. The rules for a job live once, in `app/Models/Job.php`.
- `app/Jobs/JobResource.php` is the one place that decides how a job looks as an array, for the job cards and for JSON.
- `php preflow routes:list` shows every route.

## Try this

**1. Add a filter.** Let visitors filter by a minimum salary.
- Add `salaryMin` to `JobFilters` (`app/Jobs/JobFilters.php`). It must never throw: turn anything odd into the default.
- Add a `where('salaryTo', '>=', ...)` to `JobSearch::search()`.
- Add an input to `app/Components/JobSearch/JobSearch.twig`. The page, the HTMX search and `/api/jobs` all pick it up, because they share `JobFilters`.

**2. Add a field to Job, end to end.** For example `applyUrl`.
- Add a column in a new migration, `php preflow make:migration`, then `php preflow migrate`.
- Add the property with `#[Field]` and `#[Validate]` to `app/Models/Job.php`.
- Add the name to `EDITABLE` in `app/Jobs/JobWriter.php`.
- Add it to `API_FIELDS` and `toArray()` in `app/Jobs/JobResource.php`.
- Add a form field in `app/pages/_views/employer/form.twig` and show it in `app/pages/_views/job.twig`.

**3. Add an API endpoint.** For example `GET /api/places`, the list of places that have jobs.
- Create `app/Controllers/Api/PlacesController.php` with `#[Route('/api/places')]` and a `#[Get('/')]` method.
- Take `JobSearch` in the constructor and return `Json::data($jobs->places())`.
- Run `php preflow routes:list` and `curl http://localhost:8080/api/places`. Controllers are found by their attributes, so there is nothing to register.

## The API

Everything is under `/api` and answers JSON. Keys are snake_case. A response is `{"data": ..., "meta": ...}`. An error is `{"message": "..."}`, and a validation error (422) adds `"errors": {"field": ["message"]}`. In debug mode errors also carry a `debug` key. With `APP_DEBUG=0` it is gone.

Reading is public:

```bash
# Jobs, 12 per page. Filters: q, place, type, remote, page
curl "http://localhost:8080/api/jobs?type=full_time&remote=hybrid&page=1"

# One job, by its id
curl http://localhost:8080/api/jobs/782fa59586f012609c8e476cb0dee275

# Companies, and one company with its open jobs
curl http://localhost:8080/api/companies
curl http://localhost:8080/api/companies/lumen-harbor
```

The list answers with a `meta` block: `page`, `per_page`, `total`, `last_page`. Odd filter values never cause an error: they fall back to the default, and a page past the last one is an empty list.

Writing needs a token. Create one for an employer from the command line. It is shown once:

```bash
php preflow auth:token employer@example.com --name=my-script
php preflow auth:token --list employer@example.com
php preflow auth:token --revoke=<token-id>
```

Send it as a bearer token:

```bash
TOKEN=<the token>

# Create a job. "status" is draft (the default) or published.
curl -X POST http://localhost:8080/api/jobs \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Support Engineer","place":"Zurich","employment_type":"full_time","remote":"hybrid","description":"Help our customers get unstuck, kindly and quickly.","status":"published"}'

# Change a job, or publish and unpublish it
curl -X PATCH http://localhost:8080/api/jobs/<id> \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"status":"draft"}'

# Delete a job
curl -X DELETE http://localhost:8080/api/jobs/<id> -H "Authorization: Bearer $TOKEN"
```

- `employment_type` is `full_time`, `part_time`, `contract` or `internship`. `remote` is `no`, `hybrid` or `full`. `description` is Markdown, at least 30 characters.
- No token gives 401. A missing job gives 404. A job of another company gives 403, not 404. That is on purpose: the ids of jobs you create are random and unguessable, so the answer reveals nothing, and a client gets a clearer message. (The demo seed's ids are not random: they are fixed md5 values, so the demo data is the same on every install.)
- A draft is not visible on the public read routes. Only its owner sees it, in the answer to a write.

**Never put cookie-authenticated (session) routes under `/api`.** `/api` is exempt from CSRF checks in `config/csrf.php`, because its write routes take bearer tokens only, which a browser never sends by itself. A route that trusts the session cookie under `/api` would be open to forged requests.

**CORS** is off. To let a browser app on another origin call the API, list the origin in `config/api.php`:

```php
'cors' => ['origins' => ['https://app.example.com']],
```

## API only

You can run the app as a pure API. Delete these three paths:

```bash
rm -rf app/Components app/pages app/Controllers/Site
```

The page tests need the pages, so remove them too: delete `tests/Site` and the `Site` suite in `phpunit.xml`. Then `composer test` still passes: the `Unit`, `Api` and `ApiOnly` suites run against the app without its frontend.

`tests/ApiOnlyTest.php` proves it without you deleting anything. It copies the project without those three paths, runs the `Api` suite against the copy, and checks that `/jobs` is a 404 while `/api/jobs` still answers.

## The page cache

Pages for visitors who are not signed in are kept as finished HTML (`config/page_cache.php`). It is switched on, but it is off while `APP_DEBUG=1`, which is what `.env` has after `create-project`. That way you always see your latest edits while you work.

To watch it work, set `APP_DEBUG=0` in `.env`, restart `php preflow serve`, and ask for a page twice. Use a GET (`-D -`): a HEAD request (`curl -I`) is never stored.

```bash
curl -s -o /dev/null -D - http://localhost:8080/jobs | grep -i x-page-cache   # miss
curl -s -o /dev/null -D - http://localhost:8080/jobs | grep -i x-page-cache   # hit
```

Publishing or changing a job refreshes the pages that show it. You clear nothing by hand. A signed-in employer is never served from the cache. Searching does not start a session, so a visitor who searches keeps getting cached pages. To empty the cache: `php preflow cache:clear pages`.

## Tests

```bash
composer test
```

This runs PHPUnit with four parts:

- `Unit`: small classes without the app, like `JobFilters`.
- `Site`: the pages, the search, the employer area, the page cache and the seed.
- `Api`: the REST API.
- `ApiOnly`: `tests/ApiOnlyTest.php`, which runs the `Api` suite on a copy without the frontend.

Run one part with `vendor/bin/phpunit --testsuite Api`. Every test boots the real app on a temporary copy with its own SQLite file, so `storage/data` is never touched.

## Deploy on shared hosting

1. Upload the project and run `composer install --no-dev`.
2. Create `.env` from `.env.example`. Set `APP_DEBUG=0` and a real `APP_URL` with its scheme, like `https://jobs.example.com`.
3. Run `php preflow key:generate` and `php preflow migrate`.
4. Do not run the demo seed. It refuses to run with debug off, and its password is public. Create the first account with `php preflow employer:create you@example.com --company="Your Company"`. It prints a password once.
5. Add this cron job. It rebuilds the pages that the page cache marked as outdated:
   ```
   * * * * * cd /path/to/app && php preflow pages:work
   ```
6. Point the document root at `public/`.
7. Run `php preflow optimize`. Run it again after every deploy.

The database is a SQLite file in `storage/data`. Keep that folder writable, and back it up. To use MySQL instead, change `config/data.php`: add a `mysql` driver and make it the `default`.

## Upgrading Preflow

```bash
composer update "preflow/*"
php preflow migrate
php preflow optimize
composer test
```

Read the upgrade notes in the Preflow release before you update.
