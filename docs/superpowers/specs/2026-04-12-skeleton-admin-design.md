# Skeleton Admin Demo — Design Spec

**Date:** 2026-04-12
**Status:** Approved
**Package affected:** `preflow/skeleton`

## Overview

Add a working blog admin behind authentication to the skeleton demo. Seeded admin user, full CRUD for posts, form component with co-located CSS, and header auth indicator. Showcases auth, forms, models, components, and the controller + component hybrid pattern.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Form architecture | Hybrid: component for UI, controller for CRUD | Demonstrates both patterns working together |
| Admin credentials | admin@preflow.dev / password | Zero friction for demo evaluation |
| CRUD scope | Full: list, create, edit, delete | Shows every pattern without guessing |
| Auth UI location | Header utility bar, separate from Navigation | Keeps Navigation generic, allows future growth (badges, notifications) |

---

## 1. Seeded Admin User

New `UserSeeder` in `app/Seeds/` (separate from PostSeeder):

- **Email:** `admin@preflow.dev`
- **Password:** `password` (hashed via `NativePasswordHasher`)
- **Roles:** `['admin']`
- **UUID:** `user-admin`

Runs during `php preflow db:seed` as part of the post-create-project flow.

---

## 2. Blog Admin Controller

`app/Controllers/BlogAdminController.php` with `#[Route('/admin')]` and `#[Middleware(AuthMiddleware::class)]`.

| Method | Path | Action |
|--------|------|--------|
| `GET` | `/admin` | List all posts with edit/delete links |
| `GET` | `/admin/create` | Empty PostForm component |
| `GET` | `/admin/edit/{uuid}` | Pre-filled PostForm component |
| `POST` | `/admin/save` | Create or update post |
| `POST` | `/admin/delete/{uuid}` | Delete post, redirect to list |

**Constructor dependencies:** `DataManager`, `TemplateEngineInterface`

**Save flow:** Read parsed body → validate title + body required → generate slug from title (for new posts) → generate UUID (for new posts) → save via DataManager → flash success → redirect to `/admin`.

**Delete flow:** Delete via DataManager → flash success → redirect to `/admin`.

Slug generation: `strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title))`, trimmed of leading/trailing dashes.

---

## 3. PostForm Component

`app/Components/PostForm/` — form UI component with co-located CSS.

**PHP class:** No constructor dependencies. `resolveState()` reads from props: `uuid` (empty string for create), `title`, `slug`, `body`, `status` (defaults to `'draft'`), `action` (form action URL).

**Template:** Styled form with:
- Hidden `uuid` field (only for edit)
- Title input
- Slug input
- Body textarea
- Status select (draft / published)
- CSRF token via `{{ csrf_token()|raw }}`
- Submit button ("Create Post" or "Update Post" based on uuid)
- Co-located CSS via `{% apply css %}`

Form POSTs to `/admin/save`.

---

## 4. Admin Templates

File-based templates in `app/pages/admin/` rendered by the controller (attribute routes take priority over file-based routes, no conflict).

**`admin/index.twig`** — Extends `_layout.twig`. Table of posts: title, status badge, edit link, delete form with CSRF. "New Post" button linking to `/admin/create`.

**`admin/form.twig`** — Extends `_layout.twig`. Renders `{{ component('PostForm', { ... }) }}` with post data passed as props. Heading: "New Post" or "Edit Post" based on whether uuid is present.

---

## 5. Header Auth Indicator

In `_layout.twig`, a `.header-actions` div in the header groups LocaleSwitcher + auth UI:

- **Authenticated:** "Admin" link to `/admin` + email display + logout form (POST with CSRF)
- **Guest:** "Login" link to `/login`

Compact inline styling — email in muted text, small logout button.

---

## 6. Testing

- PostForm component: `resolveState()` with empty props (create mode) and filled props (edit mode)
- Slug generation if extracted as a helper function
- Light coverage — this is skeleton demo code; the underlying framework features (auth, DataManager, CSRF) are already tested
