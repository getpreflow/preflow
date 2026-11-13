/* Folio blocks editor (blocks/field.twig, blocks/card.twig; 0.75): the "Add block" picker, the cards'
 * "…" menus, Collapse all and the block count. Vanilla, no build step; loaded after admin.js where a
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

    function parts(picker) {
        return {
            button: picker.querySelector('[data-blocks-picker-open]'),
            popover: picker.querySelector('[data-blocks-popover]'),
            search: picker.querySelector('[data-blocks-search]'),
        };
    }

    function initPicker(picker) {
        if (picker.hasAttribute('data-blocks-picker-ready')) { return; }
        var p = parts(picker);
        if (!p.button || !p.popover) { return; } // one block type: its single button
        picker.setAttribute('data-blocks-picker-ready', '');
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

    function openPicker(picker) {
        openPickers().forEach(function (other) { if (other !== picker) { closePicker(other, false); } });
        var p = parts(picker);
        p.popover.hidden = false;
        p.button.setAttribute('aria-expanded', 'true');
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
        if (refocus) { p.button.focus(); }
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
        var picker = el && el.closest ? el.closest('[data-blocks-picker-ready]') : null;
        return picker && !parts(picker).popover.hidden ? picker : null;
    }

    function onPickerClick(e) {
        var t = e.target;
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
        var inside = t && t.closest ? t.closest('[data-blocks-picker-ready]') : null;
        openPickers().forEach(function (p) {
            if (p !== inside || item) { closePicker(p, !!item && p === inside); }
        });
        var add = t && t.closest ? t.closest('.folio-blocks-add') : null;
        var picker2 = add ? add.closest('[data-blocks-picker]') : null;
        if (picker2) { pendingPick = { picker: picker2, item: add }; }
    }

    // ---- After a pick: the focus goes to the new card, or back to "Add block" when none comes.
    var pendingPick = null; // { picker, item }

    function addButtonOf(picker) {
        var open = picker.querySelector('[data-blocks-picker-open]');
        if (open && !open.hidden) { return open; }
        return picker.querySelector('.folio-blocks-add');
    }

    function onPickArrived(root) {
        if (!pendingPick || !root.matches || !root.matches('[data-block-card]')) { return; }
        var picker = pendingPick.picker;
        if (root.parentNode !== picker.parentNode) { return; }
        pendingPick = null;
        var toggle = toggleOf(root) || root.querySelector('button');
        if (toggle) { toggle.focus(); }
    }

    // The pick's request ended. Answered: the card comes with htmx:load after the swap settles; the focus
    // already waits on "Add block", so a pick that brings no card leaves it there. Failed: back to
    // "Add block" (a single type's own button is disabled during the request, hence the timer).
    function onPickFailed(e) {
        var elt = e.detail && e.detail.elt;
        if (!pendingPick || elt !== pendingPick.item) { return; }
        var pick = pendingPick;
        if (e.type === 'htmx:afterRequest' && e.detail.successful) {
            setTimeout(function () { if (pendingPick === pick) { pendingPick = null; } }, 1000);
            return;
        }
        pendingPick = null;
        setTimeout(function () { refocusAdd(pick.picker); }, 0);
    }

    function refocusAdd(picker) {
        var active = document.activeElement;
        var lost = !active || active === document.body || !active.isConnected || active.disabled
            || (active.matches && active.matches('[data-blocks-item], .folio-blocks-add, [data-blocks-picker-open]'));
        if (!lost) { return; }
        var button = addButtonOf(picker);
        if (button) { button.focus(); }
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
        var inside = e.target && e.target.closest ? e.target.closest('[data-blocks-picker-ready]') : null;
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
        document.addEventListener('click', onPickerClick);
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
