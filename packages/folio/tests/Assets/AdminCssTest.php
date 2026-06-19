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

    public function test_styles_shell_and_reused_form_hooks(): void
    {
        $css = $this->css();
        foreach (['.folio-shell', '.folio-sidebar', '.folio-table', '.folio-card', '.btn-primary', '.form-group', '.has-error'] as $sel) {
            $this->assertStringContainsString($sel, $css);
        }
    }
}
