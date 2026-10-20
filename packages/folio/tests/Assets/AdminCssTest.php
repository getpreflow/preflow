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
}
