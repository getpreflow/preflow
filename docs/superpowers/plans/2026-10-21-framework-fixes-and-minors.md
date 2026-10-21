# Framework Fixes and Access Minors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Fix the framework issues found while updating preflow.dev, and the minors deferred from the access/roles review.

**What gets fixed:**
- Component requests run through middleware.
- Action errors reach the error boundary.
- More than one login (guard) works.
- Test logins reach `can()`.
- Blade apps get file-based routes.
- `hd.actionUrl()` works in a booted app.
- An image URL transformer can come from a service provider.
- Folio's pickers and 403s respect permissions.

Then release v0.19.0 and update preflow.dev and smeyer.me.

**Architecture:**
- **Component requests:** the `/--component` endpoint moves from a shortcut in `Application::handle()` into the kernel's pipeline. It then gets the same middleware (security headers, session, CSRF exemption, auth) and the same error handling as every other request.
- **Guards:** built from config, including custom classes. Each guard keeps its own session key. The per-request auth middleware asks every configured guard, default first.
- **Everything else:** small, local fixes.

**Tech Stack:** PHP 8.4, PHPUnit 11.

**Spec:** no separate spec. The items come from two sources:
- the preflow.dev docs agents' findings (session of 2026-10-21);
- the access review's deferred minors (`docs/superpowers/plans/2026-10-21-access-and-simple-roles.md` and its review).

The binding intent comes from the extensions design (`docs/superpowers/specs/2026-10-21-folio-extensions-design.md`): staff and members are separate guards, and there is one `can()` question.

## Global Constraints

- **Existing apps keep working unchanged:**
  - smeyer.me: `session.paths` is limited to `/folio`, and public components run without a session.
  - preflow.dev: the theme toggle component uses the session, and the site has its own CSP.
  - Existing sessions stay signed in: the default guard keeps the session key `_auth_user_id`.
- **Component endpoint:**
  - Component POSTs never need a CSRF token. `CsrfMiddleware` already exempts `/--component/`; keep it that way.
  - Token checks still answer before any component code runs.
  - A bad or expired token now answers **403** through the error handler, not a PHP fatal error with status 200.
- **The Authenticatable attribute:** a user preset as a request attribute is trusted, because only in-process code can set attributes; clients can't.
- **Strict CSP:** no inline scripts in templates; nonces as today.
- **Version:** `v0.19.0`. These are behaviour changes: component middleware, multiple guards, `isDeclared` ignoring patterns.

## Review Focus

1. **Component endpoint through the pipeline:**
   - A component POST from a public page of an app whose session is limited to `/folio` (smeyer.me) still renders and runs actions.
   - The fragment keeps its `HX-Nonce` header.
   - CSRF never rejects it.
2. **Logging out of one guard** (members) keeps the other (staff) signed in. Logging out still regenerates the session id.
3. **A relation field for a user without `{target}.view`:** saving the record keeps the existing relation and lists no other records.
4. **`isDeclared()` without patterns:** Folio's names and roles' wildcard matching still work. A typo like `page.pubish` throws in debug again.
5. **Action errors:** an exception thrown in a component action renders the component's fallback (status 200, so htmx swaps it). A `ForbiddenHttpException` from `Guarded::authorize()` still answers 403.

---

### Task 1: Component requests go through the pipeline; action errors reach the boundary

**Files:**
- Modify:
  - `packages/core/src/Kernel.php`: an optional component endpoint, dispatched inside the pipeline for paths starting with `/--component`.
  - `packages/core/src/Application.php`: remove the shortcut in `handle()`, and pass the endpoint to `Kernel`.
  - `packages/htmx/src/ComponentEndpoint.php`: catch action and state errors.
  - `packages/components/src/ComponentRenderer.php`: add `renderError()`.
- Tests:
  - `packages/core/tests/KernelTest.php`
  - `packages/core/tests/ComponentEndpointPipelineTest.php` (new; a full app with a test component)
  - `packages/htmx/tests/ComponentEndpointTest.php`

**Interfaces:**
- `Kernel::__construct(..., ?\Closure $componentEndpoint = null)`, where the closure has the shape `fn(ServerRequestInterface): ResponseInterface`. It runs inside `pipeline->process` and `try`, before `router->match`.
- `ComponentRenderer::renderError(Component $component, \Throwable $e, string $phase = 'action'): string`, which wraps `ErrorBoundary::render`.
- `ComponentEndpoint`: `resolveState()` and `handleAction()` are wrapped in a `try` block. A `Preflow\Core\Exceptions\HttpException` is rethrown; any other throwable returns `renderer->renderError(...)` with status 200 and the usual headers.

