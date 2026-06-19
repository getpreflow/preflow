/* Folio admin behaviors. Vanilla, no build step. */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function initMatrix(root) {
        var field = root.getAttribute('data-field') || '';
        var rowsEl = root.querySelector('[data-matrix-rows]');
        var optsEl = root.querySelector('[data-matrix-options]');
        var typeSel = root.querySelector('[data-matrix-type]');
        var recSel = root.querySelector('[data-matrix-record]');
        var addBtn = root.querySelector('[data-matrix-add]');
        if (!rowsEl || !optsEl) { return; }

        var opts;
        try { opts = JSON.parse(optsEl.textContent || '{}'); } catch (e) { return; }
        var next = parseInt(root.getAttribute('data-next-index') || '0', 10) || 0;

        function populateRecords() {
            if (!recSel || !typeSel) { return; }
            var recs = (opts.records && opts.records[typeSel.value]) || [];
            recSel.innerHTML = '';
            recs.forEach(function (r) {
                var o = document.createElement('option');
                o.value = r.id;
                o.textContent = r.label;
                recSel.appendChild(o);
            });
        }

        function addRow(type, id, label) {
            var i = next++;
            var row = document.createElement('div');
            row.className = 'folio-matrix-row';
            row.setAttribute('data-matrix-row', '');
            row.innerHTML =
                '<input type="hidden" name="' + esc(field) + '[' + i + '][_type]" value="' + esc(type) + '">' +
                '<input type="hidden" name="' + esc(field) + '[' + i + '][id]" value="' + esc(id) + '">' +
                '<span class="folio-matrix-label">' + esc(label) + ' <em>(' + esc(type) + ')</em></span>' +
                '<span class="folio-matrix-controls">' +
                '<button type="button" data-matrix-up>Up</button>' +
                '<button type="button" data-matrix-down>Down</button>' +
                '<button type="button" data-matrix-remove>Remove</button>' +
                '</span>';
            rowsEl.appendChild(row);
        }

        if (typeSel) {
            typeSel.addEventListener('change', populateRecords);
            populateRecords();
        }

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                if (!typeSel || !recSel || !typeSel.value || !recSel.value) { return; }
                var opt = recSel.options[recSel.selectedIndex];
                addRow(typeSel.value, recSel.value, opt ? opt.textContent : recSel.value);
            });
        }

        rowsEl.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('button') : null;
            if (!btn) { return; }
            var row = btn.closest('[data-matrix-row]');
            if (!row) { return; }
            if (btn.hasAttribute('data-matrix-remove')) {
                row.remove();
            } else if (btn.hasAttribute('data-matrix-up') && row.previousElementSibling) {
                rowsEl.insertBefore(row, row.previousElementSibling);
            } else if (btn.hasAttribute('data-matrix-down') && row.nextElementSibling) {
                rowsEl.insertBefore(row.nextElementSibling, row);
            }
        });
    }

    function boot() {
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
