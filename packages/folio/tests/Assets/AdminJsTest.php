<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class AdminJsTest extends TestCase
{
    private function js(): string
    {
        $path = dirname(__DIR__, 2) . '/assets/admin.js';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_defines_matrix_add_remove_reorder(): void
    {
        $js = $this->js();
        foreach (['data-folio-matrix', 'data-matrix-add', 'data-matrix-remove', 'data-matrix-up', 'data-matrix-down'] as $hook) {
            $this->assertStringContainsString($hook, $js);
        }
    }

    public function test_addrow_builds_view_select_from_options(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('opts.views', $js);                 // reads declared views
        $this->assertStringContainsString('[view]', $js);                     // emits the [view] input name
        $this->assertStringContainsString('data-matrix-view', $js);           // matches server markup
        $this->assertStringContainsString('<option value="">Default</option>', $js);
    }

    public function test_defines_drawer_create_and_origin_checked_message_handler(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-matrix-create', $js);
        $this->assertStringContainsString('openDrawer', $js);
        $this->assertStringContainsString('/new?_drawer=1', $js);                  // create url flag
        $this->assertStringContainsString("addEventListener('message'", $js);      // cross-frame listener
        $this->assertStringContainsString('e.origin !== window.location.origin', $js); // origin pin
        $this->assertStringContainsString("data.source !== 'folio-drawer'", $js);  // shape guard
        $this->assertStringContainsString("'/label'", $js);                        // label fetch path
    }

    public function test_defines_live_preview_overlay(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-folio-preview', $js);   // button hook
        $this->assertStringContainsString('folio-preview', $js);        // overlay class
        $this->assertStringContainsString('new FormData(', $js);        // serializes the form
        $this->assertStringContainsString('folio-preview-frame', $js);  // iframe navigated by a form POST (own CSP + nonce)
        $this->assertStringContainsString("'768px'", $js);              // tablet preset
        $this->assertStringContainsString("'375px'", $js);              // mobile preset
    }

    public function test_defines_surgical_preview_patch(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-folio-field', $js); // addressing marker the client queries
        $this->assertStringContainsString('DOMParser', $js);        // parses the incoming HTML
        $this->assertStringContainsString('.innerHTML =', $js);     // surgical per-region write
        $this->assertStringContainsString('.target =', $js);         // full-reload fallback: form POST into the iframe
    }

    public function test_confirm_is_handled_by_data_attribute(): void
    {
        $this->assertStringContainsString('data-confirm', $this->js());
    }

    public function test_preview_does_not_use_srcdoc(): void
    {
        // A srcdoc document inherits the admin page's CSP and would block the preview's nonced assets.
        $this->assertStringNotContainsString('srcdoc', $this->js());
    }

    public function test_block_editor_hooks(): void
    {
        $js = $this->js();
        foreach (['data-block-up', 'data-block-down', 'data-block-remove', 'data-block-collapse', 'htmx:load'] as $hook) {
            $this->assertStringContainsString($hook, $js);
        }
        // Duplicate is a server fragment (fresh uids, re-rendered editors), not a DOM clone.
        $this->assertStringNotContainsString('cloneNode', $js);
    }

    public function test_matrix_init_is_idempotent(): void
    {
        // htmx fires htmx:load on <body> at start-up; matrices boot() already set up
        // must not get a second set of listeners.
        $this->assertMatchesRegularExpression('/function initMatrix\(root\) \{\s*if \(root\._folioMatrix\) \{ return; \}\s*root\._folioMatrix = true;/', $this->js());
    }

    public function test_block_actions_dispatch_change(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('function notifyChange', $js);
        $this->assertStringContainsString("new Event('change', { bubbles: true })", $js);
        $this->assertSame(1, preg_match('/data-block-remove[\s\S]*notifyChange\(/', $js));
    }
}
