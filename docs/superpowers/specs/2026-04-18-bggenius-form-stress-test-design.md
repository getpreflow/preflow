# BGGenius Form Package Stress Test Design

**Date:** 2026-04-18
**Status:** Draft
**Project:** /Users/smyr/Sites/gbits/bggenius-preflow
**Framework:** /Users/smyr/Sites/gbits/flopp (preflow monorepo)

---

## Overview

Stress-test `preflow/form` v0.13.0 by migrating 7 existing forms in BGGenius from manual HTML to the form builder pattern. The goal is to validate every major form package feature in a production application and surface framework gaps.

### Forms in Scope

| Form | Type | Key Features Tested |
|------|------|-------------------|
| GameForm | Component, CRUD | Model binding, field-level errors, groups, select, file input, HTMX |
| UserForm | Component, CRUD | Validation scenarios (create/update), model binding, UniqueField custom rule |
| ContactForm | Component, public | Inline HTMX validation, honeypot, RateLimit custom rule |
| LoginForm | Controller, auth | Plain mode (no component), standard POST, flash messages |
| ForgotPasswordForm | Controller, auth | Plain mode, simple single-field form |
| ResetPasswordForm | Controller, auth | Plain mode, confirmed password rule |
| AssetDetail | Component, mixed | Partial migration (metadata form only), coexists with JS image editor |

### Forms NOT in Scope

- QuizStep, InteractiveStep, PlayerShell feedback — purpose-built JS game interactions, not typical forms
- AssetManager filter, FeedbackList filter — simple dropdowns, not worth form package overhead
- Logout form — single hidden CSRF field

---

## 1. Prerequisites

### 1.1 Update BGGenius Dependencies

Update `composer.json` to require `preflow/form` and bump all preflow packages to `^0.13`:

```json
"preflow/core": "^0.13",
"preflow/routing": "^0.13",
"preflow/view": "^0.13",
"preflow/twig": "^0.13",
"preflow/components": "^0.13",
"preflow/auth": "^0.13",
"preflow/data": "^0.13",
"preflow/htmx": "^0.13",
"preflow/i18n": "^0.13",
"preflow/validation": "^0.13",
"preflow/form": "^0.13"
```

Run `composer update`.

### 1.2 User Model Scenario Support

Add validation scenarios to the User model's password property:

```php
// Before
// (password has no #[Validate] — validated manually in component)

// After
#[Validate('required', 'min:8', 'on:create')]
#[Validate('nullable', 'min:8', 'on:update')]
public string $password = '';
```

### 1.3 CSS Class Mapping

BGGenius uses `is-invalid` CSS class for error styling. The default form package field template uses `has-error`. Two options:

- **A)** Create a custom field template for BGGenius (preferred — follows the override pattern)
- **B)** Adjust BGGenius CSS to also style `has-error`

Decision: Use custom CSS that styles both `has-error` and `is-invalid`, or provide a global field template override in BGGenius config. Determine during implementation based on what feels cleaner.

---

## 2. Component Form Migrations

### 2.1 GameForm

**Current:** 15+ manually rendered fields with grid layout, file upload, auto-slug JS, status dropdown, field-level error display via `errors` variable.

**Migration:**
- Use `form_begin({model: game})` for model binding
- Use `form.field()` for standard text/number fields
- Use `form.select('status', {options: statusOptions})` for status dropdown
- Use `form.group()` for layout rows (min_players + max_players, play_time + teach_time)
- Use `form.file('cover_image_file')` for cover image upload — keep the preview JS as-is
- Keep auto-slug JavaScript unchanged — it reads from the name input and writes to slug input, works regardless of how the inputs are rendered
- The component PHP (`actionSave()`) stays unchanged — it catches `ValidationException` and sets error state

**Template changes:**
- Replace ~100 lines of manual HTML with ~25 lines of form builder calls
- `form_begin()` auto-includes CSRF token
- Field-level errors auto-displayed from ErrorBag
- Old input auto-populated from model properties

**Component PHP changes:**
- After catching `ValidationException`, set the ErrorBag on `FormExtensionProvider` so the form builder can display field errors on re-render
- May need to retrieve `FormExtensionProvider` from the container

### 2.2 UserForm

**Current:** 4 fields (username, email, password, role), 2-column grid, manual password required/optional logic.

**Migration:**
- Use `form_begin({model: user, scenario: isNew ? 'create' : 'update'})` for scenario-aware model binding
- Password field auto-detects required (create) vs optional (update) from `#[Validate]` scenarios
- Use `form.select('role', {options: roleOptions})` for role dropdown
- UniqueField custom rule stays in the component's `actionSave()` — it's business logic, not form rendering
- "Cannot demote last admin" check stays in component

