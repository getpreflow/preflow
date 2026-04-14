# Danger Zone Section — Design Spec

**Date:** 2026-04-13
**Status:** Approved
**Project:** preflow-website (`/Users/smyr/Sites/gbits/preflow-website`)

## Overview

A fun, educational section on the homepage that showcases Preflow's error boundary system. Positioned after the "13 packages, one framework" package wall. Uses a `<details>` element styled as a bold red "Danger Zone" divider. When expanded, shows a two-column comparison of error handling: a custom fallback (left) vs the debug error panel (right). All content is static/fake HTML — no real errors are triggered, no real internals are exposed. No ViewSource button on this section.

## Structure

### Trigger: Red Line with Notch

A `<details>` element. The `<summary>` renders as:

- A full-width horizontal red line (`rgb(224, 108, 117)`)
- A triangular notch/peak pointing upward, centered on the line (CSS `clip-path` or border trick on a pseudo-element)
- "Danger Zone" text in red, centered below the notch
- A Lucide `icon-chevron-down` below the text
- When `[open]`, the chevron rotates 180deg via CSS

The line spans the full section width. The notch, text, and chevron are centered.

### Expanded Content: Two Columns

Flex layout, two equal columns, stacks vertically on mobile.

#### Left Column — "With Fallback"

**Fake WeatherCard fallback:**
A styled card (dark background, consistent with site theme) showing:
- `icon-cloud-off` (Lucide) — large, muted color
- "Weather Unavailable" heading
- "Service is temporarily unreachable. Please try again later." subtext
- Subtle border, rounded corners, matches site card styling

**Description below the card:**
A text block explaining what this demonstrates. Includes a small inline code snippet showing the `fallback()` method override:

```php
public function fallback(\Throwable $e): ?string
{
    return '<div class="weather-fallback">
        <p>Weather Unavailable</p>
    </div>';
}
```

Brief explanation: "When a component defines a `fallback()` method, Preflow renders it instead of the broken component. Users see a graceful degradation — not a blank page."

#### Right Column — "Without Fallback (Debug Mode)"

**Fake debug error panel:**
A static HTML replica of `ErrorBoundary::renderDev()` output with invented data:

- Red header: `WeatherApiException: Connection to api.weather.example timed out after 5000ms`
- Grid of details:
  - Component: `App\Components\WeatherCard\WeatherCard`
  - ID: `WeatherCard-a3f8b2c1`
  - Phase: `resolveState`
  - Props: `{ "city": "Berlin", "units": "metric" }`
  - File: `app/Components/WeatherCard/WeatherCard.php:42`
- Collapsible "Stack Trace" with fake but realistic trace entries

The HTML/CSS replicates the real `renderDev()` inline styles exactly: `border: 2px solid #e74c3c`, `background: #1a1a2e`, the red header bar, the `dl` grid layout, the `details` for stack trace.

**Description below the panel:**
"Without a custom fallback, Preflow's debug mode shows this error panel with the exception, component details, and stack trace. In production (`APP_DEBUG=0`), the component silently disappears. Set `APP_DEBUG=2` for verbose mode — it shows this panel even when a fallback exists."

## Styling

- Red accent: `rgb(224, 108, 117)` (matches the existing variable/error red in the syntax highlighting palette)
- The notch line uses the red color. The notch is ~20px tall, ~40px wide at the base, centered.
- Section padding matches other homepage sections (~5rem top/bottom, 2rem sides)
- Two-column gap: ~2rem
- Description text: site's secondary/muted text colors
- Code snippets in descriptions: inline `<code>` with monospace font, or a small `<pre>` block with the dark code styling
- Mobile: single column, cards stack vertically
- The fake debug panel uses the exact inline styles from `ErrorBoundary::renderDev()` — no CSS classes needed since the real panel uses inline styles too

## Fake Stack Trace Content

```
#0 app/Components/WeatherCard/WeatherCard.php(42): App\Services\WeatherApi->fetchCurrent('Berlin')
#1 vendor/preflow/components/src/ComponentRenderer.php(23): App\Components\WeatherCard\WeatherCard->resolveState()
#2 vendor/preflow/components/src/ComponentRenderer.php(35): Preflow\Components\ComponentRenderer->renderTemplate(Object)
#3 vendor/preflow/twig/src/TwigEngine.php(58): Preflow\Components\ComponentsExtensionProvider->renderComponent('WeatherCard', Array)
#4 app/pages/index.twig(12): Twig\Template->displayBlock('content', Array)
```

## What This Does NOT Include

- No real error triggering — all content is static HTML
- No ViewSource button on this section
- No new Preflow components — everything is inline in `index.twig`
- No interactivity beyond the `<details>` expand/collapse
