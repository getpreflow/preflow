/* Folio admin behaviors. Vanilla, no build step. */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    var activeMatrix = null;   // the matrix root that opened the current drawer
    var drawerEl = null;       // the current drawer overlay element

    function closeDrawer() {
        if (drawerEl && drawerEl.parentNode) {
            drawerEl.parentNode.removeChild(drawerEl);
        }
        drawerEl = null;
        activeMatrix = null;
    }

    function openDrawer(url) {
        closeDrawer();
        var overlay = document.createElement('div');
        overlay.className = 'folio-drawer';
        var panel = document.createElement('div');
        panel.className = 'folio-drawer-panel';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'folio-drawer-close';
        close.textContent = 'Close';
        close.addEventListener('click', closeDrawer);
        var frame = document.createElement('iframe');
        frame.className = 'folio-drawer-frame';
        frame.src = url;
        panel.appendChild(close);
        panel.appendChild(frame);
        overlay.appendChild(panel);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { closeDrawer(); }
        });
        document.body.appendChild(overlay);
        drawerEl = overlay;
    }

    function initMatrix(root) {
        var field = root.getAttribute('data-field') || '';
        var rowsEl = root.querySelector('[data-matrix-rows]');
        var optsEl = root.querySelector('[data-matrix-options]');
        var typeSel = root.querySelector('[data-matrix-type]');
        var recSel = root.querySelector('[data-matrix-record]');
        var addBtn = root.querySelector('[data-matrix-add]');
        var createBtn = root.querySelector('[data-matrix-create]');
        if (!rowsEl || !optsEl) { return; }

        var opts;
        try { opts = JSON.parse(optsEl.textContent || '{}'); } catch (e) { return; }
        var prefix = opts.prefix || '';
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

        // Expose for the cross-frame message handler (keyed off activeMatrix).
        root._folioMatrix = { addRow: addRow, prefix: prefix };

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

        if (createBtn) {
            createBtn.addEventListener('click', function () {
                if (!typeSel || !typeSel.value) { return; }
                activeMatrix = root;
                openDrawer(prefix + '/' + encodeURIComponent(typeSel.value) + '/new?_drawer=1');
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

    function onMessage(e) {
        if (e.origin !== window.location.origin) { return; }
        var data = e.data;
        if (!data || typeof data !== 'object' || data.source !== 'folio-drawer') { return; }

        var matrix = activeMatrix;
        closeDrawer();
        if (!matrix || !matrix._folioMatrix) { return; }

        var ctrl = matrix._folioMatrix;
        var type = String(data.type || '');
        var id = String(data.id || '');
        if (!type || !id) { return; }

        var url = ctrl.prefix + '/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/label';
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { ctrl.addRow(type, id, (j && j.label) ? j.label : id); })
            .catch(function () { ctrl.addRow(type, id, id); });
    }

    function onKeydown(e) {
        if (e.key === 'Escape' && drawerEl) { closeDrawer(); }
    }

    function boot() {
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', onKeydown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
