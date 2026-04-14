# BGGenius → Preflow Migration Design Spec

**Date:** 2026-04-14
**Status:** Approved
**Goal:** Migrate BGGenius (Slim 4 board game teaching tool) to Preflow, converting the JS-driven player to server-rendered HTMX components. This serves as the first real stress test of the Preflow framework.

---

## 1. Project Overview

**BGGenius** is an interactive board game teaching tool. Players pick a game, walk through a layered tutorial (text, quizzes, drag-and-drop, comparisons, flowcharts), and track their progress. A full admin panel manages games, teaching flows (JSON), users, images, contact messages, and feedback.

**Current stack:** Slim 4, Twig, SQLite, vanilla JS (ES modules, Redux-like state), League Glide, PHPMailer

**Target stack:** Preflow (all packages), Twig, SQLite, HTMX (server-driven), League Glide, Symfony Mailer, league/commonmark

**New project location:** `/Users/smyr/Sites/gbits/bggenius-preflow/`

**Source project:** `/Users/smyr/Sites/gbits/bggenius/`

---

## 2. Core Paradigm Shift

### From JS-driven to server-driven

**Before:**
```
GET /play/{slug} → Server sends empty shell HTML
  → JS fetches /api/games/{slug}/teach → receives full JSON
  → JS manages state (Redux store), renders steps client-side
  → JS saves progress via API every 30s
```

**After:**
```
GET /play/{slug} → Server renders full page with current step in HTML
  → User clicks Next → HTMX POST to PlayerShell action "next"
  → Server updates progress in DB, renders next step → HTMX swaps step content area only
  → User answers quiz → HTMX POST action "answer" → Server validates, re-renders with feedback
```

### What moves server-side

