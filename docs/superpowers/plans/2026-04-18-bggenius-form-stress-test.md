# BGGenius Form Package Stress Test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate 7 BGGenius forms to use preflow/form package, validating model binding, scenarios, inline HTMX validation, and plain-mode rendering in a production app.

**Architecture:** Each form template is replaced with form builder calls while keeping CSS styling, component PHP logic, and JavaScript intact. Component forms use `form_begin({model: ...})` for model binding and `form.field()` for rendering. Controller forms use `form_begin({action: '/path'})` with `form.begin()` for the `<form>` tag. A small framework addition (`hd.actionUrl()`) supports inline validation URL generation for the ContactForm migration.

**Tech Stack:** PHP 8.4+, Preflow v0.13, Twig, HTMX

**Working directory:** `/Users/smyr/Sites/gbits/bggenius-preflow`
**Framework directory:** `/Users/smyr/Sites/gbits/flopp`

---

## File Map

### Modified files (BGGenius)

| File | Change |
|------|--------|
| `composer.json` | Bump preflow to ^0.13, add preflow/form |
| `app/Models/User.php` | Add `#[Validate]` with `on:create` / `on:update` for password |
| `app/Components/Admin/GameForm/GameForm.twig` | Replace manual fields with form builder |
| `app/Components/Admin/GameForm/GameForm.php` | Wire ErrorBag to FormExtensionProvider |
| `app/Components/Admin/UserForm/UserForm.twig` | Replace manual fields with form builder |
| `app/Components/Admin/UserForm/UserForm.php` | Use validation scenarios, wire ErrorBag |
| `app/Components/Public/ContactForm/ContactForm.twig` | Replace manual fields, inline validation via component |
| `app/Components/Public/ContactForm/ContactForm.php` | Add `actionValidateField()`, wire ErrorBag |
| `app/Components/Admin/LoginForm/LoginForm.twig` | Replace manual fields with form builder |
| `app/Components/Admin/ForgotPasswordForm/ForgotPasswordForm.twig` | Replace manual fields |
| `app/Components/Admin/ResetPasswordForm/ResetPasswordForm.twig` | Replace manual fields |
| `app/Components/Admin/AssetDetail/AssetDetail.twig` | Replace metadata form only |

### Deleted files

| File | Reason |
|------|--------|
| `app/Controllers/ValidateFieldController.php` | Inline validation moves to ContactForm component |

### Framework additions (preflow monorepo)

| File | Change |
|------|--------|
| `packages/twig/src/HdExtension.php` | Add `actionUrl()` method returning just the tokenized URL |
| `packages/twig/tests/HdExtensionTest.php` | Test for `actionUrl()` (if test file exists, add test) |

---

### Task 1: Prerequisites — Dependencies & User Model Scenarios

**Files:**
- Modify: `composer.json` (BGGenius)
- ~~Modify: `app/Models/User.php`~~ (not needed — password is not a model property, uses form-level rule overrides)

- [ ] **Step 1: Update composer.json**

In `/Users/smyr/Sites/gbits/bggenius-preflow/composer.json`, bump all preflow packages from `^0.12` to `^0.13` and add `preflow/form`. Also update the versions override in the repositories section:

```json
"require": {
    "php": ">=8.4",
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
    "preflow/form": "^0.13",
```

And in repositories:
```json
"versions": {
    "preflow/*": "0.13.0"
}
```

And in require-dev:
```json
"preflow/testing": "^0.13",
"preflow/devtools": "^0.13",
```

- [ ] **Step 2: Run composer update**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow && composer update
```

Expected: Installs preflow/form and updates all preflow packages to 0.13.0.

- [ ] **Step 3: Add validation scenarios to User model**

In `app/Models/User.php`, add `#[Validate]` attributes for the password field. The User model currently has no validation on `password_hash` — the password validation is handled manually in UserForm.php. We're adding a virtual `password` property concept via the scenario rules. But since `password` is not a model property, we need to add it.

