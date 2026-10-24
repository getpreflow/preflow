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

    public function test_sortable_headers_show_an_idle_mark(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.folio-sort-mark.is-idle', $css);
        $this->assertStringContainsString('.folio-sort-mark.is-idle::before { content: "↕"; }', $css); // not in the header's text
        $this->assertStringContainsString('.folio-sort:hover .folio-sort-mark.is-idle, .folio-sort:focus-visible .folio-sort-mark.is-idle', $css);
        $this->assertMatchesRegularExpression('/\.folio-sort \{[^}]*cursor: pointer;/', $css);
    }
}
