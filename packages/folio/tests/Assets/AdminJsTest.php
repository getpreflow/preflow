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
        foreach (['data-folio-matrix', 'data-matrix-add', 'data-matrix-remove', 'template[data-matrix-template]'] as $hook) {
            $this->assertStringContainsString($hook, $js);
        }
        // Rows added in the browser are copies of the server's row (MatrixFieldType's template: grip and
        // trash, since 0.75); moving them is fields.js's (the grip), so there are no Up / Down buttons.
        $this->assertStringContainsString('var row = fromTemplate(rowTemplate);', $js);
        $this->assertStringNotContainsString('data-matrix-up', $js);
    }

    public function test_drawer_close_is_a_folio_button(): void
    {
        $this->assertStringContainsString("close.className = 'btn btn-secondary btn-sm folio-drawer-close';", $this->js());
    }

    public function test_addrow_builds_view_select_from_options(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('opts.views', $js);                 // reads declared views
        $this->assertStringContainsString('[view]', $js);                     // emits the [view] input name
        $this->assertStringContainsString('data-matrix-view', $js);           // matches server markup
        $this->assertStringContainsString("o.textContent = v === '' ? folioT('default') : v;", $js); // in the interface language
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

    public function test_drag_and_keyboard_reorder(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("'dragstart'", $js);
        $this->assertStringContainsString("'drop'", $js);
        $this->assertStringContainsString('[data-block-handle]', $js);
        $this->assertStringContainsString('[data-blocks-live]', $js);   // screen reader announcements
        $this->assertStringContainsString("e.key === 'Escape'", $js);    // cancel a keyboard move
        $this->assertStringContainsString('parentNode !== ', $js);       // drops stay within one list
    }

    public function test_undo_remove_and_unsaved_guard(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("folioT('undo')", $js);       // toast action after removing a block
        $this->assertStringContainsString('[data-folio-toasts]', $js);
        $this->assertStringContainsString("'beforeunload'", $js);       // leaving with unsaved changes asks
        $this->assertStringContainsString("hasAttribute('hx-post')", $js); // autosaving forms are not guarded
    }

    public function test_region_patch_falls_through_when_anything_else_changed(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("skeleton(doc.body, '[data-folio-field]')", $js); // page around the regions must be unchanged
        $this->assertStringContainsString('function headAssets(', $js);                    // new component CSS in <head> forces a reload
        $this->assertStringContainsString('headAssets(doc) !== headAssets(incoming)', $js);
    }

    public function test_follow_up_fixes(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("'#preflow-debug-toolbar'", $js); // debug toolbar ignored when comparing renders
        $this->assertStringContainsString("'#folio-pe-overlay'", $js);      // so is the preview's own overlay
        $this->assertStringContainsString('lifted.moved', $js);             // Escape re-saves the restored order
        $this->assertStringContainsString('if (l.moved || l.card.nextSibling !== l.next)', $js); // so does a drop back in place
        $this->assertMatchesRegularExpression('/function initMatrix\(root\).*?notifyChange\(root\).*?\n    \}\n/s', $js); // matrix edits count as changes
        $this->assertMatchesRegularExpression('/function initUnsavedGuard\(\).*?defaultPrevented/s', $js); // a cancelled confirm keeps the guard
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
        // Duplicate is a server fragment (fresh uids, re-rendered editors), not a DOM clone: the one
        // cloneNode copies a server <template> (a matrix row, an asset card).
        $this->assertSame(1, substr_count($js, 'cloneNode'));
        $this->assertMatchesRegularExpression('/function fromTemplate\(template\) \{[^}]*\}[^}]*cloneNode\(true\)/', $js);
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
        $this->assertStringContainsString("folioT('not_saved_offline')", $js);
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
        // The picker for extension scripts (behaviour: tests/js/choose-assets.test.mjs).
        $this->assertStringContainsString('window.Folio.chooseAssets = chooseAssets;', $js);
    }

    public function test_htmx_403_message_is_shown_as_a_toast(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("addEventListener('folio:error'", $js); // HX-Trigger event from Forbidden
        $this->assertStringContainsString('function showToast(', $js);
        $this->assertStringContainsString('xhr.status === 403', $js);              // autosave says "no access", not "offline"
    }

    public function test_list_search_swaps_results_without_reload(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('[data-folio-list-search]', $js);   // the search input
        $this->assertStringContainsString('[data-folio-list-results]', $js);  // the swapped region
        $this->assertStringContainsString("'_partial', '1'", $js);            // results-only response
        $this->assertStringContainsString('history.replaceState', $js);       // the URL keeps the view
        $this->assertStringContainsString('300', $js);                        // debounce
        $this->assertStringContainsString('AbortController', $js);            // stale responses never win
    }

    public function test_relation_picker_announces_pick_and_remove_as_change(): void
    {
        // Since 0.75 the relation editor is fields.js's (its behaviour: tests/js/fields.test.mjs).
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/fields.js');
        $this->assertStringContainsString("el.dispatchEvent(new Event('change', { bubbles: true }))", $js);
        $this->assertStringContainsString("changed(row.querySelector('input[type=\"hidden\"]'));", $js);
        $this->assertStringContainsString('changed(announceOn);', $js);
    }

    public function test_delete_forms_confirm_the_delete_plan(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("hasAttribute('data-delete-plan')", $js);      // the form opts in
        $this->assertStringContainsString("body.append('ids[]'", $js);                     // posts the ids
        $this->assertStringContainsString("body.append('_csrf_token'", $js);               // with the form's token
        $this->assertStringContainsString("folioAlert({ title: folioT('delete_blocked'), message: plan.confirm", $js); // restricted: only the reason (styled dialog)
        $this->assertStringContainsString("folioConfirm({ title: question, message: plan.confirm.split('\\n')[0], items: lines", $js); // else the summary, its lines as the list
        $this->assertStringContainsString("document.addEventListener('submit', onDeletePlanSubmit)", $js);

        $list = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/_list_results.twig');
        $this->assertStringContainsString('data-delete-plan="{{ prefix }}/{{ type }}/_delete-plan" data-delete-id="{{ row.id }}"', $list);
        $this->assertStringContainsString('data-confirm="{{ t(\'folio.list.confirm_delete\') }}"', $list); // the no-JS / failure fallback
    }

    public function test_bulk_bar_follows_the_ticked_rows(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('[data-folio-bulk-item]', $js);              // a row's tick box
        $this->assertStringContainsString('[data-folio-bulk-all]', $js);               // select all on the page
        $this->assertStringContainsString("folioT('selected_count', { count: n })", $js); // "3 selected"
        $this->assertStringContainsString('bar.hidden = n === 0', $js);                // the bar shows with a tick
        $this->assertStringContainsString("bar.classList.add('is-floating')", $js);      // with JS it floats under the table
        $this->assertStringContainsString('initBulk(results)', $js);                   // a swapped region starts unticked
        $this->assertStringContainsString("document.addEventListener('change', onBulkChange)", $js);
        $this->assertStringContainsString("document.addEventListener('reset', onBulkReset)", $js); // Clear
        // Buttons of the bar carry their own confirm / delete plan; the plan posts the ticked ids.
        $this->assertStringContainsString('e.submitter', $js);
        $this->assertStringContainsString("var submitter = e.submitter || (src !== f ? src : null);", $js); // the clicked bar button is sent again
        $this->assertStringContainsString('form.requestSubmit(submitter && formOf(submitter) === form ? submitter : undefined)', $js);
        $this->assertStringContainsString("el.name === 'ids[]' && el.checked", $js);
        $bulk = (int) strpos($js, 'function bulkUpdate');
        $this->assertStringNotContainsString('.style.', substr($js, $bulk, (int) strpos($js, 'function onEnablesChange') - $bulk), 'the bar shows and hides by [hidden], no inline styles');
    }

    /**
     * The translations screen's behaviour (save all posts every changed field, hidden rows included;
     * Discard; the styled leave dialog through Folio.unsaved; Enter; the group select) is tested in
     * tests/js/translations.test.mjs (Review Focus 5). Here: it boots, and its private beforeunload is gone.
     */
    public function test_translations_boot_through_the_unsaved_guard(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('        initTranslations();', $js);
        $this->assertStringNotContainsString('initTranslationsSaveAll', $js);
        $start = (int) strpos($js, 'function initTranslations()');
        $body = substr($js, $start, (int) strpos($js, 'function plural(', $start) - $start);
        $this->assertStringContainsString('unsaved.guarded.push(bar);', $body);
        $this->assertStringNotContainsString("addEventListener('beforeunload'", $body, 'the guard\'s beforeunload, suppressed once after a confirmed leave');
    }

    public function test_a_failed_run_step_shows_continue(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("['htmx:responseError', 'htmx:sendError', 'htmx:timeout'].forEach", $js);
        // The AI run's progress and any partial marked data-folio-run-progress (the SEO audit's).
        $this->assertStringContainsString("if (!elt || (elt.id !== 'folio-ai-progress' && !(elt.hasAttribute && elt.hasAttribute('data-folio-run-progress')))) { return; }", $js);
        $this->assertStringContainsString("elt.querySelector('[data-folio-ai-stalled], [data-folio-run-stalled]')", $js);
    }
}
