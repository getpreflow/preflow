/*
 * The rbac_permissions editor (preflow/rbac, board Roles). Without this script every box stays
 * usable and the server accepts any mix; with it:
 * - What "Everything" or a row's switch ("{type}.*", data-rbac-all) already grants is shown checked
 *   and locked (disabled, so it is not posted on its own). Unlocking restores the box's own state.
 * - A column header (data-rbac-column) toggles that verb for every type of its section: on for all,
 *   or off for all when all are on. It changes only boxes that can change: never a locked one, never
 *   one the editor can't grant (data-rbac-denied), which stays disabled whatever else changes.
 * - Each section's "· N granted" (data-rbac-sum) follows the boxes.
 * - "Only new (N)" (data-rbac-fresh-filter) hides every row and chip without a "New" mark; hidden
 *   controls still post.
 * - Every change a toggle makes is announced as a "change" event, so the editor's unsaved count and
 *   guard (Folio.unsaved) see it as they see a click. Locked boxes are disabled, so they don't count.
 *   The editor's Cancel (Folio.unsaved.discard) restores every box and sends "folio:discard" on the
 *   form: the locks are applied again from the restored state.
 * State is kept in attributes (data-rbac-was), not in dataset.
 */
(function () {
    'use strict';

    var WAS = 'data-rbac-was';

    function all(scope, selector) {
        return Array.prototype.slice.call(scope.querySelectorAll(selector));
    }

    function lock(box, locked) {
        if (locked && !box.hasAttribute(WAS)) {
            box.setAttribute(WAS, box.checked ? '1' : '0');
            box.checked = true;
        } else if (!locked && box.hasAttribute(WAS)) {
            box.checked = box.getAttribute(WAS) === '1';
            box.removeAttribute(WAS);
        }
        box.disabled = locked || box.hasAttribute('data-rbac-denied');
    }

    function apply(field) {
        var everything = field.querySelector('[data-rbac-everything]');
        var rest = field.querySelector('[data-rbac-rest]');
        var whole = !!(everything && everything.checked);
        if (rest) {
            rest.classList.toggle('is-locked', whole);
        }
        // Kept names (no control) go with the rest: Everything alone stores just "*".
        all(field, '[data-rbac-kept]').forEach(function (input) {
            input.disabled = whole;
        });
        // The "All" boxes first, so their own state is back before their members follow them.
        all(field, '[data-rbac-all]').forEach(function (box) {
            lock(box, whole);
        });
        all(field, '[data-rbac-row], [data-rbac-group]').forEach(function (scope) {
            var pattern = scope.querySelector('[data-rbac-all]');
            all(scope, 'input').forEach(function (box) {
                if (box.type === 'checkbox' && !box.hasAttribute('data-rbac-all')) {
                    lock(box, whole || !!(pattern && pattern.checked));
                }
            });
        });
        sums(field);
    }

    /** Each section's granted count: its verb boxes that are checked (a locked one shows what its row grants). */
    function sums(field) {
        all(field, '[data-rbac-section]').forEach(function (section) {
            var sum = section.querySelector('[data-rbac-sum]');
            if (sum) {
                sum.textContent = String(all(section, '[data-rbac-verb]').filter(function (box) { return box.checked; }).length);
            }
        });
    }

    /** A column of one section: the boxes that can change go on together, or off when all of them are on. */
    function toggleColumn(field, button) {
        var section = button.closest('[data-rbac-section]');
        if (!section) { return; }
        var verb = button.getAttribute('data-rbac-column');
        var open = all(section, '[data-rbac-verb="' + verb + '"]').filter(function (box) {
            return !box.disabled && !box.hasAttribute('data-rbac-denied');
        });
        if (open.length === 0) { return; }
        var on = open.some(function (box) { return !box.checked; });
        open.forEach(function (box) {
            if (box.checked !== on) {
                box.checked = on;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        sums(field);
    }

    /** "Only new": hides what carries no "New" mark (rows, chips, and the blocks left empty); values still post. */
    function filterFresh(field, button) {
        var only = button.getAttribute('aria-pressed') !== 'true';
        var empty = function (el) { return only && all(el, '[data-rbac-new]').length === 0; };
        button.setAttribute('aria-pressed', only ? 'true' : 'false');
        all(field, '[data-rbac-row], .rbac-chip').forEach(function (el) {
            el.hidden = only && !el.hasAttribute('data-rbac-new');
        });
        all(field, '[data-rbac-section], [data-rbac-group], [data-rbac-others], .folio-table-wrap, .rbac-footnote').forEach(function (el) {
            el.hidden = el.classList.contains('rbac-footnote') ? only : empty(el);
        });
    }

    function init() {
        all(document, '[data-rbac-permissions]').forEach(function (field) {
            field.addEventListener('change', function (event) {
                if (event.target.matches('[data-rbac-everything], [data-rbac-all]')) {
                    apply(field);
                } else if (event.target.hasAttribute('data-rbac-verb')) {
                    sums(field);
                }
            });
            field.addEventListener('click', function (event) {
                var target = event.target && event.target.closest ? event.target : null;
                var column = target ? target.closest('[data-rbac-column]') : null;
                var filter = target ? target.closest('[data-rbac-fresh-filter]') : null;
                if (column) {
                    toggleColumn(field, column);
                } else if (filter) {
                    filterFresh(field, filter);
                }
            });
            // Cancel (Folio.unsaved.discard) put every box back to the page's state: lock again from there.
            var form = field.closest('form');
            if (form) {
                form.addEventListener('folio:discard', function () {
                    all(field, '[' + WAS + ']').forEach(function (box) { box.removeAttribute(WAS); });
                    apply(field);
                });
            }
            apply(field);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
