/* Folio admin behaviors. Vanilla, no build step. */
(function () {
    'use strict';

    // The page's words (folio.js.* in the interface language): <script type="application/json" id="folio-i18n">,
    // read once. folioT('removed', {label: 'Hero'}) fills :label. A missing key gives '' (never the raw key):
    // every key the scripts use exists in every language (JsTextKeysTest).
    var folioTexts = null;
    function folioT(key, params) {
        if (folioTexts === null) {
            folioTexts = {};
            var el = document.getElementById('folio-i18n');
            try { folioTexts = el ? JSON.parse(el.textContent || '{}') : {}; } catch (e) { folioTexts = {}; }
        }
        var text = typeof folioTexts[key] === 'string' ? folioTexts[key] : '';
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

    // A copy of a <template>'s first element (a matrix row, an asset card the server rendered as the
    // pattern for ones added here). The only clone in this file: duplicating a block is a server fragment.
    function fromTemplate(template) {
        if (!template) { return null; }
        var source = template.content ? template.content.firstElementChild : template.children[0];
        return source ? source.cloneNode(true) : null;
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

        // A new row: the field's <template data-matrix-template> (grip, entry, view, trash), filled.
        var rowTemplate = root.querySelector('template[data-matrix-template]');

        function addRow(type, id, label, view) {
            view = view || '';
            var i = next++;
            var views = (opts.views && opts.views[type]) || [];
            var row = fromTemplate(rowTemplate);
            if (!row) { return; }
            var inputs = row.querySelectorAll('input[type="hidden"]');
            inputs[0].name = field + '[' + i + '][_type]';
            inputs[0].value = type;
            inputs[1].name = field + '[' + i + '][id]';
            inputs[1].value = id;
            var name = row.querySelector('.folio-matrix-name');
            if (name) { name.textContent = label; }
            var kind = row.querySelector('.folio-matrix-label em');
            if (kind) { kind.textContent = type; }
            var viewCell = row.querySelector('.folio-matrix-view');
            if (viewCell && views.length) {
                var select = document.createElement('select');
                select.name = field + '[' + i + '][view]';
                select.setAttribute('data-matrix-view', '');
                [''].concat(views).forEach(function (v) {
                    var o = document.createElement('option');
                    o.value = v;
                    o.textContent = v === '' ? folioT('default') : v;
                    if (v === view) { o.selected = true; }
                    select.appendChild(o);
                });
                viewCell.appendChild(select);
            }
            [['[data-folio-sort-handle]', 'fields.move_named'], ['[data-matrix-remove]', 'fields.remove_named']].forEach(function (pair) {
                var el = row.querySelector(pair[0]);
                if (el) {
                    el.setAttribute('aria-label', folioT(pair[1], { label: label }));
                    el.setAttribute('title', folioT(pair[1], { label: label }));
                }
            });
            rowsEl.appendChild(row);
            ownControls(row);
            var announceOn = row.querySelector('input[type="hidden"]');
            if (announceOn) { announceOn.dispatchEvent(new Event('change', { bubbles: true })); } else { notifyChange(root); }
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
                // The focus goes to the next row's trash, else the one before, else "Add row".
                var after = row.nextElementSibling || row.previousElementSibling;
                var focusTo = after ? after.querySelector('[data-matrix-remove]') : addBtn;
                row.remove();
                if (focusTo && typeof focusTo.focus === 'function') { focusTo.focus(); }
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
        if (data && typeof data === 'object' && data.source === 'folio-drawer-cancel') { closeDrawer(); return; }
        if (!data || typeof data !== 'object' || data.source !== 'folio-drawer') { return; }

        var matrix = activeMatrix;
        closeDrawer();
        if (!matrix || !matrix._folioMatrix) {
            // A drawer opened by something else (the library's "Edit alt text"): it hears it as folio:drawer-saved.
            document.dispatchEvent(new CustomEvent('folio:drawer-saved', { detail: { type: String(data.type || ''), id: String(data.id || '') } }));
            return;
        }

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
    window.Folio.openDrawer = openDrawer;

    // Links with data-folio-drawer (Settings → Redirects: New redirect, Edit, "→ add a redirect") open their page
    // in the drawer (_drawer=1); a modified or middle click still opens the page itself. Without this
    // script they are plain links to the page. In the drawer, a Cancel link with data-folio-drawer-cancel
    // closes it (a message to the page behind) instead of leaving the form.
    function onDrawerLinkClick(e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-folio-drawer], a[data-folio-drawer-cancel]') : null;
        if (!a || e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        if (a.hasAttribute('data-folio-drawer-cancel')) {
            if (window.parent && window.parent !== window) {
                e.preventDefault();
                window.parent.postMessage({ source: 'folio-drawer-cancel' }, window.location.origin);
            }
            return;
        }
        var href = a.getAttribute('href');
        if (!href) { return; }
        e.preventDefault();
        var menu = a.closest('[data-folio-row-menu]');
        if (menu) { menu.open = false; }
        openDrawer(href + (href.indexOf('?') === -1 ? '?' : '&') + '_drawer=1');
    }

    // Inside a drawer (its own document in the drawer's iframe): Escape closes the drawer, as it does on the page
    // behind it, unless something in the drawer used it first (a listbox, a menu, an open dialog).
    function onDrawerEscape(e) {
        if (e.key !== 'Escape' || e.defaultPrevented || !window.parent || window.parent === window) { return; }
        if (!document.body || !document.body.classList.contains('folio-drawer-body')) { return; }
        if (document.querySelector('dialog[open]')) { return; }
        e.preventDefault();
        window.parent.postMessage({ source: 'folio-drawer-cancel' }, window.location.origin);
    }

    // A saved redirect drawer: the list behind it shows the saved state (and the flash) again.
    function onRedirectDrawerSaved(e) {
        if (e && e.detail && e.detail.type === 'redirect') { window.location.reload(); }
    }

    function onAssetsPicked(data) {
        var picker = activePicker;
        if (!picker) { return; }
        var items = [];
        (Array.isArray(data.items) ? data.items : []).forEach(function (item) {
            var id = String((item && item.id) || '');
            if (/^[a-f0-9]{32}$/.test(id)) {
                items.push({ id: id, thumb: String(item.thumb || ''), label: String(item.label || ''), meta: String(item.meta || ''), warn: item.warn === true, focal: String(item.focal || '') });
            }
        });
        if (!picker.multiple) { closeDrawer(); }
        if (items.length && typeof picker.onPicked === 'function') { picker.onPicked(items); }
    }

    // Asset fields: "Choose" opens the picker; picked assets become hidden {name}_ids[] inputs in a
    // card. The field's <template data-folio-asset-template> is the card (thumbnail with the focal
    // point, name, kind and size, the alt-text warning, Remove); without one, a plain item.
    function assetItem(field, item) {
        var card = fromTemplate(field.querySelector('template[data-folio-asset-template]'));
        if (card) {
            var previews = card.querySelectorAll('.folio-asset-card-preview');
            var preview = null;
            var fileIcon = null;
            Array.prototype.forEach.call(previews, function (p) { if (p.classList.contains('is-file')) { fileIcon = p; } else { preview = p; } });
            var img = card.querySelector('img');
            if (item.thumb && img) {
                img.src = item.thumb;
                img.setAttribute('src', item.thumb);
                if (fileIcon) { fileIcon.parentNode.removeChild(fileIcon); }
            } else {
                // No thumbnail (a PDF): the file icon, as the server's card shows it.
                if (preview) { preview.parentNode.removeChild(preview); }
                if (fileIcon) { fileIcon.hidden = false; }
            }
            var focal = card.querySelector('.folio-asset-focal');
            if (focal && item.thumb && /^\d{1,3},\d{1,3}$/.test(String(item.focal || ''))) {
                focal.setAttribute('data-focal', String(item.focal));
                if (window.Folio.placeFocal) { window.Folio.placeFocal(focal); }
            }
            var name = card.querySelector('.folio-asset-name');
            if (name) { name.textContent = item.label || item.id; }
            var meta = card.querySelector('.folio-asset-meta');
            if (meta) { meta.textContent = item.meta || ''; meta.hidden = !item.meta; }
            var warn = card.querySelector('.folio-asset-warn');
            if (warn) { warn.hidden = !item.warn; }
            var hidden = card.querySelector('input[type="hidden"]');
            if (hidden) { hidden.value = item.id; hidden.setAttribute('value', item.id); }
            var drop = card.querySelector('[data-folio-asset-remove]');
            if (drop) { drop.setAttribute('aria-label', folioT('remove_named', { label: item.label || item.id })); }
            return card;
        }
        var li = document.createElement('li');
        li.className = 'folio-asset-item';
        li.setAttribute('data-asset-item', '');
        if (item.thumb) {
            var thumb = document.createElement('img');
            thumb.src = item.thumb;
            thumb.alt = '';
            thumb.className = 'folio-asset-thumb';
            li.appendChild(thumb);
        } else {
            var span = document.createElement('span');
            span.textContent = item.label || item.id;
            li.appendChild(span);
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = field.getAttribute('data-selection-name');
        input.value = item.id;
        li.appendChild(input);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-ghost';
        remove.setAttribute('data-folio-asset-remove', '');
        remove.textContent = folioT('remove');
        li.appendChild(remove);
        return li;
    }

    // The change is announced on a control of the record form (the field may sit in the side panel,
    // outside the <form>): the new card's input, else the field's empty marker.
    function assetChanged(field, control) {
        var target = control || field.querySelector('input[type="hidden"]');
        if (target && target.dispatchEvent) { target.dispatchEvent(new Event('change', { bubbles: true })); } else { notifyChange(field); }
    }

    function fillAssetField(field, items) {
        if (!field.isConnected) { return; }
        var list = field.querySelector('.folio-asset-selection');
        var multiple = field.getAttribute('data-multiple') === '1';
        var last = null;
        items.forEach(function (item) {
            if (!multiple) { list.innerHTML = ''; }
            var dupe = list.querySelector('input[value="' + item.id + '"]');
            if (!dupe) {
                var card = assetItem(field, item);
                list.appendChild(card);
                ownControls(card);
                last = card.querySelector('input[type="hidden"]');
            }
        });
        assetChanged(field, last);
    }
    window.Folio.fillAssetField = fillAssetField;

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
            if (owner) { assetChanged(owner, null); }
            e.preventDefault();
        }
    }

    // Capture phase, marked handled: Escape closes the drawer only, not the editor's side sheet under it.
    function onKeydown(e) {
        if (e.key === 'Escape' && drawerEl) {
            e.preventDefault();
            e.stopPropagation();
            closeDrawer();
        }
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
            document.addEventListener(name, function (e) { if (isEdit(e.target) && formOf(e.target) === form) { schedule(); } });
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

        // Whatever has focus in the form is outlined in the preview: a block card stays selected (outlined
        // with its label) until another card is; a top-level field is outlined while it has focus.
        form.addEventListener('focusin', function (e) {
            var card = e.target.closest('[data-block-card]');
            var name = typeof e.target.name === 'string' ? e.target.name : '';
            if (card) {
                highlight(card.getAttribute('data-block-uid'), null, true, true);
            } else {
                highlight(null, name.split('[')[0] || null, true, false);
            }
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
        repostHighlights();
        return true;
    }

    // ---- Click-to-edit: preview <-> form ----------------------------------

    var lastHighlight = null;
    var selectedBlock = null; // the card selected in the form: its uid (sticky in the preview)

    // sticky: a block card was opened or focused; the preview keeps its outline and label until another
    // card is selected. Otherwise (a top-level field) the outline follows the focus.
    function highlight(uid, field, scroll, sticky) {
        if (!preview) { return; }
        sticky = !!sticky && !!uid;
        var key = (uid || '') + '|' + (field || '') + '|' + (sticky ? 's' : '');
        var now = Date.now();
        if (lastHighlight && lastHighlight.key === key && now - lastHighlight.at < 150) { return; }
        lastHighlight = { key: key, at: now, uid: uid, field: field, sticky: sticky };
        if (sticky) { selectedBlock = uid; }
        preview.post({ type: 'highlight', uid: uid, field: field, scroll: !!scroll, sticky: sticky });
    }

    // After a re-render: the selected card again, then the field that has the focus.
    function repostHighlights() {
        if (!preview) { return; }
        if (selectedBlock) { preview.post({ type: 'highlight', uid: selectedBlock, field: null, scroll: false, sticky: true }); }
        if (lastHighlight && !lastHighlight.sticky) {
            preview.post({ type: 'highlight', uid: lastHighlight.uid, field: lastHighlight.field, scroll: false, sticky: false });
        }
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
        highlight(uid, uid ? null : field, false, !!uid);
    }

    function onPreviewMessage(data) {
        var uid = typeof data.uid === 'string' && /^[a-f0-9]{16}$/.test(data.uid) ? data.uid : null;
        var field = typeof data.field === 'string' && /^[A-Za-z_][A-Za-z0-9_]*$/.test(data.field) ? data.field : null;
        if (data.type === 'select') {
            selectInForm(uid, field);
        } else if (data.type === 'ready') {
            repostHighlights();
        }
    }

    // ---- Dialogs: window.folioConfirm and window.folioAlert ---------------------------------
    // One native <dialog> for every question Folio asks (partials/_dialog.twig, rendered once by the
    // layouts; built here when a layout has none): a title, a message, an optional list (the delete
    // plan's lines), Cancel and the confirm button (.btn-danger for tone 'danger', else .btn-primary),
    // and an optional third button. For extensions too:
    //   folioConfirm({title, message, items, mono, confirm, cancel, tone, extra, icon}) → Promise<'confirm'|'cancel'|'extra'>
    //   folioAlert({title, message, items, icon}) → Promise<void> (one OK button)
    // icon: an id from icons.svg for the tile (default ui-info, ui-warn for danger); mono: the items in mono (addresses).
    // Escape and a click on the backdrop cancel. A danger dialog starts on Cancel, any other on its
    // confirm button; closing returns the focus to where it was (a closed menu's toggle when the
    // opener sat in that menu). A second ask while one is open cancels the first.
    var dialogState = null; // { dialog, resolve, opener, openedAt } while a dialog is open
    var BACKDROP_GRACE_MS = 300;

    function dialogPart(dialog, name) {
        return dialog.querySelector('[data-folio-dialog-' + name + ']');
    }

    function buildDialog() {
        var make = function (parent, tag, className, attr) {
            var node = document.createElement(tag);
            if (className) { node.className = className; }
            if (attr) { node.setAttribute(attr, ''); }
            parent.appendChild(node);
            return node;
        };
        var dialog = make(document.body, 'dialog', 'folio-dialog', 'data-folio-dialog');
        dialog.id = 'folio-dialog';
        var panel = make(dialog, 'div', 'folio-dialog-panel');
        var tile = make(panel, 'span', 'folio-dialog-icon', 'data-folio-dialog-icon');
        tile.setAttribute('aria-hidden', 'true');
        tile.appendChild(icon('ui-info'));
        make(panel, 'h2', 'folio-dialog-title', 'data-folio-dialog-title').id = 'folio-dialog-title';
        make(panel, 'p', 'folio-dialog-message', 'data-folio-dialog-message').id = 'folio-dialog-message';
        make(panel, 'ul', 'folio-dialog-items', 'data-folio-dialog-items').id = 'folio-dialog-items';
        var actions = make(panel, 'div', 'folio-dialog-actions');
        [['cancel', 'btn btn-secondary', folioT('cancel')], ['extra', 'btn btn-secondary', ''], ['confirm', 'btn btn-primary', folioT('confirm')]].forEach(function (b) {
            var button = make(actions, 'button', b[1], 'data-folio-dialog-' + b[0]);
            button.type = 'button';
            button.textContent = b[2];
        });
        return dialog;
    }

    function dialogEl() {
        var dialog = document.querySelector('dialog[data-folio-dialog]') || buildDialog();
        if (dialog._folioReady) { return dialog; }
        dialog._folioReady = true;
        // The labels the layout rendered (the interface language) are the defaults.
        ['cancel', 'confirm'].forEach(function (name) {
            var b = dialogPart(dialog, name);
            if (b) { b._folioDefault = b.textContent || folioT(name); }
        });
        // A click on the backdrop (the dialog element itself: the panel fills it) cancels, but only when the
        // press started there too (a text selection dragged out of the panel is no cancel) and not in the
        // first moments after opening (the second click of a double click on the opener). A press on the
        // backdrop released over the panel clicks their common ancestor, the dialog: the release must be
        // on the backdrop too. Closing forgets the press.
        dialog.addEventListener('pointerdown', function (e) { dialog._folioPressedBackdrop = e.target === dialog; });
        dialog.addEventListener('pointerup', function (e) { if (e.target !== dialog) { dialog._folioPressedBackdrop = false; } });
        dialog.addEventListener('click', function (e) {
            var t = e.target;
            if (t === dialog) {
                var pressed = dialog._folioPressedBackdrop === true;
                dialog._folioPressedBackdrop = false;
                if (pressed && dialogState && Date.now() - dialogState.openedAt >= BACKDROP_GRACE_MS) { finishDialog('cancel'); }
                return;
            }
            var b = t && t.closest ? t.closest('[data-folio-dialog-cancel], [data-folio-dialog-confirm], [data-folio-dialog-extra]') : null;
            if (!b) { return; }
            finishDialog(b.hasAttribute('data-folio-dialog-confirm') ? 'confirm' : b.hasAttribute('data-folio-dialog-extra') ? 'extra' : 'cancel');
        });
        dialog.addEventListener('cancel', function (e) { e.preventDefault(); finishDialog('cancel'); }); // Escape
        // Closed some other way (a form method="dialog"). Browsers queue the close event: by the time it runs,
        // the next question may have opened the dialog again, so an event for an open dialog is stale.
        dialog.addEventListener('close', function () { if (dialogState && dialogState.dialog === dialog && !dialog.open) { finishDialog('cancel'); } });
        return dialog;
    }

    function finishDialog(answer) {
        var state = dialogState;
        if (!state) { return; }
        dialogState = null;
        var dialog = state.dialog;
        dialog._folioPressedBackdrop = false;
        if (dialog.open && typeof dialog.close === 'function') { dialog.close(); }
        dialog.removeAttribute('open');
        var back = state.opener;
        var menu = back && back.closest ? back.closest('details') : null;
        if (menu && !menu.open && menu.querySelector('summary')) { back = menu.querySelector('summary'); }
        if (back && back !== document.body && typeof back.focus === 'function' && back.isConnected !== false) { back.focus(); }
        state.resolve(answer);
    }

    function openDialog(options, alertOnly) {
        var o = options || {};
        if (dialogState) { finishDialog('cancel'); }
        var dialog = dialogEl();
        var title = String(o.title || '');
        var message = String(o.message || '');
        if (title === '') { title = message; message = ''; }
        var items = Array.isArray(o.items) ? o.items.map(String).filter(function (s) { return s !== ''; }) : [];
        var danger = !alertOnly && o.tone === 'danger';

        var titleEl = dialogPart(dialog, 'title');
        var messageEl = dialogPart(dialog, 'message');
        var list = dialogPart(dialog, 'items');
        titleEl.textContent = title;
        messageEl.textContent = message;
        messageEl.hidden = message === '';
        list.innerHTML = '';
        items.forEach(function (text) {
            var li = document.createElement('li');
            li.textContent = text;
            list.appendChild(li);
        });
        list.hidden = items.length === 0;
        if (list.classList) { list.classList.toggle('is-mono', !!o.mono && items.length > 0); }
        dialog.setAttribute('aria-labelledby', titleEl.id);
        var described = message !== '' ? messageEl.id : (items.length ? list.id : '');
        if (described) { dialog.setAttribute('aria-describedby', described); } else { dialog.removeAttribute('aria-describedby'); }

        var cancel = dialogPart(dialog, 'cancel');
        var extra = dialogPart(dialog, 'extra');
        var ok = dialogPart(dialog, 'confirm');
        cancel.hidden = !!alertOnly;
        cancel.textContent = o.cancel || cancel._folioDefault;
        extra.hidden = alertOnly || !o.extra;
        extra.textContent = o.extra && !alertOnly ? String(o.extra) : '';
        // The third button is the safe way on (Save and leave): primary beside a danger confirm.
        extra.className = 'btn ' + (danger ? 'btn-primary' : 'btn-secondary');
        ok.textContent = alertOnly ? (dialog.getAttribute('data-text-ok') || folioT('ok')) : (o.confirm || dialog.getAttribute('data-text-confirm') || ok._folioDefault);
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        var use = dialog.querySelector('[data-folio-dialog-icon] use');
        var glyph = typeof o.icon === 'string' && /^[a-z0-9-]+$/.test(o.icon) ? o.icon : (danger ? 'ui-warn' : 'ui-info');
        if (use) { use.setAttribute('href', String(use.getAttribute('href') || '').split('#')[0] + '#' + glyph); }
        dialog.classList.toggle('is-danger', danger);
        dialog.classList.toggle('is-alert', !!alertOnly);
        dialog.classList.toggle('has-extra', !extra.hidden);

        return new Promise(function (resolve) {
            dialogState = { dialog: dialog, resolve: resolve, opener: document.activeElement, openedAt: Date.now() };
            if (!dialog.open && typeof dialog.showModal === 'function') { dialog.showModal(); }
            dialog.setAttribute('open', ''); // showModal() sets it too; without it the dialog shows in place
            (danger ? cancel : ok).focus();
        });
    }

    function folioConfirm(options) { return openDialog(options, false); }
    function folioAlert(options) { return openDialog(options, true).then(function () {}); }
    window.folioConfirm = folioConfirm;
    window.folioAlert = folioAlert;

    // ---- Confirmations: data-confirm, the delete plan, hx-confirm -------------------------------
    // <form data-confirm="…"> (inline onsubmit handlers are blocked by the strict CSP): the submit is
    // held and asked through the dialog; on confirm the same submitter is sent again with
    // form.requestSubmit(submitter), so its name and value (_action), its busy state and the form's CSRF
    // token go along as on the first click. Without script the form simply submits. In a form with
    // several buttons (the list's bulk bar) the clicked button may carry its own data-confirm or
    // data-delete-plan; with data-confirm-selected the dialog lists the ticked boxes' names (data-label). The confirm button says what the submitter says ("Delete", "Restore"), or
    // data-confirm-label; data-confirm-tone="danger" (or a danger submitter: .btn-danger, .is-danger,
    // data-tone="danger") makes it a danger dialog.
    function confirmSource(e) {
        var s = e.submitter;
        if (s && s.hasAttribute && (s.hasAttribute('data-confirm') || s.hasAttribute('data-delete-plan'))) { return s; }
        return e.target;
    }

    function confirmLabel(src, submitter) {
        var own = src && src.getAttribute ? src.getAttribute('data-confirm-label') : null;
        if (own) { return own; }
        var b = submitter || (src && src.tagName === 'BUTTON' ? src : null);
        if (!b || !b.getAttribute) { return ''; }
        var text = b.getAttribute('aria-label') || (b.tagName === 'INPUT' ? b.value : b.textContent) || '';
        return String(text).replace(/\s+/g, ' ').trim().replace(/(…|\.\.\.)$/, '');
    }

    function confirmTone(src, submitter) {
        var own = src && src.getAttribute ? src.getAttribute('data-confirm-tone') : null;
        if (own) { return own; }
        var b = submitter || src;
        var danger = !!(b && b.classList && (b.classList.contains('btn-danger') || b.classList.contains('is-danger') || (b.getAttribute && b.getAttribute('data-tone') === 'danger')));
        return danger ? 'danger' : 'default';
    }

    /**
     * Sends a held form again with its submitter: every submit handler runs once more (busy state,
     * the unsaved-changes guard), the confirmation ones let it through. Browsers without
     * requestSubmit() post the form directly (with the button's formaction).
     */
    function resubmit(form, submitter) {
        form._folioConfirmed = true;
        try {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter && formOf(submitter) === form ? submitter : undefined);
            } else {
                if (submitter && submitter.getAttribute('formaction')) { form.setAttribute('action', submitter.getAttribute('formaction')); }
                allowLeave();
                HTMLFormElement.prototype.submit.call(form);
            }
        } finally {
            form._folioConfirmed = false;
        }
    }

    function onSubmitConfirm(e) {
        var f = e.target;
        // htmx forms prevent their own submit and ask through htmx:confirm (onHtmxConfirm).
        if (!f || !f.hasAttribute || e.defaultPrevented || f._folioConfirmed) { return; }
        var src = confirmSource(e);
        if (src.hasAttribute('data-delete-plan')) { return; } // onDeletePlanSubmit asks
        if (removalAddresses(src) > 0) { return; } // onRemovalSubmit asks
        if (!src.hasAttribute('data-confirm')) { return; }
        e.preventDefault();
        var submitter = e.submitter || null;
        // data-confirm-item: the one thing it is about (a redirect's address, in mono); data-confirm-message: a line under the title; data-confirm-cancel and
        // data-confirm-icon name the safe button ("Keep") and the icon.
        var item = src.getAttribute('data-confirm-item');
        folioConfirm({
            title: src.getAttribute('data-confirm'),
            message: src.getAttribute('data-confirm-message') || '',
            items: item ? [item] : selectedLabels(src),
            mono: !!item,
            confirm: confirmLabel(src, submitter),
            cancel: src.getAttribute('data-confirm-cancel') || '',
            icon: src.getAttribute('data-confirm-icon') || '',
            tone: confirmTone(src, submitter)
        })
            .then(function (answer) { if (answer === 'confirm') { resubmit(f, submitter); } });
    }

    // htmx asks hx-confirm (and data-confirm on an htmx element) through htmx:confirm: held, asked
    // through the dialog, then issueRequest(true) sends it without htmx's own window.confirm.
    // A boosted link or form (hx-boost) is a navigation: the unsaved-changes guard comes first.
    function onHtmxConfirm(e) {
        var d = e.detail || {};
        var elt = d.elt;
        if (typeof d.issueRequest !== 'function' || !elt || !elt.getAttribute) { return; }
        var question = d.question || elt.getAttribute('data-confirm');
        if (question) {
            e.preventDefault();
            folioConfirm({ title: question, message: elt.getAttribute('data-confirm-message') || '', confirm: confirmLabel(elt, null), tone: confirmTone(elt, null) })
                .then(function (answer) { if (answer === 'confirm') { d.issueRequest(true); } });
            return;
        }
        var boosted = elt.closest ? elt.closest('[hx-boost]') : null;
        if (boosted && boosted.getAttribute('hx-boost') === 'true' && holdLeave(function () { d.issueRequest(true); }, null, e)) {
            e.preventDefault();
        }
    }

    // Delete forms with data-delete-plan="{url}" data-delete-id="{id}": fetch what the delete would
    // also do ("Also deletes 12 jobs.") and ask that, the plan's lines as the dialog's list; a
    // restricted plan only tells why not (with what still points here). The server plans again when
    // the form is submitted. If the plan can't be fetched, the form's own data-confirm is asked.
    // The bulk bar's Delete button carries data-delete-plan itself: the plan is asked for the ticked
    // ids[], and the button is submitted again once confirmed.
    function onDeletePlanSubmit(e) {
        var f = e.target;
        if (!f || !f.hasAttribute || e.defaultPrevented || f._folioConfirmed) { return; }
        var src = confirmSource(e);
        if (!src.hasAttribute('data-delete-plan')) { return; }
        e.preventDefault();
        var submitter = e.submitter || (src !== f ? src : null);
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
        var send = function () { resubmit(f, submitter); };
        var question = src.getAttribute('data-confirm') || folioT('confirm_delete');
        var label = src.getAttribute('data-confirm-label') || folioT('delete');
        var showPlan = function (plan) {
            if (plan.restricted) {
                // What still points here, as a list once it is more than one type (one is said in the sentence).
                var usedBy = Array.isArray(plan.used_by) && plan.used_by.length > 1 ? plan.used_by : [];
                folioAlert({ title: folioT('delete_blocked'), message: plan.confirm, items: usedBy });
            } else if ((parseInt(plan.addresses, 10) || 0) > 0) {
                // The records have public addresses: ask where their old links go as well.
                // Its title names the record ("Delete “About”?"), else the form's question; the plan's lines follow.
                var named = src.getAttribute('data-removal-label');
                removalPrompt(plan.confirm.split('\n'), folioT('redirects.prompt.delete'), function (target) {
                    setRemovalTarget(f, target);
                    send();
                }, named ? folioT('redirects.prompt.delete_title', { label: named }) : question);
            } else {
                var lines = Array.isArray(plan.lines) ? plan.lines : [];
                folioConfirm({ title: question, message: plan.confirm.split('\n')[0], items: lines, confirm: label, tone: 'danger', icon: 'ui-trash' })
                    .then(function (answer) { if (answer === 'confirm') { send(); } });
            }
        };
        var askPlain = function () {
            folioConfirm({ title: question, confirm: label, tone: 'danger', icon: 'ui-trash' })
                .then(function (answer) { if (answer === 'confirm') { send(); } });
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
                return plan;
            })
            .then(showPlan, askPlain)
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
        if (!f || !f.hasAttribute || e.defaultPrevented || f._folioConfirmed || f.hasAttribute('data-delete-plan') || removalAddresses(f) <= 0) { return; }
        e.preventDefault();
        var submitter = e.submitter || null;
        // Named: "Unpublish “About”?" with the form's warning as the line under it; else that warning is the title.
        var named = f.getAttribute('data-removal-label');
        removalPrompt([f.getAttribute('data-confirm') || ''], folioT('redirects.prompt.unpublish'), function (target) {
            setRemovalTarget(f, target);
            resubmit(f, submitter);
        }, named ? folioT('redirects.prompt.unpublish_title', { label: named }) : '');
    }

    var removalDialog = null;

    /**
     * Shows the prompt (440px, LibOverlays): the title (title, else the first line), the confirmation's
     * other lines, "Send its old links to…" and three radio cards: Leave them as not found / A page on the
     * site (its search, the results and "Now: …") / A URL or a path (its input). The chosen card opens to
     * its controls (.is-chosen); the arrow keys, Home and End move the choice between the cards. Footer
     * Cancel and $confirmLabel (danger). onConfirm(target) gets "type:id", a URL or path, or '' for none;
     * Cancel sends nothing.
     */
    function removalPrompt(lines, confirmLabel, onConfirm, title) {
        if (removalDialog) { return; }
        var t = function (key, params) { return folioT('redirects.prompt.' + key, params); };
        var el = function (tag, className, text) {
            var node = document.createElement(tag);
            if (className) { node.className = className; }
            if (text) { node.textContent = text; }
            return node;
        };
        lines = lines.filter(function (line) { return line !== ''; });
        if (!title) { title = lines.shift() || t('send_to'); }
        var uid = 'folio-removal-' + Date.now().toString(36);
        var dialog = el('dialog', 'folio-removal-dialog');
        dialog.setAttribute('data-removal-dialog', '');
        dialog.setAttribute('aria-labelledby', uid + '-title');

        var head = el('div', 'folio-removal-head');
        var heading = el('h2', 'folio-removal-title', title);
        heading.id = uid + '-title';
        head.appendChild(heading);
        lines.forEach(function (line) { head.appendChild(el('p', 'folio-removal-line', line)); });
        dialog.appendChild(head);

        var choices = el('fieldset', 'folio-removal-choices');
        choices.appendChild(el('legend', 'folio-removal-subtitle', t('send_to')));
        var radios = {};
        var cards = {};
        var option = function (value, text) {
            var card = el('div', 'folio-removal-card');
            card.setAttribute('data-removal-card', value);
            var label = el('label', 'folio-removal-option');
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'folio_removal_target';
            radio.value = value;
            radio.setAttribute('value', value);
            radio.checked = value === 'none';
            radios[value] = radio;
            cards[value] = card;
            label.appendChild(radio);
            label.appendChild(el('span', '', text));
            card.appendChild(label);
            choices.appendChild(card);
            return card;
        };
        var chosen = function () {
            return Object.keys(radios).filter(function (key) { return radios[key].checked; })[0] || 'none';
        };
        // The chosen card is marked (and opens to its controls); the others close.
        var mark = function () {
            var now = chosen();
            Object.keys(cards).forEach(function (key) { cards[key].classList.toggle('is-chosen', key === now); });
        };
        var choose = function (value) {
            Object.keys(radios).forEach(function (key) { radios[key].checked = key === value; });
            mark();
        };

        option('none', t('leave'));
        var pageCard = option('page', t('to_page'));
        var more = el('div', 'folio-removal-more');
        var searchBox = el('label', 'folio-removal-search-box');
        var search = el('input', 'folio-removal-search');
        search.type = 'search';
        search.setAttribute('data-removal-search', '');
        search.setAttribute('autocomplete', 'off');
        search.setAttribute('placeholder', t('search'));
        search.setAttribute('aria-label', t('search'));
        searchBox.appendChild(search);
        var results = el('ul', 'folio-removal-results');
        results.setAttribute('data-removal-results', '');
        var picked = el('p', 'folio-removal-picked');
        picked.setAttribute('aria-live', 'polite');
        more.appendChild(searchBox);
        more.appendChild(results);
        more.appendChild(picked);
        pageCard.appendChild(more);
        var urlCard = option('url', t('to_url'));
        var url = el('input', 'folio-removal-url');
        url.type = 'text';
        url.setAttribute('data-removal-url', '');
        url.setAttribute('autocomplete', 'off');
        url.setAttribute('placeholder', t('url_placeholder'));
        url.setAttribute('aria-label', t('to_url'));
        var urlMore = el('div', 'folio-removal-more');
        urlMore.appendChild(url);
        urlCard.appendChild(urlMore);
        dialog.appendChild(choices);
        mark();
        choices.addEventListener('change', mark);
        // The radio group's keys (as browsers do it, here also where they don't): the next or the previous card, wrapping.
        choices.addEventListener('keydown', function (e) {
            var radio = e.target;
            if (!radio || radio.name !== 'folio_removal_target') { return; }
            var keys = Object.keys(radios);
            var at = keys.indexOf(radio.value);
            var to = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? (at + 1) % keys.length
                : e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? (at - 1 + keys.length) % keys.length
                : e.key === 'Home' ? 0 : e.key === 'End' ? keys.length - 1 : -1;
            if (to < 0) { return; }
            e.preventDefault();
            choose(keys[to]);
            radios[keys[to]].focus();
        });

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
                                Array.prototype.forEach.call(results.querySelectorAll('.folio-removal-pick'), function (b) {
                                    b.classList.toggle('is-picked', b === button);
                                    b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
                                });
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

    // A list's "Add block" button (the picker's first visible button).
    function pickerButton(list) {
        var picker = null;
        for (var i = 0; i < list.children.length; i++) {
            if (list.children[i].hasAttribute('data-blocks-picker')) { picker = list.children[i]; }
        }
        var buttons = picker ? picker.querySelectorAll('button') : [];
        for (var j = 0; j < buttons.length; j++) {
            if (!buttons[j].hidden) { return buttons[j]; }
        }
        return null;
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
            var stay = siblingCard(card, 1) || siblingCard(card, -1);
            list.removeChild(card);
            notifyChange(list);
            offerUndo(card, list, next);
            // The focus stays in the list: on the next card (or the one before), else on "Add block".
            // An unknown neighbour has no chevron: its Remove, else "Add block".
            var focusTo = (stay && (stay.querySelector('[data-block-collapse]') || stay.querySelector('[data-block-remove]'))) || pickerButton(list);
            if (focusTo) { focusTo.focus(); }
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
    // edits show as "Unsaved changes" in the save state instead (editor-frame.js).
    // Hooks for in-admin navigation (a styled leave dialog, 0.75 Task 7): Folio.unsaved.isDirty(),
    // .dirtyForms(), .clear(form), and .onLeave(fn), a check every leave runs through (fn(e) returns true
    // to let the page go; beforeunload asks the browser's own question only while no hook answered), and
    // .pending(): an autosaving form holds edits not saved yet ("Unsaved changes" or "Saving…").

    // A control marked data-folio-no-edit (or inside such an element) is not record data: the block
    // picker's search, a filter box. Typing in it is no edit: no unsaved mark, autosave or preview.
    function isEdit(el) {
        return !(el && el.closest && el.closest('[data-folio-no-edit]'));
    }

    // The form a control belongs to: its form owner (form="…" included), else the form around it.
    // Folio.ownControls(node): a control a script adds inside the side panel's record fieldset
    // (.folio-side-fields[form]) names that form at once, before its change is announced, as the
    // server's FormOwner does for rendered ones. <button type="button"> is left alone (it submits
    // nothing); a control naming a form keeps it. editor-frame.js's observer uses it for the rest.
    function ownControls(node) {
        if (!node || node.nodeType !== 1 || !node.closest) { return; }
        var fieldset = node.closest('.folio-side-fields[form]');
        var formId = fieldset ? fieldset.getAttribute('form') : null;
        if (!formId) { return; }
        var controls = [];
        if (node.matches && node.matches('input, select, textarea, button')) { controls.push(node); }
        if (node.querySelectorAll) { controls = controls.concat(Array.prototype.slice.call(node.querySelectorAll('input, select, textarea, button'))); }
        controls.forEach(function (control) {
            if (control.hasAttribute('form')) { return; }
            if (control.tagName === 'BUTTON' && String(control.getAttribute('type') || '').toLowerCase() === 'button') { return; }
            control.setAttribute('form', formId);
        });
    }
    window.Folio = window.Folio || {};
    window.Folio.ownControls = ownControls;

    function formOf(el) {
        if (!el) { return null; }
        if (el.form && el.form.tagName === 'FORM') { return el.form; }
        if (el.tagName === 'FORM') { return el; }
        return el.closest ? el.closest('form') : null;
    }

    var unsaved = { dirty: [], guarded: [], leaveChecks: [], listening: false };

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
        if (!isEdit(e.target)) { return; }
        markDirty(formOf(e.target));
    }

    function onGuardSubmit(e) {
        if (!e.defaultPrevented) { clearDirty(e.target); }
    }

    function onGuardBeforeUnload(e) {
        if (leaveOnce) { leaveOnce = false; return; } // the dialog let this leave go
        if (unsaved.dirty.length === 0) { return; }
        for (var i = 0; i < unsaved.leaveChecks.length; i++) {
            if (unsaved.leaveChecks[i](e) === true) { return; }
        }
        e.preventDefault();
        e.returnValue = '';
    }

    function initUnsavedGuard() {
        // The record editor's forms, and an asset's Details form (its Save is in the topbar: data-folio-save).
        unsaved.guarded = Array.prototype.slice.call(document.querySelectorAll('.folio-form form, .folio-side form, form.folio-asset-form'))
            .filter(function (form) { return !form.hasAttribute('hx-post'); });
        if (unsaved.guarded.length === 0) { return; }
        listenUnsaved();
    }

    // Once per page: the guard's listeners (the editor's forms, or a screen that guards a form of its own,
    // such as initTranslations' save-all form).
    function listenUnsaved() {
        if (unsaved.listening) { return; }
        unsaved.listening = true;
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

    // ---- A form without autosave: its unsaved count and Cancel (0.76) ------------------------------
    // Folio.unsaved.count(form): how many of the form's controls differ from the page as loaded, its
    // own and those naming it with form="…" (the side panel's): text-like inputs and textareas by
    // defaultValue, checkboxes and radios by defaultChecked, selects by their options' defaultSelected.
    // Disabled controls, hidden and file inputs, and controls marked data-folio-no-edit are not counted
    // (a field whose state lives in hidden inputs counts through its visible controls only).
    // Folio.unsaved.discard(form): puts every such control back (disabled ones too), clears the form's
    // unsaved mark and sends a bubbling "folio:discard" on the form, so a field with derived state
    // (locks, previews, counters) restores itself from the restored controls.
    // form.twig renders [data-folio-unsaved-state="<form id>"] ("N unsaved changes") and
    // [data-folio-discard="<form id>"] (Cancel) beside Save on a framed form without autosave; both
    // stay hidden while the count is 0.
    function ownControlsOf(form) {
        var list = Array.prototype.slice.call(form.querySelectorAll('input, select, textarea'));
        if (form.id) {
            Array.prototype.slice.call(document.querySelectorAll('[form="' + form.id + '"]')).forEach(function (el) {
                if (list.indexOf(el) < 0) { list.push(el); }
            });
        }
        return list.filter(function (el) {
            var type = String(el.type || '').toLowerCase();
            return ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(el.tagName) >= 0
                && ['hidden', 'file', 'submit', 'button', 'reset', 'image'].indexOf(type) < 0
                && isEdit(el) && formOf(el) === form;
        });
    }

    function optionsOf(select) {
        return Array.prototype.slice.call(select.options || select.querySelectorAll('option'));
    }

    function controlChanged(el) {
        var type = String(el.type || '').toLowerCase();
        if (type === 'checkbox' || type === 'radio') { return !!el.checked !== !!el.defaultChecked; }
        if (el.tagName === 'SELECT') { return optionsOf(el).some(function (o) { return !!o.selected !== !!o.defaultSelected; }); }
        return String(el.value == null ? '' : el.value) !== String(el.defaultValue == null ? '' : el.defaultValue);
    }

    function changedCount(form) {
        if (!form) { return 0; }
        return ownControlsOf(form).filter(function (el) { return !el.disabled && controlChanged(el); }).length;
    }

    function discardForm(form) {
        if (!form) { return; }
        ownControlsOf(form).forEach(function (el) {
            var type = String(el.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                el.checked = !!el.defaultChecked;
            } else if (el.tagName === 'SELECT') {
                optionsOf(el).forEach(function (o) { o.selected = !!o.defaultSelected; });
            } else {
                el.value = el.defaultValue == null ? '' : el.defaultValue;
            }
        });
        clearDirty(form);
        form.dispatchEvent(new CustomEvent('folio:discard', { bubbles: true }));
        refreshUnsavedStates();
    }

    function refreshUnsavedStates() {
        Array.prototype.slice.call(document.querySelectorAll('[data-folio-unsaved-state]')).forEach(function (state) {
            var id = state.getAttribute('data-folio-unsaved-state');
            var n = changedCount(document.getElementById(id));
            state.hidden = n === 0;
            var text = state.querySelector('[data-folio-save-text]') || state;
            text.textContent = n === 0 ? '' : plural(folioT('unsaved_count', { count: adminNumber(n) }), n);
            Array.prototype.slice.call(document.querySelectorAll('[data-folio-discard="' + id + '"]')).forEach(function (button) {
                button.hidden = n === 0;
            });
        });
    }

    function initUnsavedStates() {
        if (!document.querySelector('[data-folio-unsaved-state]')) { return; }
        document.addEventListener('input', refreshUnsavedStates);
        document.addEventListener('change', refreshUnsavedStates);
        document.addEventListener('click', function (e) {
            var button = e.target && e.target.closest ? e.target.closest('[data-folio-discard]') : null;
            if (button) { discardForm(document.getElementById(button.getAttribute('data-folio-discard'))); }
        });
        refreshUnsavedStates();
    }

    window.Folio.unsaved.count = changedCount;
    window.Folio.unsaved.discard = discardForm;

    // ---- Leaving with unsaved changes: the styled dialog -------------------------------------------
    // In-admin navigation (a same-origin link: the sidebar, the rail, breadcrumbs, a row menu, a link a
    // shortcut clicks; a plain form that leaves, such as Log out; Folio.navigate(href)) while a guarded
    // form holds unsaved changes asks through the dialog: "Leave without saving" (danger), "Stay", and
    // for a link "Save and leave" where the form has a Save submitter ([data-folio-save]): the form is
    // saved with _folio_next=<the link's path>, and the server goes there once the save succeeds (a
    // refused save shows the form with its errors instead). An autosaving form (the record editor) is
    // not asked about: its pending edits are saved now (no 3 s wait) and the page leaves once they
    // are; only a save that fails (refused, offline, a conflict) asks, with the reason. Not guarded:
    // ⌘/Ctrl/Shift/Alt or middle clicks, target="_blank", download, #hash links on the same page, other
    // origins, and links htmx handles (they keep the form). The browser's own beforeunload question
    // stays for closing the tab, reloading, a typed address and back/forward (no page can style those);
    // a leave the dialog allowed (or a confirmed data-confirm submit) suppresses it once.
    var FLUSH_MAX_MS = 20000;
    var leaveOnce = false;
    var leaveOnceTimer = null;
    var flushing = false;

    function allowLeave() {
        leaveOnce = true;
        clearTimeout(leaveOnceTimer);
        // A navigation that never unloads (a download, a 204) must not switch the guard off for good.
        leaveOnceTimer = setTimeout(function () { leaveOnce = false; }, 5000);
    }

    /** The path a click on a link leaves for, or null when the click does not leave this page. */
    function leavingLink(e) {
        if (e.defaultPrevented || (typeof e.button === 'number' && e.button !== 0) || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return null; }
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) { return null; }
        var target = String(a.getAttribute('target') || '').toLowerCase();
        if ((target !== '' && target !== '_self') || a.hasAttribute('download')) { return null; }
        var url, here;
        try {
            url = new URL(a.getAttribute('href'), window.location.href);
            here = new URL(window.location.href);
        } catch (err) {
            return null;
        }
        if (url.origin !== here.origin || !/^https?:$/.test(url.protocol)) { return null; }
        if (url.hash !== '' && url.pathname === here.pathname && url.search === here.search) { return null; }
        return url.pathname + url.search + url.hash;
    }

    /** The autosaving form whose state is the topbar's save state (the record editor), if any. */
    function autosaveForm() {
        var stateEl = document.getElementById('folio-save-state');
        if (!stateEl) { return null; }
        var forms = document.querySelectorAll('form[hx-post]');
        for (var i = 0; i < forms.length; i++) {
            if (saveStateOf(forms[i]) === stateEl) { return forms[i]; }
        }
        return null;
    }

    function saveStateText(stateEl) {
        if (!stateEl) { return ''; }
        var label = stateEl.querySelector('[data-folio-save-text]');
        return String((label || stateEl).textContent || '').trim();
    }

    /** The enabled Save button ([data-folio-save]) that submits form, or null. */
    function saveButtonOf(form) {
        var buttons = document.querySelectorAll('[data-folio-save]');
        for (var i = 0; i < buttons.length; i++) {
            if (formOf(buttons[i]) === form && !buttons[i].disabled) { return buttons[i]; }
        }
        return null;
    }

    function setLeaveTo(form, href) {
        form.querySelectorAll('input[name="_folio_next"]').forEach(function (el) { el.remove(); });
        if (href === null) { return null; }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_folio_next';
        input.value = href;
        form.appendChild(input);
        return input;
    }

    // Save, then leave for href: the save posts _folio_next. A save the browser does not send (a
    // required field left empty) leaves nothing behind: no _folio_next, and the guard is on again.
    function saveAndLeave(form, save, href) {
        setLeaveTo(form, href.split('#')[0]); // the server takes a path and query (SafeReturnPath)
        allowLeave();
        var sent = false;
        var mark = function (e) { if (e.target === form && !e.defaultPrevented) { sent = true; } };
        window.addEventListener('submit', mark);
        try { submitWith(save); } finally { window.removeEventListener('submit', mark); }
        if (!sent) {
            setLeaveTo(form, null);
            leaveOnce = false;
        }
    }

    function askLeave(form, go, href) {
        var save = href !== null ? saveButtonOf(form) : null;
        folioConfirm({
            title: folioT('leave_title'),
            message: folioT('leave_message'),
            confirm: folioT('leave_confirm'),
            cancel: folioT('leave_stay'),
            tone: 'danger',
            extra: save ? folioT('save_and_leave') : null
        }).then(function (answer) {
            // The record's own pending edits (autosave) are saved first either way: the answer was
            // about form, not about them.
            var record = recordToFlush(form);
            if (answer === 'confirm') {
                if (record) { flushThenLeave(record, go); return; }
                allowLeave();
                go();
            } else if (answer === 'extra') {
                if (record) { flushThenLeave(record, function () { saveAndLeave(form, save, href); }); return; }
                saveAndLeave(form, save, href);
            }
        });
    }

    /** The autosaving form when it holds pending edits or a failed save, unless it is except; else null. */
    function recordToFlush(except) {
        var stateEl = document.getElementById('folio-save-state');
        if (!savePending() && !(stateEl && stateEl.classList.contains('is-conflict'))) { return null; }
        var record = autosaveForm();
        return record && record !== except ? record : null;
    }

    // The autosaving form's edits are saved now: a running autosave is waited for; else "folio:save-now"
    // (the form's second hx-trigger) sends one at once. The delayed autosave still queued is dropped
    // (onBeforeRequest). Edits made while a save ran are saved in another round. Once saved, go(); a
    // refused or failed save (or none within FLUSH_MAX_MS) asks with its reason; its go-ahead button
    // says goLabel ("Unpublish without saving" before a confirmed action), else "Leave without saving".
    function flushThenLeave(form, go, goLabel) {
        if (flushing) { return; }
        flushing = true;
        form._folioLeaving = true;
        var rounds = 0;
        var finished = false;
        var timer = null;
        var names = ['htmx:afterRequest', 'htmx:sendError', 'htmx:timeout', 'htmx:sendAbort'];
        var done = function (ok, reason) {
            if (finished) { return; }
            finished = true;
            flushing = false;
            clearTimeout(timer);
            names.forEach(function (name) { document.removeEventListener(name, onEnd); });
            if (ok) {
                allowLeave();
                go();
                return;
            }
            form._folioLeaving = false;
            folioConfirm({
                title: folioT('leave_failed_title'),
                message: reason || folioT('not_saved_offline'),
                confirm: goLabel || folioT('leave_confirm'),
                cancel: folioT('leave_stay'),
                tone: 'danger'
            }).then(function (answer) {
                if (answer === 'confirm') {
                    allowLeave();
                    go();
                }
            });
        };
        var saveNow = function () {
            rounds++;
            form.dispatchEvent(new CustomEvent('folio:save-now'));
        };
        var onEnd = function (e) {
            if (!e.detail || e.detail.elt !== form) { return; }
            var stateEl = document.getElementById('folio-save-state');
            if (e.type !== 'htmx:afterRequest' || !e.detail.successful) {
                done(false, saveStateText(stateEl));
            } else if (stateEl && stateEl.classList.contains('is-conflict')) {
                done(false, saveStateText(stateEl)); // refused (a conflict, a hook): answered 200 with the reason
            } else if (form._folioEditedSince && rounds < 3) {
                saveNow();
            } else {
                done(true);
            }
        };
        names.forEach(function (name) { document.addEventListener(name, onEnd); });
        timer = setTimeout(function () { done(false, folioT('not_saved_offline')); }, FLUSH_MAX_MS);
        var stateEl = document.getElementById('folio-save-state');
        if (!(stateEl && stateEl.classList.contains('is-saving'))) { saveNow(); }
    }

    /**
     * Whether leaving now must wait: true when it asks (the dialog) or saves first (autosave), and
     * go() runs once leaving is fine; false when nothing holds the page (go() is the caller's).
     * href: the path a link leaves for (it offers "Save and leave"), null for a submit.
     */
    function holdLeave(go, href, e) {
        if (leaveOnce) { return false; }
        for (var i = 0; i < unsaved.leaveChecks.length; i++) {
            if (unsaved.leaveChecks[i](e || null) === true) { return false; }
        }
        var dirty = unsaved.dirty.filter(function (f) { return f.isConnected !== false; });
        if (dirty.length) {
            // "Save and leave" saves one form: offered only when exactly one holds unsaved changes.
            askLeave(dirty[0], go, dirty.length === 1 ? href : null);
            return true;
        }
        // Pending edits, or a save that failed (a conflict, offline): saving again is tried first.
        var stateEl = document.getElementById('folio-save-state');
        var form = savePending() || (stateEl && stateEl.classList.contains('is-conflict')) ? autosaveForm() : null;
        if (form) {
            flushThenLeave(form, go);
            return true;
        }
        return false;
    }

    function navigateTo(href) {
        window.location.href = href;
    }

    /** Folio.navigate(href): go to an admin page as a link would, through the unsaved-changes guard. */
    function navigate(href) {
        if (!holdLeave(function () { navigateTo(href); }, href, null)) { navigateTo(href); }
    }

    // On the window: after every document listener, so links a script handles (drawers, menus, htmx)
    // have prevented their default already.
    function onLeaveClick(e) {
        var href = leavingLink(e);
        if (href === null) { return; }
        if (holdLeave(function () { navigateTo(href); }, href, e)) { e.preventDefault(); }
    }

    // A plain form that leaves the page (Log out, the interface language, a side panel action) while
    // another form holds unsaved changes. The guarded form's own submit is its save; the autosaving
    // form's own submit is Save draft or Publish; a confirmed data-confirm submit knows it leaves.
    function onLeaveSubmit(e) {
        var f = e.target;
        if (e.defaultPrevented || !f || f.tagName !== 'FORM') { return; }
        if (f._folioConfirmed) {
            // A confirmed action (Unpublish, Publish…) in the record editor: its pending autosave is saved
            // first, then the action is sent; a save that fails asks, its button naming the action
            // ("Unpublish without saving"). An action that throws the edits away (Delete, Discard the
            // draft: data-folio-discards) saves nothing first, and the queued autosave is dropped.
            var record = recordToFlush(f);
            if (record && f.hasAttribute('data-folio-discards')) {
                record._folioSubmitting = true;
            } else if (record && !f._folioFlushed) {
                e.preventDefault();
                var held = e.submitter || null;
                var action = confirmLabel(f, held) || folioT('confirm');
                flushThenLeave(record, function () {
                    f._folioFlushed = true;
                    try { resubmit(f, held); } finally { f._folioFlushed = false; }
                }, folioT('continue_without_saving', { action: action }));
                return;
            }
            allowLeave();
            return;
        }
        if (f._folioLeaveOk || f === autosaveForm()) { return; }
        var sub = e.submitter || null;
        var target = String((sub && sub.getAttribute && sub.getAttribute('formtarget')) || f.getAttribute('target') || '').toLowerCase();
        if ((target !== '' && target !== '_self') || String(f.getAttribute('method') || '').toLowerCase() === 'dialog') { return; }
        var others = unsaved.dirty.filter(function (form) { return form !== f && form.isConnected !== false; });
        if (others.length === 0 && !savePending() && !document.querySelector('#folio-save-state.is-conflict')) { return; }
        var again = function () {
            f._folioLeaveOk = true;
            try {
                if (typeof f.requestSubmit === 'function') { f.requestSubmit(sub && formOf(sub) === f ? sub : undefined); } else { HTMLFormElement.prototype.submit.call(f); }
            } finally {
                f._folioLeaveOk = false;
            }
        };
        var saved = unsaved.dirty;
        unsaved.dirty = others; // f itself is not what would be lost
        var held = holdLeave(again, null, e);
        unsaved.dirty = saved;
        if (held) { e.preventDefault(); }
    }

    window.Folio.navigate = navigate;

    // ---- Edits across the form attribute, the save state, "needs change" -------------
    // A form's controls may sit outside it and name it with form="…" (the editor's side panel). Their
    // events do not bubble to the form, so a document listener tells the form: every input, change and
    // trix-change of a control of an autosaving form (hx-post) is announced as "folio:edit" on the form,
    // which is its autosave trigger (hx-trigger="folio:edit delay:3s, folio:save-now": leaving sends
    // "folio:save-now" to save at once). Controls added later count too.
    function onFormEdit(e) {
        if (!isEdit(e.target)) { return; }
        var form = formOf(e.target);
        if (!form || !form.hasAttribute || !form.hasAttribute('hx-post')) { return; }
        form._folioEditedSince = true; // since its last autosave started (a flush saves again for it)
        form.dispatchEvent(new CustomEvent('folio:edit', { detail: { source: e.target, type: e.type } }));
    }

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

    // Edits an autosaving form has not saved yet ("Unsaved changes" during the delay, or "Saving…").
    function savePending() {
        var stateEl = document.getElementById('folio-save-state');
        return !!stateEl && (stateEl.classList.contains('is-unsaved') || stateEl.classList.contains('is-saving'));
    }
    window.Folio.unsaved.pending = savePending;

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
        if (!isEdit(e.target)) { return; }
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

    // Tabs: an invalid field in a hidden panel opens its tab, so the browser can show its message there.
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

    function onInvalidInTab(e) {
        var panel = e.target && e.target.closest ? e.target.closest('[data-folio-tabpanel]') : null;
        if (!panel || !panel.hidden) { return; }
        var tab = tabFor(panel);
        if (tab) { tab.click(); }
    }

    function initFormEdits() {
        ['input', 'change', 'trix-change'].forEach(function (name) { document.addEventListener(name, onFormEdit); });
        initNeedsChange(document);
        document.addEventListener('input', onNeedsChangeEdit);
        document.addEventListener('change', onNeedsChangeEdit);
        document.addEventListener('reset', onNeedsChangeReset);
        document.addEventListener('invalid', onInvalidInTab, true);
    }

    // What the editor frame (editor-frame.js) uses from here.
    window.Folio.formOf = formOf;
    window.Folio.isEdit = isEdit;
    // What blocks.js uses: select a block card in the preview (sticky outline with its label), and the
    // list rows' "…" menus, which the block cards reuse (data-folio-row-menu).
    window.Folio.preview = { select: function (uid) { highlight(uid, null, true, true); } };
    window.Folio.menus = { init: initRowMenus, open: openRowMenu, close: closeRowMenu };
    window.Folio.submitWith = submitWith;
    window.Folio.saveState = { set: setSaveState, of: saveStateOf };

    // ---- Settings → Translations: "Save all filled" -------------------------
    // Each row is its own form, so saving one row reloads the page over every other filled one.
    // The save-all form posts every row whose text differs from the saved one (keys[] + texts[]),
    // and the page asks before leaving while such rows are unsaved. A row the server refused
    // carries data-unsaved: its text is the posted one, not the saved one.

    // Settings → Redirects, the form (redirects/form.twig, [data-redirect-form]): "Send visitors to" as a segmented
    // (A page / An address). Both modes post the one field "target": the page mode its hidden "type:id"
    // ([data-redirect-page-value]), the address mode the text input; the other one is disabled. The page
    // search is a combobox over {prefix}/redirects/_targets ({items: [{value, label, type, url}]}): typing lists
    // the matching public pages, ArrowDown/ArrowUp move through them, Enter picks, Escape closes the list
    // (a second Escape clears the search). The pick shows as a row under the search. Without this script only the
    // address input shows, and it takes a URL, a path or "type:id".
    function initRedirectForm() {
        document.querySelectorAll('[data-redirect-form]').forEach(function (form) {
            if (form._folioRedirect) { return; }
            var kinds = form.querySelector('[data-redirect-kinds]');
            var pagePane = form.querySelector('[data-redirect-page]');
            var addressPane = form.querySelector('[data-redirect-address]');
            var pageValue = form.querySelector('[data-redirect-page-value]');
            var addressInput = form.querySelector('[data-redirect-address-input]');
            var search = form.querySelector('[data-redirect-search]');
            var list = form.querySelector('[data-redirect-results]');
            var picked = form.querySelector('[data-redirect-picked]');
            if (!kinds || !pagePane || !addressPane || !pageValue || !addressInput || !search || !list || !picked) { return; }
            form._folioRedirect = true;
            var pickedHelp = form.querySelector('[data-redirect-picked-help]');
            if (pickedHelp) { pickedHelp.hidden = true; }

            function mode() {
                var checked = Array.prototype.filter.call(kinds.querySelectorAll('input[name="target_kind"]'), function (r) { return r.checked; })[0];
                return checked ? checked.value : 'page';
            }
            function show() {
                var page = mode() === 'page';
                pagePane.hidden = !page;
                addressPane.hidden = page;
                pageValue.disabled = !page;
                addressInput.disabled = page;
            }
            kinds.hidden = false;
            // A page typed by hand ("type:id") in the address input is the page mode's pick.
            if (mode() === 'page' && pageValue.value === '' && /^[a-z0-9_-]+:[^\/]/i.test(addressInput.value) && !/^https?:/i.test(addressInput.value)) {
                pageValue.value = addressInput.value;
            }
            show();
            kinds.addEventListener('change', function () {
                show();
                (mode() === 'page' ? search : addressInput).focus();
            });

            var items = [];
            var active = -1;
            var seq = 0;
            var timer = null;
            var url = search.getAttribute('data-redirect-targets') || '';
            var optionId = function (i) { return list.id + '-' + i; };

            function close() {
                list.hidden = true;
                list.textContent = '';
                items = [];
                active = -1;
                search.setAttribute('aria-expanded', 'false');
                search.removeAttribute('aria-activedescendant');
            }
            function highlight(i) {
                active = i;
                Array.prototype.forEach.call(list.querySelectorAll('[role="option"]'), function (li, n) {
                    li.setAttribute('aria-selected', n === i ? 'true' : 'false');
                    li.classList.toggle('is-active', n === i);
                });
                if (i >= 0) {
                    search.setAttribute('aria-activedescendant', optionId(i));
                    var li = list.querySelector('#' + optionId(i));
                    if (li && li.scrollIntoView) { li.scrollIntoView({ block: 'nearest' }); }
                } else {
                    search.removeAttribute('aria-activedescendant');
                }
            }
            function pick(item) {
                pageValue.value = item.value;
                var label = picked.querySelector('[data-redirect-picked-label]');
                var meta = picked.querySelector('[data-redirect-picked-meta]');
                if (label) { label.textContent = String(item.label || item.value); }
                if (meta) { meta.textContent = [item.type, item.url].filter(Boolean).join(' · '); }
                var broken = picked.querySelector('[data-redirect-picked-broken]');
                if (broken) { broken.remove(); }
                picked.hidden = false;
                search.value = '';
                close();
                pageValue.dispatchEvent(new Event('change', { bubbles: true }));
            }
            function render(found) {
                list.textContent = '';
                items = found;
                active = -1;
                if (found.length === 0) {
                    var none = document.createElement('li');
                    none.className = 'folio-redirect-none';
                    none.setAttribute('role', 'presentation');
                    none.textContent = folioT('no_matches');
                    list.appendChild(none);
                }
                found.forEach(function (item, i) {
                    var li = document.createElement('li');
                    li.id = optionId(i);
                    li.className = 'folio-redirect-option';
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-selected', 'false');
                    li.setAttribute('data-redirect-option', item.value);
                    var label = document.createElement('span');
                    label.className = 'folio-redirect-option-label';
                    label.textContent = String(item.label || item.value);
                    var meta = document.createElement('span');
                    meta.className = 'folio-redirect-option-meta';
                    meta.textContent = [item.type, item.url].filter(Boolean).join(' · ');
                    li.appendChild(label);
                    li.appendChild(meta);
                    // mousedown: before the search loses focus.
                    li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    li.addEventListener('click', function () { pick(item); });
                    list.appendChild(li);
                });
                list.hidden = false;
                search.setAttribute('aria-expanded', 'true');
            }

            search.addEventListener('input', function () {
                var q = search.value.trim();
                clearTimeout(timer);
                if (q === '' || !window.fetch) { seq++; close(); return; }
                timer = setTimeout(function () {
                    var mine = ++seq;
                    fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                        .then(function (res) { return res.ok ? res.json() : { items: [] }; })
                        .then(function (data) {
                            if (mine !== seq) { return; }
                            render(((data && data.items) || []).filter(function (item) { return item && typeof item.value === 'string'; }));
                        })
                        .catch(function () {});
                }, 200);
            });
            search.addEventListener('keydown', function (e) {
                var open = !list.hidden && items.length > 0;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    if (!open) { return; }
                    e.preventDefault();
                    var n = items.length;
                    highlight(e.key === 'ArrowDown' ? (active + 1) % n : (active <= 0 ? n - 1 : active - 1));
                } else if (e.key === 'Enter') {
                    // Never sends the form from the search: Enter picks the highlighted page (or the only one).
                    e.preventDefault();
                    if (open && (active >= 0 || items.length === 1)) { pick(items[active >= 0 ? active : 0]); }
                } else if (e.key === 'Escape') {
                    if (!list.hidden) {
                        e.preventDefault();
                        e.stopPropagation();
                        close();
                    } else if (search.value !== '') {
                        e.preventDefault();
                        e.stopPropagation();
                        search.value = '';
                    }
                }
            });
            search.addEventListener('blur', function () { setTimeout(close, 150); });
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

    // ---- Settings → Translations ------------------------------------------------------------
    // Without script every row form saves its own text (its Save button) and Reset posts the row's reset
    // form. With script the row Save buttons hide and the sticky bar ([data-translations-save-all], the
    // save-all form) counts the changed fields: "N unsaved changes · in English · Discard · Save all".
    // Save all posts every changed field (keys[] / texts[] pairs), rows hidden from view included, so
    // nothing typed is lost; Enter in a field does the same. Discard puts every field back: its saved
    // text (data-saved after a refused save, else its defaultValue). A field counts as changed when it
    // differs from its default or holds a refused text (data-unsaved).
    // Leaving: the bar's form is guarded by Folio.unsaved (dirty while a field is changed), so in-admin
    // links, the language links, "Changed only" and the toolbar's search ask through the styled dialog,
    // whose "Save and leave" is Save all with _folio_next; the browser's own question stays for a tab
    // close. A row's Reset is no leave when that row holds the only change (its edit goes with it).
    // The group select sends the toolbar form itself (its Search button hides; Enter sends the search).
    function initTranslations() {
        var bar = document.querySelector('[data-translations-save-all]');
        var table = document.querySelector('.folio-translations');
        if (!bar || !table) { return; }
        var count = bar.querySelector('[data-translations-count]');
        var save = bar.querySelector('[data-folio-save]');
        var filter = document.querySelector('[data-translations-filter]');
        var submitted = null;
        var fields = function () {
            return Array.prototype.slice.call(table.querySelectorAll('input[data-ai-path], textarea[data-ai-path]'));
        };
        var isChanged = function (el) { return el.value !== el.defaultValue || el.hasAttribute('data-unsaved'); };
        var changed = function () { return fields().filter(isChanged); };
        var refresh = function () {
            fields().forEach(function (el) {
                var row = el.closest('tr');
                if (row) { row.classList.toggle('is-edited', isChanged(el)); }
            });
            var n = changed().length;
            bar.hidden = n === 0;
            if (count && n > 0) { count.textContent = plural(folioT('translations_unsaved', { count: adminNumber(n) }), n); }
            if (n > 0) { markDirty(bar); } else { clearDirty(bar); }
            // After a refused save: "N texts were not saved" goes once nothing refused (or changed) is left.
            if (n === 0) { document.querySelectorAll('[data-translations-refused]').forEach(function (el) { el.remove(); }); }
        };

        table.querySelectorAll('[data-translations-row-save]').forEach(function (b) { b.hidden = true; });
        bar.querySelectorAll('[data-translations-discard]').forEach(function (b) { b.hidden = false; });
        unsaved.guarded.push(bar);
        listenUnsaved();

        table.addEventListener('input', refresh);
        table.addEventListener('change', refresh);
        table.addEventListener('keydown', function (e) {
            var t = e.target;
            if (e.key !== 'Enter' || e.isComposing || !t || t.tagName !== 'INPUT' || !t.hasAttribute('data-ai-path')) { return; }
            e.preventDefault();
            if (changed().length > 0 && save) { submitWith(save); }
        });
        bar.addEventListener('submit', function () {
            bar.querySelectorAll('[data-save-all-row]').forEach(function (el) { el.remove(); });
            changed().forEach(function (el) {
                [['keys[]', el.getAttribute('data-ai-path')], ['texts[]', el.value]].forEach(function (pair) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = pair[0];
                    input.value = pair[1];
                    input.setAttribute('data-save-all-row', '');
                    bar.appendChild(input);
                });
            });
        });
        bar.addEventListener('click', function (e) {
            if (!e.target || !e.target.closest || !e.target.closest('[data-translations-discard]')) { return; }
            changed().forEach(function (el) {
                var saved = el.getAttribute('data-saved');
                if (saved !== null) { el.defaultValue = saved; }
                el.value = el.defaultValue;
                el.removeAttribute('data-unsaved');
                var form = formOf(el);
                var error = form ? form.querySelector('.form-error') : null;
                if (error) { error.remove(); }
            });
            refresh();
        });

        if (filter) {
            filter.querySelectorAll('[data-translations-apply]').forEach(function (b) { b.hidden = true; });
            filter.addEventListener('change', function (e) {
                if (!e.target || e.target.tagName !== 'SELECT') { return; }
                if (typeof filter.requestSubmit === 'function') { filter.requestSubmit(); } else { filter.submit(); }
            });
        }

        // A row's own form (its Reset, or its Save without script) takes only that row's edit with it.
        // On the window, after onLeaveSubmit (boot order): a submit the leave dialog held (and the person
        // stayed) is defaultPrevented and not recorded, so a later tab close still asks.
        window.addEventListener('submit', function (e) {
            submitted = e.defaultPrevented ? null : e.target;
        });
        window.Folio.unsaved.onLeave(function (e) {
            var f = e && e.type === 'submit' ? e.target : (e && e.type === 'beforeunload' ? submitted : null);
            var key = f && f.querySelector ? f.querySelector('input[name="key"]') : null;
            if (!key || f === bar) { return false; }
            var list = changed();
            return list.length > 0 && list.every(function (el) { return el.getAttribute('data-ai-path') === key.value; });
        });
        refresh();
    }

    // A count in the interface language's digits and separators (<html lang>, as folio_number formats).
    function adminNumber(n) {
        try { return new Intl.NumberFormat(document.documentElement.getAttribute('lang') || 'en').format(n); } catch (e) { return String(n); }
    }

    // "one|other" (lang files' two forms, PluralResolver's simple case): the first for 1, else the second.
    function plural(text, n) {
        var forms = String(text).split('|');
        return n === 1 || forms.length < 2 ? forms[0] : forms[1];
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
        if (root.hasAttribute && root.hasAttribute('data-block-card')) { notifyChange(root); }
        root.querySelectorAll('[data-folio-focal]').forEach(placeFocalMarker);
        initTabs(root);
        initNeedsChange(root);
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

    // Copy a value (an asset's swatches and file address, a record's id): <button data-copy="…">. It says
    // "Copied" in the toast; where the browser refuses, nothing claims a copy.
    function onDataCopyClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
        if (!btn) { return; }
        copyLater(btn.getAttribute('data-copy')).then(function (copied) {
            if (!copied) { return; }
            btn.classList.add('is-copied');
            setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
            showToast(folioT('copied'), true);
        });
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

    // Leaving (flushThenLeave): the delayed autosave still queued is dropped; the flush sends its own.
    function onBeforeRequest(e) {
        var elt = e.detail && e.detail.elt;
        if (!elt) { return; }
        var config = e.detail.requestConfig || {};
        var trigger = config.triggeringEvent || e.detail.triggeringEvent;
        if (elt._folioSubmitting || (elt._folioLeaving && trigger && trigger.type === 'folio:edit')) {
            e.preventDefault();
            return;
        }
        if (elt.tagName === 'FORM' && elt.hasAttribute && elt.hasAttribute('hx-post')) { elt._folioEditedSince = false; }
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

    // Asset page: click the image to set the focal point (percent of width/height). The marker and the crop
    // previews follow it: the panel carries it as --folio-fx / --folio-fy (admin.css cuts each preview box
    // around it), and the previews show only once this script keeps them right.
    function focalPanel(picker) {
        return picker.closest('.folio-asset-panel') || picker.closest('section');
    }

    function focalField(panel, name) {
        var form = panel ? panel.querySelector('.folio-asset-form') : null;
        return form ? form.querySelector('[name="' + name + '"]') : null;
    }

    function placeFocalMarker(picker) {
        var panel = focalPanel(picker);
        var fx = focalField(panel, 'focal_x');
        var fy = focalField(panel, 'focal_y');
        var marker = picker.querySelector('.folio-focal-marker');
        if (!fx || !fy || !marker) { return; }
        marker.style.left = fx.value + '%';
        marker.style.top = fy.value + '%';
        if (panel.style && typeof panel.style.setProperty === 'function') {
            panel.style.setProperty('--folio-fx', fx.value + '%');
            panel.style.setProperty('--folio-fy', fy.value + '%');
        }
        panel.querySelectorAll('[data-folio-crops]').forEach(function (crops) { crops.hidden = false; });
    }

    function onFocalClick(e) {
        var picker = e.target.closest ? e.target.closest('[data-folio-focal]') : null;
        if (!picker) { return; }
        var panel = focalPanel(picker);
        var fx = focalField(panel, 'focal_x');
        var fy = focalField(panel, 'focal_y');
        var rect = picker.getBoundingClientRect();
        if (!fx || !fy || rect.width === 0 || rect.height === 0) { return; }
        fx.value = String(Math.round(Math.min(100, Math.max(0, (e.clientX - rect.left) / rect.width * 100))));
        fy.value = String(Math.round(Math.min(100, Math.max(0, (e.clientY - rect.top) / rect.height * 100))));
        placeFocalMarker(picker);
        // A changed focal point is an unsaved edit like a typed one (the unsaved guard listens for change).
        fx.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Asset page, Replace file ([data-folio-replace]): a plain upload form without script. With it the file
    // input hides inside its drop zone (a label, so a click still opens the dialog) and so does the button: a
    // chosen file, or the first one dropped on the form, goes to the replace route at once. While the Details
    // form holds unsaved changes it asks first (folioConfirm, two ways: "Replace anyway", which drops those
    // changes, or Cancel; saving first is the person's own Save, as a save-then-replace would need two posts).
    // Back from the back/forward cache, the zone is idle again (pageshow).
    function initReplace(root) {
        root.querySelectorAll('[data-folio-replace]').forEach(function (form) {
            form.classList.add('is-live');
            form.querySelectorAll('[data-folio-replace-submit]').forEach(function (b) { b.hidden = true; });
        });
    }

    function sendReplace(form) {
        if (form.classList.contains('is-uploading')) { return; }
        var details = document.querySelector('form.folio-asset-form');
        var go = function () {
            if (details) { clearDirty(details); } // the replace leaves the page: its changes go with it
            form.classList.add('is-uploading');
            form.setAttribute('aria-busy', 'true');
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
        };
        if (!details || unsaved.dirty.indexOf(details) < 0) { go(); return; }
        folioConfirm({
            title: folioT('replace_unsaved_title'),
            message: folioT('replace_unsaved'),
            confirm: folioT('replace_anyway'),
            tone: 'danger',
            icon: 'ui-warn'
        }).then(function (answer) {
            if (answer === 'confirm') { go(); return; }
            var input = form.querySelector('[data-folio-replace-file]');
            if (input) { try { input.value = ''; } catch (err) { /* read-only in some browsers */ } }
        });
    }

    function resetReplace() {
        document.querySelectorAll('[data-folio-replace]').forEach(function (form) {
            form.classList.remove('is-uploading', 'is-dragover');
            form.removeAttribute('aria-busy');
        });
    }

    function onReplaceChange(e) {
        var input = e.target;
        if (!input || !input.matches || !input.matches('[data-folio-replace-file]')) { return; }
        var form = input.closest('[data-folio-replace].is-live');
        if (form && input.files && input.files.length) { sendReplace(form); }
    }

    function replaceDropForm(e) {
        var form = e.target && e.target.closest ? e.target.closest('[data-folio-replace].is-live') : null;
        return form && draggedFiles(e) ? form : null;
    }

    function onReplaceDragOver(e) {
        var form = replaceDropForm(e);
        if (!form) { return; }
        e.preventDefault();
        if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; }
        form.classList.add('is-dragover');
    }

    function onReplaceDragLeave(e) {
        var form = replaceDropForm(e);
        if (form && (!e.relatedTarget || !form.contains(e.relatedTarget))) { form.classList.remove('is-dragover'); }
    }

    function onReplaceDrop(e) {
        var form = replaceDropForm(e);
        if (!form) { return; }
        e.preventDefault();
        form.classList.remove('is-dragover');
        var files = e.dataTransfer && e.dataTransfer.files;
        var input = form.querySelector('[data-folio-replace-file]');
        if (!input || !files || !files.length) { return; }
        try {
            if (files.length > 1 && typeof window.DataTransfer === 'function') {
                var one = new window.DataTransfer(); // the route takes one file: the first
                one.items.add(files[0]);
                input.files = one.files;
            } else {
                input.files = files;
            }
        } catch (err) {
            return; // a browser that does not let a script set the files: the dialog still works
        }
        sendReplace(form);
    }

    // Asset picker (inside the drawer iframe): hand the chosen asset to the editor.
    function onPickClick(e) {
        var btn = e.target.closest ? e.target.closest('[data-folio-pick]') : null;
        if (!btn || window.parent === window) { return; }
        window.parent.postMessage({ source: 'folio-assets', multiple: btn.getAttribute('data-multiple') === '1', items: [{
            id: btn.getAttribute('data-id'),
            thumb: btn.getAttribute('data-thumb') || '',
            label: btn.getAttribute('data-label') || '',
            meta: btn.getAttribute('data-meta') || '',
            warn: btn.getAttribute('data-warn') === '1',
            focal: btn.getAttribute('data-focal') || ''
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

    // The list's relation filter: a [data-folio-list-relation] text input that queries
    // {prefix}/_relation/{type} ({items: [{id, label}], more}) as the user types. Delegated, so
    // swapped partials keep working. (The editor's relation field is fields.js's, since 0.75.)
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
        var box = e.target.closest ? e.target.closest('[data-folio-list-relation]') : null;
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
        var box = e.target.closest ? e.target.closest('[data-folio-list-relation]') : null;
        if (!box) { return; }
        if (e.key === 'Enter') { e.preventDefault(); }
        if (e.key === 'Escape') { relationResults(box).hidden = true; }
    }

    function onRelationClick(e) {
        var t = e.target.closest ? e.target : null;
        if (!t) { return; }
        var more = t.closest('[data-folio-relation-more]');
        if (more && !more.closest('[data-folio-relation]')) {
            var scope = more.closest('.folio-filter-field') || more.parentNode.parentNode.parentNode;
            var moreBox = scope.querySelector('[data-folio-list-relation]');
            if (moreBox) {
                relationFetch(moreBox, moreBox.value.trim(), parseInt(more.getAttribute('data-page') || '2', 10), true);
            }
            return;
        }
        var pick = t.closest('[data-folio-relation-pick]');
        if (pick && !pick.closest('[data-folio-relation]')) {
            var list = pick.closest('[data-folio-relation-results]');
            var id = pick.getAttribute('data-id') || '';
            var text = pick.textContent || id;
            var fb = list.parentNode.querySelector('[data-folio-list-relation]');
            if (fb && fb._folioRelationHidden) {
                fb._folioRelationHidden.value = id;
                fb.value = text;
                fb._folioRelationPicked = text;
            }
            list.hidden = true;
            list.innerHTML = '';
            return;
        }
        document.querySelectorAll('[data-folio-relation-results]').forEach(function (l) {
            if (!l.closest('[data-folio-relation]') && !l.parentNode.contains(t)) { l.hidden = true; }
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
        var ticked = items.filter(function (c) { return c.checked; });
        var n = ticked.length;
        bar.hidden = n === 0;
        // [data-folio-bulk-one="attr"]: a link for exactly one ticked box, to that box's attr (the library's Download).
        bar.querySelectorAll('[data-folio-bulk-one]').forEach(function (el) {
            var url = n === 1 ? (ticked[0].getAttribute(el.getAttribute('data-folio-bulk-one')) || '') : '';
            el.hidden = url === '';
            el.setAttribute('href', url || '#');
        });
        // [data-folio-bulk-when="selector"]: shown while a ticked box matches (Edit alt text: an image is ticked).
        bar.querySelectorAll('[data-folio-bulk-when]').forEach(function (el) {
            var when = el.getAttribute('data-folio-bulk-when');
            el.hidden = !ticked.some(function (c) { return c.matches(when); });
        });
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

    // [data-folio-bulk-drawer="url"] in the bar: opens url in the drawer with the ticked ids[] (only those matching
    // its data-folio-bulk-when) and _drawer=1, e.g. the library's "Edit alt text".
    function onBulkDrawer(e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-folio-bulk-drawer]') : null;
        var bar = t ? t.closest('[data-folio-bulk]') : null;
        if (!t || !bar) { return; }
        var when = t.getAttribute('data-folio-bulk-when');
        var ids = bulkItems(bar).filter(function (c) { return c.checked && (!when || c.matches(when)); }).map(function (c) {
            return 'ids%5B%5D=' + encodeURIComponent(c.value);
        });
        if (ids.length === 0) { return; }
        var url = t.getAttribute('data-folio-bulk-drawer');
        openDrawer(url + (url.indexOf('?') === -1 ? '?' : '&') + ids.join('&') + '&_drawer=1');
    }

    // The names a data-confirm-selected button asks about: the data-label of each ticked box of its form.
    function selectedLabels(src) {
        var form = src && src.hasAttribute && src.hasAttribute('data-confirm-selected') ? formOf(src) : null;
        if (!form || !form.id) { return []; }
        return Array.prototype.filter.call(document.querySelectorAll('[data-folio-bulk-item]'), function (c) {
            return c.checked && c.getAttribute('form') === form.id;
        }).map(function (c) { return c.getAttribute('data-label') || c.value; });
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
        var canCopy = typeof window.fetch === 'function' && typeof navigator !== 'undefined' && !!(navigator.clipboard && (navigator.clipboard.writeText || navigator.clipboard.write));
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

    // ---- Copying text that is still on its way --------------------------------------
    // Folio.copyLater(textPromise) → Promise<boolean>. Call it inside the click: the clipboard write
    // starts there, with the text as a promise (ClipboardItem), so browsers that allow a copy only
    // during the user's gesture (Safari) still take a text that a request delivers later. Where that
    // API is missing it waits for the text and uses writeText. Resolves true when the text is on the
    // clipboard, false when there was no text or the browser refused.
    function copyLater(textPromise) {
        var text = Promise.resolve(textPromise).then(function (t) {
            if (typeof t !== 'string' || t === '') { throw new Error('nothing to copy'); }
            return t;
        });
        var clip = typeof navigator !== 'undefined' ? navigator.clipboard : null;
        function viaWriteText() {
            return text.then(function (t) {
                if (!clip || typeof clip.writeText !== 'function') { return false; }
                return clip.writeText(t).then(function () { return true; });
            }).catch(function () { return false; });
        }
        if (clip && typeof clip.write === 'function' && typeof window.ClipboardItem === 'function' && typeof window.Blob === 'function') {
            var started;
            try {
                var blob = text.then(function (t) { return new window.Blob([t], { type: 'text/plain' }); });
                blob.catch(function () {}); // reported through the write
                started = clip.write([new window.ClipboardItem({ 'text/plain': blob })]);
            } catch (e) {
                started = null;
            }
            if (started && typeof started.then === 'function') {
                return started.then(function () { return true; }, function () {
                    return text.then(function () { return viaWriteText(); }, function () { return false; });
                });
            }
        }
        return viaWriteText();
    }
    window.Folio.copyLater = copyLater;

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
        var message = null;
        var url = fetch(btn.getAttribute('data-folio-copy-link'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.url) { message = data && data.message ? data.message : null; return null; }
                return data.url;
            });
        copyLater(url).then(function (copied) {
            showToast(copied ? folioT('link_copied') : (message || folioT('link_not_copied')), copied);
        });
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
    window.Folio.shortcuts = { add: addShortcut, show: showShortcuts, isTyping: isTyping, isMac: isMac, modHint: modHint };

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
        navigate(link.getAttribute('href'));
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

    // ---- The asset library (assets/index.twig, 0.76) -----------------------------------------
    // The page works without this script: a GET toolbar, the upload form, the drop zone as a label for its
    // file input, and the selection bar (Move to bin). With it, on [data-folio-assets][data-folio-assets-upload-url]:
    // the upload form hides and the header's Upload ([data-folio-assets-choose]) opens its file input; files chosen
    // there or dropped on the library go one by one to the upload route asking for JSON (as an asset field's drop),
    // then the library is fetched again and swapped in place ([data-folio-assets] and the title's count), or
    // reloaded where that cannot be done. What the route refused is listed in [data-folio-assets-errors]. A changed
    // filter or "Unused only" submits the toolbar, so its Filter button hides unless a filter has its own editor.
    // A saved "Edit alt text" drawer (folio:drawer-saved, type asset-alt) refreshes the library too.
    function assetsRoot() {
        return document.querySelector('[data-folio-assets]');
    }

    function initAssetLibrary() {
        var root = assetsRoot();
        if (!root || root.hasAttribute('data-folio-assets-ready')) { return; }
        root.setAttribute('data-folio-assets-ready', '');
        var toolbar = root.querySelector('[data-folio-assets-toolbar]');
        var submit = toolbar ? toolbar.querySelector('[data-folio-assets-submit]') : null;
        if (submit && !toolbar.querySelector('.folio-assets-filter')) { submit.hidden = true; }
        if (!root.hasAttribute('data-folio-assets-upload-url') || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') { return; }
        var form = root.querySelector('[data-folio-assets-upload]');
        if (form) { form.hidden = true; }
        root.setAttribute('data-folio-assets-live', ''); // uploads go through uploadAssets() now
        document.querySelectorAll('[data-folio-assets-choose]').forEach(function (b) { b.hidden = false; });
    }

    function onAssetsToolbarChange(e) {
        var t = e.target;
        // The toolbar's own selects and boxes ("Unused only"); a filter with its own editor (.folio-assets-filter) waits for Filter.
        if (!t || !t.matches || !t.matches('select, input[type="checkbox"]') || t.closest('.folio-assets-filter')) { return; }
        var form = t.closest('[data-folio-assets-toolbar]');
        if (!form) { return; }
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    }

    function assetsInput(root) {
        return root ? root.querySelector('[data-folio-assets-files]') : null;
    }

    // The header's Upload, and the drop zone (a label for the input, which sits in the hidden form now): the file dialog.
    function onAssetsChooseClick(e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-folio-assets-choose], [data-folio-assets-live] [data-folio-assets-drop]') : null;
        var root = assetsRoot();
        var input = t && root && root.hasAttribute('data-folio-assets-live') ? assetsInput(root) : null;
        if (!input || root.classList.contains('is-uploading')) { return; }
        e.preventDefault();
        input.click();
    }

    function onAssetsFileChange(e) {
        var input = e.target;
        if (!input || !input.matches || !input.matches('[data-folio-assets-files]')) { return; }
        var root = input.closest('[data-folio-assets]');
        if (!root || !root.hasAttribute('data-folio-assets-live')) { return; }
        var files = Array.prototype.slice.call(input.files || []);
        input.value = '';
        uploadAssets(root, files);
    }

    function draggedFiles(e) {
        var types = e.dataTransfer && e.dataTransfer.types;
        return !!types && Array.prototype.indexOf.call(types, 'Files') >= 0;
    }

    function assetsDropRoot(e) {
        var root = e.target && e.target.closest ? e.target.closest('[data-folio-assets][data-folio-assets-live]') : null;
        return root && draggedFiles(e) ? root : null;
    }

    function onAssetsDragOver(e) {
        var root = assetsDropRoot(e);
        if (!root) { return; }
        e.preventDefault();
        if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; }
        root.classList.add('is-dragover');
    }

    function onAssetsDragLeave(e) {
        var root = assetsDropRoot(e);
        if (root && (!e.relatedTarget || !root.contains(e.relatedTarget))) { root.classList.remove('is-dragover'); }
    }

    function onAssetsDrop(e) {
        var root = assetsDropRoot(e);
        if (!root) { return; }
        e.preventDefault();
        root.classList.remove('is-dragover');
        uploadAssets(root, Array.prototype.slice.call((e.dataTransfer && e.dataTransfer.files) || []));
    }

    /** Posts each file to the upload route (JSON), then refreshes the library; resolves with the refusals. */
    function uploadAssets(root, files) {
        if (!files.length || root.classList.contains('is-uploading')) { return Promise.resolve([]); }
        var url = root.getAttribute('data-folio-assets-upload-url');
        var token = document.querySelector('input[name="_csrf_token"]');
        var status = root.querySelector('[data-folio-assets-drop-status]');
        var idle = status ? status.textContent : '';
        var failed = root.getAttribute('data-text-failed') || folioT('fields.upload_failed');
        var errors = [];
        var done = 0;
        root.classList.add('is-uploading');
        root.setAttribute('aria-busy', 'true');
        var say = function () {
            if (!status) { return; }
            status.textContent = (root.getAttribute('data-text-uploading') || '').split(':done').join(String(done + 1)).split(':total').join(String(files.length));
        };
        var next = function (i) {
            if (i >= files.length) { return Promise.resolve(); }
            done = i;
            say();
            var body = new FormData();
            body.append('files[]', files[i], files[i].name);
            if (token) { body.append('_csrf_token', token.value); }
            return window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (res) {
                    var json = String((res.headers && res.headers.get && res.headers.get('Content-Type')) || '').indexOf('application/json') >= 0;
                    if (!json || (res.status !== 200 && res.status !== 422)) { errors.push(files[i].name + ': ' + failed); return; }
                    return res.json().then(function (data) {
                        var list = data && Array.isArray(data.errors) ? data.errors.map(String) : [];
                        if (res.status === 422 && !list.length) { list = [files[i].name + ': ' + failed]; }
                        errors.push.apply(errors, list);
                    }, function () { errors.push(files[i].name + ': ' + failed); });
                }, function () { errors.push(files[i].name + ': ' + failed); })
                .then(function () { return next(i + 1); });
        };
        return next(0).then(function () {
            root.classList.remove('is-uploading');
            root.removeAttribute('aria-busy');
            if (status) { status.textContent = idle; }
            showAssetErrors(errors);
            return refreshAssets().then(function () { return errors; });
        });
    }

    function showAssetErrors(errors) {
        var box = document.querySelector('[data-folio-assets-errors]');
        if (!box) { return; }
        var list = box.querySelector('ul');
        if (list) {
            list.innerHTML = '';
            errors.forEach(function (text) {
                var li = document.createElement('li');
                li.textContent = text;
                list.appendChild(li);
            });
        }
        box.hidden = errors.length === 0;
    }

    /** Fetches the library page again and swaps it in (the grid, the toolbar's counts, the title's count). */
    function refreshAssets() {
        var root = assetsRoot();
        var reload = function () { if (window.location && typeof window.location.reload === 'function') { window.location.reload(); } };
        if (!root || typeof window.DOMParser !== 'function' || typeof window.fetch !== 'function') { reload(); return Promise.resolve(); }
        return window.fetch(window.location.href, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.text();
            })
            .then(function (html) {
                var page = new window.DOMParser().parseFromString(html, 'text/html');
                var fresh = page.querySelector('[data-folio-assets]');
                if (!fresh) { throw new Error('no library'); }
                var current = assetsRoot();
                current.parentNode.replaceChild(document.importNode(fresh, true), current);
                var count = document.querySelector('[data-folio-assets-count]');
                var freshCount = page.querySelector('[data-folio-assets-count]');
                if (count && freshCount) { count.textContent = freshCount.textContent; count.hidden = freshCount.hidden; }
                initAssetLibrary();
                initBulk(document);
            })
            .catch(reload);
    }

    function onAssetsDrawerSaved(e) {
        if (e && e.detail && e.detail.type === 'asset-alt' && assetsRoot()) { refreshAssets(); }
    }

    window.Folio = window.Folio || {};
    window.Folio.uploadAssets = function (files) { var root = assetsRoot(); return root ? uploadAssets(root, Array.prototype.slice.call(files || [])) : Promise.resolve([]); };

    function boot() {
        document.querySelectorAll('[data-folio-list-form]').forEach(initList);
        document.addEventListener('submit', onSubmitConfirm);
        document.addEventListener('submit', onDeletePlanSubmit);
        document.addEventListener('submit', onRemovalSubmit);
        document.addEventListener('htmx:confirm', onHtmxConfirm);
        // The leave guard on the window: after every document listener (see onLeaveClick).
        window.addEventListener('click', onLeaveClick);
        window.addEventListener('submit', onLeaveSubmit);
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
        document.addEventListener('click', onBulkDrawer);
        initAssetLibrary();
        document.addEventListener('change', onAssetsToolbarChange);
        document.addEventListener('change', onAssetsFileChange);
        document.addEventListener('click', onAssetsChooseClick);
        document.addEventListener('dragover', onAssetsDragOver);
        document.addEventListener('dragleave', onAssetsDragLeave);
        document.addEventListener('drop', onAssetsDrop);
        document.addEventListener('folio:drawer-saved', onAssetsDrawerSaved);
        document.addEventListener('folio:drawer-saved', onRedirectDrawerSaved);
        document.addEventListener('click', onDrawerLinkClick);
        document.addEventListener('keydown', onDrawerEscape);
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
        initReplace(document);
        window.addEventListener('pageshow', resetReplace);
        document.addEventListener('change', onReplaceChange);
        document.addEventListener('dragover', onReplaceDragOver);
        document.addEventListener('dragleave', onReplaceDragLeave);
        document.addEventListener('drop', onReplaceDrop);
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
        document.addEventListener('keydown', onKeydown, true);
        initTabs(document);
        document.addEventListener('input', onRelationSearchInput);
        document.addEventListener('focusin', onRelationFocusIn);
        document.addEventListener('keydown', onRelationKeydown);
        document.addEventListener('click', onRelationClick);
        initSplit();
        initUnsavedGuard();
        initUnsavedStates();
        initFormEdits();
        initTranslations();
        initRedirectForm();
        initNavSections();
        initUserMenus();
        initShellRail();
        // After the menus' own keys (Escape, arrows), which mark their events handled.
        initShortcuts();
        initListShortcuts();
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