- [ ] **Step 1: Tests**
  - **`KernelTest`:**
    - `test_component_paths_go_to_the_endpoint_through_the_pipeline`: a pipeline middleware sets a header; `/--component/render` reaches a stub endpoint closure; the response has the header; the router is not called.
    - `test_endpoint_exceptions_use_the_error_handler`: a `SecurityException` gives 403.
  - **`ComponentEndpointPipelineTest`** (a full app with `config/auth.php`; `session.paths => ['/admin']`; a test component with `actions(): ['bump']` that reads `SessionInterface`):
    - `test_component_action_gets_security_headers_and_nonce`: `Content-Security-Policy` and `HX-Nonce` are present.
    - `test_component_post_needs_no_csrf_token`
    - `test_invalid_token_is_403_not_fatal`
    - `test_session_paths_still_apply_to_component_requests`: no `Set-Cookie`.
    - `test_signed_in_user_is_visible_to_guarded_components`: log in through the guard, then have a `Guarded` component read the request's `Authenticatable` attribute.
  - **`ComponentEndpointTest`:**
    - `test_action_exception_renders_the_fallback`: an action throws `RuntimeException`; status 200; the body contains the component's `fallback()` output.
    - `test_forbidden_from_authorize_stays_403`: `authorize()` throws `ForbiddenHttpException`, and it propagates.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement as described in Interfaces.
- [ ] **Step 4:** Run the core, htmx and components suites, and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `fix(core): component requests run through middleware and error handling; action errors reach the boundary`.

### Task 2: More than one login (guards), test logins, `can()` everywhere

**Files:**
- Modify:
  - `packages/core/src/Application.php::bootAuth`:
    - Build guards from config; custom classes come from `container->make($class, ['provider' => $provider])` and must implement `GuardInterface`, otherwise boot throws.
    - Give each `SessionGuard` its own key.
    - The global middleware asks every guard, default first.
    - It honours a preset `Authenticatable` attribute.
    - It always registers the Twig `can()`.
  - `packages/auth/src/SessionGuard.php`: the key is a constructor parameter; logout is per key.
  - `packages/auth/src/AuthManager.php`: add the constant `GUARD_ATTRIBUTE = 'preflow.auth.guard'`.
  - `packages/auth/src/Http/AuthMiddleware.php`, `GuestMiddleware.php`: use the user resolved for the request.
  - `packages/testing/src/AuthTestHelpers.php`: `actingAs(Authenticatable $user, ServerRequestInterface $request, ?string $guard = null)` also sets `GUARD_ATTRIBUTE`.
  - `packages/folio/src/Http/AdminAuthMiddleware.php`: a preset user of the Folio guard counts.
  - `packages/folio/src/FolioServiceProvider.php`: remove the `can()` stub, since core now registers it.
  - `packages/auth/README.md`: the members guard, the roles population, custom guards.
- Tests:
  - `packages/auth/tests/SessionGuardTest.php`
  - `packages/core/tests/AuthGuardsBootTest.php` (new)
  - `packages/testing/tests/AuthTestHelpersTest.php`

**Interfaces:**
- **`SessionGuard`:**
  - Constructor: `SessionGuard::__construct(UserProviderInterface $provider, PasswordHasherInterface $hasher, string $sessionKey = '_auth_user_id')`.
  - Session keys: the default guard keeps `_auth_user_id`; any other guard uses `_auth_user_id.{name}`.
  - Logout: `remove($key)`, then `regenerate()`; then `invalidate()` only when no other `_auth_user_id*` key remains.
- **Global middleware:**
  - User resolution:
    - If the request already carries `Authenticatable`, use it, with the guard from `GUARD_ATTRIBUTE` (or the default guard).
    - Otherwise, ask the guards in order: default first, then config order.
    - The first match wins. Call `setUser($user, $name)` and set both attributes.
  - Twig `can()`:
    - Bound as `Access` when `preflow/auth` is installed.
    - Without `config/auth.php`, it is `Access(null, ...)`, which always answers true.
    - It is registered on the engine in both cases.
- **`AuthMiddleware`:** `$authManager->user() ?? $authManager->guard()->user($request)`; 401 when null. `GuestMiddleware` mirrors it.

- [ ] **Step 1: Tests**
  - **`SessionGuardTest`:**
    - `test_two_guards_keep_separate_users`
    - `test_logout_of_one_guard_keeps_the_other`, which also asserts the session id changed.
    - `test_logout_of_the_last_guard_invalidates_the_session`
  - **`AuthGuardsBootTest`:**
    - `test_custom_guard_class_is_built_from_config` (a fixture guard class)
    - `test_non_guard_class_fails_boot`
    - `test_members_user_is_resolved_and_population_is_members`: a `members` guard with `population: 'members'`; log a member in through it; `Access::can('x')` follows the members default (false) and `AuthManager::guardName() === 'members'`.
    - `test_default_guard_wins_when_both_are_signed_in`
    - `test_can_twig_function_exists_without_auth_config`
  - **`AuthTestHelpersTest`:** `test_acting_as_reaches_can_and_auth_middleware`. A full app, then `handle(actingAs($staffUser, $request))` on a route behind `AuthMiddleware` whose template prints `can('x')`. Expected: 200, and the output shows true.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. In the auth README, replace the limitation note with how a members login works: a second guard, its own `login()` call, and population members.
- [ ] **Step 4:** Run the auth, core, testing and folio suites. Expected: PASS.
- [ ] **Step 5:** Commit `feat(auth): several guards (custom classes, own session keys), test logins reach can(), can() always available`.

### Task 3: Small framework fixes

