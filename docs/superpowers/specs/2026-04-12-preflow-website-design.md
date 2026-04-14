# Preflow Website — Design Spec

**Date:** 2026-04-12
**Status:** Approved
**Repository:** `preflow-website` (independent, not part of the monorepo)

## Overview

A marketing and documentation website for the Preflow PHP framework, built entirely with Preflow itself. The site serves two audiences: developers evaluating Preflow (marketing/landing page) and developers building with Preflow (documentation). It deploys to shared hosting via Deployer.

## Principles

- **Dogfooding** — every feature used on this site is a Preflow feature. No external CSS frameworks, no JavaScript build tools, no static site generators.
- **Zero build step** — CSS lives in components, Markdown lives in `docs/`, deploy and go.
- **Content as Markdown** — documentation authored as `.md` files, parsed server-side by PHP.
- **Dark/light theming** — CSS custom properties toggled via cookie-persisted preference.

## Architecture

### Base

Forked from `packages/skeleton`. Blog demo artifacts (BlogGrid, BlogPost, BlogDetail, PostForm, blog pages, Post model, migrations, seeds) are removed. Navigation, layout, and config are reshaped for the website.

### Packages Used

**Runtime (require):**
- `preflow/core` — DI, config, middleware, kernel
- `preflow/routing` — file-based + attribute routing
- `preflow/view` — asset collector, CSP nonces
- `preflow/twig` — template engine, co-located CSS/JS
- `preflow/components` — component lifecycle, error boundaries
- `preflow/htmx` — hypermedia driver for interactive elements
- `league/commonmark` — Markdown to HTML
- `tempest/highlight` — PHP-based syntax highlighting

**Development (require-dev):**
- `preflow/devtools` — CLI, dev server
- `deployer/deployer` — deployment

**Not used:** `preflow/data`, `preflow/auth`, `preflow/i18n`, `preflow/blade`, `preflow/testing`

## Project Structure

```
preflow-website/
├── app/
│   ├── Components/
│   │   ├── Navigation/              # Site nav: Home, Docs, GitHub + ThemeToggle
│   │   ├── ThemeToggle/             # Dark/light switch, cookie-persisted
│   │   ├── Hero/                    # Landing page hero
│   │   ├── FeatureCard/             # Individual Apple-style feature card
│   │   ├── FeatureGrid/             # Grid layout wrapping FeatureCards
│   │   ├── CodeExample/             # Syntax-highlighted code block
│   │   ├── PackageCard/             # Package showcase card
│   │   ├── QuickStart/              # 3-step getting started section
│   │   ├── ArchitectureDiagram/     # Request flow diagram (pure HTML/CSS)
│   │   ├── DocsSidebar/             # Collapsible grouped sidebar nav
│   │   ├── DocsPage/               # Markdown renderer + docs layout
│   │   ├── DocsSearch/             # HTMX-powered text search
│   │   ├── TableOfContents/         # Auto-generated from h2/h3 headings
│   │   └── Footer/                  # Links, GitHub, license, "Built with Preflow"
│   ├── Controllers/                 # Empty initially
│   ├── Providers/
│   │   └── DocsServiceProvider.php  # Registers CommonMark Environment + highlight
│   │                                # extension into the DI container, binds the
│   │                                # manifest.php array, and configures the docs
│   │                                # cache directory path
│   └── pages/
│       ├── _layout.twig             # Base layout with theme support
│       ├── index.twig               # Landing page
│       └── docs/
│           └── [...path].twig       # Catch-all route for all doc pages
├── config/
│   ├── app.php
│   ├── middleware.php
│   └── providers.php
├── docs/                            # Markdown documentation source
│   ├── manifest.php                 # Sidebar grouping and ordering
│   ├── getting-started/
│   │   ├── installation.md
│   │   ├── configuration.md
│   │   └── directory-structure.md
│   ├── guides/
│   │   ├── routing.md
│   │   ├── components.md
│   │   ├── data.md
│   │   ├── authentication.md
│   │   ├── internationalization.md
│   │   └── testing.md
│   └── packages/
│       ├── core.md
│       ├── routing.md
│       ├── view.md
│       ├── components.md
│       ├── twig.md
│       ├── blade.md
│       ├── data.md
│       ├── htmx.md
│       ├── i18n.md
│       ├── auth.md
│       ├── devtools.md
│       └── testing.md
├── public/
│   └── index.php                    # 3-line entry point
├── storage/
│   ├── cache/
│   │   └── docs/                    # Cached parsed markdown
│   └── logs/
├── deploy.php                       # Deployer recipe
├── composer.json
└── .env.example
```

