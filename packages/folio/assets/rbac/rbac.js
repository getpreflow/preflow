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
 * - The unsaved count and Cancel (data-rbac-changes) move next to the editor's Save. The count is the
 *   controls of the record form whose own state differs from the page's; Cancel puts them all back
 *   and clears the form's unsaved mark (Folio.unsaved). Every change a toggle makes is announced as a
 *   "change" event, so the unsaved-changes guard sees it as it sees a click.
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

    /** A box's own state: what it holds under a lock, else whether it is checked. */
    function own(box) {
        return box.hasAttribute(WAS) ? box.getAttribute(WAS) === '1' : !!box.checked;
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

    function isControl(el) {
        return el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA';
    }

    /** The record form's own controls: those inside it and those that name it (the side panel's). */
    function controls(form, field) {
        var list = all(form || field, 'input, select, textarea').filter(isControl);
        if (form && form.id) {
            all(document, '[form="' + form.id + '"]').forEach(function (el) {
                if (list.indexOf(el) < 0 && isControl(el)) { list.push(el); }
            });
        }
        return list.filter(function (el) { return el.type !== 'hidden' && !el.hasAttribute('data-folio-no-edit'); });
    }

    function stateOf(el) {
        return el.type === 'checkbox' || el.type === 'radio' ? (own(el) ? '1' : '0') : String(el.value);
    }

    function initChanges(field) {
        var panel = field.querySelector('[data-rbac-changes]');
        var form = field.closest('form');
        var list = controls(form, field);
        var saved = list.map(stateOf);
        var count = panel ? panel.querySelector('[data-rbac-changes-count]') : null;

        // Next to the editor's Save (the topbar); without one (read-only) it stays hidden.
        var save = document.querySelector('.folio-save-buttons');
        if (panel && save && save.parentNode) {
            save.parentNode.insertBefore(panel, save);
        }

        function refresh() {
            var n = list.filter(function (el, i) { return stateOf(el) !== saved[i]; }).length;
            if (!panel) { return; }
            panel.hidden = n === 0 || !save;
            if (count) {
                var forms = (count.getAttribute('data-text') || ':count').split('|');
                count.textContent = (n === 1 || forms.length === 1 ? forms[0] : forms[1]).replace(':count', String(n));
            }
        }

        function cancel() {
            // Back to the page's state: unlock everything, restore each control, then lock again.
            list.forEach(function (el) { el.removeAttribute(WAS); });
            list.forEach(function (el, i) {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = saved[i] === '1';
                } else {
                    el.value = saved[i];
                }
            });
            apply(field);
            refresh();
            if (form && window.Folio && window.Folio.unsaved) { window.Folio.unsaved.clear(form); }
        }

        ['change', 'input'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                if (list.indexOf(e.target) >= 0) { refresh(); }
            });
        });
        if (panel) {
            panel.addEventListener('click', function (e) {
                if (e.target && e.target.closest && e.target.closest('[data-rbac-cancel]')) { cancel(); }
            });
        }
        refresh();
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
            apply(field);
            initChanges(field);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