Actually — the User model stores `password_hash`, not `password`. The raw password is only present during form submission. So we should NOT add `#[Validate]` to the model for password — it should stay as form-level validation in the component, since the model never sees the raw password.

Instead, the UserForm will pass `rules` override to the form builder:

```twig
{% set form = form_begin({
    model: user,
    rules: isEdit
        ? {password: ['nullable', 'min:8']}
        : {password: ['required', 'min:8']}
}) %}
```

No model changes needed for password. The User model stays as-is.

- [ ] **Step 4: Verify the app boots**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow && php -r "require 'vendor/autoload.php';"
```

Then open the site in a browser to verify nothing is broken.

- [ ] **Step 5: Commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add composer.json composer.lock
git commit -m "chore: bump preflow to v0.13, add preflow/form package"
```

---

### Task 2: Framework Addition — `hd.actionUrl()` Method

**Files:**
- Modify: `/Users/smyr/Sites/gbits/flopp/packages/twig/src/HdExtension.php`

This adds a method to HdExtension that returns just the tokenized action URL as a string — no HTML attributes. Needed by ContactForm's inline validation to construct custom HTMX attributes with specific targets.

- [ ] **Step 1: Add `actionUrl()` method to HdExtension**

In `/Users/smyr/Sites/gbits/flopp/packages/twig/src/HdExtension.php`, add this method:

```php
/**
 * Get just the action URL (tokenized) without any HTML attributes.
 * Useful for inline validation where target/swap/trigger need customization.
 *
 * @param array<string, mixed> $props
 */
public function actionUrl(
    string $action,
    string $componentClass,
    array $props = [],
): string {
    $tokenStr = $this->token->encode($componentClass, $props, $action);
    return $this->endpointPrefix . '/action?token=' . urlencode($tokenStr);
}
```

- [ ] **Step 2: Run twig tests**

```bash
cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit --testsuite=Twig -v
```

Expected: All existing tests pass (no new test needed — method signature is simple and will be integration-tested via ContactForm).

- [ ] **Step 3: Commit in framework repo**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/twig/src/HdExtension.php
git commit -m "feat(twig): add hd.actionUrl() for inline validation URL generation"
git push origin main
```

---

### Task 3: Auth Forms — Login, Forgot Password, Reset Password

**Files:**
- Modify: `app/Components/Admin/LoginForm/LoginForm.twig`
- Modify: `app/Components/Admin/ForgotPasswordForm/ForgotPasswordForm.twig`
- Modify: `app/Components/Admin/ResetPasswordForm/ResetPasswordForm.twig`

These are controller-based forms (standard POST, no HTMX). The form builder runs in plain mode — `form.begin()` generates the `<form>` tag with action, method, and CSRF token.

**CSS approach:** These forms use `auth-form-group` and `auth-submit` CSS classes. The form builder generates `form-group` and default button markup. We update the `{% apply css %}` block to also target the form builder's classes, keeping the existing auth card design intact.

- [ ] **Step 1: Migrate LoginForm.twig**

Replace the form section in `app/Components/Admin/LoginForm/LoginForm.twig`. Keep the auth-card wrapper, header, flash messages, and footer link. Replace the manual `<form>` and fields with form builder calls.

The CSS block needs to add selectors for the form builder's generated classes alongside the existing auth-specific classes. Add these rules to the existing `{% apply css %}` block:

```css
/* Form builder integration */
.auth-card .form-group {
    margin-bottom: 1.25rem;
}

.auth-card .form-group label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
}

.auth-card .form-group input {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg-input);
    border: 1px solid var(--accent-border-strong);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: var(--text-md);
    font-family: inherit;
    transition: border-color var(--transition-fast);
}