**Files:**
- Modify:
  - `packages/routing/src/Router.php`: a `string $templateExtension = 'twig'` parameter, passed to `FileRouteScanner`.
  - `packages/core/src/Application.php::bootRouting`: pass the engine's `getTemplateExtension()`.
  - `packages/htmx/src/HtmxExtensionProvider.php`: `actionUrl(string $action, string $componentClass, array $props = []): string`, as in `HdExtension`.
  - `packages/core/src/Application.php::bootHelpers`: `ResponsiveImage` gets its transformer lazily. A `\Closure(): ImageUrlTransformer` reads the container at first use, so providers can bind one.
  - `packages/form/src/FormBuilder.php::submit`: pass `'#' . $this->componentId` as the target.
- Tests:
  - `packages/routing/tests/RouterTest.php`: `test_blade_pages_are_routed`, with a `pages/about.blade.php` file and `templateExtension: 'blade.php'`.
  - `packages/htmx/tests/HtmxExtensionProviderTest.php`: `test_action_url_carries_a_decodable_token`.
  - `packages/core/tests/ImageTransformerProviderTest.php`: `test_provider_bound_transformer_is_used`. A provider binds a transformer, and `responsive_image` output uses its URLs.
  - `packages/form/tests/FormBuilderTest.php`: `test_submit_targets_the_component_by_id_selector`, which expects `hx-target="#<id>"`.
- [ ] **Step 1:** Write the tests. **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Run those suites and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `fix: Blade file routes, hd.actionUrl in apps, provider-bound image transformers, form submit target`.

### Task 4: Access and Folio minors

**Files:**
- Modify:
  - `packages/auth/src/Access/PermissionRegistry.php`: `isDeclared()` checks names only. `patterns()` stays, for grouping in role editors.
  - `packages/roles/src/ConfigRoleResolver.php`: skip roles that are not string or int.
  - `packages/folio/src/Http/FolioPermissionMiddleware.php`: block-editor routes accept any type's `edit` or `create`. The rule token becomes `'*.edit|create'`.
  - `packages/folio/src/Http/Forbidden.php`, `packages/folio/templates/admin/forbidden.twig`:
    - With `Sec-Fetch-Dest: empty` (a fetch), answer a plain-text 403.
    - With `iframe`, render the 403 inside `_drawer_layout.twig`.
  - `packages/folio/src/Http/AdminController.php`: `previewable` also requires `can_save`.
  - `packages/folio/src/Field/Types/RelationFieldType.php`, `MatrixFieldType.php`: take the `$can` closure (Relation gets a new parameter).
    - Without `{to}.view`, the relation lists only the currently selected records, so saving keeps them.
    - The matrix lists records and the "Add" option only for viewable types; existing rows stay.
  - `packages/folio/src/FolioServiceProvider.php`: pass `$can` to Relation.
  - `packages/folio/composer.json`: `preflow/core` and `preflow/auth` become `^0.19 || @dev`.
  - `packages/auth/README.md`: patterns are for grouping, not declaration.
- Tests:
  - `packages/auth/tests/Access/PermissionRegistryTest.php`: `test_patterns_do_not_declare_names`; and `AccessTest`'s `test_typo_in_a_family_throws_in_debug`.
  - `packages/roles/tests/ConfigRoleResolverTest.php`: `test_non_scalar_roles_are_skipped`.
  - `packages/folio/tests/Integration/FolioPermissionsTest.php`:
    - `test_create_only_role_can_use_the_block_picker`
    - `test_readonly_form_offers_no_preview`
    - `test_fetch_403_is_plain_text`
    - `test_drawer_403_uses_the_drawer_layout`
    - `test_relation_without_target_view_keeps_the_selection`: save keeps the value; the edit form contains no other record's label.
    - `test_matrix_lists_only_viewable_types`
  - `packages/folio/tests/Http/FolioPermissionMiddlewareTest.php`: the mapping uses `'*.edit|create'`.
- [ ] **Step 1:** Write the tests. **Step 2:** Run. Expected: FAIL. **Step 3:** Implement. **Step 4:** Run the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `fix(folio,auth,roles): pickers and 403s respect permissions; patterns no longer declare names`.

### Task 5: Release and sites

- **Framework:**
  - Run the full suite.
  - Merge, push and tag `v0.19.0`.
  - Wait for the split and Packagist.
- **preflow.dev:**
  - Move to `^0.19`.
  - Update the docs that described the old behaviour:
    - `packages/htmx.md` and `guides/components.md`: component requests now run through middleware. Restore the `Guarded` example that reads the user.
    - `guides/error-boundaries.md`: action errors.
    - `packages/auth.md` and `guides/authentication.md`: several guards, a members login, custom guards, `actingAs`.
    - `packages/blade.md`: file routes.
    - `packages/twig.md` and `htmx.md`: `hd.actionUrl`.
    - `packages/view.md`: transformer from a provider.
    - `packages/roles.md`: patterns.
  - Render all pages and check links, then deploy and verify live.
- **smeyer.me:**
  - Move to `^0.19`, then run the suite.
  - Deploy.
  - Verify the public site, the contact form component, and that the admin login redirects.
