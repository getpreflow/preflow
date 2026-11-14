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

    public function test_list_bulk_bar_floats_under_the_table_on_an_inverse_surface(): void
    {
        $css = $this->css();
        // Board List: centred under the table, sticking to the bottom of the window while rows are ticked.
        $this->assertStringContainsString('.folio-table-frame { position: relative; }', $css);
        $this->assertMatchesRegularExpression('/\.folio-bulk-bar \{[^}]*position: sticky; bottom: 16px;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-bulk-bar \{[^}]*width: fit-content;[^}]*margin: 12px auto 0;/', $css);
        $this->assertStringContainsString('.folio-bulk-bar:is(.is-floating, .is-overlay) {', $css, 'the old class name stays an alias for one release');
        // Ghost buttons on the bar.
        $this->assertMatchesRegularExpression('/\.folio-bulk-bar \.btn \{\s*background: transparent;\s*border-color: transparent;/', $css);
        // An inverted bar (light on dark theme, dark on light theme) from one light-dark() token set.
        $this->assertMatchesRegularExpression('/--c-inverse:\s+light-dark\(/', $css);
        $this->assertStringContainsString('background: var(--c-inverse); color: var(--c-inverse-fg);', $css);
        $this->assertStringContainsString('color: var(--c-inverse-danger);', $css);
    }

    public function test_dark_side_uses_the_green_black_ink_palette(): void
    {
        $css = $this->css();
        foreach ([
            '--c-bg' => '#121816', '--c-sidebar-bg' => '#161d1a', '--c-surface' => '#1b2320',
            '--c-input' => '#18201d', '--c-border' => '#2b3531', '--c-border-strong' => '#3b4742',
            '--c-text' => '#e9f0ec', '--c-text-muted' => '#a3b1aa', '--c-accent' => '#34d399',
            '--c-accent-fg' => '#052e1f', '--c-accent-soft' => '#143a2c', '--c-accent-text' => '#6ee7b7',
        ] as $token => $dark) {
            $this->assertMatchesRegularExpression('/' . preg_quote($token, '/') . ':\s+light-dark\(#[0-9a-f]{6}, ' . $dark . '\);/', $css, $token);
        }
    }

    public function test_dark_surface_steps_stay_distinguishable(): void
    {
        // Hover on raised surfaces (menus, filter panel) and neutral badges must not vanish.
        $css = $this->css();
        $dark = [];
        foreach (['--c-surface', '--c-surface-raised', '--c-row-hover', '--c-neutral-soft'] as $token) {
            $this->assertMatchesRegularExpression('/' . preg_quote($token, '/') . ':\s+light-dark\(#[0-9a-f]{6}, (#[0-9a-f]{6})\);/', $css);
            preg_match('/' . preg_quote($token, '/') . ':\s+light-dark\(#[0-9a-f]{6}, (#[0-9a-f]{6})\);/', $css, $m);
            $dark[$token] = $m[1];
        }
        $this->assertSame($dark, array_unique($dark));
        // Lighter is higher on dark: hover sits above the raised surface it appears on.
        $this->assertGreaterThan(hexdec(substr($dark['--c-surface-raised'], 1)), hexdec(substr($dark['--c-row-hover'], 1)));
        $this->assertStringContainsString('--c-text-subtle:    light-dark(#6b6b64, #8b9a93);', $css);
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
        $this->assertMatchesRegularExpression('/:where\(:root\[data-theme="dark"\]\) [^{]*select:not\(\[multiple\]\)\) \{ --folio-chevron: url\("data:image\/svg\+xml,[^"]*%23a3b1aa/', $css);
        $this->assertMatchesRegularExpression('/@media \(prefers-color-scheme: dark\) \{\s*:where\(:root:not\(\[data-theme="light"\]\)\) [^{]*select:not\(\[multiple\]\)\) \{ --folio-chevron: url\("data:image\/svg\+xml,[^"]*%23a3b1aa/', $css);
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
        $this->assertMatchesRegularExpression('/:where\(fieldset\.form-group\) > legend \{[^}]*float: left;[^}]*width: 100%;[^}]*padding: 0;[^}]*font-weight: 500;/', $css); // label weight 13/500 (redesign)
        $this->assertMatchesRegularExpression('/:where\(fieldset\.form-group\) > legend ~ \* \{ clear: left; \}/', $css);
        // A boolean: the box inline with its label, the label keeps the field-label weight.
        $this->assertMatchesRegularExpression('/\.folio-boolean > \.form-check \{[^}]*display: inline-flex;[^}]*font-weight: 500;/', $css);
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
        // "done" in an AI run and a complete language in a list read as published: accent-soft, accent text, accent dot.
        $this->assertStringContainsString('.folio-badge.is-published, .folio-badge.is-ok { background: var(--c-accent-soft); color: var(--c-accent-text); }', $css);
        $this->assertStringContainsString('.folio-badge.is-published::before, .folio-badge.is-ok::before { background: var(--c-accent); }', $css);
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
        // The library's toolbar left the list in 0.76: a row of controls on the page, as the list toolbar (board Assets).
        foreach (['.folio-card', '.folio-table-wrap', '.folio-panel', '.folio-asset-panel', '.folio-revisions', '.folio-share'] as $surface) {
            $this->assertMatchesRegularExpression('/(?<![\w-])' . preg_quote($surface, '/') . ' \{[^}]*border: 1px solid var\(--c-border\);[^}]*border-radius: var\(--r\);[^}]*box-shadow: var\(--shadow-sm\);/', $plain, $surface);
        }
        $this->assertMatchesRegularExpression('/(?<![\w-])\.folio-assets-toolbar \{[^}]*display: flex;/', $plain);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w-])\.folio-assets-toolbar \{[^}]*border:/', $plain, 'no surface around the library toolbar');
    }
    public function test_keyboard_focus_is_a_solid_ring_on_every_filled_control(): void
    {
        $css = $this->css();
        // A surface gap, then the solid accent (>= 3:1 on every surface); the soft --c-focus-ring alone was ~1.5:1.
        $this->assertStringContainsString('--focus-ring: 0 0 0 2px var(--c-surface), 0 0 0 4px var(--c-accent);', $css);
        foreach (['.btn:focus-visible', '.btn-danger:focus-visible', '.folio-chip:has(input:focus-visible)', '.folio-assets-views > a:focus-visible', '.folio-asset-check:has(input:focus-visible)'] as $rule) {
            $this->assertMatchesRegularExpression('/' . preg_quote($rule, '/') . ' \{[^}]*box-shadow: var\(--focus-ring\);/', $css, $rule);
        }
        $this->assertDoesNotMatchRegularExpression('/:focus-visible[^{]*\{[^}]*box-shadow: 0 0 0 3px var\(--c-focus-ring\)/', $css, 'no faint halo as the only focus cue');
        // Native boxes (checkbox, radio, file) get the accent outline instead of the browser's own.
        $this->assertMatchesRegularExpression('/:where\(a, button, summary, \[tabindex\], input:is\(\[type="checkbox"\], \[type="radio"\], \[type="file"\][^)]*\)\):focus-visible \{\s*outline: 2px solid var\(--c-accent\);/', $css);
        $rbac = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/rbac/rbac.css');
        $this->assertStringContainsString('.rbac-switch-input:focus-visible + .rbac-switch { box-shadow: var(--focus-ring); }', $rbac);
        $this->assertStringContainsString('.rbac-chip:has(input:focus-visible) { box-shadow: var(--focus-ring); }', $rbac);
    }

    public function test_the_sign_in_logo_keeps_its_dark_f_and_unpublish_is_outlined(): void
    {
        $css = $this->css();
        // The sign-in's logo: the F in --ink-deep on the emerald page in both themes, as on the brand tile
        // (--c-accent-fg is white in light mode: a white F on #34d399).
        $this->assertMatchesRegularExpression('/\.folio-auth-hello \.folio-logo-f \{ stroke: var\(--ink-deep\); \}/', $css);
        $this->assertStringContainsString(':where(.folio-brand-tile, .folio-rail-brand) .folio-logo-f { stroke: var(--ink-deep);', $css);
        // Unpublish and Move to bin: outlined danger (board); the filled .btn-danger is for what cannot be undone.
        $this->assertMatchesRegularExpression('/\.btn-secondary\[data-tone="danger"\] \{ color: var\(--c-danger\);/', $css);
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/partials/_side_panel.twig');
        $this->assertStringContainsString('<button type="submit" class="btn btn-secondary" data-tone="danger">{{ t(\'folio.publishing.unpublish\') }}</button>', $form);
        // Toast colours come from a token, not light-dark() inside the component.
        $this->assertStringContainsString('--c-inverse-accent: light-dark(', $css);
        $this->assertDoesNotMatchRegularExpression('/\.folio-toast[^{]*\{[^}]*light-dark\(/', $css);
    }

    public function test_failures_on_the_asset_screens_are_error_alerts(): void
    {
        foreach (['index', 'panel', 'edit', 'bin'] as $template) {
            $twig = (string) file_get_contents(dirname(__DIR__, 2) . "/templates/admin/assets/{$template}.twig");
            $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">', $twig, $template);
            $this->assertStringNotContainsString('<div class="folio-notice" role="alert">', $twig, $template . ': not the warning tone');
        }
    }

    public function test_translation_keys_stay_readable_at_phone_width(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.folio-translations td:first-child { min-width: 12rem; }', $css);
    }

    public function test_redesigned_primitives(): void
    {
        $css = $this->css();
        // Buttons: 34px, 13px/500, primary 600; a busy button draws a 14px spinner.
        $this->assertMatchesRegularExpression('/\.btn \{[^}]*min-height: var\(--h-button\);[^}]*font-size: 13px;[^}]*font-weight: 500;/', $css);
        $this->assertStringContainsString('.btn.is-busy::before', $css);
        // Status badges: scheduled is info, failed is danger, changes-not-live has a ring dot.
        $this->assertStringContainsString('.folio-badge.is-scheduled { background: var(--c-info-soft); color: var(--c-info); }', $css);
        $this->assertStringContainsString('.folio-badge.is-failed { background: var(--c-danger-soft); color: var(--c-danger); }', $css);
        $this->assertStringContainsString('.folio-badge.is-changed::before { background: transparent; box-shadow: inset 0 0 0 1.5px currentColor; }', $css);
        // One tabs row; one menu look.
        $this->assertStringContainsString('.folio-tabs {', $css);
        $this->assertStringNotContainsString('.folio-asset-tabs', $css);
        $this->assertMatchesRegularExpression('/\.folio-menu \{[^}]*padding: 5px;[^}]*box-shadow: var\(--shadow-lg\);/', $css);
        // A plain notice is info; one that warns (role="alert" or .is-warning) keeps the warning tone.
        $this->assertMatchesRegularExpression('/\.folio-notice,\s*\.folio-locale-errors \{[^}]*background: var\(--c-info-soft\);/', $css);
        $this->assertStringContainsString('.folio-notice:is([role="alert"], .is-warning), .folio-alert-warning {', $css);
        // Focus ring of fields: accent border and a 3px accent-soft ring.
        $this->assertStringContainsString('box-shadow: 0 0 0 3px var(--c-accent-soft);', $css);
        // The toast's undo bar stops under reduced motion.
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{[^@]*\.folio-toast-timer \{ display: none; \}/', $css);
    }

    public function test_the_phone_filter_sheet_comes_after_the_base_popover_rule(): void
    {
        $css = $this->css();
        // Same specificity: the phone sheet only wins when it comes later in the file.
        $base = strpos($css, "\n.folio-filter-panel {");
        $phone = strpos($css, '.folio-filter-panel { position: fixed;');
        $this->assertNotFalse($base);
        $this->assertNotFalse($phone);
        $this->assertGreaterThan($base, $phone, 'the @media (max-width: 560px) filter sheet must follow the base .folio-filter-panel rule');
    }

    public function test_no_dead_one_column_picker_rule_before_the_base_rule(): void
    {
        $css = $this->css();
        $base = strpos($css, "\n.folio-blocks-items {");
        $this->assertNotFalse($base);
        $this->assertDoesNotMatchRegularExpression('/\.folio-blocks-items \{ grid-template-columns: minmax\(0, 1fr\); \}/', substr($css, 0, $base));
    }

    public function test_the_ink_layer_classes_and_the_sign_in_aliases(): void
    {
        $css = $this->css();
        // Marks: a layer that never takes a click, 2.2 strokes at .78, coloured by the six mark hues.
        $this->assertMatchesRegularExpression('/\.folio-ink-marks,\s*\.folio-auth-pattern \{ position: absolute; pointer-events: none; \}/', $css);
        $this->assertMatchesRegularExpression('/:is\(\.folio-ink-marks, \.folio-auth-pattern\) use \{[^}]*stroke-width: 2\.2;[^}]*opacity: \.78;/', $css);
        for ($i = 1; $i <= 6; $i++) {
            $this->assertStringContainsString(".mc-{$i} { color: var(--mark-{$i}); }", $css);
        }
        $this->assertStringNotContainsString('.pc-1', $css, 'the sign-in uses .mc-N too');
        // The line, the caret on ink and the drift, each with its sign-in alias.
        $this->assertMatchesRegularExpression('/\.folio-ink-line,\s*\.folio-auth-line \{ font-family: var\(--font-mono\); color: var\(--ink-line\); \}/', $css);
        $this->assertMatchesRegularExpression('/\.folio-ink \.folio-caret,\s*\.folio-auth-caret \{ background: var\(--ink-accent\); \}/', $css);
        $this->assertMatchesRegularExpression('/\.folio-ink-drift,\s*\.folio-auth-drift \{ animation: folio-drift /', $css);
        // Reduced motion stops the drift and the caret.
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.folio-ink-drift, \.folio-auth-drift, \.folio-caret, \.folio-auth-caret \{ animation: none !important; \}/', $css);

        // The marks are symbols of icons.svg: 2.2 stroke in currentColor, so the <use> class colours them.
        $svg = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/icons.svg');
        foreach (\Preflow\Folio\View\InkMarks::MARKS as $mark) {
            $this->assertMatchesRegularExpression('/<symbol id="mark-' . $mark . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2\.2"/', $svg);
        }
        foreach (['ui-undo', 'ui-refresh', 'ui-arrow', 'ui-filter', 'ui-clock', 'ui-globe', 'ui-desktop', 'ui-shield'] as $icon) {
            $this->assertStringContainsString('<symbol id="' . $icon . '" viewBox="0 0 24 24"', $svg);
        }
        $pattern = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/_auth_pattern.twig');
        $this->assertStringNotContainsString('<symbol', $pattern, 'no second copy of the marks');
        $this->assertSame(47, preg_match_all('/<use href="\{\{ icons \}\}#mark-[a-z]+" class="mc-[1-6]"/', $pattern));
    }

    public function test_the_skip_link_shows_on_focus_only(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression('/\.folio-skip \{[^}]*position: fixed;[^}]*transform: translateY\(calc\(-100% - 16px\)\);/', $css);
        $this->assertStringContainsString('.folio-skip:focus { transform: none; }', $css);
    }
}