.auth-card .form-group input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
```

Replace the form HTML:

```twig
{% set form = form_begin({action: '/admin/login', csrf_token: csrf_token()}) %}
{{ form.begin()|raw }}

    {{ form.field('username', {attrs: {required: true, autofocus: true, autocomplete: 'username'}})|raw }}
    {{ form.field('password', {type: 'password', attrs: {required: true, autocomplete: 'current-password'}})|raw }}

    <button type="submit" class="auth-submit">Sign In</button>
</form>
```

Note: We use `</form>` directly instead of `form_end()` since we're not calling `form.begin()` via `form_end()` — either works, but the closing tag is simpler. Actually, `form_end()` also returns `</form>`, so either is fine.

- [ ] **Step 2: Migrate ForgotPasswordForm.twig**

Replace the form HTML in `app/Components/Admin/ForgotPasswordForm/ForgotPasswordForm.twig`:

```twig
{% set form = form_begin({action: '/admin/forgot-password', csrf_token: csrf_token()}) %}
{{ form.begin()|raw }}

    {{ form.field('email', {type: 'email', label: 'Email Address', attrs: {required: true, autofocus: true, autocomplete: 'email'}})|raw }}

    <button type="submit" class="auth-submit">Send Reset Code</button>
</form>
```

This template doesn't have its own CSS block — it inherits the auth styles from the login form (they're registered globally via components CSS scoping). Add the same form-group CSS rules as LoginForm if needed, or rely on the styles already registered by LoginForm.

- [ ] **Step 3: Migrate ResetPasswordForm.twig**

Replace the form HTML in `app/Components/Admin/ResetPasswordForm/ResetPasswordForm.twig`:

```twig
{% set form = form_begin({action: '/admin/reset-password', csrf_token: csrf_token()}) %}
{{ form.begin()|raw }}

    {{ form.field('email', {type: 'email', label: 'Email Address', attrs: {required: true, autocomplete: 'email'}})|raw }}
    {{ form.field('code', {label: '6-Digit Code', attrs: {required: true, pattern: '[0-9]{6}', maxlength: '6', placeholder: '000000', autocomplete: 'one-time-code', style: 'text-align:center;letter-spacing:0.5em;font-size:1.25rem;font-weight:600;'}})|raw }}
    {{ form.field('password', {type: 'password', label: 'New Password', attrs: {required: true, minlength: '8', autocomplete: 'new-password', placeholder: 'Minimum 8 characters'}})|raw }}
    {{ form.field('password_confirm', {type: 'password', label: 'Confirm Password', attrs: {required: true, autocomplete: 'new-password'}})|raw }}

    <button type="submit" class="auth-submit">Reset Password</button>
</form>
```

- [ ] **Step 4: Verify in browser**

Open each auth page and verify:
- `/admin/login` — form renders, submitting with wrong credentials shows flash error, submitting correctly logs in
- `/admin/forgot-password` — form renders, submit works
- `/admin/reset-password` — form renders, code input has centered styling, submit works

- [ ] **Step 5: Commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add app/Components/Admin/LoginForm/LoginForm.twig \
       app/Components/Admin/ForgotPasswordForm/ForgotPasswordForm.twig \
       app/Components/Admin/ResetPasswordForm/ResetPasswordForm.twig
git commit -m "refactor: migrate auth forms to preflow/form builder

LoginForm, ForgotPasswordForm, ResetPasswordForm now use form_begin()
and form.field() in plain mode (no HTMX, standard POST)."
```

---

### Task 4: GameForm Migration

**Files:**
- Modify: `app/Components/Admin/GameForm/GameForm.twig`
- Modify: `app/Components/Admin/GameForm/GameForm.php`

The most complex form — 15+ fields, grid layout, file upload, auto-slug JS, field-level errors. The component PHP needs to wire the ErrorBag to FormExtensionProvider after validation failure.

- [ ] **Step 1: Update GameForm.php to wire ErrorBag**

In `app/Components/Admin/GameForm/GameForm.php`, add `FormExtensionProvider` as a constructor dependency and set ErrorBag on it after validation failure:

Add import:
```php
use Preflow\Form\FormExtensionProvider;
```

Update constructor:
```php
public function __construct(
    private readonly DataManager $data,
    private readonly FormExtensionProvider $formExt,
) {}
```

After each `catch (ValidationException $e)` block, add:
```php
$this->formExt->setErrorBag($this->errors);
```

Also after the manual slug uniqueness ErrorBag creation:
```php
$this->formExt->setErrorBag($this->errors);
```

- [ ] **Step 2: Rewrite GameForm.twig**

Replace the manual form fields with form builder calls. Keep the `{% apply css %}` block, the page header, flash messages, the JavaScript (auto-slug and cover preview), and the `<form>` tag with `hd.post()`. The CSS needs minor adjustments — change `.field-error` selectors to `.form-error` and add `.has-error` styling.

Update CSS: change `.game-form .field-error` to `.game-form .form-error` and add:
```css
.game-form .has-error input,
.game-form .has-error select,
.game-form .has-error textarea {
    border-color: var(--color-danger);
}
```

Remove the old `.game-form input.is-invalid` rules.

Replace the form body with:

```twig
{% set game_model = game ?? null %}
{% set form = form_begin({errorBag: errors}) %}

<form class="game-form" id="{{ componentId }}-form"
    {{ hd.post('save', componentClass, componentId, props) | raw }}
    enctype="multipart/form-data">

    <div class="card-body">
        <div class="form-grid">
            <div class="form-col-full">
                {{ form.field('name', {label: 'Game Name', required: true, value: game.name ?? ''})|raw }}
            </div>

            {{ form.field('slug', {value: game.slug ?? '', attrs: {placeholder: 'auto-generated from name'}})|raw }}
            {{ form.select('status', {value: game.status ?? 'draft', options: {draft: 'Draft', published: 'Published'}})|raw }}

            <div class="form-col-full">
                {{ form.field('description', {type: 'textarea', value: game.description ?? '', attrs: {rows: '4'}})|raw }}
            </div>

            {{ form.field('designer', {value: game.designer ?? ''})|raw }}
            {{ form.field('publisher', {value: game.publisher ?? ''})|raw }}
            {{ form.field('year_published', {type: 'number', label: 'Year Published', value: game.year_published ?? '', attrs: {min: '1900', max: '2100'}})|raw }}
            {{ form.field('bgg_id', {label: 'BGG ID', value: game.bgg_id ?? ''})|raw }}
            {{ form.field('min_players', {type: 'number', label: 'Min Players', value: game.min_players ?? '1', attrs: {min: '1', max: '20'}})|raw }}
            {{ form.field('max_players', {type: 'number', label: 'Max Players', value: game.max_players ?? '4', attrs: {min: '1', max: '100'}})|raw }}
            {{ form.field('play_time_minutes', {type: 'number', label: 'Play Time (min)', value: game.play_time_minutes ?? '', attrs: {min: '1'}})|raw }}
            {{ form.field('complexity', {type: 'number', label: 'Complexity (1.0–5.0)', value: game.complexity ?? '', attrs: {min: '1', max: '5', step: '0.1'}})|raw }}
            {{ form.field('teach_time_minutes', {type: 'number', label: 'Teach Time (min)', value: game.teach_time_minutes ?? '', attrs: {min: '1'}})|raw }}

            {# Cover image — manual, kept as-is for preview JS #}
            <div class="form-group form-col-full">
                <label for="{{ componentId }}-cover_image_file">Cover Image</label>
                {% if game is not null and (game.cover_image ?? '') != '' %}
                    <div class="current-cover" id="{{ componentId }}-cover-preview">
                        <img src="/uploads/{{ game.cover_image }}" alt="Current cover" class="cover-preview">
                    </div>
                    <input type="hidden" name="cover_image" value="{{ game.cover_image }}">
                {% endif %}
                <input type="file" id="{{ componentId }}-cover_image_file" name="cover_image_file" accept="image/*">
                <small class="form-help">Upload a new image to replace the current one.</small>
            </div>

            <div class="form-col-full">
                {{ form.field('tags', {label: 'Tags (comma-separated)', value: tagsStr, attrs: {placeholder: 'strategy, economic, worker-placement'}})|raw }}
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                {{ isEdit ? 'Update Game' : 'Create Game' }}
            </button>
            <a href="/admin/games" class="btn btn-secondary">Cancel</a>
        </div>
    </div>
</form>
```

