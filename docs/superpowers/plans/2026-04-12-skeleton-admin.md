# Skeleton Admin Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a working blog admin with full CRUD behind auth to the Preflow skeleton, including seeded admin user and header auth indicator.

**Architecture:** Hybrid pattern — PostForm component for form UI (co-located CSS/template), BlogAdminController for CRUD logic (POST handling, redirects, flash). Two small infrastructure fixes: route params on request, asset injection helper for controller-rendered pages.

**Tech Stack:** PHP 8.4+, Twig, PHPUnit 11

**Design spec:** `docs/superpowers/specs/2026-04-12-skeleton-admin-design.md`

**Infrastructure fixes (discovered during planning):**
- `Application::ensureActionDispatcher()` doesn't attach route parameters to the request — controllers can't read `{uuid}` from `/admin/edit/{uuid}`. Fix: loop `$route->parameters` onto request attributes before dispatching.
- Controllers that render templates need asset injection (CSS/JS from components). The component renderer path handles this automatically, but controller-rendered pages bypass it. Fix: small `renderPage()` helper in the controller that renders + injects assets.

---

## File Structure

### New files

```
packages/skeleton/app/Seeds/UserSeeder.php
packages/skeleton/app/Components/PostForm/PostForm.php
packages/skeleton/app/Components/PostForm/PostForm.twig
packages/skeleton/app/Controllers/BlogAdminController.php
packages/skeleton/app/pages/admin/index.twig
packages/skeleton/app/pages/admin/form.twig
packages/skeleton/tests/PostFormTest.php
```

### Modified files

```
packages/core/src/Application.php                    — attach route params in action dispatcher
packages/skeleton/app/pages/_layout.twig             — add header auth indicator
packages/skeleton/README.md                          — document admin and default credentials
```

---

### Task 1: Route params fix + UserSeeder

**Files:**
- Modify: `packages/core/src/Application.php`
- Create: `packages/skeleton/app/Seeds/UserSeeder.php`

- [ ] **Step 1: Fix action dispatcher to attach route params**

In `packages/core/src/Application.php`, find `ensureActionDispatcher()`. The current code:

```php
$this->actionDispatcher = function (Route $route, ServerRequestInterface $request) use ($container): ResponseInterface {
    [$class, $method] = explode('@', $route->handler);

    $controller = $container->has($class) ? $container->get($class) : new $class();
    $response = $controller->{$method}($request);
```

Add route params to request before dispatching. Replace with:

```php
$this->actionDispatcher = function (Route $route, ServerRequestInterface $request) use ($container): ResponseInterface {
    [$class, $method] = explode('@', $route->handler);

    // Attach route parameters as request attributes
    foreach ($route->parameters as $name => $value) {
        $request = $request->withAttribute($name, $value);
    }

    $controller = $container->has($class) ? $container->get($class) : new $class();
    $response = $controller->{$method}($request);
```

- [ ] **Step 2: Create UserSeeder**

Create `packages/skeleton/app/Seeds/UserSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Seeds;

use App\Models\User;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Data\DataManager;

final class UserSeeder
{
    public function run(DataManager $data): void
    {
        $hasher = new NativePasswordHasher();

        $user = new User();
        $user->uuid = 'user-admin';
        $user->email = 'admin@preflow.dev';
        $user->passwordHash = $hasher->hash('password');
        $user->roles = ['admin'];
        $user->createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $data->save($user);
    }
}
```

- [ ] **Step 3: Run tests to verify no regressions**

Run: `./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add packages/core/src/Application.php packages/skeleton/app/Seeds/UserSeeder.php
git commit -m "feat: attach route params to request in action dispatcher + add UserSeeder"
```

---

### Task 2: PostForm component

**Files:**
- Create: `packages/skeleton/app/Components/PostForm/PostForm.php`
- Create: `packages/skeleton/app/Components/PostForm/PostForm.twig`
- Create: `packages/skeleton/tests/PostFormTest.php`

- [ ] **Step 1: Write PostForm test**