## Landing Page Sections

Rendered top-to-bottom on `index.twig`:

### 1. Hero
- Headline: "The PHP framework that trusts the browser."
- Subline: philosophy statement (HTML-over-the-wire, components as primitives)
- Two CTAs: "Get Started" → `/docs/getting-started/installation`, "Documentation" → `/docs`
- Dark gradient background using navy/indigo tokens

### 2. Feature Cards (Apple-style grid)
- 2-column grid on desktop, stacking on mobile
- Each card: icon/emoji, title, short description
- Cards:
  - **Component Architecture** — PHP class + template + CSS in one directory
  - **HTML Over the Wire** — Server renders HTML, HTMX handles interactivity
  - **Zero External Requests** — All CSS/JS inlined, hash-deduplicated, CSP nonces
  - **Multi-Storage ORM** — SQLite, JSON, MySQL — same query API
  - **Template Engine Freedom** — Twig or Blade, swap with one config change
  - **Security First** — HMAC-signed tokens, CSRF, error boundaries, CSP

### 3. Quick Start
- 3 steps with code snippets:
  1. `composer create-project preflow/skeleton myapp`
  2. `cd myapp && php preflow serve`
  3. Visit `localhost:8080`

### 4. Code Examples
- 2-3 panels showing real Preflow code
- Component example (PHP + Twig pair)
- File-based route example
- Model with Entity attributes
- Syntax highlighted using dark code block style

### 5. Architecture Overview
- Pure HTML/CSS diagram: Request → Middleware → Kernel → Component/Action Mode → Response
- Package relationship visualization

### 6. Package Showcase
- Grid of 13 cards, one per package
- Each: name, one-liner description, link to docs page
- Subtle hover effect

### 7. Footer
- GitHub link, MIT license, "Built with Preflow" badge
- Links to doc sections

## Documentation System

### Markdown Pipeline

1. `league/commonmark` parses `.md` files
2. Custom CommonMark extension pipes fenced code blocks through `tempest/highlight`
3. Frontmatter extracted for metadata (title, description, group, order)

### Markdown Frontmatter Format

```yaml
---
title: Routing
description: File-based and attribute-based routing
group: guides
order: 1
---
```

### manifest.php

Defines sidebar grouping and page sequence:

```php
return [
    'Getting Started' => [
        'docs/getting-started/installation',
        'docs/getting-started/configuration',
        'docs/getting-started/directory-structure',
    ],
    'Guides' => [
        'docs/guides/routing',
        'docs/guides/components',
        'docs/guides/data',
        'docs/guides/authentication',
        'docs/guides/internationalization',
        'docs/guides/testing',
    ],
    'Packages' => [
        'docs/packages/core',
        'docs/packages/routing',
        'docs/packages/view',
        'docs/packages/components',
        'docs/packages/twig',
        'docs/packages/blade',
        'docs/packages/data',
        'docs/packages/htmx',
        'docs/packages/i18n',
        'docs/packages/auth',
        'docs/packages/devtools',
        'docs/packages/testing',
    ],
];
```

Titles and ordering come from frontmatter. The manifest only defines grouping and sequence. Files not in the manifest are still accessible by URL, just not shown in the sidebar.

### Rendering Flow