Keep the `<script>` block at the bottom unchanged — the auto-slug and cover preview JS will still work because the `id` attributes on inputs are generated by the form builder following the `form-{name}` pattern. Update the JS to use the new IDs:

```javascript
var nameInput = document.getElementById('form-name');
var slugInput = document.getElementById('form-slug');
```

And for cover image:
```javascript
var fileInput = document.getElementById('form-cover_image_file');
```

Update the cover preview JS references accordingly (remove `prefix + '-'` pattern, use `'form-'` pattern).

- [ ] **Step 3: Verify in browser**

- Open `/admin/games/new` — form should render with all fields
- Submit empty form — validation errors should appear under each required field
- Fill in valid data, submit — game should be created
- Edit existing game — values should be pre-populated
- Cover image preview should still work
- Auto-slug generation should still work

- [ ] **Step 4: Commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add app/Components/Admin/GameForm/
git commit -m "refactor: migrate GameForm to preflow/form builder

Replaces ~100 lines of manual field HTML with form.field() calls.
Model binding, field-level error display, and old input preservation
handled by form builder. Cover image upload and auto-slug JS unchanged."
```

---

### Task 5: UserForm Migration

**Files:**
- Modify: `app/Components/Admin/UserForm/UserForm.twig`
- Modify: `app/Components/Admin/UserForm/UserForm.php`

Uses form-level rule overrides for create/update password handling instead of model scenarios (since `password` is not a model property — the model stores `password_hash`).

- [ ] **Step 1: Update UserForm.php**

Add `FormExtensionProvider` dependency and wire ErrorBag:

Add import:
```php
use Preflow\Form\FormExtensionProvider;
```

Update constructor:
```php
public function __construct(
    private readonly DataManager $data,
    private readonly ValidatorFactory $validator,
    private readonly FormExtensionProvider $formExt,
) {}
```

After each place that sets `$this->errors = new ErrorBag(...)` or `$this->errors = $e->errorBag()`, add:
```php
$this->formExt->setErrorBag($this->errors);
```

- [ ] **Step 2: Rewrite UserForm.twig**

Update CSS: change `.user-form .field-error` to `.user-form .form-error`, add `.has-error` styling, remove `.is-invalid` rules.

Replace form body:

```twig
{% set form = form_begin({
    errorBag: errors,
    rules: isEdit
        ? {password: ['nullable', 'min:8']}
        : {password: ['required', 'min:8']}
}) %}

<form class="user-form" id="{{ componentId }}-form"
    {{ hd.post('save', componentClass, componentId, props) | raw }}>

    <div class="card-body">
        <div class="form-grid">
            {{ form.field('username', {required: true, value: user.username ?? '', attrs: {autocomplete: 'off'}})|raw }}
            {{ form.field('email', {type: 'email', value: user.email ?? '', attrs: {autocomplete: 'off'}})|raw }}
            {{ form.field('password', {
                type: 'password',
                attrs: {
                    autocomplete: 'new-password',
                    placeholder: isEdit ? 'Leave blank to keep current' : 'Min. 8 characters'
                },
                help: isEdit ? 'Leave blank to keep the current password.' : null
            })|raw }}
            {{ form.select('role', {value: user.role ?? 'editor', options: {editor: 'Editor', admin: 'Admin'}})|raw }}
        </div>

        <div class="form-actions"
             style="display:flex;gap:0.75rem;align-items:center;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--accent-border);">
            <button type="submit" class="btn btn-primary">
                {{ isEdit ? 'Update User' : 'Create User' }}
            </button>
            <a href="/admin/users" class="btn btn-secondary">Cancel</a>
        </div>
    </div>