Create `packages/skeleton/tests/PostFormTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Components\PostForm\PostForm;

final class PostFormTest extends TestCase
{
    public function test_create_mode_has_empty_fields(): void
    {
        $form = new PostForm();
        $form->setProps(['action' => '/admin/save']);
        $form->resolveState();

        $this->assertSame('', $form->uuid);
        $this->assertSame('', $form->title);
        $this->assertSame('', $form->slug);
        $this->assertSame('', $form->body);
        $this->assertSame('draft', $form->status);
        $this->assertSame('/admin/save', $form->action);
        $this->assertFalse($form->isEdit);
    }

    public function test_edit_mode_populates_fields(): void
    {
        $form = new PostForm();
        $form->setProps([
            'action' => '/admin/save',
            'uuid' => 'post-1',
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'body' => 'Post body here.',
            'status' => 'published',
        ]);
        $form->resolveState();

        $this->assertSame('post-1', $form->uuid);
        $this->assertSame('Hello World', $form->title);
        $this->assertSame('hello-world', $form->slug);
        $this->assertSame('Post body here.', $form->body);
        $this->assertSame('published', $form->status);
        $this->assertTrue($form->isEdit);
    }
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `./vendor/bin/phpunit packages/skeleton/tests/PostFormTest.php`
Expected: FAIL — `PostForm` class not found.

- [ ] **Step 3: Implement PostForm PHP class**

Create `packages/skeleton/app/Components/PostForm/PostForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Components\PostForm;

use Preflow\Components\Component;

final class PostForm extends Component
{
    public string $uuid = '';
    public string $title = '';
    public string $slug = '';
    public string $body = '';
    public string $status = 'draft';
    public string $action = '/admin/save';
    public bool $isEdit = false;

    public function resolveState(): void
    {
        $this->uuid = (string) ($this->props['uuid'] ?? '');
        $this->title = (string) ($this->props['title'] ?? '');
        $this->slug = (string) ($this->props['slug'] ?? '');
        $this->body = (string) ($this->props['body'] ?? '');
        $this->status = (string) ($this->props['status'] ?? 'draft');
        $this->action = (string) ($this->props['action'] ?? '/admin/save');
        $this->isEdit = $this->uuid !== '';
    }
}
```

- [ ] **Step 4: Run test — expect pass**

Run: `./vendor/bin/phpunit packages/skeleton/tests/PostFormTest.php`
Expected: 2 tests, all PASS.

- [ ] **Step 5: Create PostForm template**

Create `packages/skeleton/app/Components/PostForm/PostForm.twig`:

```twig
<form method="post" action="{{ action }}" class="post-form">
    {{ csrf_token()|raw }}

    {% if isEdit %}
        <input type="hidden" name="uuid" value="{{ uuid }}">
    {% endif %}

    <div class="form-group">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="{{ title }}" required
               class="form-input" placeholder="Post title">
    </div>

    <div class="form-group">
        <label for="slug">Slug</label>
        <input type="text" id="slug" name="slug" value="{{ slug }}"
               class="form-input" placeholder="auto-generated-from-title">
    </div>

    <div class="form-group">
        <label for="body">Body</label>
        <textarea id="body" name="body" rows="10" required
                  class="form-input form-textarea" placeholder="Write your post...">{{ body }}</textarea>
    </div>

    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="form-input">
            <option value="draft"{{ status == 'draft' ? ' selected' : '' }}>Draft</option>
            <option value="published"{{ status == 'published' ? ' selected' : '' }}>Published</option>
        </select>
    </div>

    <button type="submit" class="form-submit">
        {{ isEdit ? 'Update Post' : 'Create Post' }}
    </button>
</form>

{% apply css %}
.post-form {
    max-width: 600px;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.25rem;
    font-size: 0.875rem;
    color: #333;
}

.form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    font-size: 0.9375rem;
    font-family: inherit;
    color: #333;
    background: #fff;
    transition: border-color 0.15s;
}

.form-input:focus {
    outline: none;
    border-color: #0066ff;
    box-shadow: 0 0 0 2px rgba(0, 102, 255, 0.15);
}

.form-textarea {
    resize: vertical;
    min-height: 200px;
}

.form-submit {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 0.25rem;
    background: #0066ff;
    color: white;
    font-size: 0.9375rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}