- **State management** — current layer, step, completed steps, quiz answers all live in `UserProgress` DB record. Every navigation action persists automatically. No debounced saves, no localStorage state.
- **Step rendering** — text, quiz, reveal, comparison steps are pure server-rendered HTML. No JS needed.
- **Player identity** — long-lived cookie (1 year) replaces localStorage UUID. Component reads it from request.
- **Markdown parsing** — `league/commonmark` with custom `{{glossary:key}}` extension replaces client-side parser.
- **Glossary tooltips** — CSS-only tooltips (like Preflow website's Tooltip component) replace JS tooltips.

### JS that remains (co-located in components)

| Component | JS Purpose | External Library |
|---|---|---|
| `InteractiveStep` | Drag-and-drop interaction, submit result via HTMX | drag-drop lib (from existing project) |
| `FlowchartStep` | Initialize Mermaid.js on rendered markup | Mermaid.js (CDN) |
| `ThemeToggle` | Flip `data-theme` attribute, store in cookie | None |
| `FlowEditor` (admin) | Full JS editor app (preserved as-is) | Standalone in `public/js/admin/` |

External libraries loaded via `AssetCollector::addHeadTag()`. Component-specific JS that uses those libraries lives co-located in `{% apply js %}` blocks.

### JS eliminated

`state.js`, `router.js`, `renderer.js`, `api.js`, `player.js`, `steps/text.js`, `steps/quiz.js`, `steps/reveal.js`, `steps/comparison.js`, `components/progress.js`, `components/timer.js`, `components/transition.js`, `lib/markdown.js`

---

## 3. Project Structure

```
bggenius-preflow/
├── app/
│   ├── Components/
│   │   ├── Player/
│   │   │   ├── PlayerShell/          # Orchestrator: loads flow, renders chrome + current step
│   │   │   ├── TextStep/             # Markdown blocks, callouts, media, layouts
│   │   │   ├── QuizStep/             # MC/TF/select-many, answer validation, feedback
│   │   │   ├── InteractiveStep/      # Drag-drop wrapper (co-located JS)
│   │   │   ├── RevealStep/           # <details>/<summary> accordion
│   │   │   ├── ComparisonStep/       # Side-by-side grid
│   │   │   ├── FlowchartStep/        # Mermaid markup (co-located JS init)
│   │   │   ├── ProgressBar/          # Layer segments visualization
│   │   │   ├── GlossaryPanel/        # Searchable glossary (HTMX search action)
│   │   │   ├── StepNavigation/       # Prev/next/branch buttons
│   │   │   └── CompletionScreen/     # Stats + dice rating + feedback form
│   │   ├── Public/
│   │   │   ├── GameGrid/             # Game cards with search/filter (HTMX)
│   │   │   ├── GameCard/             # Single game card
│   │   │   ├── ContactForm/          # Honeypot + rate limit + email
│   │   │   └── ThemeToggle/          # Dark/light CSS toggle (co-located JS)
│   │   ├── Admin/
│   │   │   ├── Dashboard/            # Stats overview
│   │   │   ├── GameList/             # Table with HTMX delete/filter
│   │   │   ├── GameForm/             # Create/edit game
│   │   │   ├── FlowEditor/           # Wraps existing JS editor
│   │   │   ├── UserList/
│   │   │   ├── UserForm/
│   │   │   ├── ContactList/
│   │   │   ├── ContactDetail/
│   │   │   ├── FeedbackList/
│   │   │   ├── AssetManager/
│   │   │   ├── Settings/
│   │   │   └── LoginForm/
│   │   └── Shared/
│   │       ├── Navigation/           # Public + admin nav variants
│   │       ├── Header/
│   │       ├── Footer/
│   │       ├── FlashMessage/         # Session flash display
│   │       └── Pagination/           # Reusable pagination
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── AuthController.php    # POST login/logout, GET+POST forgot/reset password
│   │   │   ├── FlowApiController.php # PUT flow save, POST flow validate (JSON API for JS editor)
│   │   │   └── UploadController.php  # POST file upload
│   │   └── ImageController.php       # GET /img/{preset}/{path} (League Glide)
│   ├── Models/
│   │   ├── Game.php
│   │   ├── User.php
│   │   ├── TeachingFlow.php
│   │   ├── UserProgress.php
│   │   ├── Feedback.php
│   │   ├── ContactMessage.php
│   │   ├── Asset.php
│   │   └── FlowRevision.php
│   ├── Services/
│   │   ├── FlowService.php           # Load, validate, stats for teaching flows
│   │   ├── ProgressService.php        # Merge completions, quiz answers, calculate %
│   │   ├── ImageService.php           # League Glide wrapper with presets
│   │   └── MailService.php            # Symfony Mailer wrapper
│   ├── Middleware/
│   │   ├── AdminAuthMiddleware.php    # Path-based /admin/* protection
│   │   ├── AdminRoleMiddleware.php    # admin-role-only restriction
│   │   └── CorsMiddleware.php         # CORS headers (if API endpoints retained)
│   └── Providers/
│       └── AppServiceProvider.php     # Registers services, image presets, template functions
│   
├── app/pages/                         # File-based routes (Component mode)
│   ├── index.twig                     # GET /
│   ├── about.twig                     # GET /about
│   ├── contact.twig                   # GET /contact
│   ├── play/
│   │   └── [slug].twig               # GET /play/{slug}
│   └── admin/
│       ├── index.twig                 # GET /admin
│       ├── games.twig                 # GET /admin/games
│       ├── games/
│       │   ├── create.twig            # GET /admin/games/create
│       │   ├── [slug].twig            # GET /admin/games/{slug}
│       │   └── [slug]/
│       │       └── flow.twig          # GET /admin/games/{slug}/flow
│       ├── users.twig                 # GET /admin/users
│       ├── users/
│       │   ├── create.twig            # GET /admin/users/create
│       │   └── [id].twig             # GET /admin/users/{id}
│       ├── contact.twig               # GET /admin/contact
│       ├── contact/
│       │   └── [id].twig             # GET /admin/contact/{id}
│       ├── feedback.twig              # GET /admin/feedback
│       ├── assets.twig                # GET /admin/assets
│       └── settings.twig              # GET /admin/settings
│
├── config/
│   ├── app.php                        # Debug, locale, template engine, app key
│   ├── auth.php                       # Session config, guards
│   ├── data.php                       # SQLite driver config
│   ├── i18n.php                       # Locales (en default)
│   └── providers.php                  # [AppServiceProvider::class]
│
├── database/
│   ├── schema.sql                     # Full schema (carried from original)
│   └── bggenius.sqlite                # SQLite database file
│
├── migrations/                        # Preflow migrations (only for new tables if any)
│
├── content/                           # Teaching flow JSON files
│   └── *.json
│
├── public/
│   ├── index.php                      # 3-line Preflow entry point
│   ├── .htaccess
│   ├── uploads/                       # User-uploaded images
│   │   └── games/{slug}/
│   └── js/
│       └── admin/                     # Flow editor JS (preserved as-is)
│           └── flow-editor.js
│
├── storage/
│   ├── cache/
│   │   ├── routes.php
│   │   ├── flows/                     # Cached flow JSON
│   │   └── images/                    # Glide image cache
│   └── logs/
│
├── templates/                         # Twig layouts (underscore-prefixed = not routes)
│   ├── _base.twig                     # Public master layout
│   └── _admin.twig                    # Admin master layout (sidebar, flash, user info)
│
├── tests/
│   ├── PlayerNavigationTest.php
│   ├── QuizAnswerTest.php
│   ├── AdminAuthTest.php
│   ├── GameCrudTest.php
│   └── ProgressPersistenceTest.php
│
├── bin/
│   └── install.php                    # DB setup + initial admin user
│
├── composer.json
├── .env
└── .gitignore
```

---

## 4. Routing

### File-based routes (Component mode — GET only)

All pages that render components. See the `app/pages/` tree in section 3.

### Attribute routes (Action mode)

```php
#[Route('/admin')]
class AuthController {
    #[Get('/login')]       public function loginForm()
    #[Post('/login')]      public function login()
    #[Post('/logout')]     public function logout()
    #[Get('/forgot-password')]  public function forgotForm()
    #[Post('/forgot-password')] public function forgot()
    #[Get('/reset-password')]   public function resetForm()
    #[Post('/reset-password')]  public function reset()
}

#[Route('/admin/games', middleware: [AdminAuthMiddleware::class])]
class FlowApiController {
    #[Put('/{slug}/flow')]           public function save()
    #[Post('/{slug}/flow/validate')] public function validate()
}

#[Route('/admin', middleware: [AdminAuthMiddleware::class])]
class UploadController {
    #[Post('/upload/{slug}')] public function upload()
}

#[Route('/img')]
class ImageController {
    #[Get('/{preset}/{path}')]  public function serve()  // path is catch-all
}
```

### Component actions (via /--component/ endpoint)

All mutations that are currently separate POST routes become HTMX component actions:

- **Player:** `next`, `prev`, `jump`, `answer`, `submitInteractive`, `submitFeedback`, `restart`
- **Admin GameList:** `delete`, `filter`
- **Admin GameForm:** `save`
- **Admin UserList/UserForm:** `save`, `delete`
- **Admin ContactList/Detail:** `markRead`, `markSpam`, `delete`
- **Admin FeedbackList:** (read-only, no actions)
- **Admin AssetManager:** `replace`, `edit`, `delete`
- **Admin Settings:** `clearCache`
- **Public ContactForm:** `submit`
- **Public GameGrid:** `filter`, `search`

---

## 5. Data Models

8 typed models using Preflow's `#[Entity]` / `#[Field]` / `#[Id]` attributes. All use SQLite driver. Existing auto-increment integer `id` column kept as the ID field.

### Game

```php
#[Entity(table: 'games', storage: 'sqlite')]
class Game extends Model {
    #[Id] public int $id;
    #[Field(searchable: true)] public string $slug;
    #[Field(searchable: true)] public string $name;
    #[Field(searchable: true)] public string $description;
    #[Field] public string $designer;
    #[Field] public string $publisher;
    #[Field] public ?int $year_published;
    #[Field] public int $min_players;
    #[Field] public int $max_players;
    #[Field] public ?int $play_time_minutes;
    #[Field] public ?float $complexity;
    #[Field] public ?int $teach_time_minutes;
    #[Field] public ?string $cover_image;
    #[Field] public ?string $bgg_id;
    #[Field(transform: JsonTransformer::class)] public array $tags;
    #[Field] public string $status; // 'draft' | 'published'
    #[Field] public ?int $created_by;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $created_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $updated_at;
}
```

### User

```php
#[Entity(table: 'admin_users', storage: 'sqlite')]
class User extends Model implements Authenticatable {
    use AuthenticatableTrait;
    #[Id] public int $id;
    #[Field(searchable: true)] public string $username;
    #[Field(searchable: true)] public string $email;
    #[Field] public string $password_hash;
    #[Field] public string $role; // 'admin' | 'editor'
    #[Field] public ?string $reset_code;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $reset_code_expires_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $created_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $last_login_at;
}
```

### TeachingFlow

```php
#[Entity(table: 'teaching_flows', storage: 'sqlite')]
class TeachingFlow extends Model {
    #[Id] public int $id;
    #[Field] public int $game_id;
    #[Field(transform: JsonTransformer::class)] public array $flow_json;
    #[Field] public int $version;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $created_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $updated_at;
}
```

### UserProgress

```php
#[Entity(table: 'user_progress', storage: 'sqlite')]
class UserProgress extends Model {
    #[Id] public int $id;
    #[Field] public string $player_token;
    #[Field] public int $game_id;
    #[Field] public int $current_layer;
    #[Field] public int $current_step;
    #[Field(transform: JsonTransformer::class)] public array $completed_steps;
    #[Field(transform: JsonTransformer::class)] public array $quiz_answers;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $started_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $last_activity_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $completed_at;
}
```

### Feedback

```php
#[Entity(table: 'feedback', storage: 'sqlite')]
class Feedback extends Model {
    #[Id] public int $id;
    #[Field] public int $game_id;
    #[Field] public string $player_token;
    #[Field] public int $rating; // 1-6
    #[Field] public ?string $comment;
    #[Field] public ?string $name;
    #[Field] public ?string $email;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $created_at;
}
```

### ContactMessage

```php
#[Entity(table: 'contact_messages', storage: 'sqlite')]
class ContactMessage extends Model {
    #[Id] public int $id;
    #[Field(searchable: true)] public string $name;
    #[Field] public string $email;
    #[Field(searchable: true)] public string $subject;
    #[Field(searchable: true)] public string $message;
    #[Field] public bool $is_read;
    #[Field] public bool $is_spam;
    #[Field] public ?string $ip_address;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $created_at;
}
```

### Asset

```php
#[Entity(table: 'assets', storage: 'sqlite')]
class Asset extends Model {
    #[Id] public int $id;
    #[Field(searchable: true)] public string $path;
    #[Field] public ?int $game_id;
    #[Field(searchable: true)] public string $filename;
    #[Field] public ?string $usage_type;
    #[Field] public ?int $file_size;
    #[Field] public ?string $mime_type;
    #[Field] public ?int $width;
    #[Field] public ?int $height;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $created_at;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $updated_at;
}
```

### FlowRevision

```php
#[Entity(table: 'flow_revisions', storage: 'sqlite')]
class FlowRevision extends Model {
    #[Id] public int $id;
    #[Field] public int $game_id;
    #[Field(transform: JsonTransformer::class)] public array $flow_json;
    #[Field] public int $version;
    #[Field(transform: DateTimeTransformer::class)] public ?DateTimeInterface $saved_at;
}
```

---

## 6. Services

### FlowService

- `loadForGame(int $gameId): ?array` — loads flow JSON from `TeachingFlow` model, falls back to `/content/{slug}.json` file
- `validate(array $flowData): array` — validates layer/step structure, returns error list
- `getStats(array $flowData): array` — returns `['layer_count', 'step_count', 'estimated_minutes']`
- `getStep(array $flowData, int $layerIndex, int $stepIndex): ?array` — extracts a specific step
- `isLastStep(array $flowData, int $layerIndex, int $stepIndex): bool`

### ProgressService

- `mergeStepCompletion(array $completed, string $stepId): array` — adds step to completed list
- `mergeQuizAnswer(array $answers, string $stepId, string $answer, bool $correct): array`
- `calculatePercent(array $completed, array $flowData): float`
- `isFlowComplete(array $completed, array $flowData): bool`

### ImageService

- `url(string $path, string $preset): string` — generates `/img/{preset}/{path}` URL
- `serve(string $preset, string $path): ResponseInterface` — League Glide response
- `clearCache(?string $path = null): void`
- Presets: `cover-thumb`, `cover-card`, `cover-hero`, `step-side`, `step-full`, `glossary`, `original`

### MailService

- `send(string $to, string $subject, string $body): void` — Symfony Mailer wrapper
- `sendContactNotification(ContactMessage $message): void`
- `sendPasswordResetCode(User $user, string $code): void`
- Config from `.env`: SMTP host, port, user, pass, from name

### AppServiceProvider

Registered in `config/providers.php`. Registers:
- All services as singletons in the container
- Template function `img_url(path, preset)` via `TemplateExtensionProvider`
- Template global `unread_contact_count` for admin layout badge
- Player token cookie handling

---

## 7. Player Component Interactions

### PlayerShell — the orchestrator

Receives `slug` prop from the page template. Renders the player chrome once. The step content area is the HTMX swap target.

```
PlayerShell (props: {slug})
├── resolveState():
│   - Read player_token from cookie (create + set cookie if absent)
│   - Load Game by slug
│   - Load TeachingFlow for game
│   - Load/create UserProgress for player_token + game
│   - Extract current step data from flow JSON
│
├── actions(): ['next', 'prev', 'jump', 'answer', 'submitInteractive', 'submitFeedback', 'restart']
│
├── Template structure:
│   ├── Header: game title, step counter "{current} of {total}", estimated time remaining
│   ├── ProgressBar component (props: layers, completedSteps)
│   ├── Step content <div id="step-content"> ← HTMX swap target
│   │   └── Renders appropriate step component based on step type
│   ├── StepNavigation component (prev/next buttons, branch buttons)
│   └── GlossaryPanel component (props: glossary terms from flow)
```

### Navigation actions

**next/prev:** Increment/decrement step index. At layer boundaries, advance to next/previous layer. Save updated position to `UserProgress`. Re-render step content area. If flow complete, render `CompletionScreen` instead.

**jump:** Set specific layer + step index (from layer overview). Save to `UserProgress`. Re-render.

**answer:** Receive `stepId` + `selected` (single or array for select-many). Validate against flow JSON's correct answers. Record in `quiz_answers`. Mark step completed if correct. Re-render `QuizStep` with feedback state showing correct/incorrect per option and explanation.

**submitInteractive:** Receive `stepId` + `order`/`selections` (depends on interaction type). Validate against flow JSON. Record and re-render with feedback.

**submitFeedback:** Receive `rating` (1-6) + optional `comment`, `name`, `email`. Save/update `Feedback` record. Re-render `CompletionScreen` with thank-you state.

**restart:** Reset `UserProgress` for this game. Re-render from first step.

### HTMX swap strategy

The PlayerShell wrapper (header, progress, nav) renders once on initial page load. Navigation buttons use:

```html
<button
    hx-post="/--component/"
    hx-vals='{"action":"next"}'
    hx-target="#step-content"
    hx-swap="innerHTML"
    hx-indicator="#step-loading">
    Next
</button>
```

The component action handler returns the step content HTML fragment plus an out-of-band progress bar update via `hx-swap-oob="true"`. This keeps both the step area and progress bar in sync without re-rendering the full shell.

CSS transitions via `htmx-swapping`/`htmx-settling` classes provide the fade effect matching the current JS transitions.

### Step type rendering

| Type | Server renders | Client JS |
|---|---|---|
| **text** | Markdown → HTML via league/commonmark. Blocks, callouts, media (images with `img_url()`), layouts (default, media_left, media_right, two_column). Glossary terms wrapped in tooltip `<span>`. | None |
| **quiz** | Question text, option buttons/checkboxes. After answer: correct/incorrect state per option, feedback text, explanation. | None |
| **reveal** | `<details><summary>` elements for each section. | None (native HTML) |
| **comparison** | Side-by-side grid/table with headings and attributes. | None |
| **interactive** | Items rendered in initial order. Submit button with HTMX. After submit: feedback. | Co-located JS: drag-drop initialization on rendered items, collect order on submit |
| **flowchart** | Mermaid diagram markup rendered in a `<pre class="mermaid">` block. | Co-located JS: `mermaid.init()` on component mount |

---

## 8. Admin Panel

### Authentication

- Preflow's `SessionGuard` with `User` model implementing `Authenticatable`
- `AdminAuthMiddleware` as global middleware: checks path starts with `/admin` (excludes `/admin/login`, `/admin/forgot-password`, `/admin/reset-password`)
- `AdminRoleMiddleware` applied via `#[Middleware]` on controller routes that need admin-only access
- CSRF handled by Preflow's built-in `CsrfMiddleware` (global pipeline)
- Component actions secured by HMAC tokens (Preflow's ComponentToken)

### Admin layout

`_admin.twig` layout template provides:
- Sidebar navigation with links to all admin sections
- Unread contact message count badge (template global)
- Flash message display (via Preflow's session flash)
- Current user info + logout button
- Content area where page components render

### CRUD pattern (repeated for all admin entities)

**List component** (e.g., `GameList`):
- `resolveState()`: queries via `DataManager::query(Model::class)` with filters, search, pagination
- Renders table/grid with data rows and action buttons
- HTMX actions: `delete` (with confirmation), `filter`/`search` (re-render list)

**Form component** (e.g., `GameForm`):
- Props: `{slug?}` or `{id?}` — present for edit, absent for create
- `resolveState()`: loads existing record if editing
- Renders form fields matching the model
- HTMX action `save`: validates input, creates/updates via `DataManager::save()`, sets flash message
- After save: HTMX redirect to list page

### Flow editor

`FlowEditor` component renders admin layout chrome + game info header. The body embeds the existing JS flow editor application. The JS files live in `public/js/admin/` (the one exception to co-location — it's a preserved standalone app).

The JS editor communicates with action routes:
- `PUT /admin/games/{slug}/flow` → save flow JSON, increment version, create revision
- `POST /admin/games/{slug}/flow/validate` → validate structure, return errors

These are `FlowApiController` action routes returning JSON responses.

---

## 9. Remaining Features

### Image serving

`ImageController` action route at `GET /img/{preset}/{path}`. Delegates to `ImageService` which wraps League Glide. Template function `img_url(path, preset)` registered by `AppServiceProvider` generates URLs.

Presets (carried from original): `cover-thumb` (200x200), `cover-card` (400x300), `cover-hero` (1200x400), `step-side` (500x), `step-full` (1000x), `glossary` (100x100), `original`.

### Contact form

`ContactForm` component with `submit` action:
1. Honeypot field check (hidden `website` field must be empty)
2. Rate limiting: query `ContactMessage` by IP in last hour, reject if >= 3
3. Validate required fields (name, email, subject, message)
4. Save to database via `DataManager::save()`
5. Send notification email via `MailService`
6. Flash success message, re-render form in success state

### Theme toggle

CSS custom properties for dark/light theme (ported from existing `variables.css`). `ThemeToggle` component renders a button. Co-located JS flips `data-theme` attribute on `<html>` and stores preference in a cookie. Server reads cookie on page load to set initial theme (no flash of wrong theme).

### Glossary

`GlossaryPanel` component rendered inside PlayerShell layout. Terms from flow JSON passed as props. Search/filter via HTMX action `search` that re-renders the filtered term list. Glossary terms in step content converted to CSS-only tooltip `<span>` elements by the commonmark extension during server-side markdown rendering.

### Markdown rendering

`league/commonmark` with custom extensions:
- `{{glossary:key}}` → `<span class="glossary-term" data-tooltip="definition">Term Name</span>`
- Standard markdown (headings, lists, bold, italic, links, images)
- Callout blocks mapped from the existing JSON callout structure to styled `<aside>` elements

---

## 10. CSS Strategy

Port existing CSS into Preflow's co-located component model:

- **Global CSS** (`_base.twig` layout): CSS custom properties (variables.css), reset, typography, base layout. Loaded via `{% apply css %}` in the base layout template.
- **Component CSS**: Each component's template includes its styles in `{% apply css %}` blocks. `AssetCollector` deduplicates and inlines with CSP nonces.
- **Step type CSS**: Each step component (TextStep, QuizStep, etc.) carries its own CSS from the existing `css/steps/` files.
- **Admin CSS**: Admin components carry their own styles. Admin layout CSS in `_admin.twig`.
- **FontAwesome**: Loaded via CDN as a head tag.

No CSS redesign — faithful port of existing styles into the co-located model.

---

## 11. Dependencies

```json
{
    "require": {
        "php": "^8.4",
        "preflow/core": "^0.10",
        "preflow/routing": "^0.10",
        "preflow/view": "^0.10",
        "preflow/components": "^0.10",
        "preflow/htmx": "^0.10",
        "preflow/twig": "^0.10",
        "preflow/auth": "^0.10",
        "preflow/data": "^0.10",
        "preflow/i18n": "^0.10",
        "preflow/devtools": "^0.10",
        "league/glide": "^3.2",
        "symfony/mailer": "^7.0",
        "league/commonmark": "^2.0"
    },
    "require-dev": {
        "preflow/testing": "^0.10"
    }
}
```

---

## 12. Smoke Tests

Minimal test suite using Preflow's testing package:

### PlayerNavigationTest (ComponentTestCase)
- Load a test flow fixture
- Render PlayerShell with slug
- Trigger `next` action, verify step content changes
- Trigger `prev` action, verify return to previous step
- Navigate to last step, trigger `next`, verify CompletionScreen renders

### QuizAnswerTest (ComponentTestCase)
- Render QuizStep with a quiz step from fixture
- Submit correct answer, verify feedback shows correct state
- Submit incorrect answer, verify feedback shows incorrect state with explanation

### AdminAuthTest (RouteTestCase)
- Request `/admin` without auth → redirect to login
- POST `/admin/login` with valid credentials → redirect to dashboard
- Request `/admin` with auth → 200
- POST `/admin/logout` → redirect to login

### GameCrudTest (ComponentTestCase)
- Render GameForm, trigger `save` with valid data → game created in DB
- Render GameForm with existing slug, trigger `save` → game updated
- Render GameList, trigger `delete` → game removed

### ProgressPersistenceTest (DataTestCase)
- Create UserProgress record
- Query by player_token + game_id → found
- Update progress fields → persisted
- Verify JSON fields (completed_steps, quiz_answers) round-trip correctly

---

## 13. Migration Strategy

### Database

Keep existing SQLite schema and data file as-is. Copy `database/schema.sql` and `database/bggenius.sqlite` to new project. Preflow typed models map to existing tables — no schema changes needed. The `id` field (auto-increment integer) used as `#[Id]` instead of Preflow's default `uuid`.

### Content

Copy `/content/*.json` flow files directly. Format unchanged.

### Uploads

Copy `storage/uploads/` directory. Image paths stored in DB remain valid.

### Install script

`bin/install.php` adapted to use Preflow's `Application::create()` for bootstrapping, then runs schema.sql and creates initial admin user.

### No data migration needed

The existing database works as-is with the new typed models. This is a code migration, not a data migration.