</form>
```

- [ ] **Step 3: Verify in browser**

- `/admin/users/new` — password required indicator shown, submit validates password
- Edit existing user — password not required, help text shown
- Username uniqueness check still works
- Role dropdown correctly shows current role

- [ ] **Step 4: Commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add app/Components/Admin/UserForm/
git commit -m "refactor: migrate UserForm to preflow/form builder

Uses form-level rule overrides for create/update password handling.
ErrorBag wired through FormExtensionProvider."
```

---

### Task 6: ContactForm Migration

**Files:**
- Modify: `app/Components/Public/ContactForm/ContactForm.twig`
- Modify: `app/Components/Public/ContactForm/ContactForm.php`
- Delete: `app/Controllers/ValidateFieldController.php`

The most complex migration — inline HTMX validation moves from controller to component action. Uses `hd.actionUrl()` (Task 2) for generating the tokenized validation endpoint URL.

- [ ] **Step 1: Add `actionValidateField()` to ContactForm.php**

Add `FormExtensionProvider` dependency and the new action:

Add import:
```php
use Preflow\Form\FormExtensionProvider;
use Preflow\Data\ModelMetadata;
```

Update constructor:
```php
public function __construct(
    private readonly DataManager $data,
    private readonly MailService $mailService,
    private readonly ValidatorFactory $validator,
    private readonly ValidationExtensionProvider $validationExt,
    private readonly FormExtensionProvider $formExt,
) {}
```

Update `actions()`:
```php
public function actions(): array
{
    return ['submit', 'validateField'];
}
```

Add the validation action:
```php
protected function actionValidateField(array $params): void
{
    $field = (string) ($params['field'] ?? '');
    $value = trim((string) ($params[$field] ?? ''));

    $meta = ModelMetadata::for(ContactMessage::class);
    $rules = $meta->validationRules[$field] ?? null;

    if ($rules === null) {
        return;
    }

    $result = $this->validator->make(
        [$field => $rules],
        [$field => $value],
    )->validate();

    $error = $result->firstError($field) ?? '';

    // Set a minimal ErrorBag so the field re-renders with error state
    if ($error !== '') {
        $errorBag = new ErrorBag($result);
        $this->errors = $errorBag;
        $this->formExt->setErrorBag($errorBag);
    } else {
        $this->errors = null;
    }

    $this->old = [$field => $value];
}
```

Also wire ErrorBag in `actionSubmit()` — after each place that sets `$this->errors`, add:
```php
$this->formExt->setErrorBag($this->errors);
```

- [ ] **Step 2: Rewrite ContactForm.twig**

Replace form fields with form builder calls. Keep honeypot as manual HTML. Use `hd.actionUrl()` for inline validation attributes:

```twig
{% if success %}
    <div class="page__flash page__flash--success">
        <i class="fa-solid fa-check-circle"></i>
        Thanks for your feedback! We will get back to you if needed.
    </div>
{% else %}
    {% if errors is not null and not errors.isEmpty %}
        <div class="page__flash page__flash--error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Please fix the errors below.
        </div>
    {% endif %}

    {% set form = form_begin({errorBag: errors}) %}
    {% set validateUrl = hd.actionUrl('validateField', componentClass) %}

    <form class="page__form"
        {{ hd.post('submit', componentClass, componentId) | raw }}
        hx-include="#{{ componentId }} form">

        {# Honeypot — hidden from humans, visible to bots #}
        <div aria-hidden="true" style="position: absolute; left: -9999px; top: -9999px;">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
        </div>

        {{ form.field('name', {
            label: 'Your name',
            value: old.name ?? '',
            attrs: {
                'hx-disinherit': '*',
                'hx-post': validateUrl,
                'hx-vals': '{"field": "name", "_csrf_token": "' ~ csrf_token() ~ '"}',
                'hx-include': '[name=name]',
                'hx-trigger': 'blur delay:300ms',
                'hx-target': '#cf-name-error',
                'hx-swap': 'innerHTML'
            }
        })|raw }}

        {{ form.field('email', {
            type: 'email',
            label: 'Your email',
            value: old.email ?? '',
            attrs: {
                'hx-disinherit': '*',
                'hx-post': validateUrl,
                'hx-vals': '{"field": "email", "_csrf_token": "' ~ csrf_token() ~ '"}',
                'hx-include': '[name=email]',
                'hx-trigger': 'blur delay:300ms',
                'hx-target': '#cf-email-error',
                'hx-swap': 'innerHTML'
            }
        })|raw }}

        {{ form.select('subject', {
            label: 'Subject',
            value: old.subject ?? 'feedback',
            options: {
                feedback: 'General feedback',
                bug: 'Bug report',
                'game-request': 'Game request',
                other: 'Other'
            }
        })|raw }}

        {{ form.field('message', {
            type: 'textarea',
            label: 'Message',
            value: old.message ?? '',
            attrs: {
                rows: '6',
                'hx-disinherit': '*',
                'hx-post': validateUrl,
                'hx-vals': '{"field": "message", "_csrf_token": "' ~ csrf_token() ~ '"}',
                'hx-include': '[name=message]',
                'hx-trigger': 'blur delay:300ms',
                'hx-target': '#cf-message-error',
                'hx-swap': 'innerHTML'
            }
        })|raw }}

        <button type="submit" class="form__submit">
            <i class="fa-solid fa-paper-plane"></i> Send feedback
        </button>
    </form>

    {% apply js %}
    document.addEventListener('htmx:afterSwap', function(e) {
        var target = e.detail.target;
        if (target && target.classList.contains('form-error')) {
            var group = target.closest('.form-group');
            if (group) {
                if (target.textContent.trim() !== '') {
                    group.classList.add('has-error');
                } else {
                    group.classList.remove('has-error');
                }
            }
        }
    });
    {% endapply %}
{% endif %}
```

Update CSS to target form builder classes: change `.form__group` to `.form-group`, `.form__label` to `.form-group label`, `.form__input` to `.form-group input, .form-group select, .form-group textarea`, `.field-error` to `.form-error`, etc.

Note: The inline validation HTMX targets (`#cf-name-error`, etc.) need to match the error display IDs in the rendered output. The form builder renders errors inside `<div class="form-error">` — we may need to adjust the `hx-target` to target the `.form-error` div inside the `.form-group`, or add IDs to the error divs. This may require a FieldRenderer adjustment or using the `closest .form-group` target pattern.

Actually, a simpler approach for inline validation targets: use `hx-target="closest .form-group"` and `hx-swap="outerHTML"` — this replaces the entire field group with the re-rendered version from the component. The component's `actionValidateField` sets the error state, the field re-renders with or without the error message.

This is cleaner and avoids the ID-matching problem. Update the HTMX attrs to:
```
'hx-target': 'closest .form-group',
'hx-swap': 'outerHTML'
```

And remove the `#cf-*-error` ID-based targeting.

- [ ] **Step 3: Delete ValidateFieldController**

```bash
rm app/Controllers/ValidateFieldController.php
```

- [ ] **Step 4: Verify in browser**

