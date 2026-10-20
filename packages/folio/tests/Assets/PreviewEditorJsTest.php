<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

/** The preview-only editor bridge (runs inside the preview iframe). */
final class PreviewEditorJsTest extends TestCase
{
    private function file(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/assets/' . $name;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_talks_to_the_editor_over_same_origin_messages(): void
    {
        $js = $this->file('preview-editor.js');
        $this->assertStringContainsString("source: 'folio-preview'", $js); // preview -> editor
        $this->assertStringContainsString("'folio-editor'", $js);          // editor -> preview
        $this->assertStringContainsString('e.origin !== window.location.origin', $js); // origin pin
        $this->assertStringContainsString('window.location.origin)', $js); // explicit target origin
    }

    public function test_click_selects_instead_of_navigating(): void
    {
        $js = $this->file('preview-editor.js');
        $this->assertStringContainsString('preventDefault', $js);
        $this->assertStringContainsString('data-folio-edit', $js);  // field target
        $this->assertStringContainsString('data-folio-field', $js); // top-level field region
        $this->assertStringContainsString('data-folio-block', $js);
    }

    public function test_highlight_scrolls_into_view(): void
    {
        $js = $this->file('preview-editor.js');
        $this->assertStringContainsString('is-folio-active', $js);
        $this->assertStringContainsString('scrollIntoView', $js);
    }

    public function test_styles_use_no_remote_resources(): void
    {
        $css = $this->file('preview-editor.css');
        $this->assertStringContainsString('[data-folio-block]', $css);
        $this->assertDoesNotMatchRegularExpression('#url\(|@import#', $css);
    }
}
