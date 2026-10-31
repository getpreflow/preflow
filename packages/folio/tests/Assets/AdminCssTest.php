<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class AdminCssTest extends TestCase
{
    private function css(): string
    {
        $path = dirname(__DIR__, 2) . '/assets/admin.css';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_defines_base_and_dark_token_sets(): void
    {
        $css = $this->css();
        $this->assertStringContainsString(':root', $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
    }

    public function test_defines_emerald_accent_and_core_tokens(): void
    {
        $css = $this->css();
        foreach (['--c-accent', '--c-bg', '--c-surface', '--c-border', '--c-text', '--font-sans'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
    }

    public function test_every_token_used_is_defined(): void
    {
        // An undefined token silently falls back (or to nothing): the light-only
        // colours that leaked into dark mode came from exactly that.
        $css = $this->css();
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $css, $defined);
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $used);

        $this->assertSame([], array_values(array_diff(array_unique($used[1]), $defined[1])));
    }

    public function test_tokens_follow_color_scheme(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('color-scheme: light dark', $css);
        $this->assertStringContainsString('light-dark(', $css);
        $this->assertStringContainsString(':root[data-theme="light"] { color-scheme: light; }', $css);
    }

    public function test_styles_shell_and_reused_form_hooks(): void
    {
        $css = $this->css();
        foreach (['.folio-shell', '.folio-sidebar', '.folio-table', '.folio-card', '.btn-primary', '.form-group', '.has-error'] as $sel) {
            $this->assertStringContainsString($sel, $css);
        }
    }

    public function test_list_bulk_bar_overlays_the_header_on_an_inverse_surface(): void
    {
        $css = $this->css();
        // With JS the bar lies over the header row of the frame, so nothing below moves.
        $this->assertStringContainsString('.folio-table-frame { position: relative; }', $css);
        $this->assertMatchesRegularExpression('/\.folio-bulk-bar\.is-overlay \{[^}]*position: absolute;/', $css);
        $this->assertStringContainsString('min-height: calc(var(--h-list-head) + 1px);', $css);
        $this->assertStringContainsString('.folio-table thead tr { height: var(--h-list-head); }', $css);
        // The header's select-all box stays clickable above the bar.
        $this->assertMatchesRegularExpression('/\.folio-table thead \.folio-bulk-cell input \{[^}]*z-index: 3;/', $css);
        // A dark bar with light text in both themes (one light-dark() token set).
        $this->assertMatchesRegularExpression('/--c-inverse: light-dark\(/', $css);
        $this->assertStringContainsString('background: var(--c-inverse); color: var(--c-inverse-fg);', $css);
        $this->assertStringContainsString('color: var(--c-inverse-danger);', $css);
    }

    public function test_dark_side_uses_the_soft_graphite_palette(): void
    {
        $css = $this->css();
        foreach ([
            '--c-bg' => '#1e1f22', '--c-sidebar-bg' => '#232428', '--c-surface' => '#2a2b2f',
            '--c-input' => '#25262a', '--c-border' => '#36373c', '--c-border-strong' => '#47494f',
            '--c-text' => '#e9e9ec', '--c-text-muted' => '#a3a5ad', '--c-accent' => '#3fcf98',
            '--c-accent-fg' => '#062a1d', '--c-accent-soft' => '#203a31', '--c-accent-text' => '#6fe2b4',
        ] as $token => $dark) {
            $this->assertMatchesRegularExpression('/' . preg_quote($token, '/') . ': light-dark\(#[0-9a-f]{6}, ' . $dark . '\);/', $css, $token);
        }
    }

    public function test_dark_surface_steps_stay_distinguishable(): void
    {
        // Hover on raised surfaces (menus, filter panel) and neutral badges must not vanish.
        $css = $this->css();
        $dark = [];
        foreach (['--c-surface', '--c-surface-raised', '--c-row-hover', '--c-neutral-soft'] as $token) {
            $this->assertMatchesRegularExpression('/' . preg_quote($token, '/') . ': light-dark\(#[0-9a-f]{6}, (#[0-9a-f]{6})\);/', $css);
            preg_match('/' . preg_quote($token, '/') . ': light-dark\(#[0-9a-f]{6}, (#[0-9a-f]{6})\);/', $css, $m);
            $dark[$token] = $m[1];
        }
        $this->assertSame($dark, array_unique($dark));
        // Lighter is higher on dark: hover sits above the raised surface it appears on.
        $this->assertGreaterThan(hexdec(substr($dark['--c-surface-raised'], 1)), hexdec(substr($dark['--c-row-hover'], 1)));
        $this->assertStringContainsString('--c-text-subtle: light-dark(#8e8e86, #90929b);', $css);
    }

    public function test_controls_share_one_base_style_inside_every_admin_root(): void
    {
        $css = $this->css();
        // One rule for every admin root (shell, drawer, sign-in), zero specificity so
        // container rules (widths, margins) still win; never reaches site previews (iframes).
        $roots = ':where(.folio-shell, .folio-drawer-body, .folio-auth)';
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($roots, '/') . ' :where\(\s*input:not\(\[type="checkbox"\], \[type="radio"\], \[type="file"\], \[type="hidden"\][^)]*\),\s*textarea,\s*select\s*\) \{[^}]*min-height: var\(--h-control\);[^}]*border-radius: var\(--r-sm\);/',
            $css,
        );
        foreach ([':hover', ':focus', ':disabled'] as $state) {
            $this->assertStringContainsString($state, $css);
        }
        $this->assertStringContainsString($roots . ' ::placeholder', $css);
    }

    public function test_single_selects_draw_their_own_chevron_inside_the_field(): void
    {
        $css = $this->css();
        // A stroked SVG chevron on top, the CSS-only caret below it for sites whose img-src blocks data:.
        $this->assertMatchesRegularExpression('/:where\(select:not\(\[multiple\]\)\) \{[^}]*--folio-chevron: url\("data:image\/svg\+xml,[^"]*stroke-width=\'1\.5\'[^}]*appearance: none;[^}]*padding-right: 34px;[^}]*background-image:\s+var\(--folio-chevron\),\s+linear-gradient\(/', $css);
        // light-dark() cannot reach into a data: URI: the dark chevron follows both theme switches.
        $this->assertMatchesRegularExpression('/:where\(:root\[data-theme="dark"\]\) [^{]*select:not\(\[multiple\]\)\) \{ --folio-chevron: url\("data:image\/svg\+xml,[^"]*%23a3a5ad/', $css);
        $this->assertMatchesRegularExpression('/@media \(prefers-color-scheme: dark\) \{\s*:where\(:root:not\(\[data-theme="light"\]\)\) [^{]*select:not\(\[multiple\]\)\) \{ --folio-chevron: url\("data:image\/svg\+xml,[^"]*%23a3a5ad/', $css);
        // Container rules must not reset the background (that would drop the chevron) or the right padding.
        $plain = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $plain, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selector, $body]) {
            $selector = trim($selector);
            if (!str_starts_with($selector, ':where(.folio-shell') && preg_match('/(?<![\w-])select(?![\w-])/', $selector) === 1) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\w-])(background|padding):/', $body, trim($selector));
            }
        }
    }

    public function test_sortable_headers_show_an_idle_mark(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.folio-sort-mark.is-idle', $css);
        $this->assertStringContainsString('.folio-sort-mark.is-idle::before { content: "↕"; }', $css); // not in the header's text
        $this->assertStringContainsString('.folio-sort:hover .folio-sort-mark.is-idle, .folio-sort:focus-visible .folio-sort-mark.is-idle', $css);
        $this->assertMatchesRegularExpression('/\.folio-sort \{[^}]*cursor: pointer;/', $css);
    }

    public function test_field_groups_have_the_label_above_and_no_border(): void
    {
        $css = $this->css();
        // Radio/checkbox groups and relation fields are fieldsets: borderless, the legend laid out like a
        // field label (floated full width, so every browser places it as a plain block above the options).
        $this->assertMatchesRegularExpression('/:where\(fieldset\.form-group\) \{[^}]*border: 0;[^}]*padding: 0;/', $css);
        $this->assertMatchesRegularExpression('/:where\(fieldset\.form-group\) > legend \{[^}]*float: left;[^}]*width: 100%;[^}]*padding: 0;[^}]*font-weight: 620;/', $css);
        $this->assertMatchesRegularExpression('/:where\(fieldset\.form-group\) > legend ~ \* \{ clear: left; \}/', $css);
        // A boolean: the box inline with its label, the label keeps the field-label weight.
        $this->assertMatchesRegularExpression('/\.folio-boolean > \.form-check \{[^}]*display: inline-flex;[^}]*font-weight: 620;/', $css);
    }

    public function test_chips_look_disabled_in_read_only_forms(): void
    {
        $css = $this->css();
        // input:disabled also matches every box inside a fieldset[disabled].
        $this->assertMatchesRegularExpression('/\.folio-chip:has\(input:disabled\) \{[^}]*color: var\(--c-text-muted\);[^}]*cursor: default;/', $css);
        $this->assertStringContainsString('.form-group.has-error .folio-chip { border-color: var(--c-danger); }', $css);
    }

    public function test_a_failed_group_keeps_the_red_border_on_disabled_chips(): void
    {
        // .folio-chip:has(input:disabled:checked) is (0,3,1) and would beat .form-group.has-error .folio-chip (0,3,0).
        $this->assertMatchesRegularExpression('/\.form-group\.has-error \.folio-chip:has\(input:disabled\)[^{]*\{ border-color: var\(--c-danger\); \}/', $this->css());
    }

    public function test_boolean_shows_its_error_and_its_disabled_state(): void
    {
        $css = $this->css();
        // A native checkbox shows no border colour: the label turns red and the box gets a red outline.
        $this->assertMatchesRegularExpression('/\.folio-boolean\.has-error > \.form-check \{[^}]*color: var\(--c-danger\);/', $css);
        $this->assertMatchesRegularExpression('/\.folio-boolean\.has-error > \.form-check > input \{[^}]*outline: 2px solid var\(--c-danger\);/', $css);
        // In a read-only form (fieldset[disabled]) the label is muted and no longer looks clickable.
        $this->assertMatchesRegularExpression('/\.folio-boolean > \.form-check:has\(input:disabled\) \{[^}]*color: var\(--c-text-muted\);[^}]*cursor: default;/', $css);
    }

    public function test_progress_bars_and_row_headers_are_styled(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression('/:where\(\.folio-shell, \.folio-drawer-body\) :where\(progress\) \{[^}]*appearance: none;/', $css);
        $this->assertStringContainsString('::-webkit-progress-value', $css);
        $this->assertStringContainsString('::-moz-progress-bar', $css);
        // A key/value table (Settings → AI) has th scope=row in the body: aligned like the cells, not centred.
        $this->assertMatchesRegularExpression('/\.folio-table tbody th \{[^}]*padding: 10px 16px;[^}]*text-align: left;/', $css);
    }

    public function test_sub_headings_stay_inside_the_admin_and_ok_badges_are_success(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression('/:where\(\.folio-shell, \.folio-drawer-body\) h3 \{/', $css);
        $this->assertDoesNotMatchRegularExpression('/^h3 \{/m', $css);
        // "done" in an AI run and a complete language in a list read as success, like a published record.
        $this->assertStringContainsString('.folio-badge.is-published, .folio-badge.is-ok { background: var(--c-success-soft); color: var(--c-success); }', $css);
    }

    public function test_row_actions_outside_tables_are_a_row_and_respect_hidden(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression('/(?<![\w-])\.folio-row-actions \{[^}]*display: flex;/', $css);
        // Without this, display: flex would show the "Save all filled" bar that admin.js keeps [hidden].
        $this->assertStringContainsString('.folio-row-actions[hidden] { display: none; }', $css);
    }

    public function test_in_page_surfaces_share_one_radius_and_soft_shadow(): void
    {
        $plain = (string) preg_replace('#/\*.*?\*/#s', '', $this->css());
        foreach (['.folio-card', '.folio-table-wrap', '.folio-panel', '.folio-asset-panel', '.folio-assets-toolbar', '.folio-revisions', '.folio-share'] as $surface) {
            $this->assertMatchesRegularExpression('/(?<![\w-])' . preg_quote($surface, '/') . ' \{[^}]*border: 1px solid var\(--c-border\);[^}]*border-radius: var\(--r\);[^}]*box-shadow: var\(--shadow-sm\);/', $plain, $surface);
        }
    }
}