- Open the contact page
- Tab through fields (blur) — inline validation errors should appear for empty required fields
- Fill in valid data, submit — success message shown
- Fill in invalid email — inline error on blur
- Verify honeypot still works (won't be visible, test by inspecting)
- Verify rate limiting still works

- [ ] **Step 5: Commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add app/Components/Public/ContactForm/ \
       app/Controllers/ValidateFieldController.php
git commit -m "refactor: migrate ContactForm to preflow/form builder

Inline HTMX validation moved from ValidateFieldController to component
actionValidateField(). Uses hd.actionUrl() for tokenized endpoint URL.
Honeypot and rate limiting unchanged."
```

---

### Task 7: AssetDetail Migration (Metadata Form Only)

**Files:**
- Modify: `app/Components/Admin/AssetDetail/AssetDetail.twig`

Only migrate the metadata edit form (usage_type dropdown, ~14 lines). Leave file upload and image editor completely untouched.

- [ ] **Step 1: Replace metadata form**

Find the metadata form section (around lines 299-312) and replace:

Before:
```twig
<form {{ hd.post('updateMeta', componentClass, componentId, props) | raw }}>
    <div class="form-group">
        <label for="{{ componentId }}-usage">Usage type</label>
        <select id="{{ componentId }}-usage" name="usage_type"
                onchange="this.form.requestSubmit()">
            <option value=""{{ not asset.usage_type ? ' selected' : '' }}>None</option>
            <option value="cover"{{ asset.usage_type == 'cover' ? ' selected' : '' }}>Cover Image</option>
            <option value="step"{{ asset.usage_type == 'step' ? ' selected' : '' }}>Step Media</option>
            <option value="glossary"{{ asset.usage_type == 'glossary' ? ' selected' : '' }}>Glossary</option>
            <option value="other"{{ asset.usage_type == 'other' ? ' selected' : '' }}>Other</option>
        </select>
    </div>
</form>
```

After:
```twig
{% set metaForm = form_begin({}) %}
<form {{ hd.post('updateMeta', componentClass, componentId, props) | raw }}>
    {{ metaForm.select('usage_type', {
        label: 'Usage type',
        value: asset.usage_type ?? '',
        options: {'': 'None', cover: 'Cover Image', step: 'Step Media', glossary: 'Glossary', other: 'Other'},
        attrs: {onchange: 'this.form.requestSubmit()'}
    })|raw }}
</form>
```

- [ ] **Step 2: Verify in browser**

- Open an asset detail page
- Usage type dropdown should show current value
- Changing the dropdown should auto-submit and update
- File upload and image editor should be completely unaffected

- [ ] **Step 3: Commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add app/Components/Admin/AssetDetail/AssetDetail.twig
git commit -m "refactor: migrate AssetDetail metadata form to preflow/form builder

Only the usage_type select form migrated. File upload and image editor
JS remain as manual HTML — tests coexistence of form builder with
non-migrated forms in the same template."
```

---

### Task 8: Final Verification & Gap Documentation

**Files:** None (verification only)

- [ ] **Step 1: Full walkthrough**

Test every migrated form end-to-end:

1. **Login** — `/admin/login` — login with valid/invalid credentials
2. **Forgot Password** — `/admin/forgot-password` — submit email
3. **Reset Password** — `/admin/reset-password` — submit with code
4. **GameForm Create** — `/admin/games/new` — create game with validation errors, then valid data
5. **GameForm Edit** — `/admin/games/{slug}/edit` — edit existing game, verify values populated
6. **UserForm Create** — `/admin/users/new` — create user, password required
7. **UserForm Edit** — `/admin/users/{id}/edit` — edit user, password optional, verify help text
8. **ContactForm** — public contact page — inline validation on blur, full submit, success message
9. **AssetDetail** — `/admin/assets/{id}` — usage type dropdown, file upload still works

- [ ] **Step 2: Document findings**

Create a findings document noting:
- Any framework gaps discovered (CSS class customization, inline validation wiring, etc.)
- What worked well
- What needed workarounds
- Recommendations for framework improvements

- [ ] **Step 3: Final commit**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add -A
git commit -m "docs: form package stress test complete — findings documented"
```
