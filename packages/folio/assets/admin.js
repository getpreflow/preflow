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
        if (root._folioMatrix) { return; }
        root._folioMatrix = true;
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
            notifyChange(root);
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
            } else {
                return;
            }
            // Autosave, the preview and the unsaved guard all listen for change.
            notifyChange(root);
        });
    }

    function onMessage(e) {
        if (e.origin !== window.location.origin) { return; }
        var data = e.data;
        if (data && typeof data === 'object' && data.source === 'folio-assets') { onAssetsPicked(data); return; }
        if (data && typeof data === 'object' && data.source === 'folio-preview') { onPreviewMessage(data); return; }
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

    // Asset fields: "Choose" opens the library as a picker in the drawer; picked
    // assets become hidden {name}_ids[] inputs with a thumbnail.
    var activeAssetField = null;

    function assetItem(field, id, thumb, label) {
        var li = document.createElement('li');
        li.className = 'folio-asset-item';
        li.setAttribute('data-asset-item', '');
        if (thumb) {
            var img = document.createElement('img');
            img.src = thumb;
            img.alt = '';
            img.className = 'folio-asset-thumb';
            li.appendChild(img);
        } else {
            var span = document.createElement('span');
            span.textContent = label || id;
            li.appendChild(span);
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = field.getAttribute('data-selection-name');
        input.value = id;
        li.appendChild(input);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-ghost';
        remove.setAttribute('data-folio-asset-remove', '');
        remove.textContent = 'Remove';
        li.appendChild(remove);
        return li;
    }

    function onAssetsPicked(data) {
        var field = activeAssetField;
        if (!field || !field.isConnected) { return; }
        var list = field.querySelector('.folio-asset-selection');
        var multiple = field.getAttribute('data-multiple') === '1';
        (data.items || []).forEach(function (item) {
            var id = String(item.id || '');
            if (!/^[a-f0-9]{32}$/.test(id)) { return; }
            if (!multiple) { list.innerHTML = ''; }
            var dupe = list.querySelector('input[value="' + id + '"]');
            if (!dupe) { list.appendChild(assetItem(field, id, String(item.thumb || ''), String(item.label || ''))); }
        });
        notifyChange(field);
        if (!multiple) { closeDrawer(); activeAssetField = null; }
    }

    function onAssetFieldClick(e) {
        var choose = e.target.closest ? e.target.closest('[data-folio-asset-choose]') : null;
        if (choose) {
            var field = choose.closest('[data-folio-asset-field]');
            if (field && field.getAttribute('data-picker-url')) {
                openDrawer(field.getAttribute('data-picker-url'));
                activeAssetField = field;
            }
            e.preventDefault();
            return;
        }
        var remove = e.target.closest ? e.target.closest('[data-folio-asset-remove]') : null;
        if (remove) {
            var item = remove.closest('[data-asset-item]');
            var owner = remove.closest('[data-folio-asset-field]');
            if (item) { item.parentNode.removeChild(item); }
            notifyChange(owner);
            e.preventDefault();
        }
    }

    function onKeydown(e) {
        if (e.key === 'Escape' && drawerEl) { closeDrawer(); }
    }

    // ---- Split-view editor ---------------------------------------------
    // Routed types edit next to their live preview: form | resizer | stage.
    // The view mode and form width are admin conveniences in localStorage.

    function store(key, value) {
        try {
            if (value === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, String(value)); }
        } catch (e) {}
    }

    function stored(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    // An icon from the admin sprite (the URL is taken from an icon already on the page).
    function icon(id) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'folio-icon');
        svg.setAttribute('aria-hidden', 'true');
        var any = document.querySelector('use[href*="icons.svg"]');
        if (any) {
            var use = document.createElementNS(ns, 'use');
            use.setAttribute('href', any.getAttribute('href').split('#')[0] + '#' + id);
            svg.appendChild(use);
        }
        return svg;
    }

    var preview = null; // { frame, form, post(msg) } once the split view runs

    function initSplit() {
        var split = document.querySelector('[data-folio-split]');
        if (!split) { return; }
        var form = split.querySelector('.folio-split-form form');
        var stage = split.querySelector('[data-folio-stage]');
        var frame = stage ? stage.querySelector('.folio-preview-frame') : null;
        var resizer = split.querySelector('[data-folio-resizer]');
        if (!form || !frame) { return; }
        var url = stage.getAttribute('data-preview-url') || '';
        var reqSeq = 0, timer = null, hasDoc = false, started = false, stale = false;

        preview = {
            frame: frame,
            form: form,
            post: function (msg) {
                if (!hasDoc || !frame.contentWindow) { return; }
                msg.source = 'folio-editor';
                frame.contentWindow.postMessage(msg, window.location.origin);
            }
        };

        var keepScroll = null;
        frame.addEventListener('load', function () {
            try { hasDoc = frame.contentWindow.location.href !== 'about:blank'; } catch (e) { hasDoc = false; }
            if (hasDoc && keepScroll !== null) { frame.contentWindow.scrollTo(0, keepScroll); }
            keepScroll = null;
        });

        // Full render: POST the form's current state into the iframe, so the preview
        // is a real navigated document with its own CSP header and nonce (injected HTML
        // document would inherit the admin page's policy and block the preview's
        // nonced assets). File inputs are skipped; unsaved uploads are not previewed.
        function fullRender() {
            try { keepScroll = hasDoc ? frame.contentWindow.scrollY : null; } catch (e) { keepScroll = null; }
            var poster = document.createElement('form');
            poster.method = 'post';
            poster.action = url;
            poster.target = frame.name;
            poster.hidden = true;
            new FormData(form).forEach(function (value, key) {
                if (typeof value !== 'string') { return; }
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                poster.appendChild(input);
            });
            document.body.appendChild(poster);
            poster.submit();
            document.body.removeChild(poster);
        }

        // Patch only what changed, directly into the same-origin preview document:
        // first the [data-folio-field] regions, else the top-level blocks by _uid.
        // Anything else (first render, no markers, structure changed) is a full render.
        function applyHtml(html) {
            try {
                var doc = hasDoc ? frame.contentDocument : null;
                if (!doc || !doc.body) { fullRender(); return; }
                var incoming = new DOMParser().parseFromString(html, 'text/html');
                if (headAssets(doc) !== headAssets(incoming)) { fullRender(); return; }
                if (!patchRegions(doc, incoming) && !patchBlocks(doc, incoming)) { fullRender(); }
            } catch (e) {
                fullRender();
            }
        }

        function patchRegions(doc, incoming) {
            var incomingRegions = incoming.querySelectorAll('[data-folio-field]');
            var curRegions = doc.querySelectorAll('[data-folio-field]');
            if (incomingRegions.length === 0 || incomingRegions.length !== curRegions.length) { return false; }
            // Only the regions may differ; blocks or markup outside them need another path.
            if (skeleton(doc.body, '[data-folio-field]') !== skeleton(incoming.body, '[data-folio-field]')) { return false; }

            var patches = [];
            var seen = Object.create(null);
            for (var i = 0; i < incomingRegions.length; i++) {
                var name = incomingRegions[i].getAttribute('data-folio-field');
                // Duplicate field name -> querySelector(name) is ambiguous; full-reload rather than patch the wrong region.
                if (seen[name]) { return false; }
                seen[name] = true;
                var cur = doc.querySelector('[data-folio-field="' + name + '"]');
                if (!cur) { return false; } // a region went missing -> full reload
                if (cur.innerHTML !== incomingRegions[i].innerHTML) {
                    patches.push([cur, incomingRegions[i].innerHTML]);
                }
            }
            for (var j = 0; j < patches.length; j++) {
                patches[j][0].innerHTML = patches[j][1];
            }
            return true;
        }

        function render() {
            started = true;
            stale = false;
            if (!hasDoc) { fullRender(); return; } // first render: navigate, no fetch needed
            var seq = ++reqSeq;
            fetch(url, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (html != null && seq === reqSeq) { applyHtml(html); }
                })
                .catch(function () {});
        }

        function schedule() {
            if (split.getAttribute('data-mode') === 'form') { stale = true; return; }
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(render, 400);
        }

        // View mode: form | split | preview.
        var switcher = document.querySelector('[data-folio-preview]');
        function setMode(mode) {
            if (['form', 'split', 'preview'].indexOf(mode) < 0) { mode = 'split'; }
            split.setAttribute('data-mode', mode);
            if (switcher) {
                switcher.querySelectorAll('[data-folio-view]').forEach(function (b) {
                    b.setAttribute('aria-pressed', b.getAttribute('data-folio-view') === mode ? 'true' : 'false');
                });
            }
            if (mode !== 'form' && (!started || stale)) { render(); }
        }
        if (switcher) {
            switcher.addEventListener('click', function (e) {
                var b = e.target.closest('[data-folio-view]');
                if (!b) { return; }
                var mode = b.getAttribute('data-folio-view');
                store('folio-split-mode', mode);
                setMode(mode);
            });
        }

        // Viewport widths inside the stage.
        stage.addEventListener('click', function (e) {
            var b = e.target.closest('[data-preview-viewport]');
            if (!b) { return; }
            var w = b.getAttribute('data-preview-viewport');
            frame.style.width = w;
            frame.classList.toggle('is-device', w !== '100%');
            stage.querySelectorAll('[data-preview-viewport]').forEach(function (o) {
                o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
            });
        });

        // Resizable form pane: pointer drag or arrow keys; the width is remembered.
        function bounds() {
            var total = split.getBoundingClientRect().width;
            return [320, Math.max(320, Math.round(total * 0.6))];
        }
        function setFormWidth(px, remember) {
            var b = bounds();
            var w = Math.min(b[1], Math.max(b[0], Math.round(px)));
            split.style.setProperty('--folio-form-w', w + 'px');
            if (resizer) {
                resizer.setAttribute('aria-valuemin', String(b[0]));
                resizer.setAttribute('aria-valuemax', String(b[1]));
                resizer.setAttribute('aria-valuenow', String(w));
            }
            if (remember) { store('folio-split-width', w); }
            return w;
        }
        if (resizer) {
            resizer.addEventListener('pointerdown', function (e) {
                if (e.button !== 0) { return; }
                e.preventDefault();
                resizer.setPointerCapture(e.pointerId);
                split.classList.add('is-resizing');
                var left = split.getBoundingClientRect().left;
                function move(ev) { setFormWidth(ev.clientX - left, false); }
                function up(ev) {
                    resizer.releasePointerCapture(ev.pointerId);
                    resizer.removeEventListener('pointermove', move);
                    resizer.removeEventListener('pointerup', up);
                    resizer.removeEventListener('pointercancel', up);
                    split.classList.remove('is-resizing');
                    setFormWidth(ev.clientX - left, true);
                }
                resizer.addEventListener('pointermove', move);
                resizer.addEventListener('pointerup', up);
                resizer.addEventListener('pointercancel', up);
            });
            resizer.addEventListener('keydown', function (e) {
                var cur = parseInt(resizer.getAttribute('aria-valuenow') || '0', 10) || split.querySelector('.folio-split-form').getBoundingClientRect().width;
                var b = bounds();
                var next = e.key === 'ArrowLeft' ? cur - 24 : e.key === 'ArrowRight' ? cur + 24 : e.key === 'Home' ? b[0] : e.key === 'End' ? b[1] : null;
                if (next === null) { return; }
                e.preventDefault();
                setFormWidth(next, true);
            });
        }

        var savedWidth = parseInt(stored('folio-split-width') || '', 10);
        setFormWidth(savedWidth > 0 ? savedWidth : Math.min(520, Math.round(split.getBoundingClientRect().width * 0.42)), false);

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);

        // Focus mode: opening a block from the preview closes the others.
        var focusToggle = stage.querySelector('[data-folio-focus]');
        if (focusToggle) {
            focusToggle.setAttribute('aria-pressed', stored('folio-focus-mode') === '1' ? 'true' : 'false');
            focusToggle.addEventListener('click', function () {
                var on = focusToggle.getAttribute('aria-pressed') !== 'true';
                focusToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
                store('folio-focus-mode', on ? '1' : null);
            });
        }

        // Whatever has focus in the form is outlined in the preview.
        form.addEventListener('focusin', function (e) {
            var card = e.target.closest('[data-block-card]');
            var name = typeof e.target.name === 'string' ? e.target.name : '';
            highlight(card ? card.getAttribute('data-block-uid') : null, card ? null : (name.split('[')[0] || null), true);
        });

        preview.setMode = setMode;
        preview.mode = function () { return split.getAttribute('data-mode'); };
        setMode(stored('folio-split-mode') || (window.innerWidth >= 1100 ? 'split' : 'form'));
    }

    // ---- Block-level preview patching -------------------------------------

    function topBlocks(root) {
        return Array.prototype.filter.call(root.querySelectorAll('[data-folio-block]'), function (el) {
            return !el.parentElement || !el.parentElement.closest('[data-folio-block]');
        });
    }

    // A detached copy (parsed from markup, so live listeners never come along)
    // without what differs between equal renders: the preview editor's outlines
    // and label badge, and CSP nonces.
    function cleaned(html) {
        var t = document.createElement('template');
        t.innerHTML = html;
        t.content.querySelectorAll('.folio-pe-badge').forEach(function (b) { b.parentNode.removeChild(b); });
        // The debug toolbar (local debug mode) carries per-request timings.
        t.content.querySelectorAll('#preflow-debug-toolbar').forEach(function (b) { b.parentNode.removeChild(b); });
        // The preview editor's own overlay layer.
        t.content.querySelectorAll('#folio-pe-overlay').forEach(function (b) { b.parentNode.removeChild(b); });
        // Every response has a fresh CSP nonce, and loaded pages hide its value anyway.
        t.content.querySelectorAll('[nonce]').forEach(function (n) { n.removeAttribute('nonce'); });
        t.content.querySelectorAll('.is-folio-hover, .is-folio-active').forEach(function (n) {
            n.classList.remove('is-folio-hover', 'is-folio-active');
            if (n.getAttribute('class') === '') { n.removeAttribute('class'); }
        });
        return t;
    }

    // The body with the patchable parts emptied (top-level blocks, or every element
    // matching selector): equal skeletons mean nothing else on the page changed.
    function skeleton(body, selector) {
        var t = cleaned(body.innerHTML);
        (selector ? Array.prototype.slice.call(t.content.querySelectorAll(selector)) : topBlocks(t.content))
            .forEach(function (el) { el.innerHTML = ''; });
        return t.innerHTML;
    }

    // Stylesheets in <head> (component CSS is collected there): a new or changed
    // one cannot be patched in, because its nonce would not match the page's CSP.
    function headAssets(doc) {
        return Array.prototype.map.call(doc.head ? doc.head.querySelectorAll('style, link[rel="stylesheet"]') : [], function (el) {
            return cleaned(el.outerHTML).innerHTML;
        }).join('');
    }

    // Replace the blocks whose markup changed; false when the page around them
    // changed too, or the block list itself differs (added, removed, reordered).
    function patchBlocks(doc, incoming) {
        var cur = topBlocks(doc.body);
        var next = topBlocks(incoming.body);
        if (next.length === 0 || cur.length !== next.length) { return false; }
        for (var i = 0; i < cur.length; i++) {
            if (cur[i].getAttribute('data-folio-block') !== next[i].getAttribute('data-folio-block')) { return false; }
        }
        if (skeleton(doc.body) !== skeleton(incoming.body)) { return false; }
        for (var j = 0; j < cur.length; j++) {
            if (cleaned(cur[j].outerHTML).innerHTML !== cleaned(next[j].outerHTML).innerHTML) {
                cur[j].outerHTML = next[j].outerHTML;
            }
        }
        if (lastHighlight) {
            preview.post({ type: 'highlight', uid: lastHighlight.uid, field: lastHighlight.field, scroll: false });
        }
        return true;
    }

    // ---- Click-to-edit: preview <-> form ----------------------------------

    var lastHighlight = null;

    function highlight(uid, field, scroll) {
        if (!preview) { return; }
        var key = (uid || '') + '|' + (field || '');
        var now = Date.now();
        if (lastHighlight && lastHighlight.key === key && now - lastHighlight.at < 150) { return; }
        lastHighlight = { key: key, at: now, uid: uid, field: field };
        preview.post({ type: 'highlight', uid: uid, field: field, scroll: !!scroll });
    }

    function setOpen(card, open) {
        card.classList.toggle('is-open', open);
        var toggle = card.querySelector(':scope > .folio-block-head [data-block-collapse]');
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    function parentCard(card) {
        return card.parentElement ? card.parentElement.closest('[data-block-card]') : null;
    }

    // The editable control for one field: inputs named "…[uid][field]" (or a
    // locale under it), or a top-level "field"; rich text focuses its editor.
    function fieldControl(scope, uid, field) {
        var controls = scope.querySelectorAll('input, textarea, select');
        for (var i = 0; i < controls.length; i++) {
            var name = controls[i].name || '';
            var hit;
            if (uid) {
                var key = '[' + uid + '][' + field + ']';
                var at = name.lastIndexOf(key);
                hit = at > -1 && /^(\[[a-z]{2}(-[A-Za-z]{2})?\])?$/.test(name.slice(at + key.length));
            } else {
                hit = name === field || name.indexOf(field + '[') === 0;
            }
            if (!hit) { continue; }
            var el = controls[i];
            if (el.type === 'hidden') {
                var editor = el.id ? scope.querySelector('trix-editor[input="' + CSS.escape(el.id) + '"]') : null;
                if (editor) { return editor; }
                var group = el.closest('.form-group');
                var visible = group ? group.querySelector('input:not([type="hidden"]), textarea, select, button') : null;
                if (visible) { return visible; }
                continue;
            }
            return el;
        }
        return null;
    }

    function selectInForm(uid, field) {
        if (!preview) { return; }
        var form = preview.form;
        var card = uid ? form.querySelector('#block-' + CSS.escape(uid)) : null;
        if (uid && !card) { return; }
        if (preview.mode() === 'preview') { preview.setMode('split'); }

        var target = null;
        if (card) {
            var focus = stored('folio-focus-mode') === '1';
            for (var c = card; c; c = parentCard(c)) {
                if (focus && c.parentElement) {
                    Array.prototype.forEach.call(c.parentElement.children, function (sib) {
                        if (sib !== c && sib.hasAttribute('data-block-card')) { setOpen(sib, false); }
                    });
                }
                setOpen(c, true);
            }
            var body = card.querySelector(':scope > .folio-block-body');
            target = field && body ? fieldControl(body, uid, field) : null;
            if (!target && body) {
                target = Array.prototype.find.call(body.querySelectorAll('input:not([type="hidden"]), textarea, select, trix-editor'), function (el) {
                    return el.closest('[data-block-card]') === card;
                }) || null;
            }
            card.classList.remove('is-located');
            void card.offsetWidth; // restart the flash
            card.classList.add('is-located');
            setTimeout(function () { card.classList.remove('is-located'); }, 1400);
        } else if (field) {
            target = fieldControl(form, null, field);
        }

        var anchor = card || (target && (target.closest('.form-group') || target));
        if (anchor) { anchor.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
        if (target && target.focus) { target.focus({ preventScroll: true }); }
        highlight(uid, uid ? null : field, false);
    }

    function onPreviewMessage(data) {
        var uid = typeof data.uid === 'string' && /^[a-f0-9]{16}$/.test(data.uid) ? data.uid : null;
        var field = typeof data.field === 'string' && /^[A-Za-z_][A-Za-z0-9_]*$/.test(data.field) ? data.field : null;
        if (data.type === 'select') {
            selectInForm(uid, field);
        } else if (data.type === 'ready' && lastHighlight) {
            preview.post({ type: 'highlight', uid: lastHighlight.uid, field: lastHighlight.field, scroll: false });
        }
    }

    // Confirmation for destructive forms: <form data-confirm="…"> (replaces inline
    // onsubmit handlers, which the strict CSP blocks).
    function onSubmitConfirm(e) {
        var f = e.target;
        if (f && f.getAttribute && f.hasAttribute('data-confirm') && !window.confirm(f.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    }

    // ---- Blocks editor -------------------------------------------------
    // Each card carries its own [_order][] input, so DOM order is the saved
    // order: moving or duplicating a card never renumbers input names.

    function siblingCard(card, dir) {
        var el = dir < 0 ? card.previousElementSibling : card.nextElementSibling;
        while (el && !el.hasAttribute('data-block-card')) {
            el = dir < 0 ? el.previousElementSibling : el.nextElementSibling;
        }
        return el;
    }

    // Changes that are not input events (moving, removing, adding cards) still
    // count for autosave and the preview.
    function notifyChange(el) {
        var form = el && el.closest ? el.closest('form') : null;
        if (form) { form.dispatchEvent(new Event('change', { bubbles: true })); }
    }

    function onBlockClick(e) {
        // Duplicate is handled by htmx (server-rendered copy with fresh uids).
        var btn = e.target.closest ? e.target.closest('[data-block-collapse],[data-block-up],[data-block-down],[data-block-remove]') : null;
        if (!btn) { return; }
        var card = btn.closest('[data-block-card]');
        if (!card) { return; }
        if (btn.hasAttribute('data-block-collapse')) {
            var open = card.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        } else if (btn.hasAttribute('data-block-up')) {
            var prev = siblingCard(card, -1);
            if (prev) { card.parentNode.insertBefore(card, prev); notifyChange(card); }
        } else if (btn.hasAttribute('data-block-down')) {
            var next = siblingCard(card, 1);
            if (next) { card.parentNode.insertBefore(next, card); notifyChange(card); }
        } else if (btn.hasAttribute('data-block-remove')) {
            var list = card.parentNode;
            var next = card.nextSibling;
            list.removeChild(card);
            notifyChange(list);
            offerUndo(card, list, next);
        }
        e.preventDefault();
    }

    // ---- Block reorder: drag the handle, or Space + arrows on it --------------
    // Order is carried by each card's own [_order][] input, so moving the card
    // in the DOM is the whole change. Moves stay within one list.

    var dragCard = null;
    var lifted = null; // { card, parent, next, handle }

    function cardLabel(card) {
        var el = card.querySelector(':scope > .folio-block-head .folio-block-label');
        return el ? el.textContent.trim() : 'Block';
    }

    function cardPosition(card) {
        var cards = Array.prototype.filter.call(card.parentNode.children, function (el) { return el.hasAttribute('data-block-card'); });
        return (cards.indexOf(card) + 1) + ' of ' + cards.length;
    }

    function announce(card, text) {
        var field = card.closest('[data-blocks-field]');
        var live = field ? field.querySelector(':scope > [data-blocks-live]') : null;
        if (!live) { return; }
        live.textContent = '';
        setTimeout(function () { live.textContent = text; }, 30);
    }

    function clearDropMarks() {
        document.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (el) {
            el.classList.remove('is-drop-before', 'is-drop-after');
        });
    }

    // The card under the pointer at the dragged card's level, if any.
    function dropTarget(el) {
        var target = el && el.closest ? el.closest('[data-block-card]') : null;
        while (target && target.parentNode !== dragCard.parentNode) { target = parentCard(target); }
        return target && target !== dragCard ? target : null;
    }

    function onHandlePointerDown(e) {
        var handle = e.target.closest ? e.target.closest('[data-block-handle]') : null;
        if (!handle) { return; }
        var card = handle.closest('[data-block-card]');
        if (card) { card.draggable = true; }
    }

    function onDragStart(e) {
        var card = e.target.closest ? e.target.closest('[data-block-card]') : null;
        if (!card || !card.draggable) { return; }
        dragCard = card;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.getAttribute('data-block-uid') || '');
        card.classList.add('is-dragging');
    }

    function onDragOver(e) {
        if (!dragCard) { return; }
        var target = dropTarget(e.target);
        clearDropMarks();
        if (!target) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var r = target.getBoundingClientRect();
        target.classList.add(e.clientY < r.top + r.height / 2 ? 'is-drop-before' : 'is-drop-after');
    }

    function onDrop(e) {
        if (!dragCard) { return; }
        var target = document.querySelector('.is-drop-before, .is-drop-after');
        if (!target || target.parentNode !== dragCard.parentNode) { return; }
        e.preventDefault();
        target.parentNode.insertBefore(dragCard, target.classList.contains('is-drop-before') ? target : target.nextSibling);
        announce(dragCard, 'Moved ' + cardLabel(dragCard) + ', position ' + cardPosition(dragCard) + '.');
        notifyChange(dragCard);
    }

    function onDragEnd() {
        clearDropMarks();
        if (dragCard) {
            dragCard.classList.remove('is-dragging');
            dragCard.draggable = false;
        }
        dragCard = null;
    }

    function onPointerUp() {
        if (dragCard) { return; }
        document.querySelectorAll('[data-block-card][draggable="true"]').forEach(function (c) { c.draggable = false; });
    }

    function endLift(cancel) {
        var l = lifted;
        lifted = null;
        l.card.classList.remove('is-lifted');
        l.handle.setAttribute('aria-pressed', 'false');
        if (cancel) {
            l.parent.insertBefore(l.card, l.next);
            // An autosave may have stored the moved order meanwhile: save the restored one.
            if (l.moved) { notifyChange(l.card); }
            l.handle.focus();
            announce(l.card, 'Cancelled. ' + cardLabel(l.card) + ' is back at position ' + cardPosition(l.card) + '.');
            return;
        }
        announce(l.card, 'Dropped ' + cardLabel(l.card) + ', position ' + cardPosition(l.card) + '.');
        // Moved and back again counts too: an autosave may have stored the moved order.
        if (l.moved || l.card.nextSibling !== l.next) { notifyChange(l.card); }
    }

    function onHandleKeydown(e) {
        var handle = e.target.closest ? e.target.closest('[data-block-handle]') : null;
        if (!handle) { return; }
        var card = handle.closest('[data-block-card]');
        if (!card) { return; }
        if (e.key === ' ' || e.key === 'Enter') {
            e.preventDefault();
            if (lifted && lifted.card === card) { endLift(false); return; }
            if (lifted) { endLift(false); }
            lifted = { card: card, parent: card.parentNode, next: card.nextSibling, handle: handle };
            card.classList.add('is-lifted');
            handle.setAttribute('aria-pressed', 'true');
            announce(card, 'Lifted ' + cardLabel(card) + ', position ' + cardPosition(card) + '. Arrow keys move it, Space drops it, Escape cancels.');
        } else if (lifted && lifted.card === card && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            var other = siblingCard(card, e.key === 'ArrowUp' ? -1 : 1);
            if (!other) { return; }
            card.parentNode.insertBefore(card, e.key === 'ArrowUp' ? other : other.nextSibling);
            lifted.moved = true;
            handle.focus();
            announce(card, cardLabel(card) + ', position ' + cardPosition(card) + '.');
        } else if (lifted && lifted.card === card && e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            endLift(true);
        }
    }

    function onHandleFocusOut(e) {
        if (!lifted || e.target !== lifted.handle) { return; }
        // Moving the card can blur its handle for a moment; only a real focus loss drops.
        setTimeout(function () {
            if (lifted && document.activeElement !== lifted.handle) { endLift(false); }
        }, 0);
    }

    // ---- Undo a removed block ---------------------------------------------
    // The removed card is kept (with its inputs) until the toast times out or a
    // newer removal replaces it; Undo puts it back where it was.

    var pendingUndo = null; // { toast, timer }

    function dismissUndo() {
        if (!pendingUndo) { return; }
        clearTimeout(pendingUndo.timer);
        if (pendingUndo.toast.parentNode) { pendingUndo.toast.parentNode.removeChild(pendingUndo.toast); }
        pendingUndo = null;
    }

    function offerUndo(card, list, next) {
        var region = document.querySelector('[data-folio-toasts]');
        if (!region) { return; }
        dismissUndo();
        var toast = document.createElement('div');
        toast.className = 'folio-toast';
        var text = document.createElement('span');
        text.textContent = cardLabel(card) + ' removed';
        var undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'btn btn-sm folio-toast-action';
        undo.textContent = 'Undo';
        undo.addEventListener('click', function () {
            if (!list.isConnected) { dismissUndo(); return; }
            var before = next && next.parentNode === list ? next : list.querySelector(':scope > [data-blocks-picker]');
            list.insertBefore(card, before);
            notifyChange(card);
            dismissUndo();
            var toggle = card.querySelector(':scope > .folio-block-head [data-block-collapse]');
            if (toggle) { toggle.focus(); }
        });
        toast.appendChild(text);
        toast.appendChild(undo);
        region.appendChild(toast);
        pendingUndo = { toast: toast, timer: setTimeout(dismissUndo, 8000) };
    }

    // ---- Unsaved-changes guard ---------------------------------------------
    // Forms that autosave (hx-post) never lose work; the others ask before leaving.

    function initUnsavedGuard() {
        var dirty = [];
        document.querySelectorAll('.folio-form form').forEach(function (form) {
            if (form.hasAttribute('hx-post')) { return; }
            var mark = function () { if (dirty.indexOf(form) < 0) { dirty.push(form); } };
            form.addEventListener('input', mark);
            form.addEventListener('change', mark);
        });
        // Registered after onSubmitConfirm (boot order), so a cancelled data-confirm
        // is already defaultPrevented here and keeps the guard; a real submit clears it
        // synchronously, before the navigation's beforeunload.
        document.addEventListener('submit', function (e) {
            if (!e.defaultPrevented) { dirty = dirty.filter(function (f) { return f !== e.target; }); }
        });
        window.addEventListener('beforeunload', function (e) {
            if (dirty.length === 0) { return; }
            e.preventDefault();
            e.returnValue = '';
        });
    }

    // Cards added by htmx: run the same per-element setup as on page load.
    function onHtmxLoad(e) {
        var root = e.detail && e.detail.elt ? e.detail.elt : null;
        if (!root || !root.querySelectorAll) { return; }
        if (root.hasAttribute && root.hasAttribute('data-folio-matrix')) { initMatrix(root); }
        root.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
        initRelationFilter(root);
        if (root.hasAttribute && root.hasAttribute('data-block-card')) { notifyChange(root); }
        root.querySelectorAll('[data-folio-focal]').forEach(placeFocalMarker);
        initTabs(root);
    }

    // Share-preview link: copy the URL (select it when the clipboard is unavailable).
    function onCopyClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-folio-copy]') : null;
        if (!btn) { return; }
        var box = btn.closest('.folio-share');
        var input = box ? box.querySelector('.folio-share-url') : null;
        if (!input) { return; }
        var done = function () { btn.textContent = 'Copied'; };
        var fallback = function () { input.focus(); input.select(); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done, fallback);
        } else {
            fallback();
        }
    }

    // Autosave never races a real save: once the form is submitted, pending
    // autosaves are dropped (their delay timer can still fire during navigation,
    // and would bump _version under the submit, which then looks like a conflict).
    function onAutosaveSubmit(e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('hx-post')) { e.target._folioSubmitting = true; }
    }

    function onBeforeRequest(e) {
        var elt = e.detail && e.detail.elt;
        if (elt && elt._folioSubmitting) { e.preventDefault(); }
    }

    // A failed autosave must say so: htmx does not swap error responses.
    function onAutosaveError(e) {
        var elt = e.detail && e.detail.elt;
        var state = document.getElementById('folio-save-state');
        if (!elt || !state || elt.tagName !== 'FORM' || !elt.hasAttribute('hx-post')) { return; }
        var xhr = e.detail.xhr;
        state.textContent = xhr && xhr.status === 403
            ? 'Not saved — you don\'t have access to edit this.'
            : 'Not saved — check your connection and save manually.';
        state.classList.add('is-conflict');
    }

    // A short message in the toast region; the server sends it as HX-Trigger "folio:error" (e.g. a 403).
    function showToast(message) {
        var region = document.querySelector('[data-folio-toasts]');
        if (!region || !message) { return; }
        var toast = document.createElement('div');
        toast.className = 'folio-toast';
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        region.appendChild(toast);
        setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 6000);
    }

    function onFolioError(e) {
        showToast(e.detail && typeof e.detail.message === 'string' ? e.detail.message : '');
    }

    // Asset library: click the image to set the focal point (percent of width/height).
    function placeFocalMarker(picker) {
        var form = picker.closest('section') ? picker.closest('section').querySelector('.folio-asset-form') : null;
        var marker = picker.querySelector('.folio-focal-marker');
        if (!form || !marker) { return; }
        marker.style.left = form.querySelector('[name=focal_x]').value + '%';
        marker.style.top = form.querySelector('[name=focal_y]').value + '%';
    }

    function onFocalClick(e) {
        var picker = e.target.closest ? e.target.closest('[data-folio-focal]') : null;
        if (!picker) { return; }
        var form = picker.closest('section') ? picker.closest('section').querySelector('.folio-asset-form') : null;
        var rect = picker.getBoundingClientRect();
        if (!form || rect.width === 0 || rect.height === 0) { return; }
        var x = Math.round(Math.min(100, Math.max(0, (e.clientX - rect.left) / rect.width * 100)));
        var y = Math.round(Math.min(100, Math.max(0, (e.clientY - rect.top) / rect.height * 100)));
        form.querySelector('[name=focal_x]').value = String(x);
        form.querySelector('[name=focal_y]').value = String(y);
        placeFocalMarker(picker);
    }

    // Asset picker (inside the drawer iframe): hand the chosen asset to the editor.
    function onPickClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-folio-pick]') : null;
        if (!btn || window.parent === window) { return; }
        window.parent.postMessage({ source: 'folio-assets', multiple: btn.getAttribute('data-multiple') === '1', items: [{
            id: btn.getAttribute('data-id'),
            thumb: btn.getAttribute('data-thumb') || '',
            label: btn.getAttribute('data-label') || ''
        }] }, window.location.origin);
        btn.textContent = 'Selected';
    }

    // Tabs: <div data-folio-tabs> with a .folio-segmented of <button data-folio-tab="key"> and
    // sibling <div data-folio-tabpanel="key">. Without JS every panel shows.
    function initTabs(root) {
        var boxes = Array.prototype.slice.call(root.querySelectorAll('[data-folio-tabs]'));
        if (root.matches && root.matches('[data-folio-tabs]')) { boxes.unshift(root); }
        boxes.forEach(function (box) {
            try { initTabBox(box); } catch (e) { if (window.console) { console.error('folio tabs', e); } } // one bad group must not stop the rest
        });
    }

    function initTabBox(box) {
        if (box.hasAttribute('data-folio-tabs-ready')) { return; }
        var tabs = Array.prototype.slice.call(box.querySelectorAll(':scope > * > [data-folio-tab], :scope > [data-folio-tab]'));
        if (!tabs.length) { return; }
        var tablist = tabs[0].parentNode;
        tablist.setAttribute('role', 'tablist');
        var n = 0;
        var uid = Math.random().toString(36).slice(2, 7);
        function panelOf(tab) {
            // compared by attribute, not through a selector, so any key is safe (quotes, backslashes)
            var key = tab.getAttribute('data-folio-tab');
            var panels = box.querySelectorAll(':scope > [data-folio-tabpanel]');
            for (var i = 0; i < panels.length; i++) {
                if (panels[i].getAttribute('data-folio-tabpanel') === key) { return panels[i]; }
            }
            return null;
        }
        function select(tab, focus) {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                var p = panelOf(t);
                if (p) { p.hidden = !on; }
            });
            if (focus) { tab.focus(); }
        }
        tabs.forEach(function (t) {
            var p = panelOf(t);
            var id = 'folio-tab-' + uid + '-' + (n++);
            t.setAttribute('role', 'tab');
            t.id = id;
            if (!t.hasAttribute('type')) { t.type = 'button'; }
            if (p) {
                p.setAttribute('role', 'tabpanel');
                p.tabIndex = 0;
                p.setAttribute('aria-labelledby', id);
                t.setAttribute('aria-controls', p.id || (p.id = id + '-panel'));
            }
            t.addEventListener('click', function () { select(t, false); });
            t.addEventListener('keydown', function (e) {
                var i = tabs.indexOf(t);
                var next = null;
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = tabs[(i + 1) % tabs.length]; }
                else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
                else if (e.key === 'Home') { next = tabs[0]; }
                else if (e.key === 'End') { next = tabs[tabs.length - 1]; }
                if (next) { e.preventDefault(); select(next, true); }
            });
        });
        select(tabs.filter(function (t) { return t.hasAttribute('data-folio-tab-active'); })[0] || tabs[0], false);
        box.setAttribute('data-folio-tabs-ready', '');
    }

    // Multi relation: the filter input narrows the checkbox chips (checked ones stay in the form).
    function initRelationFilter(root) {
        var inputs = Array.prototype.slice.call(root.querySelectorAll('[data-folio-relation-filter]'));
        if (root.matches && root.matches('[data-folio-relation-filter]')) { inputs.unshift(root); }
        inputs.forEach(function (input) {
            var box = input.closest('[data-folio-relation-multi]');
            if (!box || input.hasAttribute('data-folio-relation-ready')) { return; }
            input.setAttribute('data-folio-relation-ready', '');
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase();
                box.querySelectorAll('.folio-chip').forEach(function (chip) {
                    chip.hidden = q !== '' && chip.textContent.toLowerCase().indexOf(q) === -1;
                });
            });
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
        });
    }

    function boot() {
        document.addEventListener('submit', onSubmitConfirm);
        document.addEventListener('click', onBlockClick);
        document.addEventListener('pointerdown', onHandlePointerDown);
        document.addEventListener('pointerup', onPointerUp);
        document.addEventListener('dragstart', onDragStart);
        document.addEventListener('dragover', onDragOver);
        document.addEventListener('drop', onDrop);
        document.addEventListener('dragend', onDragEnd);
        document.addEventListener('keydown', onHandleKeydown);
        document.addEventListener('focusout', onHandleFocusOut);
        document.addEventListener('click', onCopyClick);
        document.addEventListener('click', onFocalClick);
        document.addEventListener('click', onPickClick);
        document.addEventListener('click', onAssetFieldClick);
        document.querySelectorAll('[data-folio-focal]').forEach(placeFocalMarker);
        document.addEventListener('submit', onAutosaveSubmit);
        document.addEventListener('htmx:beforeRequest', onBeforeRequest);
        document.addEventListener('htmx:responseError', onAutosaveError);
        document.addEventListener('htmx:sendError', onAutosaveError);
        document.addEventListener('htmx:load', onHtmxLoad);
        document.addEventListener('folio:error', onFolioError);
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', onKeydown);
        initTabs(document);
        initRelationFilter(document);
        initSplit();
        initUnsavedGuard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
