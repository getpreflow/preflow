# REST pieces (0.62) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Preflow what a JSON API needs (JSON responses, JSON errors with 422 field errors, 405, CORS, bearer-token routes and a command that issues tokens), so the starters can serve a REST API from the same app as their pages.

**Architecture:**
- **Core, HTTP layer:**
  - one `config/api.php` names the API path prefixes and the CORS settings;
  - `ErrorHandler` answers JSON for API requests;
  - a CORS middleware sits outermost and also decorates error responses, the way security headers already do.
- **Routing:** throws a 405 with an `Allow` header when the path exists for other methods.
- **Auth:** gains a middleware for bearer-token routes.
- **Devtools:** gains `auth:token`.
- **Testing:** gains JSON assertions.

**Tech Stack:** PHP 8.4, PSR-7 (nyholm/psr7), PHPUnit 11, Preflow monorepo (`/Users/smyr/Sites/gbits/preflow-framework`).

**Spec:** `docs/superpowers/specs/2026-11-09-starters-design.md`, section "Framework gaps the starters need filled", plus the "API's shape" call under "My calls" (plain JSON, `data` / `meta` / `errors`).

## Global Constraints

- **Framework rules (owner):** features live in the framework's packages behind public interfaces; Folio is only the UI adapter; project-specific things stay in projects.
- **Upgrading changes nothing for a site without `config/api.php`, except two things (plus the default `/api` prefix, see the README's upgrade notes):**
  - a wrong method on an existing path answers 405 instead of 404;
  - an uncaught `ValidationException` answers 422 instead of 500.
  - Four of the 5 production sites have no `/api` routes; holz-objekte has a legacy `/api` controller (it already lists /api as non-public and catches its 404s itself). Their HTML error pages, CSRF, sessions and page cache behave as before.
- **API prefixes:**
  - `config/api.php` key `prefixes`, default `['/api']`;
  - CORS under key `cors`;
  - CORS is off while `cors.origins` is empty (the default).
- **JSON envelope:**
  - success: `{"data": …}` with an optional `"meta": {…}`;
  - error: `{"message": "<text>"}`;
  - 422 adds `"errors": {"<field>": ["<message>", …]}`;
  - debug mode adds `"debug": {"exception": <class>, "message": …, "file": …, "line": …}`.
- **Error messages:**
  - an `HttpException`'s own message is shown (it is written for the client);
  - any other exception shows `Server Error` and never its message outside debug.
- **JSON encoding flags:** `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`.
- **Content type:** `application/json` for every JSON response, error responses included.
- **No new Composer dependencies.**
- **Release 0.62.0:**
  - routing and auth require core `^0.62`;
  - core conflicts with routing `<0.62`;
  - devtools requires core `^0.62`;
  - after release, all 5 sites go to `^0.62` (composer.json and pin tests).
- **Comments and docblocks:** match the surrounding code (short, plain English, say why).
- **Tests:** run with `vendor/bin/phpunit <path>` from the monorepo root; the full suite with `vendor/bin/phpunit`. Never run two suites at once.

## Review Focus

1. **A cached API GET and CORS:**
   - a page-cache hit for `/api/jobs` must still carry the right `Access-Control-Allow-Origin` for the requesting origin;
   - the stored copy must carry none, or another origin would get it.
   - Test in Task 4.
2. **An error thrown before or inside the pipeline:** a 401 or 422 from a cross-origin call must carry the CORS headers, or the browser hides the JSON from the frontend. Test in Task 4.
3. **A browser on an HTML page asking for an unknown path:** the error stays HTML. `Accept: text/html,…,*/*` must never get JSON, and an HTMX request (`Accept: */*`) must get HTML too. Test in Task 2.
4. **A path that exists only for GET, requested with HEAD:**
   - HEAD still works;
   - `Allow` lists `GET, HEAD`;
   - a POST to a GET-only page on a non-API path answers 405 with an HTML page, not a JSON body.
   - Test in Task 3.
5. **A bearer token on a request that also carries a session cookie:** CSRF must still apply, since `CsrfMiddleware` exempts only `Authorization` without cookies. `TokenAuthMiddleware` must not let a session user through either. Test in Task 5.

## Checked, no task needed

- **"API only" boot:** `Application` already tolerates an absent `app/pages` and `app/Components` (`is_dir` checks in `bootRouting()` and component discovery), so the starters' API-only test needs no framework option.
- **API GETs and the page cache:** JSON 200 responses are already storable (`PageCacheMiddleware::refusal()` accepts `json` types) and invalidated by the same data tags; Task 4 keeps CORS outside it.
- **CSRF:** `CsrfMiddleware` already skips requests with `Authorization` and no cookies, so bearer API calls need no exemption.

## File map

| File | Change | Task |
|---|---|---|
| `packages/core/src/Http/Json.php` | new: JSON response factory | 1 |
| `packages/core/src/Application.php` | action dispatcher returns JSON for arrays; wiring for Tasks 2–4 | 1–4 |
| `packages/core/src/Http/JsonBodyMiddleware.php` | 400 on malformed JSON | 1 |
| `packages/core/src/Http/Api/ApiConfig.php` | new: prefixes + CORS config, `isApi()`, `wantsJson()` | 2 |
| `packages/core/src/Error/JsonErrorRenderer.php` | new | 2 |
| `packages/core/src/Error/ErrorHandler.php` | JSON for API requests, 422, exception headers | 2 |
| `packages/core/src/Exceptions/HttpException.php` | `headers` | 2 |
| `packages/core/src/Exceptions/MethodNotAllowedHttpException.php` | new | 3 |
| `packages/routing/src/RouteMatcher.php`, `Router.php` | 405 | 3 |
| `packages/core/src/Http/Api/CorsMiddleware.php` | new | 4 |
| `packages/core/src/Kernel.php` | CORS on error responses | 4 |
| `packages/auth/src/Http/TokenAuthMiddleware.php` | new | 5 |
| `packages/devtools/src/Command/AuthTokenCommand.php`, `Console.php` | new command | 5 |
| `packages/testing/src/TestResponse.php` | JSON assertions | 6 |
| READMEs, `packages/skeleton/config/api.php`, `ROADMAP.md`, composer.json files | docs, release | 6 |

---

### Task 1: JSON responses

**Files:**
- Create: `packages/core/src/Http/Json.php`
- Modify: `packages/core/src/Application.php` (`ensureActionDispatcher()`, around line 2551)
- Modify: `packages/core/src/Http/JsonBodyMiddleware.php`
- Test: `packages/core/tests/Http/JsonTest.php`, `packages/core/tests/Http/JsonBodyMiddlewareTest.php` (extend if it exists), an action-dispatcher test next to the existing Application tests

**Interfaces:**
- Produces:
  - `Preflow\Core\Http\Json::response(mixed $body, int $status = 200, array $headers = []): ResponseInterface`: encodes with the Global Constraints flags.
  - `Json::data(mixed $data, array $meta = [], int $status = 200, array $headers = []): ResponseInterface`: gives `{"data": …}`, plus `"meta"` only when `$meta !== []`.
  - `Json::noContent(): ResponseInterface`: gives 204 with an empty body and no Content-Type.
  - `Json::FLAGS` (public const int).

- [ ] **Step 1: Write the failing tests**
  - `test_response_encodes_with_status_headers_and_content_type`:
    - status 201;
    - header `Content-Type: application/json`;
    - a custom header kept;
    - body `{"a":"é/x","n":1.0}` for `['a' => 'é/x', 'n' => 1.0]`.
  - `test_data_wraps_in_envelope_and_adds_meta_only_when_given`:
    - `Json::data([1])` gives `{"data":[1]}`;
    - `Json::data([1], ['page' => 2])` gives `{"data":[1],"meta":{"page":2}}`.
  - `test_no_content_is_204_without_body`.
  - `test_unencodable_body_throws_json_exception`: `Json::response(NAN)` throws `\JsonException`.
  - Dispatcher test `test_controller_returning_array_answers_json`: a controller action returning `['ok' => true]` answers 200 `application/json` with `{"ok":true}`. A `\JsonSerializable` return does the same. A string return still answers `text/html`.
  - JsonBodyMiddleware:
    - `test_malformed_json_body_is_a_400`: `Content-Type: application/json` with body `{"a":` throws `HttpException` with `statusCode === 400` and message `The request body is not valid JSON.`;
    - an empty body still passes;
    - a JSON scalar body (`"x"`) passes unchanged (no parsed body), as today.

- [ ] **Step 2: Run them, expect failures**
  Run: `vendor/bin/phpunit packages/core/tests/Http`. Expected: FAIL (`Json` not found, no 400).

- [ ] **Step 3: Implement**
  - `Json` is a `final class` with static methods and nyholm `Response`.
  - In `ensureActionDispatcher()`, return `Json::response($response)` when the controller returns an `array` or a `\JsonSerializable`; leave every other branch as is.
  - In `JsonBodyMiddleware`, decode with `JSON_THROW_ON_ERROR` and throw `new HttpException(400, 'The request body is not valid JSON.', $e)` on `\JsonException`.

- [ ] **Step 4: Run, expect PASS**
  Run: `vendor/bin/phpunit packages/core/tests`.

- [ ] **Step 5: Commit**
  `git commit -m "feat(core): JSON responses, array returns as JSON, 400 on malformed JSON bodies"`

---

### Task 2: JSON errors for API requests, 422 for validation

**Files:**
- Create: `packages/core/src/Http/Api/ApiConfig.php`, `packages/core/src/Error/JsonErrorRenderer.php`
- Modify:
  - `packages/core/src/Exceptions/HttpException.php`;
  - `packages/core/src/Error/ErrorHandler.php`;
  - `packages/core/src/Application.php`: error handler block (~line 373) and the `NonPublicPaths` instance (~line 335).
- Test: `packages/core/tests/Http/Api/ApiConfigTest.php`, `packages/core/tests/Error/ErrorHandlerTest.php` (extend), `packages/core/tests/Error/JsonErrorRendererTest.php`

**Interfaces:**
- Produces:
  - `HttpException::__construct(int $statusCode, string $message = '', ?\Throwable $previous = null, array $headers = [])`, with a `public readonly array $headers` (`array<string, string>`).
    - Subclasses gain a trailing `array $headers = []` parameter, passed through.
    - Existing call sites stay valid.
  - `final readonly class Preflow\Core\Http\Api\ApiConfig`, with:
    - `public function __construct(array $prefixes = ['/api'], array $corsOrigins = [], array $corsMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], array $corsHeaders = ['Authorization', 'Content-Type', 'Accept'], array $corsExpose = [], int $corsMaxAge = 600, bool $corsCredentials = false)`;
    - `public static function fromArray(?array $config): self` (the shape of `config/api.php`: `prefixes`, `cors.origins`, `cors.methods`, `cors.headers`, `cors.expose`, `cors.max_age`, `cors.credentials`);
    - `public function isApi(ServerRequestInterface $r): bool`;
    - `public function wantsJson(ServerRequestInterface $r): bool`.
  - `ErrorHandler::__construct(ErrorRendererInterface $renderer, ?ErrorRendererInterface $internal = null, ?ApiConfig $api = null, ?ErrorRendererInterface $json = null)`.
  - `final class JsonErrorRenderer implements ErrorRendererInterface`, `__construct(bool $debug)`, which returns the JSON body string.
  - The container gets `ApiConfig::class` as an instance (Task 4 reads it).

- [ ] **Step 1: Write the failing tests**
  - `ApiConfigTest`:
    - `isApi` is true for `/api` and `/api/jobs`, and false for `/apix` and `/jobs`. A prefix matches whole path segments.
    - `wantsJson` is true for:
      - an `/api/…` path whatever the Accept;
      - `Accept: application/json` on `/jobs`;
      - `Accept: application/vnd.x+json` on `/jobs`.
    - `wantsJson` is false for:
      - `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8`;
      - `Accept: */*`;
      - `Accept: application/json, text/html`, because any mention of `text/html` means HTML.
    - `fromArray(null)` gives the defaults.
    - `fromArray(['cors' => ['origins' => ['*'], 'credentials' => true]])` throws `\InvalidArgumentException` mentioning `cors.credentials`, since browsers refuse `*` with credentials.
  - `JsonErrorRendererTest`:
    - 404 `NotFoundHttpException('Job 7 not found')` renders `{"message":"Job 7 not found"}`;
    - a `\RuntimeException('db password x')` with status 500 and `debug: false` renders `{"message":"Server Error"}`, and the body does not contain `db password`;
    - with `debug: true` the body has a `debug` object with `exception` = `RuntimeException` and the message;
    - a `Preflow\Validation\ValidationException` (build it from `new ValidationResult(['title' => ['The title is required.']])`, using the real constructor) at 422 renders `{"message":"The given data was invalid.","errors":{"title":["The title is required."]}}`.
  - `ErrorHandlerTest`:
    - `test_api_request_gets_json_error`: `GET /api/x` with a `NotFoundHttpException` gives 404 and `Content-Type: application/json`.
    - `test_html_request_keeps_html_error`: `GET /x` with `Accept: text/html,*/*` gives `text/html; charset=UTF-8`.
    - `test_validation_exception_is_422`: for both an API and an HTML request.
    - `test_http_exception_headers_reach_the_response`: an `HttpException(401, 'Unauthorized', null, ['WWW-Authenticate' => 'Bearer'])` keeps its header.
    - `test_internal_render_keeps_internal_renderer`: unchanged behaviour for `InternalRender` requests.

- [ ] **Step 2: Run, expect failures**
  Run: `vendor/bin/phpunit packages/core/tests/Error packages/core/tests/Http/Api`.

- [ ] **Step 3: Implement**
  - **Status:**
    - `HttpException` uses its own `statusCode`;
    - `$e instanceof \Preflow\Validation\ValidationException` gives 422 (core references the optional package by name, as it does for devtools and i18n elsewhere; `instanceof` of a missing class is false);
    - otherwise 500.
  - **Choosing the renderer** in `ErrorHandler`:
    - an internal render keeps the internal renderer;
    - else, `$api?->wantsJson($request)` uses the JSON renderer with `Content-Type: application/json`;
    - else, today's HTML.
  - **Headers:** apply `HttpException::$headers` to either response.
  - **Validation message:** 422 is always `The given data was invalid.`
  - **Wiring in `Application::boot()`:**
    - `ApiConfig::fromArray($this->configFile('api'))`, put in the container;
    - the error handler gets `new JsonErrorRenderer($debug->isDebug())`;
    - each API prefix is added to `NonPublicPaths`, so SEO and GEO skip it. Check `NonPublicPaths`' API for how to add one.

- [ ] **Step 4: Run, expect PASS**
  Run: `vendor/bin/phpunit packages/core/tests`.

- [ ] **Step 5: Commit**
  `git commit -m "feat(core): JSON errors for API requests, 422 for validation failures"`

---

### Task 3: 405 Method Not Allowed

**Files:**
- Create: `packages/core/src/Exceptions/MethodNotAllowedHttpException.php`
- Modify: `packages/routing/src/RouteMatcher.php`, `packages/routing/src/Router.php`, `packages/routing/composer.json` (core `^0.62 || @dev`), `packages/core/composer.json` (conflict `preflow/routing: <0.62`)
- Test: `packages/routing/tests/RouteMatcherTest.php`, `packages/routing/tests/RouterTest.php` (extend)

**Interfaces:**
- Consumes: `HttpException` headers (Task 2).
- Produces:
  - `final class MethodNotAllowedHttpException extends HttpException`, `__construct(array $allowed, string $message = 'Method Not Allowed', ?\Throwable $previous = null)`. It is status 405 with header `Allow: <comma+space separated, sorted>`, and has a `public readonly array $allowed` (`list<string>`).
  - `RouteMatcher::allowedMethods(string $uri): list<string>`: the methods of every entry whose pattern matches `$uri`, by the same three passes and trailing-slash normalisation as `match()`. `HEAD` is added when `GET` is there.

- [ ] **Step 1: Write the failing tests**
  - `allowedMethods('/jobs/7')` with `GET /jobs/{id}` and `DELETE /jobs/{id}` gives `['DELETE', 'GET', 'HEAD']`; for `/nope` it gives `[]`.
  - Router: `POST /jobs/7` throws `MethodNotAllowedHttpException` with `statusCode === 405` and `headers['Allow'] === 'DELETE, GET, HEAD'`.
  - Router: `HEAD /jobs/7` still matches the GET route.
  - Router: `POST /nope` still throws `NotFoundHttpException`.
  - Through `ErrorHandler` (core test or routing test with the real handler): a 405 on `/jobs/7` with `Accept: text/html` gives an HTML body; on `/api/jobs/7` it gives `{"message":"Method Not Allowed"}`.

- [ ] **Step 2: Run, expect failures.** `vendor/bin/phpunit packages/routing/tests`

- [ ] **Step 3: Implement.** In `Router::match()`, when `match()` returns null, call `allowedMethods()`. Throw a 405 when the list isn't empty, else today's 404.

- [ ] **Step 4: Run** `vendor/bin/phpunit packages/routing/tests packages/core/tests packages/folio/tests`, expect PASS. Folio registers catch-all page routes, so a test there that expected 404 for a POST may now see 405. If one does, check that 405 is right for it, and update it only then.

- [ ] **Step 5: Commit** `git commit -m "feat(routing): 405 with Allow when the path exists for other methods"`

---

### Task 4: CORS

**Files:**
- Create: `packages/core/src/Http/Api/CorsMiddleware.php`
- Modify: `packages/core/src/Kernel.php`, `packages/core/src/Application.php`
- Test: `packages/core/tests/Http/Api/CorsMiddlewareTest.php`, a Kernel or Application test for the error path and the page-cache hit

**Interfaces:**
- Consumes: `ApiConfig` (Task 2), from the container.
- Produces:
  - `final class CorsMiddleware implements MiddlewareInterface`, `__construct(ApiConfig $config)`;
  - `public function decorate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface`, which adds the headers for an allowed origin and is used by the Kernel's error path;
  - `Kernel::__construct(…, ?CorsMiddleware $cors = null)`, added as the last named parameter.

**Behaviour:**
- **Scope:** CORS acts only on `ApiConfig::isApi()` paths that carry an `Origin` header.
- **Allowed origin:** exact string match against `cors.origins`, or `*` in the list.
- **Preflight:** `OPTIONS` with `Access-Control-Request-Method` is answered by the middleware without calling the handler. It returns 204 with:
  - `Access-Control-Allow-Origin` (the origin, or `*` when the list is `['*']` and credentials are off);
  - `Access-Control-Allow-Methods` (joined `cors.methods`);
  - `Access-Control-Allow-Headers` (joined `cors.headers`);
  - `Access-Control-Max-Age`;
  - `Vary: Origin`;
  - `Access-Control-Allow-Credentials: true` only with credentials.
  - A disallowed origin's preflight gets 204 without any `Access-Control-*` header, so the browser blocks it.
- **Other requests:** the handler's response gets:
  - `Access-Control-Allow-Origin`;
  - `Vary: Origin`, appended to any existing `Vary`;
  - `Access-Control-Expose-Headers` when `cors.expose` is set;
  - the credentials header when credentials are on.
- **Placement:**
  - registered only when `cors.origins` is non-empty;
  - prepended **after** `bootGeo()`, so it is the outermost middleware and wraps the page cache: stored pages never hold CORS headers, and hits get them;
  - the Kernel's catch block applies `$this->cors?->decorate()` next to `securityHeaders`.

- [ ] **Step 1: Write the failing tests**
  - `test_preflight_from_allowed_origin_is_answered_without_handler`: the handler throws if called.
  - `test_preflight_from_other_origin_gets_no_cors_headers`.
  - `test_simple_request_gets_allow_origin_and_vary`.
  - `test_wildcard_origin_without_credentials_sends_star`.
  - `test_non_api_path_is_untouched`.
  - `test_request_without_origin_is_untouched`.
  - `test_error_response_carries_cors_headers`: booted app with `config/api.php`, `origins: ['https://app.test']`, a route-less `GET /api/x` with `Origin: https://app.test` gives a 404 JSON with `Access-Control-Allow-Origin: https://app.test`.
  - `test_page_cache_hit_gets_cors_for_each_origin` (Review Focus 1):
    - booted app with the page cache on and a JSON controller at `/api/ping`;
    - the first request from origin A, the second (a hit, `x-page-cache: hit`) from origin B;
    - B's response carries B's origin, and the stored file holds no `Access-Control-Allow-Origin`.
    - Use the testing package's `WebApplication`, or the existing page cache test setup in `packages/core/tests/PageCache`.

- [ ] **Step 2: Run, expect failures.** `vendor/bin/phpunit packages/core/tests/Http/Api packages/core/tests/PageCache`

- [ ] **Step 3: Implement** as described above.

- [ ] **Step 4: Run** `vendor/bin/phpunit packages/core/tests`, expect PASS.

- [ ] **Step 5: Commit** `git commit -m "feat(core): CORS for API paths (config/api.php), outside the page cache and on error responses"`

---

### Task 5: Token routes and `auth:token`

**Files:**
- Create: `packages/auth/src/Http/TokenAuthMiddleware.php`, `packages/devtools/src/Command/AuthTokenCommand.php`
- Modify:
  - `packages/core/src/Application.php`: bind `TokenAuthMiddleware` next to `AuthMiddleware` (~line 2404);
  - `packages/devtools/src/Console.php`;
  - `packages/auth/composer.json`: core `^0.62 || @dev`, conflict `<0.62`;
  - `packages/devtools/composer.json`: core `^0.62 || @dev`, conflict `<0.62`, `suggest` preflow/auth.
- Test: `packages/auth/tests/Http/TokenAuthMiddlewareTest.php`, `packages/devtools/tests/Command/AuthTokenCommandTest.php`

**Interfaces:**
- Consumes: `HttpException` headers (Task 2), `TokenGuard`, `PersonalAccessToken`, `AuthManager`.
- Produces:
  - `final class Preflow\Auth\Http\TokenAuthMiddleware implements MiddlewareInterface`, `__construct(AuthManager $authManager, ?string $guard = null)`.
    - Its guard is `$guard`, else `auth.api_guard` from `config/auth.php`, else the first configured guard whose class is `TokenGuard`.
    - It passes only a user that this guard finds on the request itself, never `AuthManager::user()` from another guard, so a session cookie never passes a token route.
    - Otherwise it throws `UnauthorizedHttpException('Unauthorized', null, ['WWW-Authenticate' => 'Bearer'])`.
    - A user that passes is set on the AuthManager under the token guard's name and on the request attribute `Authenticatable::class`, as `AuthMiddleware` does.
  - `AuthTokenCommand`, name `auth:token`. It boots the app through an `?\Closure $appFactory` like `PagesWorkCommand`.
    - `auth:token <email-or-id> [--name=<label>]` issues a token:
      - it finds the user via the token guard's provider: `findByCredentials(['email' => …])` when the argument contains `@`, else `findById`;
      - it stores a `PersonalAccessToken` with `tokenHash`, `userId`, `name` (default `cli`) and `createdAt` (`date('c')`);
      - it prints the plain token once, on its own line, after `Token for <email-or-id> (<name>), shown once:`.
    - `auth:token --list <email-or-id>` prints uuid, name and createdAt per token, never a hash.
    - `auth:token --revoke=<uuid>` deletes one token and prints `Revoked.`, or exits 1 with `No token <uuid>.`
    - Errors exit 1 with one line:
      - unknown user;
      - no token guard configured;
      - preflow/auth not installed;
      - the `user_tokens` table missing (catch the storage error and say `Run the user_tokens migration first.`).

- [ ] **Step 1: Write the failing tests**
  - Middleware:
    - a valid bearer token passes and the handler sees the user;
    - no header, an unknown token or `Bearer ` (empty) gives 401 with `WWW-Authenticate: Bearer`;
    - Review Focus 5: a request whose session guard has a signed-in user but no bearer token gives 401;
    - a route with `#[Middleware(TokenAuthMiddleware::class)]` through a booted test app answers 401 JSON on `/api/…`.
  - Command (temp SQLite, the skeleton's `user_tokens` migration, a test user model):
    - issuing prints a 64-hex token, and the stored row holds `hash('sha256', token)`, not the token;
    - `--list` shows the name and does not contain the token or its hash;
    - `--revoke` deletes it;
    - an unknown email exits 1;
    - the token issued authenticates through `TokenGuard::user()`.

- [ ] **Step 2: Run, expect failures.** `vendor/bin/phpunit packages/auth/tests packages/devtools/tests`

- [ ] **Step 3: Implement** as specified. Register `AuthTokenCommand` in `Console`'s built-in list after `AuthUserCommand`. Every reference to auth classes is guarded by `class_exists`, so devtools keeps working without preflow/auth.

- [ ] **Step 4: Run** `vendor/bin/phpunit packages/auth/tests packages/devtools/tests packages/core/tests`, expect PASS.

- [ ] **Step 5: Commit** `git commit -m "feat(auth): TokenAuthMiddleware for bearer routes; auth:token issues, lists and revokes tokens"`

---

### Task 6: Testing helpers, docs, release prep

**Files:**
- Modify:
  - `packages/testing/src/TestResponse.php`;
  - READMEs of core (JSON responses, API errors, CORS, `config/api.php`), routing (405), auth (`TokenAuthMiddleware`, tokens) and devtools (`auth:token`);
  - `packages/skeleton/config/api.php` (new, commented, CORS off);
  - `ROADMAP.md`: a 0.62.0 entry, and the "Protected APIs" entry updated so that CORS is done and a CLI exists for tokens;
  - the remaining composer constraints from the Global Constraints.
- Test: `packages/testing/tests/TestResponseTest.php`

**Interfaces:**
- Produces, on `TestResponse`:
  - `json(?string $path = null): mixed`: decodes the body. `$path` is dot notation (`data.0.title`), and a missing path fails the test.
  - `assertJsonPath(string $path, mixed $expected): self`.
  - `assertJsonValidationErrors(string ...$fields): self`: status 422 and each field present under `errors`.
  - `assertMethodNotAllowed(): self` (405).

- [ ] **Step 1: Write the failing tests** for each method, including a failing assertion on a missing path. Use `expectException(AssertionFailedError::class)`.
- [ ] **Step 2: Run, expect failures.** `vendor/bin/phpunit packages/testing/tests`
- [ ] **Step 3: Implement**, then write the docs.
  - The core README's API section shows a complete small example:
    - a controller with `#[Route('/api/jobs')]` returning `Json::data($jobs, ['page' => 1])`;
    - a `#[Middleware(TokenAuthMiddleware::class)]` POST that saves through the DataManager, where a failed validation becomes the 422 shape;
    - `config/api.php`;
    - curl with a bearer token.
  - It states the two upgrade-visible changes (405, 422).
- [ ] **Step 4: Run the full suite** `vendor/bin/phpunit`, expect all green (baseline 6433 tests, plus the new ones).
- [ ] **Step 5: Commit** `git commit -m "docs: 0.62.0 REST pieces; TestResponse JSON assertions"`

Release, after the final review (controller, not a task): tag `v0.62.0`, check both split runs, then on all 5 sites raise `preflow/*` to `^0.62` and update the pin tests, run each suite, back up, deploy, and verify `installed.json` on the servers shows v0.62.0.