1. User hits `/docs/guides/routing`
2. Catch-all `docs/[...path].twig` route fires
3. `DocsPage` component receives path, resolves `docs/guides/routing.md`
4. Reads markdown, parses frontmatter
5. Runs CommonMark → HTML with syntax highlighting
6. `TableOfContents` extracts h2/h3 headings from parsed HTML
7. `DocsSidebar` reads `manifest.php`, highlights current page

### Docs Layout

- **Desktop (3-column):** sidebar (240px) | content (flex) | table of contents (200px)
- **Tablet (2-column):** sidebar | content (TOC collapses into dropdown)
- **Mobile (1-column):** hamburger sidebar, content only
- Prev/Next links at bottom, derived from manifest order

### Search

- `DocsSearch` component scans markdown files, builds flat index of titles + first 200 chars
- Cached to `storage/cache/docs-index.json` on first request
- HTMX-powered: typing fires a request, returns matching results as HTML fragment
- No external search service

### Content Source

Initial documentation content pulled from existing package READMEs. Each package already has a detailed README with API examples — these are reformatted into the markdown structure with frontmatter added.

## Theme System

### CSS Custom Properties

All components reference `var(--token-name)` exclusively. No hardcoded colors.

```css
/* Dark theme (default) */
:root[data-theme="dark"] {
    --bg-primary: rgb(14, 28, 41);
    --bg-surface: rgb(50, 61, 104);
    --bg-card: rgba(94, 120, 143, 0.15);
    --bg-code: rgba(94, 120, 143, 0.2);
    --text-primary: rgb(255, 255, 255);
    --text-secondary: rgb(216, 223, 229);
    --text-muted: rgba(94, 120, 143, 0.5);
    --accent: rgb(119, 75, 229);
    --border: rgba(216, 223, 229, 0.15);
    --surface-hover: rgba(240, 248, 255, 0.08);
}

/* Light theme */
:root[data-theme="light"] {
    --bg-primary: rgb(246, 251, 255);
    --bg-surface: rgba(240, 248, 255, 0.9);
    --bg-card: rgb(255, 255, 255);
    --bg-code: rgb(246, 251, 255);
    --text-primary: rgb(14, 28, 41);
    --text-secondary: rgb(50, 61, 104);
    --text-muted: rgba(94, 120, 143, 0.5);
    --accent: rgb(119, 75, 229);
    --border: rgb(216, 223, 229);
    --surface-hover: rgba(240, 248, 255, 0.9);
}
```

### ThemeToggle Component

- Reads `preflow_theme` cookie in `resolveState()`
- Defaults to `dark` if no cookie set
- Toggle action sets cookie and swaps `data-theme` attribute on `<html>`
- HTMX action swaps the toggle button icon (sun/moon)
- Server reads cookie on initial render so pages arrive in correct theme — no flash

### Brand Constant

Purple accent `rgb(119, 75, 229)` stays the same in both themes.

## Deployment

### Deployer Recipe

- Target: shared hosting via SSH
- Tasks: `composer install --no-dev`, symlink `storage/` as shared, clear caches
- Shared dirs: `storage/cache`, `storage/logs`
- No database tasks, no migrations
- Deploy hook: clear `storage/cache/docs/` and `storage/cache/docs-index.json`

### Cache Strategy

- Parsed markdown cached per file in `storage/cache/docs/` keyed by file path + mtime
- Docs search index cached, rebuilt on deploy or first request after cache clear
- Route cache via `php preflow cache:clear` in deploy hook

## What Is Explicitly Out of Scope

- **No i18n** — English only at launch
- **No auth** — public site, no admin
- **No database** — no models, no migrations, no data layer
- **No Blade** — Twig only
- **No JavaScript build tools** — no npm, webpack, vite
- **No external CSS frameworks** — no Tailwind, Bootstrap
- **No comparison pages** — features speak for themselves
- **No blog** — can be added later
- **No interactive demos** — can be added later with HTMX components
