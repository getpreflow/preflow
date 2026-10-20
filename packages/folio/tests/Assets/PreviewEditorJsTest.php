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

    public function test_links_outside_blocks_do_not_navigate(): void
    {
        $js = $this->file('preview-editor.js');
        $this->assertStringContainsString("closest('a[href]')", $js);
        $this->assertStringContainsString("charAt(0) !== '#'", $js); // same-page anchors still scroll
    }

    public function test_highlight_scrolls_into_view(): void
    {
        $js = $this->file('preview-editor.js');
        $this->assertStringContainsString("box('is-active')", $js); // the active outline is an overlay box
        $this->assertStringContainsString('scrollIntoView', $js);
    }

    public function test_outlines_are_drawn_in_an_overlay_not_on_the_site_elements(): void
    {
        $js = $this->file('preview-editor.js');
        $this->assertStringContainsString("'folio-pe-overlay'", $js);
        $this->assertStringContainsString('getBoundingClientRect', $js);
        $this->assertStringNotContainsString("classList.add('is-folio", $js); // site elements get no classes
        $this->assertStringNotContainsString('appendChild(badge)', $js.'');  // nor injected children
        $css = $this->file('preview-editor.css');
        $this->assertDoesNotMatchRegularExpression('/\[data-folio-block\][^{]*\{[^}]*(position|outline)/', $css); // layout-neutral
    }

    public function test_styles_use_no_remote_resources(): void
    {
        $css = $this->file('preview-editor.css');
        $this->assertStringContainsString('.folio-pe-overlay', $css);
        $this->assertDoesNotMatchRegularExpression('#url\(|@import#', $css);
    }
}
