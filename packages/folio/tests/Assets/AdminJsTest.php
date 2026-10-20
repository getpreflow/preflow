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

    public function test_defines_live_preview(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-folio-preview', $js);   // view switch hook
        $this->assertStringContainsString('new FormData(', $js);        // serializes the form
        $this->assertStringContainsString('folio-preview-frame', $js);  // iframe navigated by a form POST (own CSP + nonce)
        $this->assertStringContainsString('data-preview-viewport', $js); // viewport presets live in form.twig
    }

    public function test_split_view_resizes_and_remembers(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('[data-folio-split]', $js);
        $this->assertStringContainsString("'folio-split-width'", $js);   // remembered pane width
        $this->assertStringContainsString("'folio-split-mode'", $js);    // remembered view mode
        $this->assertStringContainsString('[data-folio-resizer]', $js);
        $this->assertStringContainsString('setPointerCapture', $js);      // drag keeps tracking outside the handle
        $this->assertStringContainsString("'--folio-form-w'", $js);       // width as a custom property, not an inline style attribute
    }

    public function test_click_to_edit_and_two_way_highlight(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("data.source === 'folio-preview'", $js); // preview -> editor messages
        $this->assertStringContainsString("data.type === 'select'", $js);          // click in the preview
        $this->assertStringContainsString("'is-located'", $js);                    // card flash
        $this->assertStringContainsString("'folio-focus-mode'", $js);              // remembered focus mode
        $this->assertStringContainsString("'focusin'", $js);                       // form focus -> preview highlight
        $this->assertStringContainsString("type: 'highlight'", $js);
    }

    public function test_patches_changed_blocks_and_keeps_scroll(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('function patchBlocks(', $js); // block-level patch by _uid
        $this->assertStringContainsString('.outerHTML =', $js);           // a changed block is replaced in place
        $this->assertStringContainsString('scrollY', $js);                // full reloads keep the scroll position
        $this->assertStringContainsString('folio-pe-badge', $js);         // editor artefacts are ignored when comparing
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

    public function test_autosave_yields_to_submit_and_reports_failures(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("'htmx:beforeRequest'", $js);
        $this->assertStringContainsString('_folioSubmitting', $js);
        $this->assertStringContainsString("'htmx:responseError'", $js);
        $this->assertStringContainsString("'htmx:sendError'", $js);
        $this->assertStringContainsString('Not saved', $js);
    }

    public function test_library_focal_and_pick_handlers(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('[data-folio-focal]', $js);
        $this->assertStringContainsString('[data-folio-pick]', $js);
        $this->assertStringContainsString("source: 'folio-assets'", $js);
    }

    public function test_asset_field_choose_remove_and_message(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('[data-folio-asset-choose]', $js);
        $this->assertStringContainsString('[data-folio-asset-remove]', $js);
        $this->assertStringContainsString("data.source === 'folio-assets'", $js);
        $this->assertStringContainsString('e.origin !== window.location.origin', $js);
    }
}