.form-submit:hover {
    background: #0052cc;
}
{% endapply %}
```

- [ ] **Step 6: Commit**

```bash
git add packages/skeleton/app/Components/PostForm/ packages/skeleton/tests/PostFormTest.php
git commit -m "feat(skeleton): add PostForm component with co-located form styles"
```

---

### Task 3: BlogAdminController + admin templates

**Files:**
- Create: `packages/skeleton/app/Controllers/BlogAdminController.php`
- Create: `packages/skeleton/app/pages/admin/index.twig`
- Create: `packages/skeleton/app/pages/admin/form.twig`

- [ ] **Step 1: Create BlogAdminController**

Create `packages/skeleton/app/Controllers/BlogAdminController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Post;
use Nyholm\Psr7\Response;
use Preflow\Auth\Http\AuthMiddleware;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\SortDirection;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Post as HttpPost;
use Preflow\Routing\Attributes\Route;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/admin')]
#[Middleware(AuthMiddleware::class)]
final class BlogAdminController
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly AssetCollector $assets,
    ) {}

    #[Get('/')]
    public function index(ServerRequestInterface $request): Response
    {
        $posts = $this->dm->query(Post::class)
            ->orderBy('uuid', SortDirection::Desc)
            ->get();

        return $this->renderPage('admin/index.twig', [
            'posts' => $posts->items(),
        ]);
    }

    #[Get('/create')]
    public function create(ServerRequestInterface $request): Response
    {
        return $this->renderPage('admin/form.twig', [
            'post' => null,
            'pageTitle' => 'New Post',
        ]);
    }

    #[Get('/edit/{uuid}')]
    public function edit(ServerRequestInterface $request): Response
    {
        $uuid = $request->getAttribute('uuid');
        $post = $this->dm->find(Post::class, $uuid);

        if ($post === null) {
            return new Response(302, ['Location' => '/admin']);
        }

        return $this->renderPage('admin/form.twig', [
            'post' => $post,
            'pageTitle' => 'Edit Post',
        ]);
    }

    #[HttpPost('/save')]
    public function save(ServerRequestInterface $request): Response
    {
        $body = (array) $request->getParsedBody();
        $session = $request->getAttribute(SessionInterface::class);

        $title = trim((string) ($body['title'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        $postBody = trim((string) ($body['body'] ?? ''));
        $status = (string) ($body['status'] ?? 'draft');
        $uuid = (string) ($body['uuid'] ?? '');

        if ($title === '' || $postBody === '') {
            $session?->flash('error', 'Title and body are required.');
            return new Response(302, ['Location' => $uuid !== '' ? '/admin/edit/' . $uuid : '/admin/create']);
        }

        if ($slug === '') {
            $slug = $this->slugify($title);
        }

        if ($uuid !== '') {
            // Update existing
            $post = $this->dm->find(Post::class, $uuid);
            if ($post === null) {
                return new Response(302, ['Location' => '/admin']);
            }
        } else {
            // Create new
            $post = new Post();
            $post->uuid = $this->generateUuid();
            $post->created_at = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        $post->title = $title;
        $post->slug = $slug;
        $post->body = $postBody;
        $post->status = $status;
        $post->updated_at = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->dm->save($post);

        $session?->flash('success', $uuid !== '' ? 'Post updated.' : 'Post created.');
        return new Response(302, ['Location' => '/admin']);
    }

    #[HttpPost('/delete/{uuid}')]
    public function delete(ServerRequestInterface $request): Response
    {
        $uuid = $request->getAttribute('uuid');
        $session = $request->getAttribute(SessionInterface::class);

        $this->dm->delete(Post::class, $uuid);

        $session?->flash('success', 'Post deleted.');
        return new Response(302, ['Location' => '/admin']);
    }

    private function renderPage(string $template, array $context = []): Response
    {
        $html = $this->engine->render($template, $context);
        $head = $this->assets->renderHead();
        $body = $this->assets->renderAssets();
        if ($head !== '') {
            $html = str_replace('</head>', $head . '</head>', $html);
        }
        if ($body !== '') {
            $html = str_replace('</body>', $body . '</body>', $html);
        }
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
        return trim($slug, '-');
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

Note: `Post` attribute is aliased as `HttpPost` to avoid conflict with `App\Models\Post`.

- [ ] **Step 2: Create admin index template**

Create `packages/skeleton/app/pages/admin/index.twig`:

```twig
{% extends "_layout.twig" %}

{% block title %}Admin — Posts{% endblock %}

{% block content %}
{% apply css %}
.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.admin-header h1 {
    font-size: 1.5rem;
}

.btn {
    display: inline-block;
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 0.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.15s;
}

.btn-primary {
    background: #0066ff;
    color: white;
}

.btn-primary:hover {
    background: #0052cc;
}

.btn-danger {
    background: #dc3545;
    color: white;
    font-size: 0.8125rem;
    padding: 0.25rem 0.75rem;
}

.btn-danger:hover {
    background: #c82333;
}

.post-table {
    width: 100%;
    border-collapse: collapse;
}

.post-table th,
.post-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.post-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: #999;
    font-weight: 600;
}

.post-table td a {
    color: #0066ff;
    text-decoration: none;
}

.post-table td a:hover {
    text-decoration: underline;
}

.status-badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-published {
    background: #d4edda;
    color: #155724;
}

.status-draft {
    background: #fff3cd;
    color: #856404;
}

.flash-success {
    padding: 0.75rem 1rem;
    background: #d4edda;
    color: #155724;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
}

.flash-error {
    padding: 0.75rem 1rem;
    background: #f8d7da;
    color: #721c24;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
}

.delete-form {
    display: inline;
}

.actions-cell {
    white-space: nowrap;
}

.actions-cell a {
    margin-right: 0.5rem;
}
{% endapply %}

{% set success = flash('success') %}
{% set error = flash('error') %}

{% if success %}
    <div class="flash-success">{{ success }}</div>
{% endif %}

{% if error %}
    <div class="flash-error">{{ error }}</div>
{% endif %}

<div class="admin-header">
    <h1>Posts</h1>
    <a href="/admin/create" class="btn btn-primary">New Post</a>
</div>

<table class="post-table">
    <thead>
        <tr>
            <th>Title</th>
            <th>Slug</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        {% for post in posts %}
            <tr>
                <td><a href="/admin/edit/{{ post.uuid }}">{{ post.title }}</a></td>
                <td>{{ post.slug }}</td>
                <td>
                    <span class="status-badge status-{{ post.status }}">{{ post.status }}</span>
                </td>
                <td class="actions-cell">
                    <a href="/admin/edit/{{ post.uuid }}">Edit</a>
                    <form method="post" action="/admin/delete/{{ post.uuid }}" class="delete-form"
                          onsubmit="return confirm('Delete this post?')">
                        {{ csrf_token()|raw }}
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        {% else %}
            <tr>
                <td colspan="4" style="text-align: center; color: #999; padding: 2rem;">
                    No posts yet. <a href="/admin/create">Create one.</a>
                </td>
            </tr>
        {% endfor %}
    </tbody>
</table>
{% endblock %}
```

- [ ] **Step 3: Create admin form template**

Create `packages/skeleton/app/pages/admin/form.twig`:

```twig
{% extends "_layout.twig" %}

{% block title %}{{ pageTitle }} — Admin{% endblock %}

{% block content %}
{% apply css %}
.admin-form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.admin-form-header h1 {
    font-size: 1.5rem;
}

.back-link {
    color: #0066ff;
    text-decoration: none;
    font-size: 0.875rem;
}

.back-link:hover {
    text-decoration: underline;
}
{% endapply %}

{% set error = flash('error') %}
{% if error %}
    <div class="flash-error">{{ error }}</div>
{% endif %}

<div class="admin-form-header">
    <h1>{{ pageTitle }}</h1>
    <a href="/admin" class="back-link">Back to posts</a>
</div>

{{ component('PostForm', {
    action: '/admin/save',
    uuid: post ? post.uuid : '',
    title: post ? post.title : '',
    slug: post ? post.slug : '',
    body: post ? post.body : '',
    status: post ? post.status : 'draft',
}) }}
{% endblock %}
```

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/skeleton/app/Controllers/BlogAdminController.php packages/skeleton/app/pages/admin/
git commit -m "feat(skeleton): add BlogAdminController with full CRUD and admin templates"
```

---

### Task 4: Header auth indicator + layout update

**Files:**
- Modify: `packages/skeleton/app/pages/_layout.twig`

- [ ] **Step 1: Update layout with auth indicator**

Replace `packages/skeleton/app/pages/_layout.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Preflow App{% endblock %}</title>
</head>
<body>
    <header class="main-header">
        {{ component('Navigation', {
            brand: 'Preflow',
            items: [
                { path: '/', label: 'app.nav.home' },
                { path: '/blog', label: 'app.nav.blog' },
                { path: '/about', label: 'app.nav.about' },
            ]
        }) }}
        <div class="header-actions">
            {{ component('LocaleSwitcher', { locales: ['en', 'de'] }) }}
            {% if auth_check() %}
                <a href="/admin" class="header-admin-link">Admin</a>
                <span class="header-user">{{ auth_user().email }}</span>
                <form method="post" action="/logout" class="header-logout-form">
                    {{ csrf_token()|raw }}
                    <button type="submit" class="header-logout-btn">Logout</button>
                </form>
            {% else %}
                <a href="/login" class="header-login-link">Login</a>
            {% endif %}
        </div>
    </header>

    <main class="main-content">
        {% block content %}{% endblock %}
    </main>

    <footer class="main-footer">
        {{ t('app.footer') }}
    </footer>
</body>
</html>

{% apply css %}
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: system-ui, -apple-system, sans-serif;
    color: #333;
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.main-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-right: 1rem;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.main-header nav {
    border-bottom: none;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.8125rem;
}

.header-admin-link {
    color: #0066ff;
    text-decoration: none;
    font-weight: 600;
}

.header-admin-link:hover {
    text-decoration: underline;
}

.header-user {
    color: #999;
}

.header-logout-form {
    display: inline;
}

.header-logout-btn {
    background: none;
    border: none;
    color: #dc3545;
    cursor: pointer;
    font-size: 0.8125rem;
    padding: 0;
}

.header-logout-btn:hover {
    text-decoration: underline;
}

.header-login-link {
    color: #0066ff;
    text-decoration: none;
    font-weight: 600;
}

.header-login-link:hover {
    text-decoration: underline;
}

.main-content {
    flex: 1;
    padding: 2rem;
    max-width: 800px;
    width: 100%;
    margin: 0 auto;
}

.main-footer {
    padding: 1.5rem 2rem;
    text-align: center;
    color: #999;
    font-size: 0.875rem;
    border-top: 1px solid #eee;
}
{% endapply %}
```

- [ ] **Step 2: Commit**

```bash
git add packages/skeleton/app/pages/_layout.twig
git commit -m "feat(skeleton): add auth indicator and admin link to header"
```

---

### Task 5: README update + final verification

**Files:**
- Modify: `packages/skeleton/README.md`

- [ ] **Step 1: Add admin section to README**

In `packages/skeleton/README.md`, add after the "### Authentication" section:

```markdown
### Blog Admin

The skeleton includes a full blog admin at `/admin` (requires login). Create, edit, and delete posts through a form-based interface.

Default admin credentials:

- **Email:** admin@preflow.dev
- **Password:** password

The admin demonstrates the hybrid pattern: `PostForm` component handles form UI with co-located CSS, `BlogAdminController` handles CRUD logic with redirects and flash messages.
```

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass (including new PostFormTest). No regressions.

- [ ] **Step 3: Commit**

```bash
git add packages/skeleton/README.md
git commit -m "docs(skeleton): document blog admin and default credentials"
```

---

## Implementation Notes

### Post model field names

The existing Post model uses `created_at` and `updated_at` (snake_case) while User model uses `createdAt` (camelCase). The BlogAdminController must use the correct field names matching the Post model — check `packages/skeleton/app/Models/Post.php` for exact property names.

### Asset injection in controllers

The `renderPage()` helper in BlogAdminController replicates the asset injection logic from `Application::injectAssets()`. This is necessary because controller-rendered pages bypass the component renderer path. If the framework later adds a `renderView()` helper on the Application or a ViewResponse class, the controller can be simplified.

### Template paths

The Twig engine is initialized with `app/pages/` as its template directory. Templates at `app/pages/admin/index.twig` are referenced as `admin/index.twig` in `$engine->render()` calls. The `{% extends "_layout.twig" %}` works because `_layout.twig` is in the same `app/pages/` root.

### Post attribute alias

In BlogAdminController, `Preflow\Routing\Attributes\Post` conflicts with `App\Models\Post`. The routing attribute is imported as `HttpPost`:
```php
use Preflow\Routing\Attributes\Post as HttpPost;
```
