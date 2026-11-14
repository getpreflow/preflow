/* Folio editor frame (form.twig, 0.75): the save state, the Publish split button, the side panel (its
 * record controls and its phone sheet), the editor's shortcuts and the preview bar's Copy preview link.
 * Vanilla, no build step; loaded after admin.js on editor pages, and uses what admin.js shares on
 * window.Folio (formOf, submitWith, saveState, shortcuts, the folio:edit event).
 *
 * The record form (#folio-record) is posted by buttons outside it (the topbar) and holds controls
 * outside it (the side panel), all naming it with form="…". admin.js announces every edit of its
 * controls as "folio:edit" on the form (the autosave trigger); this script shows it in the save state.
 */
(function () {
    'use strict';

    var Folio = window.Folio || {};

    function t(key) {
        var el = document.getElementById('folio-i18n');
        var words = {};
        try { words = el ? JSON.parse(el.textContent || '{}') : {}; } catch (e) { words = {}; }
        return Object.prototype.hasOwnProperty.call(words, key) ? words[key] : ''; // never the raw key (JsTextKeysTest)
    }

    // ---- Save state: the first edit after a save says "Unsaved changes"; autosave then shows "Saving…",
    // and its answer replaces the element ("Draft saved 19:37").
    var autosaving = null; // the form whose autosave request runs
    var queuedSave = null; // the button of a ⌘S or ⌘↵ that came while it ran (the later one wins)

    function onRecordEdit(e) {
        var form = e.target;
        var stateEl = Folio.saveState.of(form);
        if (!stateEl || stateEl.classList.contains('is-unsaved') || stateEl.classList.contains('is-saving')) { return; }
        Folio.saveState.set(stateEl, 'unsaved', stateEl.getAttribute('data-text-unsaved') || '');
    }

    function onAutosaveStart(e) {
        var elt = e.detail && e.detail.elt;
        if (e.defaultPrevented || !elt || elt.tagName !== 'FORM') { return; }
        var stateEl = Folio.saveState.of(elt);
        if (!stateEl) { return; }
        autosaving = elt;
        Folio.saveState.set(stateEl, 'saving', stateEl.getAttribute('data-text-saving') || '');
    }

    // The autosave ended (saved, refused or failed): a ⌘S or ⌘↵ that waited for it saves or publishes
    // now, once htmx has given the buttons back.
    function onAutosaveEnd(e) {
        var elt = e.detail && e.detail.elt;
        if (!elt || elt !== autosaving) { return; }
        autosaving = null;
        var button = queuedSave;
        queuedSave = null;
        if (button) { setTimeout(function () { Folio.submitWith(button); }, 0); }
    }

    // ---- Side panel record controls: a control added later inside a side fieldset (a relation row, a
    // picked asset, a cloned template row) gets the fieldset's form, like those rendered on the server
    // (FormOwner): Folio.ownControls (admin.js), which the scripts that insert controls also call at
    // once, before they announce the change. <button type="button"> stays without one.
    function own(node) {
        if (typeof Folio.ownControls === 'function') { Folio.ownControls(node); }
    }

    function initSideFields() {
        if (typeof window.MutationObserver !== 'function') { return; }
        document.querySelectorAll('.folio-side-fields[form]').forEach(function (fieldset) {
            new window.MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes || [], own);
                });
            }).observe(fieldset, { childList: true, subtree: true });
        });
    }

    // ---- Publish split button --------------------------------------------------
    // Publish, and a chevron opening "Publish at…" (a <details>, so it works without script). With
    // script: aria-expanded on the chevron, Escape and a click elsewhere close it (Escape returns the
    // focus to the chevron), opening moves the focus to the date; a date set turns Publish into
    // "Schedule", and the hint under the date says when ("in 2 days").

    // "In 2 days": the date field's helper (fields.js, loaded before this script), so both hints agree.
    function relativeWhen(value) {
        return typeof Folio.relativeTime === 'function' ? Folio.relativeTime(value) : '';
    }

    // Publish publishes now (_action=publish); with a date it schedules: _action=schedule, a save that
    // keeps the date (the server publishes it when due). "Publish at this time" waits for a date.
    function publishLabel(group) {
        var main = group.querySelector('[data-folio-publish]');
        var input = group.querySelector('[data-folio-publish-at]');
        var hint = group.querySelector('[data-folio-date-hint]');
        var at = group.querySelector('[data-folio-schedule]');
        if (!main || !input) { return; }
        var scheduled = String(input.value || '') !== '';
        var text = main.getAttribute(scheduled ? 'data-label-schedule' : 'data-label-publish') || '';
        if (!main._folioBusy) {
            main.setAttribute('value', scheduled ? 'schedule' : 'publish');
            main.value = scheduled ? 'schedule' : 'publish';
            var busy = main.getAttribute(scheduled ? 'data-busy-schedule' : 'data-busy-publish');
            if (busy) { main.setAttribute('data-busy-label', busy); }
            if (text) {
                main.textContent = text;
                main.setAttribute('title', text + ' (' + Folio.shortcuts.modHint('↵') + ')');
            }
        }
        if (at && !at._folioBusy) { at.disabled = !scheduled; }
        if (hint) { hint.textContent = scheduled ? relativeWhen(input.value) : ''; }
    }

    function splitMenuOf(group) { return group.querySelector('[data-folio-publish-menu]'); }

    function setSplitOpen(group, open, refocus) {
        var menu = splitMenuOf(group);
        if (!menu) { return; }
        var summary = menu.querySelector('summary');
        menu.open = open;
        if (open) { menu.setAttribute('open', ''); } else { menu.removeAttribute('open'); }
        if (summary) { summary.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if (open) {
            var input = group.querySelector('[data-folio-publish-at]');
            if (input && typeof input.focus === 'function') { input.focus(); }
        } else if (refocus && summary) {
            summary.focus();
        }
    }

    function initSplitButtons(root) {
        root.querySelectorAll('[data-folio-split-button]').forEach(function (group) {
            if (group.hasAttribute('data-folio-split-ready')) { return; }
            group.setAttribute('data-folio-split-ready', '');
            var menu = splitMenuOf(group);
            var summary = menu ? menu.querySelector('summary') : null;
            if (summary) { summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false'); }
            if (menu) {
                // The browser toggled it (a click on the chevron): keep aria-expanded and the focus in step.
                menu.addEventListener('toggle', function () {
                    var open = !!menu.open;
                    if (summary && summary.getAttribute('aria-expanded') !== String(open)) { setSplitOpen(group, open, false); }
                });
            }
            publishLabel(group);
        });
    }

    // Escape (capture: before the shortcut registry) closes an open menu.
    function onSplitKeydown(e) {
        var group = e.target && e.target.closest ? e.target.closest('[data-folio-split-ready]') : null;
        var menu = group ? splitMenuOf(group) : null;
        if (e.key === 'Escape' && menu && menu.open) {
            e.preventDefault();
            setSplitOpen(group, false, true);
        }
    }

    function onSplitClick(e) {
        var target = e.target;
        // The date's clear button is fields.js's (every .folio-date-input); its input event updates the label.
        if (target && target.closest && target.closest('[data-folio-date-clear]')) { return; }
        // A click elsewhere closes an open menu.
        document.querySelectorAll('[data-folio-split-ready]').forEach(function (g) {
            var m = splitMenuOf(g);
            var inside = target && target.closest ? target.closest('[data-folio-split-ready]') === g : false;
            if (m && m.open && !inside) { setSplitOpen(g, false, false); }
        });
    }

    function onDateEdit(e) {
        var target = e.target;
        if (target && target.hasAttribute && target.hasAttribute('data-folio-publish-at')) {
            var owner = target.closest('[data-folio-split-ready]');
            if (owner) { publishLabel(owner); }
        }
    }

    // ---- The side panel at phone width: a sheet the topbar's toggle opens; Esc and × close it.
    var sidePanel = null;

    function sidePanelOpen() {
        return !!(sidePanel && sidePanel.classList.contains('is-open'));
    }

    function openSidePanel() {
        if (!sidePanel) { return; }
        sidePanel.classList.add('is-open');
        var toggle = document.querySelector('[data-folio-side-toggle]');
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
        var close = sidePanel.querySelector('[data-folio-side-close]');
        if (close) { close.focus(); }
    }

    function closeSidePanel(refocus) {
        if (!sidePanelOpen()) { return false; }
        sidePanel.classList.remove('is-open');
        var toggle = document.querySelector('[data-folio-side-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            if (refocus) { toggle.focus(); }
        }
        return true;
    }

    function initSidePanel() {
        sidePanel = document.querySelector('[data-folio-side]');
        var toggle = document.querySelector('[data-folio-side-toggle]');
        if (!sidePanel || !toggle) { return; }
        sidePanel.setAttribute('data-folio-side-ready', '');
        toggle.hidden = false;
        toggle.addEventListener('click', function () {
            if (sidePanelOpen()) { closeSidePanel(true); } else { openSidePanel(); }
        });
        var close = sidePanel.querySelector('[data-folio-side-close]');
        if (close) { close.addEventListener('click', function () { closeSidePanel(true); }); }
    }

    // A link to a tab (the search result's "add one in SEO & sharing") opens it, closing the sheet.
    function onTabLink(e) {
        var link = e.target && e.target.closest ? e.target.closest('[data-folio-tab-link]') : null;
        if (!link) { return; }
        var tab = document.querySelector('[data-folio-tabs-ready] [data-folio-tab="' + link.getAttribute('data-folio-tab-link').replace(/[^\w-]/g, '') + '"]');
        if (!tab) { return; }
        e.preventDefault();
        closeSidePanel(false);
        tab.click();
        tab.focus();
    }

    // The browser shows its message at the first invalid field, so that field must be visible: one in
    // the closed phone sheet (an empty required address) opens the sheet, unless the form has one first.
    function onInvalidInSide(e) {
        var target = e.target;
        var side = target && target.closest ? target.closest('[data-folio-side-ready]') : null;
        if (!side || sidePanelOpen() || !window.getComputedStyle || window.getComputedStyle(side).display !== 'none') { return; }
        var form = Folio.formOf(target);
        if (form && form.querySelector && form.querySelector(':invalid')) { return; }
        openSidePanel();
    }

    // ---- Editor shortcuts: ⌘S / Ctrl+S saves (the Save draft or Save button, also while typing; the
    // browser's save dialog never opens in the editor; during an autosave it waits for it and saves
    // then), ⌘↵ / Ctrl+Enter publishes (while Publish is there and enabled; during an autosave it waits
    // for it likewise), Esc closes the side panel sheet (phone width).
    // Submits button now; while the record's autosave runs (hx-disabled-elt has disabled it) it is sent
    // right after the autosave instead. False when neither happens (Publish disabled for another reason).
    function submitOrQueue(button) {
        if (Folio.submitWith(button)) { return true; }
        if (button && autosaving && Folio.formOf(button) === autosaving) {
            queuedSave = button;
            return true;
        }
        return false;
    }

    function initEditorShortcuts() {
        if (document.querySelector('[data-folio-save]')) {
            Folio.shortcuts.add({ key: 's', mod: true, typing: true, label: t('shortcut_save'), run: function () {
                submitOrQueue(document.querySelector('[data-folio-save]'));
                return true;
            } });
        }
        if (document.querySelector('[data-folio-publish]')) {
            Folio.shortcuts.add({ key: 'Enter', mod: true, typing: true, label: t('shortcut_publish'), run: function () {
                return submitOrQueue(document.querySelector('[data-folio-publish]'));
            } });
        }
        if (sidePanel && document.querySelector('[data-folio-side-toggle]')) {
            Folio.shortcuts.add({ key: 'Escape', typing: true, label: t('shortcut_close_side'), hint: 'Esc', run: function () {
                return closeSidePanel(true);
            } });
        }
    }

    // ---- Preview bar: Copy preview link. The button posts the share route (htmx) and its answer lands
    // under the bar (#folio-share: the address, Copy, Revoke). The clipboard write starts in the click
    // itself (Folio.copyLater, with the address still on its way) and takes the answer's address when it
    // comes. Then the button reads "Link copied · 7 days" (its data-text-copied, the real lifetime) for a
    // few seconds, or says the link could not be copied (the answer under the bar is then the way). A
    // role="status" next to the button announces it.
    var COPIED_MS = 4000;
    var shareCopy = null; // { button, resolve } while a copy waits for the answer

    function onShareClick(e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-folio-share-copy]') : null;
        if (!btn || typeof Folio.copyLater !== 'function') { return; }
        if (shareCopy) { shareCopy.resolve(null); }
        var pending = { button: btn, resolve: null };
        var text = new Promise(function (resolve) { pending.resolve = resolve; });
        shareCopy = pending;
        Folio.copyLater(text).then(function (copied) { showCopyState(btn, copied); });
    }

    function onShareAnswer(e) {
        var btn = e.detail && e.detail.elt;
        if (!shareCopy || btn !== shareCopy.button) { return; }
        var pending = shareCopy;
        shareCopy = null;
        var box = document.getElementById('folio-share');
        var input = box ? box.querySelector('.folio-share-url') : null;
        pending.resolve(e.type === 'htmx:afterRequest' && e.detail.successful && input && input.value ? input.value : null);
    }

    function showCopyState(btn, copied) {
        var status = btn.querySelector('.folio-share-status');
        var announce = btn.parentNode ? btn.parentNode.querySelector('[data-folio-share-announce]') : null;
        var text = copied ? (btn.getAttribute('data-text-copied') || '') : t('link_not_copied');
        btn.classList.toggle('is-copied', copied);
        btn.classList.toggle('is-copy-failed', !copied);
        if (status) { status.textContent = text; }
        if (announce) { announce.textContent = text; }
        clearTimeout(btn._folioCopied);
        btn._folioCopied = setTimeout(function () {
            btn.classList.remove('is-copied', 'is-copy-failed');
            if (status) { status.textContent = ''; }
            if (announce) { announce.textContent = ''; }
        }, COPIED_MS);
    }

    function boot() {
        if (!Folio.saveState || !Folio.shortcuts || !Folio.submitWith) { return; } // admin.js did not run
        // The buttons' shortcut hints say Ctrl+ where there is no ⌘.
        if (!Folio.shortcuts.isMac()) {
            document.querySelectorAll('[aria-keyshortcuts][title]').forEach(function (el) {
                el.setAttribute('title', el.getAttribute('title').replace(/⌘/g, 'Ctrl+'));
            });
        }
        document.querySelectorAll('form[hx-post]').forEach(function (form) { form.addEventListener('folio:edit', onRecordEdit); });
        document.addEventListener('htmx:beforeRequest', onAutosaveStart);
        document.addEventListener('htmx:afterRequest', onAutosaveEnd);
        initSideFields();
        initSplitButtons(document);
        document.addEventListener('htmx:load', function (e) { if (e.detail && e.detail.elt && e.detail.elt.querySelectorAll) { initSplitButtons(e.detail.elt); } });
        document.addEventListener('keydown', onSplitKeydown, true);
        document.addEventListener('click', onSplitClick);
        document.addEventListener('input', onDateEdit);
        document.addEventListener('change', onDateEdit);
        initSidePanel();
        document.addEventListener('click', onTabLink);
        document.addEventListener('invalid', onInvalidInSide, true);
        initEditorShortcuts();
        document.addEventListener('click', onShareClick);
        ['htmx:afterRequest', 'htmx:responseError', 'htmx:sendError', 'htmx:timeout', 'htmx:sendAbort'].forEach(function (name) {
            document.addEventListener(name, onShareAnswer);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
