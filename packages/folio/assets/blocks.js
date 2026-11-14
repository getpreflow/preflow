/* Folio blocks editor (blocks/field.twig, blocks/card.twig; 0.75): the "Add block" picker, the cards'
 * "…" menus, Collapse all and the block count; the "+" insert lines between cards and "/" (0.77). Vanilla, no build step; loaded after admin.js where a
 * blocks field is on the page, and uses what admin.js shares on window.Folio (menus, preview). Moving,
 * removing, duplicating and dragging cards stay in admin.js; so does announcing a card that htmx adds
 * as an edit of the record form (folio:edit, the unsaved-changes guard).
 *
 * Everything is delegated from the document or set up again on htmx:load, so cards and nested block
 * fields that arrive after the page loaded work the same.
 */
(function () {
    'use strict';

    var Folio = window.Folio || {};

    function own(parent, selector) {
        return parent ? Array.prototype.filter.call(parent.children || [], function (c) { return c.matches && c.matches(selector); }) : [];
    }

    // ---- The picker -----------------------------------------------------------------------------
    // Without script the groups are listed in place under "Add block". With it they wait in a
    // non-modal popover behind the "Add block" button (aria-haspopup="dialog"): the focus goes to the
    // search, typing filters the items by label and description, Enter adds the first match, the
    // arrows move through the visible items (up from the first returns to the search), Escape closes it
    // and returns the focus to the button, a click or the focus elsewhere closes it. The search is no
    // record data (data-folio-no-edit): typing in it marks nothing unsaved and saves nothing.

    // The popover can stand at an insert line while it is open there (picker._folioAt), so a picker
    // keeps its own reference to it, and the popover to its picker.
    function parts(picker) {
        var popover = picker._folioPopover || picker.querySelector('[data-blocks-popover]');
        return {
            button: picker.querySelector('[data-blocks-picker-open]'),
            popover: popover,
            search: popover ? popover.querySelector('[data-blocks-search]') : null,
        };
    }

    // The picker el belongs to: its popover (wherever it stands), the picker itself, or the insert line
    // its popover is open at.
    function pickerFrom(el) {
        if (!el || !el.closest) { return null; }
        var popover = el.closest('[data-blocks-popover]');
        if (popover && popover._folioPicker) { return popover._folioPicker; }
        var line = el.closest('[data-blocks-insert]');
        if (line) { return line._folioPicker && line._folioPicker._folioAt === line ? line._folioPicker : null; }
        return el.closest('[data-blocks-picker-ready]');
    }

    function initPicker(picker) {
        if (picker.hasAttribute('data-blocks-picker-ready')) { return; }
        var p = parts(picker);
        if (!p.button || !p.popover) { return; } // one block type: its single button
        picker.setAttribute('data-blocks-picker-ready', '');
        picker._folioPopover = p.popover;
        picker._folioAt = null;
        p.popover._folioPicker = picker;
        p.button.hidden = false;
        p.button.setAttribute('aria-expanded', 'false');
        p.popover.hidden = true;
        p.popover.setAttribute('role', 'dialog');
        var box = p.popover.querySelector('[data-blocks-search-box]');
        if (box) { box.hidden = false; }
        p.popover.querySelectorAll('.folio-blocks-picker-label').forEach(function (l) { l.hidden = true; });
    }

    function initPickers(root) {
        if (root.matches && root.matches('[data-blocks-picker]')) { initPicker(root); }
        root.querySelectorAll('[data-blocks-picker]').forEach(initPicker);
    }

    function openPickers() {
        return Array.prototype.filter.call(document.querySelectorAll('[data-blocks-picker-ready]'), function (picker) {
            var popover = parts(picker).popover;
            return popover && !popover.hidden;
        });
    }

    // At the end ("Add block", "/") or at an insert line: there the popover moves into the line, below
    // its "+", and a pick goes in at that place (onLinePick).
    function openPicker(picker, line) {
        openPickers().forEach(function (other) { if (other !== picker) { closePicker(other, false); } });
        var p = parts(picker);
        var at = line || null;
        if (picker._folioAt && picker._folioAt !== at) { leaveLine(picker); }
        if (at) {
            at.appendChild(p.popover);
            at.classList.add('is-open');
        }
        picker._folioAt = at;
        p.popover.hidden = false;
        p.button.setAttribute('aria-expanded', at ? 'false' : 'true');
        if (at) { lineButton(at).setAttribute('aria-expanded', 'true'); }
        if (p.search) {
            p.search.value = '';
            filter(picker, '');
            p.search.focus();
        }
    }

    function closePicker(picker, refocus) {
        var p = parts(picker);
        if (!p.popover || p.popover.hidden) { return; }
        p.popover.hidden = true;
        p.button.setAttribute('aria-expanded', 'false');
        var at = picker._folioAt;
        leaveLine(picker);
        if (refocus) { (at && at.isConnected ? lineButton(at) : p.button).focus(); }
    }

    // The popover goes back to the end of the list from the line it was open at.
    function leaveLine(picker) {
        var at = picker._folioAt;
        if (!at) { return; }
        picker._folioAt = null;
        at.classList.remove('is-open');
        var button = lineButton(at);
        if (button) { button.setAttribute('aria-expanded', 'false'); }
        var popover = parts(picker).popover;
        if (popover && popover.parentNode !== picker) { picker.appendChild(popover); }
    }

    // Lower case without accents: "Ecole" finds "École".
    function fold(text) {
        var s = String(text || '').toLowerCase();
        return typeof s.normalize === 'function' ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    }

    function filter(picker, query) {
        var popover = parts(picker).popover;
        var q = fold(query).trim();
        var any = false;
        popover.querySelectorAll('[data-blocks-group]').forEach(function (group) {
            var shown = 0;
            group.querySelectorAll('[data-blocks-item]').forEach(function (item) {
                item.hidden = q !== '' && fold(item.getAttribute('data-search')).indexOf(q) < 0;
                if (!item.hidden) { shown++; }
            });
            group.hidden = shown === 0;
            any = any || shown > 0;
        });
        var empty = popover.querySelector('[data-blocks-empty]');
        if (empty) {
            empty.hidden = any;
            empty.textContent = any ? '' : (empty.getAttribute('data-text') || '').replace(':query', query.trim());
        }
    }

    function visibleItems(picker) {
        return Array.prototype.filter.call(parts(picker).popover.querySelectorAll('[data-blocks-item]'), function (item) {
            var group = item.closest('[data-blocks-group]');
            return !item.hidden && !item.disabled && !(group && group.hidden);
        });
    }

    function pickerOf(el) {
        var picker = pickerFrom(el);
        return picker && picker.hasAttribute('data-blocks-picker-ready') && !parts(picker).popover.hidden ? picker : null;
    }

    function onPickerClick(e) {
        var t = e.target;
        var plus = t && t.closest ? t.closest('[data-blocks-insert-open]') : null;
        if (plus) {
            e.preventDefault();
            onLineClick(plus);
            return;
        }
        var button = t && t.closest ? t.closest('[data-blocks-picker-open]') : null;
        var picker = button ? button.closest('[data-blocks-picker-ready]') : null;
        if (picker) {
            e.preventDefault();
            if (parts(picker).popover.hidden) { openPicker(picker); } else { closePicker(picker, false); }
            return;
        }
        // An item sends its request (htmx, on the item itself); the popover closes with it and the focus
        // waits on "Add block" until the new card arrives (then it moves to that card's chevron).
        var item = t && t.closest ? t.closest('[data-blocks-item]') : null;
        var inside = pickerFrom(t);
        openPickers().forEach(function (p) {
            if (p !== inside || item) { closePicker(p, !!item && p === inside); }
        });
        var add = t && t.closest ? t.closest('.folio-blocks-add') : null;
        var picker2 = add ? add.closest('[data-blocks-picker]') : null;
        if (picker2) { pendingPick = { picker: picker2, list: picker2.parentNode, item: add, back: null }; }
    }

    // ---- After a pick: the focus goes to the new card, or back to where the pick was made ("Add block"
    // or the insert line) when none comes.
    var pendingPick = null; // { picker, list, item, back }

    function addButtonOf(picker) {
        var open = picker.querySelector('[data-blocks-picker-open]');
        if (open && !open.hidden) { return open; }
        return picker.querySelector('.folio-blocks-add');
    }

    function onPickArrived(root) {
        if (!pendingPick || !root.matches || !root.matches('[data-block-card]')) { return; }
        if (root.parentNode !== pendingPick.list) { return; }
        busy(pendingPick.back, false);
        pendingPick = null;
        var toggle = toggleOf(root) || root.querySelector('button');
        if (toggle) { toggle.focus(); }
    }

    // The pick's request ended. Answered: the card comes with htmx:load after the swap settles; the focus
    // already waits on "Add block" (or the line), so a pick that brings no card leaves it there. Failed:
    // back there (a single type's own button is disabled during the request, hence the timer), and the
    // toast says the block was not added, unless the request was aborted or the server sent its own
    // message. htmx sends a failed afterRequest before sendError, timeout or sendAbort (responseError
    // comes first), so a failed afterRequest waits a tick for the specific event.
    function onPickFailed(e) {
        var elt = e.detail && e.detail.elt;
        if (!pendingPick || elt !== pendingPick.item) { return; }
        var pick = pendingPick;
        busy(pick.back, false);
        if (e.type === 'htmx:afterRequest') {
            if (e.detail.successful) {
                setTimeout(function () { if (pendingPick === pick) { pendingPick = null; } }, 1000);
            } else {
                setTimeout(function () { if (pendingPick === pick) { pickFailed(pick, e); } }, 0);
            }
            return;
        }
        pickFailed(pick, e);
    }

    function pickFailed(pick, e) {
        pendingPick = null;
        if (e.type !== 'htmx:sendAbort' && !serverSaid(e)) { announceFailure(pick.item); }
        setTimeout(function () { refocusAdd(pick); }, 0);
    }

    // A refusal the server explains itself (HX-Trigger "folio:error", shown as a toast by admin.js).
    function serverSaid(e) {
        var xhr = e.detail && e.detail.xhr;
        var trigger = xhr && typeof xhr.getResponseHeader === 'function' ? xhr.getResponseHeader('HX-Trigger') : null;
        return !!trigger && String(trigger).indexOf('folio:error') >= 0;
    }

    // Otherwise (offline, a timeout, a server error) the toast says so, through the same folio:error.
    function announceFailure(from) {
        var target = from && from.isConnected ? from : document.body;
        target.dispatchEvent(new CustomEvent('folio:error', { bubbles: true, detail: { message: t('block_not_added') } }));
    }

    function refocusAdd(pick) {
        var active = document.activeElement;
        var lost = !active || active === document.body || !active.isConnected || active.disabled
            || (active.matches && active.matches('[data-blocks-item], .folio-blocks-add, [data-blocks-picker-open], [data-blocks-insert-open]'));
        if (!lost) { return; }
        var button = pick.back && pick.back.isConnected ? pick.back : addButtonOf(pick.picker);
        if (button) { button.focus(); }
    }

    // ---- Insert lines -----------------------------------------------------------------------------
    // A "+" line before every card of a list (so before the first and between two; after the last is
    // the end picker): a real button, shown on hover and focus, that the Tab key reaches after the card
    // above it. Script only (without it the end picker is listed in place), and outside the cards, so
    // the [_order][] inputs, the saved order, stay the cards' DOM order. It opens the field's picker at
    // the line; a field with one block type adds that block there directly. A pick loads the item's
    // fragment URL (the end picker's request) with htmx.ajax, after the card above the line, or before
    // the first card for the top line. Each list keeps its lines (syncLines) as cards come, go and move.

    function lineButton(line) { return own(line, '[data-blocks-insert-open]')[0] || null; }

    // The admin's words (admin.js's folioT); JsTextKeysTest finds the keys this is called with.
    function t(key) { return typeof window.folioT === 'function' ? window.folioT(key) : ''; }

    function makeLine(picker) {
        var line = document.createElement('div');
        line.className = 'folio-blocks-insert';
        line.setAttribute('data-blocks-insert', '');
        line._folioPicker = picker;
        var button = document.createElement('button');
        button.setAttribute('type', 'button');
        button.className = 'folio-blocks-insert-btn';
        button.setAttribute('data-blocks-insert-open', '');
        var label = t('insert_block_here');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        var p = parts(picker);
        if (p.button && p.popover) {
            button.setAttribute('aria-haspopup', 'dialog');
            button.setAttribute('aria-expanded', 'false');
            if (p.popover.id) { button.setAttribute('aria-controls', p.popover.id); }
        }
        var plus = document.createElement('span');
        plus.className = 'folio-blocks-insert-plus';
        var icon = picker.querySelector('svg');
        if (icon) { plus.appendChild(icon.cloneNode(true)); }
        button.appendChild(plus);
        line.appendChild(button);
        return line;
    }

    function syncLines(list) {
        var picker = own(list, '[data-blocks-picker]')[0];
        if (!picker) { return; }
        own(list, '[data-blocks-insert]').forEach(function (line) {
            var next = line.nextElementSibling;
            if (next && next.matches && next.matches('[data-block-card]')) { return; }
            if (line._folioPicker && line._folioPicker._folioAt === line) { closePicker(line._folioPicker, false); }
            line.remove();
        });
        cardsOf(list).forEach(function (card) {
            var prev = card.previousElementSibling;
            if (prev && prev.matches && prev.matches('[data-blocks-insert]')) { return; }
            list.insertBefore(makeLine(picker), card);
        });
    }

    function neighbour(line, dir) {
        var el = dir < 0 ? line.previousElementSibling : line.nextElementSibling;
        while (el && !(el.matches && el.matches('[data-block-card]'))) { el = dir < 0 ? el.previousElementSibling : el.nextElementSibling; }
        return el;
    }

    function insertAt(line, url, source) {
        var picker = line._folioPicker;
        var above = neighbour(line, -1);
        var below = above ? null : neighbour(line, 1);
        if (!url || !(above || below) || !window.htmx || typeof window.htmx.ajax !== 'function') { return; }
        pendingPick = { picker: picker, list: line.parentNode, item: source, back: lineButton(line) };
        busy(pendingPick.back, true);
        window.htmx.ajax('GET', url, { source: source, target: above || below, swap: above ? 'afterend' : 'beforebegin' });
    }

    // While its pick is under way a line takes no clicks (no second insert); it keeps the focus, so it
    // is marked aria-disabled rather than disabled.
    function busy(button, on) {
        if (!button) { return; }
        if (on) { button.setAttribute('aria-disabled', 'true'); } else { button.removeAttribute('aria-disabled'); }
    }

    function onLineClick(button) {
        if (button.getAttribute('aria-disabled') === 'true') { return; }
        var line = button.closest('[data-blocks-insert]');
        var picker = line ? line._folioPicker : null;
        if (!picker || !picker.isConnected) { return; }
        if (picker.hasAttribute('data-blocks-picker-ready')) {
            if (picker._folioAt === line && !parts(picker).popover.hidden) { closePicker(picker, false); } else { openPicker(picker, line); }
            return;
        }
        openPickers().forEach(function (p) { closePicker(p, false); });
        var add = picker.querySelector('.folio-blocks-add');
        if (add) { insertAt(line, add.getAttribute('hx-get'), button); }
    }

    // Capture phase: a pick in a popover open at a line is this script's request (htmx.ajax at the
    // line), not the item's own hx-get to the end of the list.
    function onLinePick(e) {
        var item = e.target && e.target.closest ? e.target.closest('[data-blocks-item]') : null;
        var picker = item ? pickerFrom(item) : null;
        var line = picker ? picker._folioAt : null;
        if (!line) { return; }
        e.preventDefault();
        e.stopPropagation();
        closePicker(picker, true); // the focus waits on the line until the card comes
        insertAt(line, item.getAttribute('hx-get'), item);
    }

    // "/" (no modifier, not typing, no dialog or picker open): the picker of the blocks field holding
    // the focus, else of the first one on the page, at the end.
    function onSlash() {
        if (openPickers().length) { return false; }
        var field = fieldOf(document.activeElement) || document.querySelector('[data-blocks-field]');
        var list = field ? fieldParts(field).list : null;
        var picker = list ? own(list, '[data-blocks-picker]')[0] : null;
        if (!picker) { return false; }
        if (picker.hasAttribute('data-blocks-picker-ready')) {
            openPicker(picker, null);
            if (typeof picker.scrollIntoView === 'function') { picker.scrollIntoView({ block: 'nearest' }); }
            return true;
        }
        var add = picker.querySelector('.folio-blocks-add');
        if (!add) { return false; }
        add.click();
        return true;
    }

    function onPickerInput(e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches('[data-blocks-search]')) { return; }
        var picker = pickerOf(t);
        if (picker) { filter(picker, t.value); }
    }

    // Capture phase: Escape here closes the popover before the editor's own Escape (the side sheet).
    function onPickerKeydown(e) {
        var picker = pickerOf(e.target);
        if (!picker) { return; }
        var p = parts(picker);
        var onSearch = e.target === p.search;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closePicker(picker, true);
            return;
        }
        var items = visibleItems(picker);
        if (onSearch) {
            if (e.key === 'Enter') {
                e.preventDefault(); // never the record form's implicit submit
                if (items.length) { items[0].click(); }
            } else if (e.key === 'ArrowDown' && items.length) {
                e.preventDefault();
                items[0].focus();
            }
            return;
        }
        var at = items.indexOf(e.target);
        if (at < 0) { return; }
        var next = null;
        if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { next = items[(at + 1) % items.length]; }
        else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { next = at === 0 && e.key === 'ArrowUp' && p.search ? p.search : items[(at - 1 + items.length) % items.length]; }
        else if (e.key === 'Home') { next = items[0]; }
        else if (e.key === 'End') { next = items[items.length - 1]; }
        if (!next) { return; }
        e.preventDefault();
        next.focus();
    }

    // The focus moving out of an open picker closes it (not a click inside, which moves no focus in Safari).
    function onPickerFocusIn(e) {
        var inside = pickerFrom(e.target);
        openPickers().forEach(function (p) { if (p !== inside) { closePicker(p, false); } });
    }

    // ---- Cards ------------------------------------------------------------------------------------
    // The title (tile, label, summary) toggles the card like its chevron, for the pointer; the chevron is
    // the keyboard's control. Opening a card selects it in the preview. The "…" menu is the list rows'
    // menu (admin.js); here its Move up / Move down follow the card's place, and it closes after an action.

    function head(card) { return own(card, '.folio-block-head')[0] || null; }

    function toggleOf(card) { return own(head(card), '[data-block-collapse]')[0] || null; }

    function cardsOf(list) { return own(list, '[data-block-card]'); }

    function fieldParts(field) {
        // A field's own parts come first in document order; nested fields sit inside its cards.
        return {
            list: field.querySelector('[data-blocks-list]'),
            count: field.querySelector('[data-blocks-count]'),
            all: field.querySelector('[data-blocks-collapse-all]'),
        };
    }

    function setOpen(card, open) {
        card.classList.toggle('is-open', open);
        var toggle = toggleOf(card);
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function plural(n, one, other) {
        var form = 'other';
        try { form = new Intl.PluralRules(document.documentElement.getAttribute('lang') || 'en').select(n); } catch (e) { form = n === 1 ? 'one' : 'other'; }
        return (form === 'one' ? one : other).replace(':count', String(n));
    }

    // "· 3 blocks" and Collapse all / Expand all, as the cards come, go, open and close.
    function updateField(field) {
        var f = fieldParts(field);
        if (!f.list) { return; }
        syncLines(f.list);
        var cards = cardsOf(f.list);
        syncMenus(cards);
        if (f.count) {
            f.count.textContent = '· ' + plural(cards.length, f.count.getAttribute('data-one') || ':count', f.count.getAttribute('data-other') || ':count');
        }
        if (f.all) {
            var anyOpen = cards.some(function (c) { return c.classList.contains('is-open'); });
            f.all.hidden = cards.length === 0;
            f.all.textContent = f.all.getAttribute(anyOpen ? 'data-text-collapse' : 'data-text-expand') || '';
            f.all.setAttribute('aria-expanded', anyOpen ? 'true' : 'false');
        }
    }

    function updateAll(root) {
        (root || document).querySelectorAll('[data-blocks-field]').forEach(updateField);
    }

    function fieldOf(el) { return el && el.closest ? el.closest('[data-blocks-field]') : null; }

    function onCollapseAll(e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-blocks-collapse-all]') : null;
        if (!btn) { return; }
        var field = fieldOf(btn);
        var list = field ? fieldParts(field).list : null;
        if (!list) { return; }
        e.preventDefault();
        var cards = cardsOf(list);
        var anyOpen = cards.some(function (c) { return c.classList.contains('is-open'); });
        cards.forEach(function (c) { setOpen(c, !anyOpen); });
        updateField(field);
    }

    // The menu's Move up / Move down follow the card's place (none above the first, none below the last).
    function syncMenus(cards) {
        cards.forEach(function (card, at) {
            var h = head(card);
            if (!h) { return; }
            h.querySelectorAll('[data-block-up]').forEach(function (b) { b.disabled = at === 0; });
            h.querySelectorAll('[data-block-down]').forEach(function (b) { b.disabled = at === cards.length - 1; });
        });
    }

    function blockMenuOf(el) {
        var menu = el && el.closest ? el.closest('[data-folio-row-menu]') : null;
        return menu && menu.classList.contains('folio-block-menu') ? menu : null;
    }

    function onCardClick(e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var title = t.closest('[data-block-title]');
        if (title) {
            var titled = title.closest('[data-block-card]');
            var toggle = titled ? toggleOf(titled) : null;
            if (toggle) { toggle.click(); }
            return;
        }
        var collapse = t.closest('[data-block-collapse]');
        if (collapse) {
            var card = collapse.closest('[data-block-card]');
            if (card && card.classList.contains('is-open') && Folio.preview) { Folio.preview.select(card.getAttribute('data-block-uid')); }
            updateField(fieldOf(card) || document.body);
            return;
        }
        var action = t.closest('[data-block-up], [data-block-down], [data-block-duplicate], [data-block-remove]');
        if (!action) { return; }
        // Removing moved the focus on (admin.js); the others give it back to the menu's button.
        var menu = blockMenuOf(action);
        if (menu && Folio.menus) { Folio.menus.close(menu, !action.hasAttribute('data-block-remove') && menu.isConnected); }
        updateAll();
    }

    // Cards and fields that htmx adds (picker, duplicate): their menus and pickers, and the counts.
    function onHtmxLoad(e) {
        var root = e.detail && e.detail.elt;
        if (!root || !root.querySelectorAll) { return; }
        if (Folio.menus) { Folio.menus.init(root.parentNode || root); }
        initPickers(root);
        updateAll();
        onPickArrived(root);
    }

    function boot() {
        initPickers(document);
        updateAll();
        document.addEventListener('click', onLinePick, true);
        document.addEventListener('click', onPickerClick);
        if (document.querySelector('[data-blocks-field]') && Folio.shortcuts) {
            Folio.shortcuts.add({ key: '/', label: t('shortcut_add_block'), run: onSlash, quietInMenus: true });
        }
        ['htmx:afterRequest', 'htmx:responseError', 'htmx:sendError', 'htmx:timeout', 'htmx:sendAbort'].forEach(function (name) {
            document.addEventListener(name, onPickFailed);
        });
        document.addEventListener('input', onPickerInput);
        document.addEventListener('keydown', onPickerKeydown, true);
        document.addEventListener('focusin', onPickerFocusIn);
        document.addEventListener('click', onCardClick);
        document.addEventListener('click', onCollapseAll);
        document.addEventListener('htmx:load', onHtmxLoad);
        // Cards dragged, put back by Undo or moved with the keyboard: the counts follow.
        if (typeof window.MutationObserver === 'function') {
            new window.MutationObserver(function (records) {
                var fields = [];
                records.forEach(function (r) {
                    var target = r.target;
                    if (target && target.matches && target.matches('[data-blocks-list]')) {
                        var field = fieldOf(target);
                        if (field && fields.indexOf(field) < 0) { fields.push(field); }
                    }
                });
                fields.forEach(updateField);
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