**User model changes:**
- Add `#[Validate('required', 'min:8', 'on:create')]` and `#[Validate('nullable', 'min:8', 'on:update')]` to password property

### 2.3 ContactForm

**Current:** 4 fields with HTMX inline validation via dedicated `/validate-contact-field` controller endpoint. Honeypot spam field. RateLimit custom rule.

**Migration:**
- Use `form_begin({model: contactMessage})` with hypermedia driver auto-detection
- The form builder auto-generates `hx-post` / `hx-trigger="blur"` attributes for inline validation
- Add `actionValidateField()` to ContactForm component — validates a single field from the POST body against the ContactMessage model rules, returns the re-rendered field HTML
- Delete `ValidateFieldController` (`/validate-contact-field` endpoint) — no longer needed
- Honeypot field stays as manual `<input>` outside the form builder (intentionally hidden, no validation)
- RateLimit custom rule stays on the model — works unchanged
- The component needs to register `validateField` in its `actions()` array

**Component PHP changes:**
- Add `actionValidateField(array $params)` method
- Register 'validateField' in `actions()` return array
- The action validates the single submitted field, creates an ErrorBag with just that field's result, sets it on FormExtensionProvider, and re-renders just the field block

---

## 3. Controller Form Migrations

### 3.1 LoginForm

**Current:** Manual `<form method="post" action="/admin/login">` with username and password fields. Errors via flash messages.

**Migration:**
- Use `form_begin({action: '/admin/login'})` — plain mode, no model
- `form.field('username')` and `form.field('password', {type: 'password'})`
- `form.submit('Login')`
- Flash messages continue to be displayed by the `_admin.twig` layout — the form package doesn't interfere
- `AuthController` stays unchanged

### 3.2 ForgotPasswordForm

**Current:** Single email field, POST to `/admin/forgot-password`.

**Migration:**
- `form_begin({action: '/admin/forgot-password'})`
- `form.field('email', {type: 'email'})`
- `form.submit('Send Reset Code')`

### 3.3 ResetPasswordForm

**Current:** 4 fields (email, code, password, password_confirm), POST to `/admin/reset-password`.

**Migration:**
- `form_begin({action: '/admin/reset-password'})`
- `form.field('email', {type: 'email'})`
- `form.field('code')` 
- `form.field('password', {type: 'password'})`
- `form.field('password_confirm', {type: 'password'})`
- `form.submit('Reset Password')`

---

## 4. Mixed Form Migration

### 4.1 AssetDetail

**Current:** 3 sub-forms in one template: metadata edit (usage_type dropdown, HTMX), file upload (standard POST with drag-drop), image crop/rotate (Fetch API + JS).

**Migration:** Only the metadata edit form. The file upload form and image editor stay as raw HTML.

- Metadata form: `form_begin({...})` with `form.select('usage_type', {options: usageTypes})`
- File upload form: unchanged (standard `<form enctype="multipart/form-data">` with drag-drop JS)
- Image editor: unchanged (Fetch API, not a form at all)

This tests that the form package coexists cleanly with non-migrated forms in the same template.

---

## 5. Expected Framework Gaps to Investigate

These may or may not be actual gaps — finding out is the point of the stress test:

1. **ErrorBag wiring in components** — components catching `ValidationException` need to set the ErrorBag on `FormExtensionProvider`. May need a helper or auto-wiring in the component endpoint.

2. **Form enctype** — `form_begin()` should accept `enctype` option, or auto-detect when a file field is present.

3. **Inline validation endpoint URL** — the form builder needs the component's validation action URL to generate `hx-post` attributes. This needs to flow through the component context via `FormExtensionProvider::setComponentContext()`.

4. **Single-field validation action** — the `actionValidateField` pattern (validate one field, return one field block) may need a framework-level helper or convention.

5. **CSS class customization** — the default `has-error` class may not match BGGenius's `is-invalid`. A global configuration for error classes would be useful.

---

## 6. Success Criteria

- All 7 forms render correctly and function identically to their manual HTML versions
- Form submission, validation, error display, and old input preservation work end-to-end
- Inline HTMX validation works on ContactForm via component action
- User create/update scenario correctly toggles password required/optional
- AssetDetail's non-migrated forms continue to work alongside the migrated metadata form
- No visual regressions in the admin UI
- Any framework gaps found are documented and fixed in the framework (not worked around in BGGenius)

---

## What This Design Does NOT Include

- Migration of game interaction forms (QuizStep, InteractiveStep) — intentionally excluded
- Migration of filter forms (AssetManager, FeedbackList) — too simple to benefit
- New features in BGGenius — this is a migration, not a feature addition
- Custom field template implementation — may emerge as a gap fix, but not designed upfront
