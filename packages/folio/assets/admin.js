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

        function addRow(type, id, label, view) {
            view = view || '';
            var i = next++;
            var row = document.createElement('div');
            row.className = 'folio-matrix-row';
            row.setAttribute('data-matrix-row', '');

            var views = (opts.views && opts.views[type]) || [];
            var viewSelect = '';
            if (views.length) {
                viewSelect = '<select name="' + esc(field) + '[' + i + '][view]" data-matrix-view>' +
                    '<option value="">Default</option>';
                views.forEach(function (v) {
                    viewSelect += '<option value="' + esc(v) + '"' + (v === view ? ' selected' : '') + '>' + esc(v) + '</option>';
                });
                viewSelect += '</select>';
            }

            row.innerHTML =
                '<input type="hidden" name="' + esc(field) + '[' + i + '][_type]" value="' + esc(type) + '">' +
                '<input type="hidden" name="' + esc(field) + '[' + i + '][id]" value="' + esc(id) + '">' +
                '<span class="folio-matrix-label">' + esc(label) + ' <em>(' + esc(type) + ')</em></span>' +
                viewSelect +
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

    function initPreview() {
        var btn = document.querySelector('[data-folio-preview]');
        if (!btn) { return; }
        var form = document.querySelector('.folio-form form');
        if (!form) { return; }
        var url = btn.getAttribute('data-preview-url') || '';

        var overlay = null, frame = null, anchor = null, parent = null, reqSeq = 0, timer = null, keyHandler = null, hasDoc = false;

        function fullRender(html) {
            frame.srcdoc = html;
            hasDoc = true;
        }

        // Patch only the [data-folio-field] regions that changed, directly into the
        // same-origin srcdoc document. Fall back to a full srcdoc reload on the first
        // render, when the template has no markers, or when the field structure differs.
        function applyHtml(html) {
            try {
                var doc = hasDoc ? frame.contentDocument : null;
                if (!doc || !doc.body) { fullRender(html); return; }

                var incoming = new DOMParser().parseFromString(html, 'text/html');
                var incomingRegions = incoming.querySelectorAll('[data-folio-field]');
                var curRegions = doc.querySelectorAll('[data-folio-field]');
                if (incomingRegions.length === 0 || incomingRegions.length !== curRegions.length) {
                    fullRender(html);
                    return;
                }

                var patches = [];
                for (var i = 0; i < incomingRegions.length; i++) {
                    var name = incomingRegions[i].getAttribute('data-folio-field');
                    var cur = doc.querySelector('[data-folio-field="' + name + '"]');
                    if (!cur) { fullRender(html); return; } // a region went missing -> full reload
                    if (cur.innerHTML !== incomingRegions[i].innerHTML) {
                        patches.push([cur, incomingRegions[i].innerHTML]);
                    }
                }
                for (var j = 0; j < patches.length; j++) {
                    patches[j][0].innerHTML = patches[j][1];
                }
            } catch (e) {
                fullRender(html);
            }
        }

        function render() {
            if (!frame) { return; }
            var seq = ++reqSeq;
            fetch(url, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (html != null && seq === reqSeq && frame) { applyHtml(html); }
                })
                .catch(function () {});
        }

        function schedule() {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(render, 400);
        }

        function setWidth(w) {
            if (frame) { frame.style.width = w; }
        }

        function closePreview() {
            if (timer) { clearTimeout(timer); timer = null; }
            if (keyHandler) { document.removeEventListener('keydown', keyHandler); keyHandler = null; }
            form.removeEventListener('input', schedule);
            form.removeEventListener('change', schedule);
            if (anchor && parent) { parent.insertBefore(form, anchor); parent.removeChild(anchor); }
            if (overlay && overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            overlay = null; frame = null; anchor = null; parent = null; hasDoc = false;
        }

        function open() {
            if (overlay) { return; }
            overlay = document.createElement('div');
            overlay.className = 'folio-preview';
            var formPane = document.createElement('div');
            formPane.className = 'folio-preview-form';
            var stage = document.createElement('div');
            stage.className = 'folio-preview-stage';
            var bar = document.createElement('div');
            bar.className = 'folio-preview-bar';

            [['Desktop', '100%'], ['Tablet', '768px'], ['Mobile', '375px']].forEach(function (vp) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = vp[0];
                b.setAttribute('data-preview-viewport', vp[1]);
                b.addEventListener('click', function () { setWidth(vp[1]); });
                bar.appendChild(b);
            });
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'folio-preview-close';
            close.textContent = 'Close';
            close.addEventListener('click', closePreview);
            bar.appendChild(close);

            frame = document.createElement('iframe');
            frame.className = 'folio-preview-frame';

            stage.appendChild(bar);
            stage.appendChild(frame);

            // Remember the form's home, then move the real form into the overlay
            // (appendChild preserves the node + its listeners, so trix/matrix survive).
            parent = form.parentNode;
            anchor = document.createElement('span');
            anchor.style.display = 'none';
            parent.insertBefore(anchor, form);
            formPane.appendChild(form);

            overlay.appendChild(formPane);
            overlay.appendChild(stage);
            document.body.appendChild(overlay);

            form.addEventListener('input', schedule);
            form.addEventListener('change', schedule);

            // Escape closes; scoped to the overlay's lifetime so no listener leaks
            // if boot()/initPreview ever runs more than once.
            keyHandler = function (e) {
                if (e.key === 'Escape') { closePreview(); }
            };
            document.addEventListener('keydown', keyHandler);

            render();
        }

        btn.addEventListener('click', open);
    }

    function boot() {
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', onKeydown);
        initPreview();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
