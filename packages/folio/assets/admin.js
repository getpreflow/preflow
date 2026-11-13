/* Folio admin behaviors. Vanilla, no build step. */
(function () {
    'use strict';

    // The page's words (folio.js.* in the interface language): <script type="application/json" id="folio-i18n">,
    // read once. folioT('removed', {label: 'Hero'}) fills :label; a missing key comes back as the key.
    var folioTexts = null;
    function folioT(key, params) {
        if (folioTexts === null) {
            folioTexts = {};
            var el = document.getElementById('folio-i18n');
            try { folioTexts = el ? JSON.parse(el.textContent || '{}') : {}; } catch (e) { folioTexts = {}; }
        }
        var text = typeof folioTexts[key] === 'string' ? folioTexts[key] : key;
        Object.keys(params || {}).forEach(function (name) {
            text = text.split(':' + name).join(String(params[name]));
        });
        return text;
    }
    window.folioT = folioT; // for the other admin scripts (image editor, extensions)

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    var activeMatrix = null;   // the matrix root that opened the current drawer
    var drawerEl = null;       // the current drawer overlay element
    var activePicker = null;   // {multiple, onPicked} of the asset picker in the drawer

    function closeDrawer() {
        if (drawerEl && drawerEl.parentNode) {
            drawerEl.parentNode.removeChild(drawerEl);
        }
        drawerEl = null;
        activeMatrix = null;
        activePicker = null;
    }

    function openDrawer(url) {
        closeDrawer();
        var overlay = document.createElement('div');
        overlay.className = 'folio-drawer';
        var panel = document.createElement('div');
        panel.className = 'folio-drawer-panel';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn btn-secondary btn-sm folio-drawer-close';
        close.textContent = folioT('close');
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
                    '<option value="">' + esc(folioT('default')) + '</option>';
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
                '<button type="button" class="btn btn-ghost" data-matrix-up>' + esc(folioT('up')) + '</button>' +
                '<button type="button" class="btn btn-ghost" data-matrix-down>' + esc(folioT('down')) + '</button>' +
                '<button type="button" class="btn btn-ghost" data-tone="danger" data-matrix-remove>' + esc(folioT('remove')) + '</button>' +
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

    // Messages count only from Folio's own frames: the drawer and the preview. A same-origin
    // window elsewhere (another tab, a popup, a frame on a site page) cannot steer the editor.
    function fromOwnFrame(source) {
        if (!source) { return false; }
        var drawerFrame = drawerEl ? drawerEl.querySelector('iframe') : null;
        if (drawerFrame && drawerFrame.contentWindow === source) { return true; }
        return !!(preview && preview.frame && preview.frame.contentWindow === source);
    }

    function onMessage(e) {
        if (e.origin !== window.location.origin || !fromOwnFrame(e.source)) { return; }
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

    // The asset library as a picker in the drawer: Folio.chooseAssets(options, onPicked).
    // options: multiple (default false), private (true: private assets only, false: public only,
    // default null: no filter), url (the picker URL, default {prefix}/assets?picker=1&...).
    // onPicked(items) gets [{id, thumb, label}] with ids checked; single mode closes after one pick.
    function chooseAssets(options, onPicked) {
        options = options || {};
        var url = options.url ? String(options.url) : null;
        if (url === null) {
            var prefix = (document.body && document.body.getAttribute('data-folio-prefix')) || '';
            url = prefix.replace(/\/+$/, '') + '/assets?picker=1&multiple=' + (options.multiple ? '1' : '0');
            if (options.private === true || options.private === false) { url += '&private=' + (options.private ? '1' : '0'); }
        }
        openDrawer(url);
        activePicker = { multiple: options.multiple === true, onPicked: onPicked };
    }
    window.Folio = window.Folio || {};
    window.Folio.chooseAssets = chooseAssets;

    function onAssetsPicked(data) {
        var picker = activePicker;
        if (!picker) { return; }
        var items = [];
        (Array.isArray(data.items) ? data.items : []).forEach(function (item) {
            var id = String((item && item.id) || '');
            if (/^[a-f0-9]{32}$/.test(id)) { items.push({ id: id, thumb: String(item.thumb || ''), label: String(item.label || '') }); }
        });
        if (!picker.multiple) { closeDrawer(); }
        if (items.length && typeof picker.onPicked === 'function') { picker.onPicked(items); }
    }

    // Asset fields: "Choose" opens the picker; picked assets become hidden {name}_ids[] inputs
    // with a thumbnail.
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
        remove.textContent = folioT('remove');
        li.appendChild(remove);
        return li;
    }

    function fillAssetField(field, items) {
        if (!field.isConnected) { return; }
        var list = field.querySelector('.folio-asset-selection');
        var multiple = field.getAttribute('data-multiple') === '1';
        items.forEach(function (item) {
            if (!multiple) { list.innerHTML = ''; }
            var dupe = list.querySelector('input[value="' + item.id + '"]');
            if (!dupe) { list.appendChild(assetItem(field, item.id, item.thumb, item.label)); }
        });
        notifyChange(field);
    }

    function onAssetFieldClick(e) {
        var choose = e.target.closest ? e.target.closest('[data-folio-asset-choose]') : null;
        if (choose) {
            var field = choose.closest('[data-folio-asset-field]');
            if (field && field.getAttribute('data-picker-url')) {
                chooseAssets({ url: field.getAttribute('data-picker-url'), multiple: field.getAttribute('data-multiple') === '1' }, function (items) {
                    fillAssetField(field, items);
                });
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

        // Edits of the form's own controls and of those outside it (the side panel's form="…" fields).
        ['input', 'change'].forEach(function (name) {
            document.addEventListener(name, function (e) { if (formOf(e.target) === form) { schedule(); } });
        });

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
    // onsubmit handlers, which the strict CSP blocks). In a form with several buttons
    // (the list's bulk bar) the clicked button may carry its own data-confirm / data-delete-plan.
    function confirmSource(e) {
        var s = e.submitter;
        if (s && s.hasAttribute && (s.hasAttribute('data-confirm') || s.hasAttribute('data-delete-plan'))) { return s; }
        return e.target;
    }

    function onSubmitConfirm(e) {
        var f = e.target;
        if (!f || !f.hasAttribute) { return; }
        var src = confirmSource(e);
        if (src.hasAttribute('data-delete-plan')) { return; } // onDeletePlanSubmit asks
        if (removalAddresses(src) > 0) { return; } // onRemovalSubmit asks
        if (src.hasAttribute('data-confirm') && !window.confirm(src.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    }

    // Delete forms with data-delete-plan="{url}" data-delete-id="{id}": fetch what the
    // delete would also do ("Also deletes 12 jobs.") and confirm that; a restricted plan
    // only shows why. The server plans again when the form is submitted. If the summary
    // can't be fetched, the form's generic data-confirm text is asked instead.
    // The bulk bar's Delete button carries data-delete-plan itself: the plan is asked for
    // the ticked ids[], and the button is submitted again once confirmed.
    function onDeletePlanSubmit(e) {
        var f = e.target;
        if (!f || !f.hasAttribute) { return; }
        if (f.hasAttribute('data-delete-confirmed')) { f.removeAttribute('data-delete-confirmed'); return; }
        var src = confirmSource(e);
        if (!src.hasAttribute('data-delete-plan')) { return; }
        e.preventDefault();
        var ids = src === f
            ? [f.getAttribute('data-delete-id') || '']
            : Array.prototype.filter.call(f.elements, function (el) { return el.name === 'ids[]' && el.checked; })
                .map(function (el) { return el.value; });
        if (!ids.length || f.getAttribute('data-delete-busy') === '1') { return; }
        f.setAttribute('data-delete-busy', '1');
        var body = new URLSearchParams();
        var token = f.querySelector('input[name="_csrf_token"]');
        if (token) { body.append('_csrf_token', token.value); }
        ids.forEach(function (id) { body.append('ids[]', id); });
        var send = function () {
            if (src === f) {
                HTMLFormElement.prototype.submit.call(f);
            } else if (f.requestSubmit) {
                f.setAttribute('data-delete-confirmed', '1');
                f.requestSubmit(src);
            } else {
                f.setAttribute('action', src.getAttribute('formaction') || f.getAttribute('action') || '');
                HTMLFormElement.prototype.submit.call(f);
            }
        };
        fetch(src.getAttribute('data-delete-plan'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('plan ' + r.status)); })
            .then(function (plan) {
                if (!plan || typeof plan.confirm !== 'string') { throw new Error('plan shape'); }
                if (plan.restricted) {
                    window.alert(plan.confirm);
                } else if ((parseInt(plan.addresses, 10) || 0) > 0) {
                    // The records have public addresses: ask where their old links go as well.
                    removalPrompt(plan.confirm.split('\n'), folioT('redirects.prompt.delete'), function (target) {
                        setRemovalTarget(f, target);
                        send();
                    });
                } else if (window.confirm(plan.confirm)) {
                    send();
                }
            })
            .catch(function () {
                if (window.confirm(src.getAttribute('data-confirm') || folioT('confirm_delete'))) { send(); }
            })
            .then(function () { f.removeAttribute('data-delete-busy'); });
    }

    // ---- "Send its old links to…" -------------------------------------------
    // Deleting or unpublishing records that have public addresses asks, in place of window.confirm,
    // where their old links go: nowhere ("Leave them as not found", the default: they answer 404),
    // a page picked from {prefix}/redirects/_targets (posted as its "type:id"), or a URL or path.
    // The choice is posted as _redirect_to; the server checks it again (RemovalTarget::parse).
    // The unpublish form carries its count as data-removal-addresses; the delete plan as "addresses".

    function removalAddresses(el) {
        return el && el.getAttribute ? (parseInt(el.getAttribute('data-removal-addresses') || '0', 10) || 0) : 0;
    }

    /** The form's _redirect_to: the chosen target, or none at all for "Leave them as not found". */
    function setRemovalTarget(form, target) {
        form.querySelectorAll('input[name="_redirect_to"]').forEach(function (el) { el.remove(); });
        if (!target) { return; }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_redirect_to';
        input.value = target;
        form.appendChild(input);
    }

    function onRemovalSubmit(e) {
        var f = e.target;
        if (!f || !f.hasAttribute || f.hasAttribute('data-delete-plan') || removalAddresses(f) <= 0) { return; }
        e.preventDefault();
        removalPrompt([f.getAttribute('data-confirm') || ''], folioT('redirects.prompt.unpublish'), function (target) {
            setRemovalTarget(f, target);
            HTMLFormElement.prototype.submit.call(f);
        });
    }

    var removalDialog = null;

    /**
     * Shows the prompt: the confirmation's lines, the three choices, Cancel and $confirmLabel.
     * onConfirm(target) gets "type:id", a URL or path, or '' for none; Cancel sends nothing.
     */
    function removalPrompt(lines, confirmLabel, onConfirm) {
        if (removalDialog) { return; }
        var t = function (key, params) { return folioT('redirects.prompt.' + key, params); };
        var el = function (tag, className, text) {
            var node = document.createElement(tag);
            if (className) { node.className = className; }
            if (text) { node.textContent = text; }
            return node;
        };
        var dialog = el('dialog', 'folio-removal-dialog');
        dialog.setAttribute('data-removal-dialog', '');
        dialog.setAttribute('aria-label', t('send_to'));
        lines.filter(function (line) { return line !== ''; }).forEach(function (line) {
            dialog.appendChild(el('p', 'folio-removal-line', line));
        });

        var choices = el('fieldset', 'folio-removal-choices');
        choices.appendChild(el('legend', '', t('send_to')));
        var radios = {};
        var option = function (value, text) {
            var label = el('label', 'folio-removal-option');
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'folio_removal_target';
            radio.value = value;
            radio.setAttribute('value', value);
            radio.checked = value === 'none';
            radios[value] = radio;
            label.appendChild(radio);
            label.appendChild(el('span', '', text));
            choices.appendChild(label);
            return label;
        };
        var choose = function (value) {
            Object.keys(radios).forEach(function (key) { radios[key].checked = key === value; });
        };
        var chosen = function () {
            return Object.keys(radios).filter(function (key) { return radios[key].checked; })[0] || 'none';
        };

        option('none', t('leave'));
        option('page', t('to_page'));
        var search = el('input', 'folio-removal-search');
        search.type = 'search';
        search.setAttribute('data-removal-search', '');
        search.setAttribute('autocomplete', 'off');
        search.setAttribute('placeholder', t('search'));
        search.setAttribute('aria-label', t('search'));
        var results = el('ul', 'folio-removal-results');
        results.setAttribute('data-removal-results', '');
        var picked = el('p', 'folio-removal-picked');
        picked.setAttribute('aria-live', 'polite');
        choices.appendChild(search);
        choices.appendChild(results);
        choices.appendChild(picked);
        option('url', t('to_url'));
        var url = el('input', 'folio-removal-url');
        url.type = 'text';
        url.setAttribute('data-removal-url', '');
        url.setAttribute('autocomplete', 'off');
        url.setAttribute('placeholder', t('url_placeholder'));
        url.setAttribute('aria-label', t('to_url'));
        choices.appendChild(url);
        dialog.appendChild(choices);

        var error = el('p', 'folio-removal-error');
        error.setAttribute('data-removal-error', '');
        error.setAttribute('role', 'alert');
        error.hidden = true;
        dialog.appendChild(error);

        var footer = el('div', 'folio-removal-footer');
        var cancel = el('button', 'btn btn-ghost', t('cancel'));
        cancel.type = 'button';
        cancel.setAttribute('data-removal-cancel', '');
        var ok = el('button', 'btn btn-danger', confirmLabel);
        ok.type = 'button';
        ok.setAttribute('data-removal-confirm', '');
        footer.appendChild(cancel);
        footer.appendChild(ok);
        dialog.appendChild(footer);

        var page = null; // the picked page's "type:id"
        var seq = 0;
        var timer = null;
        search.addEventListener('input', function () {
            choose('page');
            var q = search.value.trim();
            clearTimeout(timer);
            if (q === '') { results.innerHTML = ''; return; }
            timer = setTimeout(function () {
                var mine = ++seq;
                var prefix = document.body.getAttribute('data-folio-prefix') || '';
                fetch(prefix + '/redirects/_targets?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : { items: [] }; })
                    .then(function (data) {
                        if (mine !== seq) { return; }
                        results.innerHTML = '';
                        var items = (data && data.items) || [];
                        if (items.length === 0) { results.appendChild(el('li', 'folio-removal-none', folioT('no_matches'))); }
                        items.forEach(function (item) {
                            if (!item || typeof item.value !== 'string') { return; }
                            var li = el('li');
                            var button = el('button', 'folio-removal-pick');
                            button.type = 'button';
                            button.setAttribute('data-removal-pick', item.value);
                            button.appendChild(el('span', 'folio-removal-pick-label', String(item.label || item.value)));
                            button.appendChild(el('span', 'folio-removal-pick-meta', [item.type, item.url].filter(Boolean).join(' · ')));
                            button.addEventListener('click', function () {
                                page = item.value;
                                choose('page');
                                picked.textContent = t('picked', { label: String(item.label || item.value) });
                                error.hidden = true;
                            });
                            li.appendChild(button);
                            results.appendChild(li);
                        });
                    })
                    .catch(function () {});
            }, 200);
        });
        url.addEventListener('input', function () { choose('url'); });

        var close = function () {
            clearTimeout(timer);
            if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
            dialog.remove();
            removalDialog = null;
        };
        cancel.addEventListener('click', close);
        dialog.addEventListener('cancel', function (e) { e.preventDefault(); close(); }); // Escape
        ok.addEventListener('click', function () {
            var target = '';
            var mode = chosen();
            if (mode === 'page') {
                if (!page) { error.textContent = t('pick_page'); error.hidden = false; return; }
                target = page;
            } else if (mode === 'url') {
                target = url.value.trim();
                // An http(s) URL with a host, or a path on this site (never "//host" or "/\host").
                if (!/^https?:\/\/[^\/\\\s]+/i.test(target) && !/^\/(?![\/\\])/.test(target)) {
                    error.textContent = t('url_invalid');
                    error.hidden = false;
                    return;
                }
            }
            close();
            onConfirm(target);
        });

        document.body.appendChild(dialog);
        removalDialog = dialog;
        if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
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
        return el ? el.textContent.trim() : folioT('block');
    }

    function cardPosition(card) {
        var cards = Array.prototype.filter.call(card.parentNode.children, function (el) { return el.hasAttribute('data-block-card'); });
        return folioT('position', { index: cards.indexOf(card) + 1, total: cards.length });
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
        announce(dragCard, folioT('moved', { label: cardLabel(dragCard), position: cardPosition(dragCard) }));
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
            announce(l.card, folioT('cancelled', { label: cardLabel(l.card), position: cardPosition(l.card) }));
            return;
        }
        announce(l.card, folioT('dropped', { label: cardLabel(l.card), position: cardPosition(l.card) }));
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
            announce(card, folioT('lifted', { label: cardLabel(card), position: cardPosition(card) }));
        } else if (lifted && lifted.card === card && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            var other = siblingCard(card, e.key === 'ArrowUp' ? -1 : 1);
            if (!other) { return; }
            card.parentNode.insertBefore(card, e.key === 'ArrowUp' ? other : other.nextSibling);
            lifted.moved = true;
            handle.focus();
            announce(card, folioT('at_position', { label: cardLabel(card), position: cardPosition(card) }));
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
    var UNDO_MS = 8000;     // how long a removed block can be put back

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
        text.textContent = folioT('removed', { label: cardLabel(card) });
        var undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'btn btn-sm folio-toast-action';
        undo.textContent = folioT('undo');
        undo.addEventListener('click', function () {
            if (!list.isConnected) { dismissUndo(); return; }
            var before = next && next.parentNode === list ? next : list.querySelector(':scope > [data-blocks-picker]');
            list.insertBefore(card, before);
            notifyChange(card);
            dismissUndo();
            var toggle = card.querySelector(':scope > .folio-block-head [data-block-collapse]');
            if (toggle) { toggle.focus(); }
        });
        // The undo window, shown as a bar that runs out with it.
        var timer = document.createElement('span');
        timer.className = 'folio-toast-timer';
        timer.setAttribute('aria-hidden', 'true');
        if (timer.style) { timer.style.animationDuration = UNDO_MS + 'ms'; }
        toast.appendChild(text);
        toast.appendChild(undo);
        toast.appendChild(timer);
        region.appendChild(toast);
        pendingUndo = { toast: toast, timer: setTimeout(dismissUndo, UNDO_MS) };
    }

    // ---- Unsaved-changes guard ---------------------------------------------
    // One guard for the editor's forms (.folio-form, and the side panel's own forms). An input or change on
    // a control of a form marks that form dirty: a control inside it, or outside with form="…" (the
    // editor's side panel names the record form so). A submit that goes ahead clears it, before the
    // navigation's beforeunload. Forms that autosave (hx-post) never lose work and are not guarded; their
    // edits show as "Unsaved changes" in the save state instead (onRecordEdit).
    // Hooks for in-admin navigation (a styled leave dialog, 0.75 Task 7): Folio.unsaved.isDirty(),
    // .dirtyForms(), .clear(form), and .onLeave(fn), a check every leave runs through (fn(e) returns true
    // to let the page go; beforeunload asks the browser's own question only while no hook answered).

    // The form a control belongs to: its form owner (form="…" included), else the form around it.
    function formOf(el) {
        if (!el) { return null; }
        if (el.form && el.form.tagName === 'FORM') { return el.form; }
        if (el.tagName === 'FORM') { return el; }
        return el.closest ? el.closest('form') : null;
    }

    var unsaved = { dirty: [], guarded: [], leaveChecks: [] };

    function guards(form) {
        return !!form && unsaved.guarded.indexOf(form) >= 0;
    }

    function markDirty(form) {
        if (guards(form) && unsaved.dirty.indexOf(form) < 0) { unsaved.dirty.push(form); }
    }

    function clearDirty(form) {
        unsaved.dirty = form ? unsaved.dirty.filter(function (f) { return f !== form; }) : [];
    }

    function onGuardEdit(e) {
        markDirty(formOf(e.target));
    }

    function onGuardSubmit(e) {
        if (!e.defaultPrevented) { clearDirty(e.target); }
    }

    function onGuardBeforeUnload(e) {
        if (unsaved.dirty.length === 0) { return; }
        for (var i = 0; i < unsaved.leaveChecks.length; i++) {
            if (unsaved.leaveChecks[i](e) === true) { return; }
        }
        e.preventDefault();
        e.returnValue = '';
    }

    function initUnsavedGuard() {
        unsaved.guarded = Array.prototype.slice.call(document.querySelectorAll('.folio-form form, .folio-side form'))
            .filter(function (form) { return !form.hasAttribute('hx-post'); });
        if (unsaved.guarded.length === 0) { return; }
        document.addEventListener('input', onGuardEdit);
        document.addEventListener('change', onGuardEdit);
        // Registered after onSubmitConfirm (boot order), so a cancelled data-confirm
        // is already defaultPrevented here and keeps the guard; a real submit clears it
        // synchronously, before the navigation's beforeunload.
        document.addEventListener('submit', onGuardSubmit);
        window.addEventListener('beforeunload', onGuardBeforeUnload);
    }

    window.Folio = window.Folio || {};
    window.Folio.unsaved = {
        isDirty: function (form) { return form ? unsaved.dirty.indexOf(form) >= 0 : unsaved.dirty.length > 0; },
        dirtyForms: function () { return unsaved.dirty.slice(); },
        clear: function (form) { clearDirty(form || null); },
        onLeave: function (fn) { if (typeof fn === 'function') { unsaved.leaveChecks.push(fn); } }
    };

    // ---- Editor frame (form.twig, 0.75) ----------------------------------------
    // The record form (#folio-record) is posted by buttons outside it (the topbar) and holds controls
    // outside it (the side panel), all naming it with form="…". Their events do not bubble to the form,
    // so what reacts to an edit listens on the document and asks formOf(): the save state, the preview,
    // the guard, "needs change" buttons.

    // The save state (#folio-save-state): its text, and the state class (unsaved, saving, conflict; none = saved).
    function setSaveState(stateEl, state, text) {
        if (!stateEl) { return; }
        ['is-unsaved', 'is-saving', 'is-conflict'].forEach(function (c) { stateEl.classList.remove(c); });
        if (state) { stateEl.classList.add('is-' + state); }
        var label = stateEl.querySelector('[data-folio-save-text]');
        if (label) { label.textContent = text; } else { stateEl.textContent = text; }
    }

    // An autosaving form's save state: the element its hx-target names (#folio-save-state).
    function saveStateOf(form) {
        if (!form || !form.hasAttribute || !form.hasAttribute('hx-post')) { return null; }
        var target = form.getAttribute('hx-target') || '';
        return target.charAt(0) === '#' ? document.getElementById(target.slice(1)) : null;
    }

    // The first edit after a save says so ("Unsaved changes"); autosave then shows "Saving…", and its
    // answer replaces the element ("Draft saved 19:37").
    function onRecordEdit(e) {
        var form = formOf(e.target);
        var stateEl = saveStateOf(form);
        if (!stateEl || stateEl.classList.contains('is-unsaved') || stateEl.classList.contains('is-saving')) { return; }
        setSaveState(stateEl, 'unsaved', stateEl.getAttribute('data-text-unsaved') || '');
    }

    function onAutosaveStart(e) {
        var elt = e.detail && e.detail.elt;
        if (e.defaultPrevented || !elt || elt.tagName !== 'FORM') { return; }
        var stateEl = saveStateOf(elt);
        if (stateEl) { setSaveState(stateEl, 'saving', stateEl.getAttribute('data-text-saving') || ''); }
    }

    // "Needs change": a submit with data-folio-needs-change stays disabled until a control of its own form
    // changes (a panel's "Transfer" with nothing picked yet); a reset of the form disables it again.
    function initNeedsChange(root) {
        var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-folio-needs-change]'));
        if (root.matches && root.matches('[data-folio-needs-change]')) { buttons.unshift(root); }
        buttons.forEach(function (btn) {
            if (btn.hasAttribute('data-folio-needs-change-ready')) { return; }
            btn.setAttribute('data-folio-needs-change-ready', '');
            btn.disabled = true;
        });
    }

    function onNeedsChangeEdit(e) {
        var form = formOf(e.target);
        if (!form) { return; }
        document.querySelectorAll('[data-folio-needs-change-ready]').forEach(function (btn) {
            if (formOf(btn) === form && btn !== e.target) { btn.disabled = false; }
        });
    }

    function onNeedsChangeReset(e) {
        var form = e.target;
        setTimeout(function () {
            document.querySelectorAll('[data-folio-needs-change-ready]').forEach(function (btn) {
                if (formOf(btn) === form) { btn.disabled = true; }
            });
        }, 0);
    }

    // Submit a form with the button that would have been clicked: requestSubmit(button) runs the checks,
    // the submit handlers (confirm, busy state, guard) and sends the button's name and value (_action).
    function submitWith(button) {
        var form = formOf(button);
        if (!button || button.disabled || !form) { return false; }
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(button); } else { button.click(); }
        return true;
    }

    // ---- Publish split button --------------------------------------------------
    // Publish, and a chevron opening "Publish at…" (a <details>, so it works without script). With
    // script: aria-expanded on the chevron, Escape and a click elsewhere close it (Escape returns the
    // focus to the chevron), opening moves the focus to the date; a date set turns Publish into
    // "Schedule", and the hint under the date says when ("in 2 days").
    function relativeWhen(value) {
        var at = value ? new Date(value) : null;
        if (!at || isNaN(at.getTime())) { return ''; }
        var seconds = Math.round((at.getTime() - Date.now()) / 1000);
        var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        var unit = 'second', amount = seconds;
        for (var i = 0; i < units.length; i++) {
            if (Math.abs(seconds) >= units[i][1]) { unit = units[i][0]; amount = Math.round(seconds / units[i][1]); break; }
        }
        try {
            var lang = (document.documentElement.getAttribute && document.documentElement.getAttribute('lang')) || undefined;
            return new Intl.RelativeTimeFormat(lang, { numeric: 'auto' }).format(amount, unit);
        } catch (err) {
            return '';
        }
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
                main.setAttribute('title', text + ' (' + modHint('↵') + ')');
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

    function onSplitButtonEvent(e) {
        var target = e.target;
        if (e.type === 'keydown') {
            var group = target && target.closest ? target.closest('[data-folio-split-ready]') : null;
            var menu = group ? splitMenuOf(group) : null;
            if (e.key === 'Escape' && menu && menu.open) {
                e.preventDefault();
                setSplitOpen(group, false, true);
            }
            return;
        }
        if (e.type === 'click') {
            var clear = target && target.closest ? target.closest('[data-folio-date-clear]') : null;
            if (clear) {
                var box = clear.closest('[data-folio-split-ready]') || document;
                var input = box.querySelector('[data-folio-publish-at]');
                if (input) {
                    input.value = '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    if (typeof input.focus === 'function') { input.focus(); }
                }
                return;
            }
            // A click elsewhere closes an open menu.
            document.querySelectorAll('[data-folio-split-ready]').forEach(function (g) {
                var m = splitMenuOf(g);
                var inside = target && target.closest ? target.closest('[data-folio-split-ready]') === g : false;
                if (m && m.open && !inside) { setSplitOpen(g, false, false); }
            });
            return;
        }
        // input / change on the date
        if (target && target.hasAttribute && target.hasAttribute('data-folio-publish-at')) {
            var owner = target.closest('[data-folio-split-ready]');
            if (owner) { publishLabel(owner); }
        }
    }

    // ---- Tabs in the editor: an invalid field in a hidden panel opens its tab; a link to a tab opens it.
    function tabFor(panel) {
        var box = panel && panel.closest ? panel.closest('[data-folio-tabs]') : null;
        var key = panel ? panel.getAttribute('data-folio-tabpanel') : null;
        if (!box || key === null) { return null; }
        var tabs = box.querySelectorAll('[data-folio-tab]');
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].getAttribute('data-folio-tab') === key) { return tabs[i]; }
        }
        return null;
    }

    // The browser shows its message at the first invalid field, so that field must be visible: its tab
    // opens, and a field in the side panel's closed phone sheet (an empty required address) opens the sheet.
    function onInvalidInTab(e) {
        var target = e.target;
        var side = target && target.closest ? target.closest('[data-folio-side-ready]') : null;
        var form = formOf(target);
        var earlier = form && form.querySelector ? form.querySelector(':invalid') : null; // a field in the form itself comes first
        if (side && !earlier && !sidePanelOpen() && window.getComputedStyle && window.getComputedStyle(side).display === 'none') {
            openSidePanel();
            return;
        }
        var panel = target && target.closest ? target.closest('[data-folio-tabpanel]') : null;
        if (!panel || !panel.hidden) { return; }
        var tab = tabFor(panel);
        if (tab) { tab.click(); }
    }

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

    // ---- Editor shortcuts: ⌘S / Ctrl+S saves (the Save draft or Save button, also while typing; the
    // browser's save dialog never opens in the editor), ⌘↵ / Ctrl+Enter publishes (only while Publish is
    // there and enabled), Esc closes the side panel sheet (phone width).
    function initEditorShortcuts() {
        var save = document.querySelector('[data-folio-save]');
        if (save) {
            addShortcut({ key: 's', mod: true, typing: true, label: folioT('shortcut_save'), run: function () {
                submitWith(document.querySelector('[data-folio-save]'));
                return true;
            } });
        }
        if (document.querySelector('[data-folio-publish]')) {
            addShortcut({ key: 'Enter', mod: true, typing: true, label: folioT('shortcut_publish'), run: function () {
                return submitWith(document.querySelector('[data-folio-publish]'));
            } });
        }
        if (sidePanel && document.querySelector('[data-folio-side-toggle]')) {
            addShortcut({ key: 'Escape', typing: true, label: folioT('shortcut_close_side'), hint: 'Esc', run: function () {
                return closeSidePanel(true);
            } });
        }
    }

    function initEditor() {
        // The buttons' shortcut hints say Ctrl+ where there is no ⌘.
        if (!isMac()) {
            document.querySelectorAll('[aria-keyshortcuts][title]').forEach(function (el) {
                el.setAttribute('title', el.getAttribute('title').replace(/⌘/g, 'Ctrl+'));
            });
        }
        document.addEventListener('input', onRecordEdit);
        document.addEventListener('change', onRecordEdit);
        document.addEventListener('htmx:beforeRequest', onAutosaveStart);
        initNeedsChange(document);
        document.addEventListener('input', onNeedsChangeEdit);
        document.addEventListener('change', onNeedsChangeEdit);
        document.addEventListener('reset', onNeedsChangeReset);
        initSplitButtons(document);
        document.addEventListener('keydown', onSplitButtonEvent);
        document.addEventListener('click', onSplitButtonEvent);
        document.addEventListener('input', onSplitButtonEvent);
        document.addEventListener('change', onSplitButtonEvent);
        document.addEventListener('invalid', onInvalidInTab, true);
        document.addEventListener('click', onTabLink);
        initSidePanel();
    }

    // ---- Settings → Translations: "Save all filled" -------------------------
    // Each row is its own form, so saving one row reloads the page over every other filled one.
    // The save-all form posts every row whose text differs from the saved one (keys[] + texts[]),
    // and the page asks before leaving while such rows are unsaved. A row the server refused
    // carries data-unsaved: its text is the posted one, not the saved one.

    // Settings → Redirects: the target field's page picker. As the user types, the field's datalist
    // gets the matching public pages from {prefix}/redirects/_targets ({items: [{value, label, type, url}]}):
    // the value "type:id" goes into the field, a URL or path typed by hand stays as it is.
    function initRedirectTargets() {
        var input = document.querySelector('[data-redirect-targets]');
        var list = input && input.list;
        if (!list || !window.fetch) { return; }
        var seq = 0;
        var timer = null;
        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q === '' || q.charAt(0) === '/' || /^https?:/i.test(q)) { list.textContent = ''; return; }
            timer = setTimeout(function () {
                var mine = ++seq;
                fetch(input.getAttribute('data-redirect-targets') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (res) { return res.ok ? res.json() : { items: [] }; })
                    .then(function (data) {
                        if (mine !== seq) { return; }
                        list.textContent = '';
                        (data.items || []).forEach(function (item) {
                            var option = document.createElement('option');
                            option.value = item.value;
                            option.label = item.label + ' (' + item.type + ') ' + item.url;
                            list.appendChild(option);
                        });
                    })
                    .catch(function () {});
            }, 200);
        });
    }

    // Address fields (the wrapper has data-folio-address="segment" or "path"): what the editor types becomes
    // lowercase a-z, digits and single hyphens ("Über uns" → "ueber-uns"), with single slashes between parts
    // for "path". Done when the value changes and again before the form is sent; the server refuses anything else.
    // Slug::clean() in preflow/validation does the same for machine translations.
    var ADDRESS_LETTERS = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss', 'æ': 'ae', 'œ': 'oe', 'ø': 'o', 'đ': 'd', 'ł': 'l', 'þ': 'th' };

    function addressPart(text, path) {
        var plain = String(text).normalize('NFC').toLowerCase()
            .replace(/[äöüßæœøđłþ]/g, function (ch) { return ADDRESS_LETTERS[ch]; })
            .normalize('NFD').replace(/[̀-ͯ]/g, '');
        var word = function (part) { return part.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); };
        if (!path) { return word(plain); }
        return plain.split('/').map(word).filter(function (part) { return part !== ''; }).join('/');
    }

    function cleanAddressInput(input) {
        var wrap = input && input.closest ? input.closest('[data-folio-address]') : null;
        var text = input && (input.tagName === 'TEXTAREA' || (input.tagName === 'INPUT' && (!input.type || input.type === 'text')));
        if (!wrap || !text) { return; }
        var clean = addressPart(input.value, wrap.getAttribute('data-folio-address') === 'path');
        // Nothing left (another script, only punctuation): kept as typed, so the server's message explains it.
        if (clean !== '' && clean !== input.value) { input.value = clean; }
    }

    function onAddressChange(e) {
        cleanAddressInput(e.target);
    }

    function onAddressSubmit(e) {
        var form = e.target;
        if (!form || !form.querySelectorAll) { return; }
        // form.elements: the address fields in the editor's side panel (form="…") too.
        var controls = form.elements ? Array.prototype.slice.call(form.elements)
            : Array.prototype.slice.call(form.querySelectorAll('[data-folio-address] input, [data-folio-address] textarea'));
        controls.forEach(cleanAddressInput);
    }

    function initTranslationsSaveAll() {
        var saveAll = document.querySelector('[data-translations-save-all]');
        var table = document.querySelector('.folio-translations');
        if (!saveAll || !table) { return; }
        var submitted = null;
        var changed = function () {
            return Array.prototype.filter.call(table.querySelectorAll('input[data-ai-path], textarea[data-ai-path]'), function (el) {
                return el.value !== el.defaultValue || el.hasAttribute('data-unsaved');
            });
        };
        var refresh = function () { saveAll.hidden = changed().length === 0; };
        table.addEventListener('input', refresh);
        table.addEventListener('change', refresh);
        saveAll.addEventListener('submit', function () {
            saveAll.querySelectorAll('[data-save-all-row]').forEach(function (el) { el.remove(); });
            changed().forEach(function (el) {
                [['keys[]', el.getAttribute('data-ai-path')], ['texts[]', el.value]].forEach(function (pair) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = pair[0];
                    input.value = pair[1];
                    input.setAttribute('data-save-all-row', '');
                    saveAll.appendChild(input);
                });
            });
        });
        // As initUnsavedGuard: a cancelled data-confirm is defaultPrevented and keeps the guard.
        document.addEventListener('submit', function (e) {
            if (!e.defaultPrevented) { submitted = e.target; }
        });
        window.addEventListener('beforeunload', function (e) {
            if (submitted === saveAll) { return; }
            var unsaved = changed().filter(function (el) { return el.form !== submitted; });
            if (unsaved.length === 0) { return; }
            e.preventDefault();
            e.returnValue = '';
        });
    }

    // ---- Stepped runs: a step that did not come back ---------------------------
    // The AI run's progress (#folio-ai-progress) and any other self-polling progress partial marked
    // data-folio-run-progress (the SEO audit's). htmx swaps nothing for an error status, a dropped
    // connection or a timeout, so the progress would stop polling without a word. Show why, with
    // Continue (the AI run's start, the audit's run: both take the run up again).

    function onRunStepFailed(e) {
        var elt = e.detail && e.detail.elt;
        if (!elt || (elt.id !== 'folio-ai-progress' && !(elt.hasAttribute && elt.hasAttribute('data-folio-run-progress')))) { return; }
        var stalled = elt.querySelector('[data-folio-ai-stalled], [data-folio-run-stalled]');
        if (stalled) { stalled.hidden = false; }
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
        initNeedsChange(root);
        initSplitButtons(root);
    }

    // Share-preview link: copy the URL (select it when the clipboard is unavailable).
    function onCopyClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-folio-copy]') : null;
        if (!btn) { return; }
        var box = btn.closest('.folio-share');
        var input = box ? box.querySelector('.folio-share-url') : null;
        if (!input) { return; }
        var done = function () { btn.textContent = folioT('copied'); };
        var fallback = function () { input.focus(); input.select(); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done, fallback);
        } else {
            fallback();
        }
    }

    // Copy a value (asset library swatches: hex or rgb): <button data-copy="…">.
    function onDataCopyClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
        if (!btn || !navigator.clipboard || !navigator.clipboard.writeText) { return; }
        navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
            btn.classList.add('is-copied');
            setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
        }, function () {});
    }

    // ---- Busy buttons ---------------------------------------------------------
    // A submit button with data-busy-label shows that its request runs: is-busy (spinner), aria-busy,
    // disabled and the label as its text. A plain submit keeps it until the page unloads or comes back
    // from the back/forward cache (a slow Save or Publish must never be posted twice). An htmx request
    // keeps it until that request ends, fails or is aborted, its markup is swapped away, or after
    // BUSY_MAX_MS at the latest (a request that never reports back must not leave the button dead).
    // Each htmx request carries its own token (its xhr): the end, abort or timer of an older request
    // (hx-sync="replace") never clears the busy state of a newer one. Clearing puts the button's own
    // nodes back (its icon too); after a failure the button gets the keyboard focus back.

    var BUSY_MAX_MS = 45000;
    var busyButtons = [];
    var pendingPlainSubmit = false;

    // request: null for a plain submit (no timer); the request's token for htmx.
    function setBusy(btn, request) {
        if (!btn || !btn.getAttribute) { return; }
        var label = btn.getAttribute('data-busy-label');
        if (!label) { return; }
        var state = btn._folioBusy;
        if (state) {
            // Already busy: a newer htmx request from the same button takes over the token and the timer.
            if (request && state.request) {
                clearTimeout(state.timer);
                state.request = request;
                state.timer = busyTimer(btn, state);
            }
            return;
        }
        state = { nodes: Array.prototype.slice.call(btn.childNodes), disabled: !!btn.disabled, request: request || null, timer: null };
        if (request) { state.timer = busyTimer(btn, state); }
        btn._folioBusy = state;
        btn.classList.add('is-busy');
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        btn.textContent = label;
        busyButtons.push(btn);
    }

    function busyTimer(btn, state) {
        var request = state.request;
        return setTimeout(function () {
            if (btn._folioBusy === state && state.request === request) { clearBusy(btn, true); }
        }, BUSY_MAX_MS);
    }

    function clearBusy(btn, failed) {
        if (!btn || !btn._folioBusy) { return; }
        var saved = btn._folioBusy;
        btn._folioBusy = null;
        clearTimeout(saved.timer);
        btn.classList.remove('is-busy');
        btn.removeAttribute('aria-busy');
        btn.disabled = saved.disabled;
        btn.textContent = '';
        saved.nodes.forEach(function (node) { btn.appendChild(node); });
        busyButtons = busyButtons.filter(function (b) { return b !== btn; });
        // The disabled button lost the focus; after a failure the user tries again from where they were.
        var active = document.activeElement;
        if (failed && btn.isConnected && !btn.disabled && (!active || active === document.body || active === btn)) {
            btn.focus();
        }
    }

    function clearAllBusy() { busyButtons.slice().forEach(function (btn) { clearBusy(btn); }); }

    // A plain submit: the button is disabled only after the browser has collected the form's values,
    // or a disabled submitter (Publish: _action=publish) would be left out of them.
    function onBusySubmit(e) {
        var btn = e.submitter;
        if (!btn || !btn.hasAttribute || !btn.hasAttribute('data-busy-label')) { return; }
        pendingPlainSubmit = true;
        setTimeout(function () { pendingPlainSubmit = false; if (!e.defaultPrevented) { setBusy(btn, null); } }, 0);
    }

    // A leave prompt (an unsaved-changes guard cancelled beforeunload) can be answered "Stay": the
    // page lives on and the plain submit's button would stay busy. The prompt blocks the page while
    // it is open, so a second later we are either gone or staying. Without a prompt nothing is
    // scheduled, so a slow Save or Publish is never released (and never posted twice).
    function onBusyBeforeUnload(e) {
        if (!e.defaultPrevented || (busyButtons.length === 0 && !pendingPlainSubmit)) { return; }
        setTimeout(function () {
            // Only plain submits: an htmx request on the page still ends (or times out) on its own.
            busyButtons.slice().forEach(function (btn) { if (btn._folioBusy && !btn._folioBusy.request) { clearBusy(btn); } });
        }, 1000);
    }

    // The button an htmx request belongs to: the element itself, or the submitter of the form it sends.
    function busyButtonOf(detail) {
        var elt = detail && detail.elt;
        if (!elt || !elt.hasAttribute) { return null; }
        if (elt.hasAttribute('data-busy-label')) { return elt; }
        var ev = detail.requestConfig && detail.requestConfig.triggeringEvent;
        var sub = ev && ev.submitter;
        return sub && sub.hasAttribute && sub.hasAttribute('data-busy-label') ? sub : null;
    }

    function onBusyRequest(e) {
        if (e.defaultPrevented) { return; }
        var btn = busyButtonOf(e.detail);
        if (!btn) { return; }
        e.detail.elt._folioBusyButton = btn;
        setBusy(btn, e.detail.xhr || {});
    }

    function onBusyDone(e) {
        var elt = e.detail && e.detail.elt;
        if (!elt) { return; }
        var btn = elt._folioBusyButton || busyButtonOf(e.detail);
        var state = btn && btn._folioBusy;
        if (!state || !state.request) { return; }
        // The end of an older request, replaced by a newer one from the same button: not ours to clear.
        if (e.detail.xhr && state.request !== e.detail.xhr) { return; }
        clearBusy(btn, e.type !== 'htmx:afterRequest');
        elt._folioBusyButton = null;
    }

    // A swap that replaced a busy button: forget it (its new markup is not busy).
    function onBusySwap() {
        busyButtons.slice().forEach(function (btn) { if (!btn.isConnected) { clearBusy(btn); } });
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
        setSaveState(state, 'conflict', xhr && xhr.status === 403
            ? folioT('not_saved_forbidden')
            : folioT('not_saved_offline'));
    }

    // A short message in the toast region; the server sends it as HX-Trigger "folio:error" (e.g. a 403).
    // ok: a confirmation ("Link copied", the check glyph) instead of an error.
    function showToast(message, ok) {
        var region = document.querySelector('[data-folio-toasts]');
        if (!region || !message) { return; }
        var toast = document.createElement('div');
        toast.className = ok ? 'folio-toast' : 'folio-toast is-error';
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
        btn.textContent = folioT('selected');
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

    // Relation search (large targets). The editor renders [data-folio-relation-remote] with the linked
    // records as chips + hidden inputs and a [data-folio-relation-search] box; the list's relation
    // filter is a [data-folio-list-relation] text input. Both query {prefix}/_relation/{type}
    // ({items: [{id, label}], more}) as the user types. Delegated, so swapped partials keep working.
    var relationSeq = 0;

    function relationUrl(box, q, page) {
        var base = box.getAttribute('data-folio-relation-url') || '';
        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q) + '&page=' + page;
    }

    // The filter input submits an id, not the typed text: its name moves to a hidden input.
    function prepareRelationBox(box) {
        if (!box.hasAttribute('data-folio-list-relation') || box._folioRelationHidden) { return; }
        var name = box.getAttribute('name');
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        if (name) { hidden.name = name; box.removeAttribute('name'); }
        hidden.value = box.value;
        box.parentNode.insertBefore(hidden, box.nextSibling);
        box._folioRelationHidden = hidden;
    }

    function relationResults(box) {
        if (box._folioRelationList) { return box._folioRelationList; }
        var list = box.parentNode.querySelector('[data-folio-relation-results]');
        if (!list) {
            list = document.createElement('ul');
            list.className = 'folio-relation-results';
            list.setAttribute('data-folio-relation-results', '');
            list.hidden = true;
            var anchor = box._folioRelationHidden || box;
            anchor.parentNode.insertBefore(list, anchor.nextSibling);
        }
        box._folioRelationList = list;
        return list;
    }

    function relationFetch(box, q, page, append) {
        var seq = ++relationSeq;
        box._folioRelationSeq = seq;
        fetch(relationUrl(box, q, page), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('relation search failed')); })
            .then(function (data) {
                if (box._folioRelationSeq !== seq) { return; }
                var list = relationResults(box);
                var more = list.querySelector('[data-folio-relation-more]');
                if (more) { more.parentNode.removeChild(more); }
                if (!append) { list.innerHTML = ''; }
                (data.items || []).forEach(function (item) {
                    var li = document.createElement('li');
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'folio-relation-result';
                    b.setAttribute('data-folio-relation-pick', '');
                    b.setAttribute('data-id', item.id);
                    b.textContent = item.label;
                    li.appendChild(b);
                    list.appendChild(li);
                });
                if (!append && !(data.items || []).length) {
                    var empty = document.createElement('li');
                    empty.className = 'folio-relation-empty';
                    empty.textContent = folioT('no_matches');
                    list.appendChild(empty);
                }
                if (data.more) {
                    var li2 = document.createElement('li');
                    var mb = document.createElement('button');
                    mb.type = 'button';
                    mb.className = 'folio-relation-result folio-relation-more';
                    mb.setAttribute('data-folio-relation-more', '');
                    mb.setAttribute('data-page', String(page + 1));
                    mb.textContent = folioT('more');
                    li2.appendChild(mb);
                    list.appendChild(li2);
                }
                list.hidden = false;
            })
            .catch(function () {});
    }

    function onRelationSearchInput(e) {
        var box = e.target.closest ? e.target.closest('[data-folio-relation-search], [data-folio-list-relation]') : null;
        if (!box) { return; }
        prepareRelationBox(box);
        // The filter submits what is typed (a record id works) until a result is picked; typing over
        // a picked label drops the picked id.
        if (box._folioRelationHidden && box.value !== box._folioRelationPicked) {
            box._folioRelationPicked = undefined;
            box._folioRelationHidden.value = box.value.trim();
        }
        clearTimeout(box._folioRelationTimer);
        box._folioRelationTimer = setTimeout(function () { relationFetch(box, box.value.trim(), 1, false); }, 300);
    }

    function onRelationFocusIn(e) {
        var box = e.target.closest ? e.target.closest('[data-folio-list-relation]') : null;
        if (box) { prepareRelationBox(box); }
    }

    function onRelationKeydown(e) {
        var box = e.target.closest ? e.target.closest('[data-folio-relation-search], [data-folio-list-relation]') : null;
        if (!box) { return; }
        if (e.key === 'Enter') { e.preventDefault(); }
        if (e.key === 'Escape') { relationResults(box).hidden = true; }
    }

    function addRelationItem(field, id, text) {
        var name = field.getAttribute('data-folio-relation-name') || '';
        var holder = field.querySelector('[data-folio-relation-selected]');
        if (!holder) { return; }
        if (!field.hasAttribute('data-folio-relation-multiple')) {
            holder.innerHTML = '';
        } else {
            var dup = Array.prototype.some.call(holder.querySelectorAll('input[type="hidden"]'), function (i) { return i.value === id; });
            if (dup) { return; }
        }
        var chip = document.createElement('span');
        chip.className = 'folio-chip';
        chip.setAttribute('data-folio-relation-item', '');
        var label = document.createElement('span');
        label.className = 'folio-chip-label';
        label.textContent = text;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = id;
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'folio-chip-remove';
        remove.setAttribute('data-folio-relation-remove', '');
        remove.setAttribute('aria-label', folioT('remove_named', { label: text }));
        remove.textContent = '×';
        chip.appendChild(label);
        chip.appendChild(input);
        chip.appendChild(remove);
        holder.appendChild(chip);
        // Code-made changes fire no events: autosave, live preview and the unsaved-changes guard listen for change.
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function onRelationClick(e) {
        var t = e.target.closest ? e.target : null;
        if (!t) { return; }
        var remove = t.closest('[data-folio-relation-remove]');
        if (remove) {
            var field = remove.closest('[data-folio-relation-remote]');
            var chip = remove.closest('[data-folio-relation-item]');
            var chipHolder = chip ? chip.parentNode : null;
            if (chip && chip.parentNode) { chip.parentNode.removeChild(chip); }
            if (field && !field.hasAttribute('data-folio-relation-multiple')) {
                var holder = field.querySelector('[data-folio-relation-selected]');
                if (holder && !holder.querySelector('input')) {
                    var blank = document.createElement('input');
                    blank.type = 'hidden';
                    blank.name = field.getAttribute('data-folio-relation-name') || '';
                    blank.value = '';
                    holder.appendChild(blank);
                }
            }
            if (chipHolder) { chipHolder.dispatchEvent(new Event('change', { bubbles: true })); }
            return;
        }
        var more = t.closest('[data-folio-relation-more]');
        if (more) {
            var scope = more.closest('[data-folio-relation-remote]') || more.closest('.folio-filter-field') || more.parentNode.parentNode.parentNode;
            var moreBox = scope.querySelector('[data-folio-relation-search], [data-folio-list-relation]');
            if (moreBox) {
                relationFetch(moreBox, moreBox.value.trim(), parseInt(more.getAttribute('data-page') || '2', 10), true);
            }
            return;
        }
        var pick = t.closest('[data-folio-relation-pick]');
        if (pick) {
            var list = pick.closest('[data-folio-relation-results]');
            var id = pick.getAttribute('data-id') || '';
            var text = pick.textContent || id;
            var remote = pick.closest('[data-folio-relation-remote]');
            if (remote) {
                addRelationItem(remote, id, text);
                var search = remote.querySelector('[data-folio-relation-search]');
                if (search) { search.value = ''; }
            } else {
                var fb = list.parentNode.querySelector('[data-folio-list-relation]');
                if (fb && fb._folioRelationHidden) {
                    fb._folioRelationHidden.value = id;
                    fb.value = text;
                    fb._folioRelationPicked = text;
                }
            }
            list.hidden = true;
            list.innerHTML = '';
            return;
        }
        document.querySelectorAll('[data-folio-relation-results]').forEach(function (l) {
            if (!l.parentNode.contains(t)) { l.hidden = true; }
        });
    }

    // Admin lists: the search narrows as you type. The results region is fetched with ?_partial=1
    // and swapped; replaceState keeps the view in the URL, so back from a record returns to it.
    // Filters (the "+ Filter" menu, chips), sorting and paging stay plain links and GET submits.
    function listParams(form) {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (value, name) {
            if (typeof value === 'string' && value !== '') { params.append(name, value); }
        });
        return params;
    }

    function initList(form) {
        if (form.hasAttribute('data-folio-list-ready')) { return; }
        form.setAttribute('data-folio-list-ready', '');
        var input = form.querySelector('[data-folio-list-search]');
        var results = document.querySelector('[data-folio-list-results]');
        var submit = form.querySelector('[data-folio-list-submit]');
        var action = form.getAttribute('action') || window.location.pathname;
        var timer = null;
        var pending = null;

        // A plain submit (the filter menu's Apply) leaves empty fields out of the URL.
        form.addEventListener('submit', function () {
            form.querySelectorAll('input[name], select[name]').forEach(function (el) {
                if (el.value === '') { el.disabled = true; }
            });
        });
        window.addEventListener('pageshow', function () {
            form.querySelectorAll('[disabled]').forEach(function (el) { el.disabled = false; });
        });

        if (!input || !results || !window.fetch || !window.AbortController) { return; }
        if (submit) { submit.hidden = true; }

        function run() {
            clearTimeout(timer);
            var params = listParams(form);
            var query = params.toString();
            var url = action + (query ? '?' + query : '');
            params.set('_partial', '1');
            if (pending) { pending.abort(); }
            pending = new AbortController();
            fetch(action + '?' + params.toString(), { credentials: 'same-origin', signal: pending.signal })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.text();
                })
                .then(function (html) {
                    results.innerHTML = html;
                    initBulk(results);
                    initRowMenus(results);
                    keepStatusLinks(form, params.get('q') || '');
                    history.replaceState(history.state, '', url);
                })
                .catch(function (e) {
                    if (e.name !== 'AbortError') { window.location.href = url; }
                });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 300);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); run(); }
        });
    }

    // The status links (All · Published · …) sit outside the swapped region: they take the new search along.
    function keepStatusLinks(form, q) {
        form.querySelectorAll('[data-folio-list-status] a[href]').forEach(function (a) {
            var href = a.getAttribute('href');
            var at = href.indexOf('?');
            var params = new URLSearchParams(at < 0 ? '' : href.slice(at + 1));
            if (q) { params.set('q', q); } else { params.delete('q'); }
            params.delete('page');
            var query = params.toString();
            a.setAttribute('href', (at < 0 ? href : href.slice(0, at)) + (query ? '?' + query : ''));
        });
    }

    // Bulk bar (<form data-folio-bulk>, under the table): the rows' tick boxes
    // ([data-folio-bulk-item], form="{bar id}") show it with "N selected"; [data-folio-bulk-all]
    // ticks the page. Without JS the bar simply stays visible above the table. The boxes live in the swapped
    // region, so a new search, filter or page starts with nothing ticked.
    function bulkItems(bar) {
        return Array.prototype.filter.call(document.querySelectorAll('[data-folio-bulk-item]'), function (c) {
            return c.getAttribute('form') === bar.id;
        });
    }

    function bulkUpdate(bar) {
        var items = bulkItems(bar);
        var n = items.filter(function (c) { return c.checked; }).length;
        bar.hidden = n === 0;
        var count = bar.querySelector('[data-folio-bulk-count]');
        if (count) { count.textContent = folioT('selected_count', { count: n }); }
        var all = document.querySelector('[data-folio-bulk-all]');
        if (all) {
            all.checked = n > 0 && n === items.length;
            all.indeterminate = n > 0 && n < items.length;
        }
    }

    function initBulk(scope) {
        scope.querySelectorAll('[data-folio-bulk]').forEach(function (bar) {
            var all = document.querySelector('[data-folio-bulk-all]');
            if (all) { all.hidden = false; }
            // With JS the bar floats at the bottom of the window while rows are ticked (admin.css).
            bar.classList.add('is-floating');
            bar.querySelectorAll('[data-folio-export]').forEach(function (b) { b.hidden = false; });
            bulkUpdate(bar);
        });
    }

    function onBulkChange(e) {
        var t = e.target;
        if (!t || !t.matches) { return; }
        var bar = document.querySelector('[data-folio-bulk]');
        if (!bar) { return; }
        if (t.matches('[data-folio-bulk-all]')) {
            bulkItems(bar).forEach(function (c) { c.checked = t.checked; });
        } else if (!t.matches('[data-folio-bulk-item]')) {
            return;
        }
        bulkUpdate(bar);
    }

    // Export in the bulk bar: a GET download of the ticked rows (ids[]) in the list's current view.
    function onBulkExport(e) {
        var all = e.target && e.target.closest ? e.target.closest('[data-folio-export-all]') : null;
        if (all) {
            // The header link: the view as the live search left it in the address bar.
            all.href = all.getAttribute('data-folio-export-all') + window.location.search;
            return;
        }
        var t = e.target && e.target.closest ? e.target.closest('[data-folio-export]') : null;
        var bar = document.querySelector('[data-folio-bulk]');
        if (!t || !bar) { return; }
        var ids = bulkItems(bar).filter(function (c) { return c.checked; }).map(function (c) {
            return 'ids%5B%5D=' + encodeURIComponent(c.value);
        });
        if (ids.length === 0) { return; }
        var url = t.getAttribute('data-folio-export');
        window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + ids.join('&');
    }

    // A confirmation checkbox (data-folio-enables="<button id>") enables its button only while ticked,
    // e.g. the AI run's "go live without review" (the server asks for the box as well).
    function onEnablesChange(e) {
        var box = e.target;
        if (!box || !box.matches || !box.matches('input[type="checkbox"][data-folio-enables]')) { return; }
        var target = document.getElementById(box.getAttribute('data-folio-enables'));
        if (target) { target.disabled = !box.checked; }
    }

    // "Clear" is a reset button: it unticks the rows (form-associated) with or without JS.
    function onBulkReset(e) {
        var bar = e.target;
        if (!bar || !bar.hasAttribute || !bar.hasAttribute('data-folio-bulk')) { return; }
        setTimeout(function () { bulkUpdate(bar); }, 0);
    }

    // ---- List rows: the "…" menu ------------------------------------------------
    // Each row ends in <details class="folio-row-menu" data-folio-row-menu> with a role="menu" panel.
    // Without this script it opens in place. With it (data-folio-row-menu-ready) the panel floats next to
    // its button (position: fixed, so the table's scroll box does not cut it off), one menu is open at a
    // time, and the keyboard works as for a menu button: Enter or Space (the summary's click) or ArrowDown
    // opens it on the first item, ArrowUp on the last; ArrowUp / ArrowDown / Home / End move between the
    // items; Escape closes it and returns the focus to its button; Tab or a click elsewhere closes it.
    function rowMenuItems(menu) {
        return Array.prototype.filter.call(menu.querySelectorAll('[role="menuitem"]'), function (item) {
            return !item.hidden && !item.disabled;
        });
    }

    function openRowMenus() {
        return Array.prototype.filter.call(document.querySelectorAll('[data-folio-row-menu]'), function (m) { return !!m.open; });
    }

    function placeRowMenu(menu) {
        var summary = menu.querySelector('summary');
        var panel = menu.querySelector('[role="menu"]');
        if (!summary || !panel || typeof summary.getBoundingClientRect !== 'function') { return; }
        var at = summary.getBoundingClientRect();
        var width = panel.offsetWidth || 0;
        var height = panel.offsetHeight || 0;
        var vw = window.innerWidth || document.documentElement.clientWidth || 0;
        var vh = window.innerHeight || document.documentElement.clientHeight || 0;
        var x = Math.max(8, Math.min(at.right - width, vw - width - 8));
        var y = at.bottom + 4;
        if (vh && y + height > vh - 8 && at.top - height - 4 >= 8) { y = at.top - height - 4; }
        if (panel.style) {
            panel.style.left = Math.round(x) + 'px';
            panel.style.top = Math.round(y) + 'px';
        }
    }

    function openRowMenu(menu, last) {
        openRowMenus().forEach(function (m) { if (m !== menu) { closeRowMenu(m, false); } });
        menu.open = true;
        var summary = menu.querySelector('summary');
        if (summary) { summary.setAttribute('aria-expanded', 'true'); }
        placeRowMenu(menu);
        var items = rowMenuItems(menu);
        if (items.length) { items[last ? items.length - 1 : 0].focus(); }
    }

    function closeRowMenu(menu, refocus) {
        if (!menu.open) { return; }
        menu.open = false;
        var summary = menu.querySelector('summary');
        if (summary) {
            summary.setAttribute('aria-expanded', 'false');
            if (refocus) { summary.focus(); }
        }
    }

    function initRowMenus(scope) {
        scope.querySelectorAll('[data-folio-row-menu]').forEach(function (menu) {
            menu.setAttribute('data-folio-row-menu-ready', '');
            var summary = menu.querySelector('summary');
            if (summary) { summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false'); }
        });
        // Copying a preview link needs a POST and the clipboard: the item shows only when both work.
        var canCopy = typeof window.fetch === 'function' && typeof navigator !== 'undefined' && !!(navigator.clipboard && navigator.clipboard.writeText);
        scope.querySelectorAll('[data-folio-copy-link]').forEach(function (b) { b.hidden = !canCopy; });
    }

    function onRowMenuClick(e) {
        var t = e.target;
        var summary = t && t.closest ? t.closest('summary') : null;
        var menu = summary && summary.parentNode && summary.parentNode.hasAttribute && summary.parentNode.hasAttribute('data-folio-row-menu') ? summary.parentNode : null;
        if (menu) {
            e.preventDefault(); // the script opens and closes it (one at a time, placed, focus moved)
            if (menu.open) { closeRowMenu(menu, false); } else { openRowMenu(menu, false); }
            return;
        }
        var inside = t && t.closest ? t.closest('[data-folio-row-menu]') : null;
        openRowMenus().forEach(function (m) { if (m !== inside) { closeRowMenu(m, false); } });
    }

    function onRowMenuKeydown(e) {
        var t = e.target;
        var menu = t && t.closest ? t.closest('[data-folio-row-menu]') : null;
        if (!menu) { return; }
        var onSummary = t.matches && t.matches('summary');
        if (!menu.open) {
            if (onSummary && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); openRowMenu(menu, e.key === 'ArrowUp'); }
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            closeRowMenu(menu, true);
            return;
        }
        if (e.key === 'Tab') { closeRowMenu(menu, false); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') { return; }
        e.preventDefault();
        var items = rowMenuItems(menu);
        if (!items.length) { return; }
        var at = items.indexOf(t);
        var next = e.key === 'Home' ? 0
            : e.key === 'End' ? items.length - 1
            : e.key === 'ArrowDown' ? (at + 1) % items.length
            : (at <= 0 ? items.length - 1 : at - 1);
        items[next].focus();
    }

    function onRowMenuScroll() {
        openRowMenus().forEach(placeRowMenu);
    }

    // "Copy preview link" in a row menu: the share route answers JSON ({url, message}) for it; the link
    // goes to the clipboard with a "Link copied" toast, or the toast says why there is none.
    function onCopyLinkClick(e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-folio-copy-link]') : null;
        if (!btn) { return; }
        e.preventDefault();
        var menu = btn.closest('[data-folio-row-menu]');
        if (menu) { closeRowMenu(menu, true); }
        var body = new URLSearchParams();
        var token = document.querySelector('input[name="_csrf_token"]');
        if (token) { body.append('_csrf_token', token.value); }
        fetch(btn.getAttribute('data-folio-copy-link'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.url) { showToast(data && data.message ? data.message : folioT('link_not_copied')); return null; }
                return navigator.clipboard.writeText(data.url).then(function () { showToast(folioT('link_copied'), true); });
            })
            .catch(function () { showToast(folioT('link_not_copied')); });
    }

    // ---- List toolbar: the filter popover and the row height ------------------------
    // "+ Filter" is a <details> (it opens without script); Escape inside it or a click outside closes it,
    // and Escape returns the focus to its button.
    function onFilterMenuKeydown(e) {
        if (e.key !== 'Escape') { return; }
        var menu = e.target && e.target.closest ? e.target.closest('[data-folio-filter-menu]') : null;
        if (!menu || !menu.open) { return; }
        e.preventDefault();
        menu.open = false;
        var summary = menu.querySelector('summary');
        if (summary) { summary.focus(); }
    }

    function onFilterMenuClick(e) {
        document.querySelectorAll('[data-folio-filter-menu]').forEach(function (menu) {
            if (menu.open && !(e.target && e.target.closest && e.target.closest('[data-folio-filter-menu]') === menu)) { menu.open = false; }
        });
    }

    // Compact (44px rows) or comfortable (60px, the default), kept in localStorage 'folio.density'; the
    // layout's head script puts it on <html> as data-folio-density before the first paint.
    function currentDensity() {
        return document.documentElement.getAttribute('data-folio-density') === 'compact' ? 'compact' : 'comfortable';
    }

    function markDensity() {
        var d = currentDensity();
        document.querySelectorAll('[data-folio-density-choice]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-folio-density-choice') === d ? 'true' : 'false');
        });
    }

    function initDensity() {
        var groups = document.querySelectorAll('[data-folio-density]');
        if (!groups.length) { return; }
        groups.forEach(function (g) { g.hidden = false; });
        markDensity();
        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('[data-folio-density-choice]') : null;
            if (!b) { return; }
            var d = b.getAttribute('data-folio-density-choice') === 'compact' ? 'compact' : 'comfortable';
            document.documentElement.setAttribute('data-folio-density', d);
            try { window.localStorage.setItem('folio.density', d); } catch (err) { /* this page only */ }
            markDensity();
        });
    }

    // ---- Keyboard shortcuts -----------------------------------------------------
    // One registry: a screen adds its keys with Folio.shortcuts.add({key, label, run, mod, typing, hint}).
    // key is e.key ("j", "/", "?"); mod: true for ⌘ (Ctrl elsewhere) + key; run(e) returns false when it
    // did nothing (the key then keeps its usual effect). Keys never fire while the focus is in an input,
    // textarea, select or editable text unless the entry says typing: true (the editor's ⌘S, ⌘↵ and Esc),
    // never while a dialog is open, and plain keys never with Ctrl, Alt or ⌘ held. "?" lists them all.
    var shortcutList = [];
    var shortcutsReady = false;
    var shortcutsDialog = null;

    // Inputs that take no text: a key on them is no typing (a row's tick box keeps j / k / x working).
    var NOT_TEXT = ['checkbox', 'radio', 'button', 'submit', 'reset', 'image', 'file', 'color', 'range'];

    function isTyping(el) {
        if (!el || !el.tagName) { return false; }
        var tag = el.tagName.toUpperCase();
        if (tag === 'INPUT') {
            var kind = String(el.getAttribute('type') || el.type || 'text').toLowerCase();
            return NOT_TEXT.indexOf(kind) === -1;
        }
        if (tag === 'TEXTAREA' || tag === 'SELECT') { return true; }
        if (el.isContentEditable) { return true; }
        var editable = el.getAttribute ? el.getAttribute('contenteditable') : null;
        return editable !== null && editable !== 'false';
    }

    function addShortcut(spec) {
        if (!spec || typeof spec.key !== 'string' || typeof spec.run !== 'function') { return; }
        shortcutList = shortcutList.filter(function (s) { return !(s.key === spec.key && !!s.mod === !!spec.mod); });
        shortcutList.push(spec);
    }

    // ⌘ on a Mac (and where the platform is unknown), Ctrl+ elsewhere.
    function isMac() {
        var nav = window.navigator;
        if (!nav) { return true; }
        var platform = (nav.userAgentData && nav.userAgentData.platform) || nav.platform || '';
        return platform === '' || /mac|iphone|ipad|ipod/i.test(platform);
    }

    function modHint(key) {
        return (isMac() ? '⌘' : 'Ctrl+') + key;
    }

    function shortcutHint(spec) {
        if (spec.hint) { return spec.hint; }
        var key = spec.key === 'Enter' ? '↵' : spec.key.length === 1 ? spec.key.toUpperCase() : spec.key;
        return spec.mod ? modHint(key) : key;
    }

    function showShortcuts() {
        if (!shortcutsDialog) {
            shortcutsDialog = document.createElement('dialog');
            shortcutsDialog.className = 'folio-shortcuts';
            shortcutsDialog.setAttribute('aria-labelledby', 'folio-shortcuts-title');
            var head = document.createElement('div');
            head.className = 'folio-shortcuts-head';
            var title = document.createElement('h2');
            title.id = 'folio-shortcuts-title';
            title.textContent = folioT('shortcuts');
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn btn-ghost btn-icon btn-sm';
            close.setAttribute('aria-label', folioT('close'));
            close.setAttribute('title', folioT('close'));
            close.appendChild(icon('ui-x'));
            close.addEventListener('click', function () { hideShortcuts(); });
            head.appendChild(title);
            head.appendChild(close);
            shortcutsDialog.appendChild(head);
            var list = document.createElement('dl');
            list.className = 'folio-shortcuts-list';
            shortcutsDialog.appendChild(list);
            shortcutsDialog.addEventListener('close', function () { shortcutsDialog.removeAttribute('open'); });
            document.body.appendChild(shortcutsDialog);
        }
        var dl = shortcutsDialog.querySelector('dl');
        dl.innerHTML = '';
        shortcutList.forEach(function (spec) {
            if (!spec.label) { return; } // an alias (Enter for e) is listed with its twin
            var dt = document.createElement('dt');
            var kbd = document.createElement('kbd');
            kbd.textContent = shortcutHint(spec);
            dt.appendChild(kbd);
            var dd = document.createElement('dd');
            dd.textContent = spec.label || '';
            dl.appendChild(dt);
            dl.appendChild(dd);
        });
        if (typeof shortcutsDialog.showModal === 'function') { shortcutsDialog.showModal(); }
        shortcutsDialog.setAttribute('open', ''); // showModal() sets it too; without it the dialog shows in place
        return shortcutsDialog;
    }

    function hideShortcuts() {
        if (!shortcutsDialog) { return; }
        if (typeof shortcutsDialog.close === 'function') { shortcutsDialog.close(); }
        shortcutsDialog.removeAttribute('open');
    }

    function onShortcutKeydown(e) {
        if (e.defaultPrevented || e.isComposing || !e.key) { return; }
        var mod = !!(e.metaKey || e.ctrlKey);
        if (e.altKey || (e.metaKey && e.ctrlKey)) { return; }
        var typing = isTyping(e.target) || isTyping(document.activeElement);
        var open = document.querySelector('dialog[open]');
        var key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        if (e.key === '?') { key = '?'; }
        for (var i = 0; i < shortcutList.length; i++) {
            var spec = shortcutList[i];
            if (!!spec.mod !== mod || (spec.key.length === 1 ? spec.key.toLowerCase() : spec.key) !== key) { continue; }
            if ((typing && !spec.typing) || open) { return; }
            // A row menu open with the focus in it would hold j / k / x / . back: close it first
            // (Enter keeps its meaning there: it activates the menu item).
            if (!spec.mod && spec.key !== 'Enter') { openRowMenus().forEach(function (m) { closeRowMenu(m, false); }); }
            if (spec.run(e) !== false) { e.preventDefault(); }
            return;
        }
    }

    function initShortcuts() {
        if (shortcutsReady) { return; }
        shortcutsReady = true;
        addShortcut({ key: '?', label: folioT('shortcut_help'), run: function () { showShortcuts(); } });
        document.addEventListener('keydown', onShortcutKeydown);
    }

    window.Folio = window.Folio || {};
    window.Folio.shortcuts = { add: addShortcut, show: showShortcuts, isTyping: isTyping };

    // A list's keys: / search, n new, j / k move the row focus, Enter or e open the row, x tick it,
    // . open its "…" menu, d duplicate it (its menu's Duplicate, so only where that item is there).
    function listRows() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-folio-list-table] [data-folio-row]'));
    }

    function currentRow() {
        var active = document.activeElement;
        return active && active.closest ? active.closest('[data-folio-row]') : null;
    }

    function focusRow(row) {
        var link = row ? row.querySelector('.folio-row-link') : null;
        if (!link) { return false; }
        link.focus();
        if (typeof link.scrollIntoView === 'function') { link.scrollIntoView({ block: 'nearest' }); }
        return true;
    }

    function moveRow(step) {
        var rows = listRows();
        if (!rows.length) { return false; }
        var at = rows.indexOf(currentRow());
        var next = at < 0 ? (step > 0 ? 0 : rows.length - 1) : Math.max(0, Math.min(rows.length - 1, at + step));
        return focusRow(rows[next]);
    }

    function openRow(e) {
        var row = currentRow();
        var link = row ? row.querySelector('.folio-row-link') : null;
        // Enter only for the row's own link (Enter on a button or a menu item keeps its own meaning).
        if (!link || (e.key === 'Enter' && document.activeElement !== link)) { return false; }
        window.location.href = link.getAttribute('href');
        return true;
    }

    function tickRow() {
        var row = currentRow();
        var box = row ? row.querySelector('[data-folio-bulk-item]') : null;
        if (!box) { return false; }
        box.checked = !box.checked;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function rowMenuOf() {
        var row = currentRow();
        return row ? row.querySelector('[data-folio-row-menu]') : null;
    }

    function initListShortcuts() {
        if (!document.querySelector('[data-folio-list-form], [data-folio-list-table]')) { return; }
        addShortcut({ key: '/', label: folioT('shortcut_search'), run: function () {
            var input = document.querySelector('[data-folio-list-search]');
            if (!input) { return false; }
            input.focus();
            if (typeof input.select === 'function') { input.select(); }
            return true;
        } });
        addShortcut({ key: 'n', label: folioT('shortcut_new'), run: function () {
            var link = document.querySelector('[data-folio-new]');
            if (!link) { return false; }
            link.click();
            return true;
        } });
        addShortcut({ key: 'j', label: folioT('shortcut_next'), run: function () { return moveRow(1); } });
        addShortcut({ key: 'k', label: folioT('shortcut_previous'), run: function () { return moveRow(-1); } });
        addShortcut({ key: 'e', label: folioT('shortcut_open'), hint: 'E / ↵', run: openRow });
        addShortcut({ key: 'Enter', label: '', run: openRow });
        addShortcut({ key: 'x', label: folioT('shortcut_tick'), run: tickRow });
        addShortcut({ key: '.', label: folioT('shortcut_menu'), run: function () {
            var menu = rowMenuOf();
            if (!menu) { return false; }
            openRowMenu(menu, false);
            return true;
        } });
        addShortcut({ key: 'd', label: folioT('shortcut_duplicate'), run: function () {
            var menu = rowMenuOf();
            var item = menu ? menu.querySelector('[data-folio-menu-duplicate]') : null;
            if (!item || item.disabled) { return false; }
            item.click();
            return true;
        } });
    }

    function boot() {
        document.querySelectorAll('[data-folio-list-form]').forEach(initList);
        document.addEventListener('submit', onSubmitConfirm);
        document.addEventListener('submit', onDeletePlanSubmit);
        document.addEventListener('submit', onRemovalSubmit);
        initBulk(document);
        initRowMenus(document);
        document.addEventListener('click', onRowMenuClick);
        document.addEventListener('keydown', onRowMenuKeydown);
        document.addEventListener('click', onCopyLinkClick);
        document.addEventListener('keydown', onFilterMenuKeydown);
        document.addEventListener('click', onFilterMenuClick);
        window.addEventListener('scroll', onRowMenuScroll, true);
        window.addEventListener('resize', onRowMenuScroll);
        initDensity();
        document.addEventListener('change', onBulkChange);
        document.addEventListener('reset', onBulkReset);
        document.addEventListener('click', onBulkExport);
        document.addEventListener('change', onEnablesChange);
        // Back/forward may restore ticked boxes: show the bar for them.
        window.addEventListener('pageshow', function () { initBulk(document); });
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
        document.addEventListener('click', onDataCopyClick);
        document.addEventListener('click', onFocalClick);
        document.addEventListener('click', onPickClick);
        document.addEventListener('click', onAssetFieldClick);
        document.querySelectorAll('[data-folio-focal]').forEach(placeFocalMarker);
        // Capture phase: addresses are clean before autosave or any other handler reads the field.
        document.addEventListener('change', onAddressChange, true);
        document.addEventListener('submit', onAddressSubmit, true);
        document.addEventListener('submit', onAutosaveSubmit);
        document.addEventListener('htmx:beforeRequest', onBeforeRequest);
        document.addEventListener('htmx:responseError', onAutosaveError);
        document.addEventListener('htmx:sendError', onAutosaveError);
        ['htmx:responseError', 'htmx:sendError', 'htmx:timeout'].forEach(function (name) {
            document.addEventListener(name, onRunStepFailed);
        });
        document.addEventListener('htmx:load', onHtmxLoad);
        document.addEventListener('folio:error', onFolioError);
        // Busy buttons: after the confirm, removal and delete-plan handlers above, so a cancelled submit stays idle.
        document.addEventListener('submit', onBusySubmit);
        document.addEventListener('htmx:beforeRequest', onBusyRequest);
        ['htmx:afterRequest', 'htmx:responseError', 'htmx:sendError', 'htmx:timeout', 'htmx:sendAbort'].forEach(function (name) {
            document.addEventListener(name, onBusyDone);
        });
        document.addEventListener('htmx:afterSwap', onBusySwap);
        window.addEventListener('pageshow', clearAllBusy);
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', onKeydown);
        initTabs(document);
        initRelationFilter(document);
        document.addEventListener('input', onRelationSearchInput);
        document.addEventListener('focusin', onRelationFocusIn);
        document.addEventListener('keydown', onRelationKeydown);
        document.addEventListener('click', onRelationClick);
        initSplit();
        initUnsavedGuard();
        initEditor();
        initTranslationsSaveAll();
        initRedirectTargets();
        initNavSections();
        initUserMenus();
        initShellRail();
        // After the menus' own keys (Escape, arrows), which mark their events handled.
        initShortcuts();
        initListShortcuts();
        initEditorShortcuts();
        // Last, after the unsaved-changes guards: when one of them asked "Leave the page?" and the
        // person chose to stay, nothing else would release a plain submit's busy button.
        window.addEventListener('beforeunload', onBusyBeforeUnload);
    }

    // The user menu (partials/_user_menu.twig): a button that opens a small non-modal dialog. Not a
    // role="menu", because its theme and language rows are segmented toggle groups. Enter or Space on
    // the button opens it and moves focus to the first row; ArrowUp/ArrowDown move between the rows
    // (a segmented row takes focus on its pressed button), ArrowLeft/ArrowRight move inside a row,
    // Home/End jump to the first or last row; Escape closes it and returns focus to the button;
    // tabbing out of it or clicking elsewhere closes it. Without this script the panel stays open in place.
    function initUserMenus() {
        document.querySelectorAll('[data-user-menu]').forEach(function (menu) {
            var button = menu.querySelector('button[aria-controls]');
            var panel = menu.querySelector('[data-user-panel]');
            if (!button || !panel || menu.getAttribute('data-user-menu-ready') === '1') { return; }
            menu.setAttribute('data-user-menu-ready', '1');
            var inside = function (node) {
                return !!(node && typeof node.closest === 'function' && node.closest('[data-user-menu]') === menu);
            };
            var rows = function () { return Array.prototype.slice.call(panel.querySelectorAll('[data-menu-row]')); };
            var controls = function (row) {
                if (row.matches('a, button')) { return [row]; }
                return Array.prototype.slice.call(row.querySelectorAll('a, button'));
            };
            var target = function (row) {
                var list = controls(row);
                return list.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; })[0] || list[0] || null;
            };
            var focusRow = function (row) { var t = row ? target(row) : null; if (t) { t.focus(); } };
            var isOpen = function () { return !panel.hidden; };
            var open = function (last) {
                panel.hidden = false;
                menu.classList.add('is-open');
                button.setAttribute('aria-expanded', 'true');
                var list = rows();
                focusRow(last ? list[list.length - 1] : list[0]);
            };
            var close = function (refocus) {
                if (!isOpen()) { return; }
                panel.hidden = true;
                menu.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
                if (refocus) { button.focus(); }
            };
            panel.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', function () {
                if (isOpen()) { close(false); } else { open(false); }
            });
            menu.addEventListener('keydown', function (e) {
                if (!isOpen()) {
                    // As a menu button does: ArrowDown on the closed button opens it at the first row, ArrowUp at the last.
                    if (e.target === button && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); open(e.key === 'ArrowUp'); }
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(true);
                    return;
                }
                if (e.target === button) { return; }
                var list = rows();
                var row = e.target.closest ? e.target.closest('[data-menu-row]') : null;
                var at = list.indexOf(row);
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End') {
                    e.preventDefault();
                    if (list.length === 0) { return; }
                    var next = e.key === 'Home' ? 0
                        : e.key === 'End' ? list.length - 1
                        : e.key === 'ArrowDown' ? (at + 1) % list.length
                        : (at <= 0 ? list.length - 1 : at - 1);
                    focusRow(list[next]);
                } else if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && row) {
                    var inRow = controls(row);
                    var i = inRow.indexOf(e.target);
                    if (inRow.length < 2 || i < 0) { return; }
                    e.preventDefault();
                    inRow[(i + (e.key === 'ArrowRight' ? 1 : inRow.length - 1)) % inRow.length].focus();
                }
            });
            // Tab out: focus moved to something outside the menu.
            menu.addEventListener('focusout', function (e) {
                if (isOpen() && e.relatedTarget && !inside(e.relatedTarget)) { close(false); }
            });
            document.addEventListener('click', function (e) {
                if (isOpen() && !inside(e.target)) { close(false); }
            });
        });
    }

    // The editor rail (.folio-shell.is-rail): its expand button shows the sidebar instead and keeps that for
    // the session (sessionStorage 'folio-shell'; the layout's head script applies it before the first paint);
    // the sidebar's collapse button goes back to the rail. Storage may be refused: then it holds for this page.
    // A nav that scrolls (the sidebar's row on a phone, the rail's strip, a long rail on a short screen):
    // bring the current item into view, or headings and other items push it out.
    function revealCurrent(nav) {
        var active = nav ? nav.querySelector('a[aria-current="page"]') : null;
        if (!active || typeof nav.getBoundingClientRect !== 'function') { return; }
        var box = nav.getBoundingClientRect();
        var at = active.getBoundingClientRect();
        if (nav.scrollWidth > nav.clientWidth + 1 && (at.right > box.right || at.left < box.left)) { nav.scrollLeft += at.left - box.left - 16; }
        if (nav.scrollHeight > nav.clientHeight + 1 && (at.bottom > box.bottom || at.top < box.top)) { nav.scrollTop += at.top - box.top - 16; }
    }

    function initShellRail() {
        var shell = document.querySelector('.folio-shell.is-rail');
        if (!shell) { return; }
        revealCurrent(shell.querySelector('.folio-rail-nav'));
        // Once more after load: icons and fonts can change the strip's widths after this first pass.
        window.addEventListener('load', function () { revealCurrent(shell.querySelector('.folio-rail-nav')); });
        var root = document.documentElement;
        var remember = function (mode) {
            try {
                if (mode) { window.sessionStorage.setItem('folio-shell', mode); } else { window.sessionStorage.removeItem('folio-shell'); }
            } catch (e) { /* private mode or blocked storage: this page only */ }
        };
        shell.addEventListener('click', function (e) {
            var expand = e.target.closest ? e.target.closest('[data-shell-expand]') : null;
            var collapse = e.target.closest ? e.target.closest('[data-shell-collapse]') : null;
            if (expand) {
                root.setAttribute('data-folio-shell', 'sidebar');
                remember('sidebar');
                var back = shell.querySelector('[data-shell-collapse]');
                if (back) { back.focus(); }
            } else if (collapse) {
                root.removeAttribute('data-folio-shell');
                remember(null);
                var again = shell.querySelector('[data-shell-expand]');
                if (again) { again.focus(); }
            }
        });
    }

    // Sidebar sections (<details class="folio-nav-section">): each open or close is saved for the
    // person with a fetch to the nav's data-nav-save. The state rendered by the server is the start;
    // a toggle event without a change (browsers send one for a section rendered open) saves nothing.
    // A failed or refused save is silent: the section stays as the person left it on this page.
    function initNavSections() {
        var nav = document.querySelector('nav.folio-nav');
        if (!nav) { return; }
        revealCurrent(nav);
        window.addEventListener('load', function () { revealCurrent(nav); });
        var url = nav.getAttribute('data-nav-save');
        if (!url) { return; }
        nav.querySelectorAll('details.folio-nav-section').forEach(function (section) {
            var known = !!section.open;
            section.addEventListener('toggle', function () {
                var open = !!section.open;
                var name = section.getAttribute('data-section') || '';
                if (open === known || name === '') { return; }
                known = open;
                var body = new URLSearchParams();
                var token = document.querySelector('input[name="_csrf_token"]');
                if (token) { body.append('_csrf_token', token.value); }
                body.append('section', name);
                body.append('open', open ? '1' : '0');
                try {
                    fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .catch(function () {});
                } catch (e) { /* no fetch: the choice holds for this page only */ }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
