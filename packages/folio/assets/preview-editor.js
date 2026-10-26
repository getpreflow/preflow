/* Folio preview editor bridge: runs only inside the admin's preview iframe
   (injected by PreviewController, never on the public site). Clicking the page
   asks the editor to open that block or field; the editor asks back to
   highlight what has focus in the form. Messages are same-origin only.
   Outlines live in an overlay layer of their own: the site's elements get no
   classes, styles or children, so the preview lays out exactly like the page. */
(function () {
    'use strict';

    if (window.parent === window) { return; }

    var overlay = document.createElement('div');
    overlay.id = 'folio-pe-overlay';
    overlay.className = 'folio-pe-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    var hoverBox = box('is-hover');
    var label = document.createElement('span');
    label.className = 'folio-pe-label';
    hoverBox.appendChild(label);
    var activeBox = box('is-active');
    var fieldBox = box('is-field');
    var hovered = null;
    var hoveredField = null;
    var active = null;
    var frame = 0;

    // The editor's word for an unnamed block, from the admin page's dictionary (same origin; folio.js.block).
    var blockWordText = null;
    function blockWord() {
        if (blockWordText === null) {
            blockWordText = '';
            try {
                var el = window.parent.document.getElementById('folio-i18n');
                var texts = el ? JSON.parse(el.textContent || '{}') : {};
                blockWordText = typeof texts.block === 'string' ? texts.block : '';
            } catch (e) { blockWordText = ''; }
        }
        return blockWordText;
    }

    function box(state) {
        var b = document.createElement('div');
        b.className = 'folio-pe-box ' + state;
        b.hidden = true;
        overlay.appendChild(b);
        return b;
    }

    function place(b, el) {
        if (!el || !el.isConnected) { b.hidden = true; return; }
        var r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) { b.hidden = true; return; }
        b.hidden = false;
        b.style.transform = 'translate(' + r.left + 'px,' + r.top + 'px)';
        b.style.width = r.width + 'px';
        b.style.height = r.height + 'px';
    }

    function update() {
        frame = 0;
        place(hoverBox, hovered);
        place(activeBox, active);
        place(fieldBox, hoveredField);
    }

    function schedule() {
        if (!frame) { frame = window.requestAnimationFrame(update); }
    }

    function send(msg) {
        window.parent.postMessage(Object.assign({ source: 'folio-preview' }, msg), window.location.origin);
    }

    // Hover: outline the innermost block and show its label.
    // Hover: outline the innermost block, show its label, and underline the
    // field target (folio_editable) under the pointer: a click opens that field.
    document.addEventListener('mouseover', function (e) {
        var block = e.target.closest ? e.target.closest('[data-folio-block]') : null;
        var field = e.target.closest ? e.target.closest('[data-folio-edit]') : null;
        if (field && field.closest('[data-folio-block]') !== block) { field = null; }
        if (block === hovered && field === hoveredField) { return; }
        hovered = block;
        hoveredField = field;
        label.textContent = block ? (block.getAttribute('data-folio-label') || blockWord()) : '';
        schedule();
    });
    document.addEventListener('mouseleave', function () { hovered = null; hoveredField = null; schedule(); });

    // Click: select instead of following links or pressing buttons.
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        var block = e.target.closest('[data-folio-block]');
        var edit = e.target.closest('[data-folio-edit]');
        var region = e.target.closest('[data-folio-field]');
        if (!block && !region) {
            // Header, footer, pages without markers: stay on the preview.
            var link = e.target.closest('a[href]');
            if (link && (link.getAttribute('href') || '').charAt(0) !== '#') { e.preventDefault(); }
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        if (block) {
            var field = edit && block.contains(edit) && edit.closest('[data-folio-block]') === block
                ? edit.getAttribute('data-folio-edit')
                : null;
            send({ type: 'select', uid: block.getAttribute('data-folio-block'), field: field });
        } else {
            send({ type: 'select', uid: null, field: region.getAttribute('data-folio-field') });
        }
    }, true);

    // Forms inside the preview never submit.
    document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

    function find(uid, field) {
        if (uid) {
            return document.querySelector('[data-folio-block="' + CSS.escape(uid) + '"]');
        }
        return field ? document.querySelector('[data-folio-field="' + CSS.escape(field) + '"]') : null;
    }

    function highlight(uid, field, scroll) {
        active = find(uid, field);
        if (active && scroll) {
            var r = active.getBoundingClientRect();
            if (r.bottom < 0 || r.top > window.innerHeight) {
                active.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
        schedule();
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin) { return; }
        var data = e.data;
        if (!data || data.source !== 'folio-editor') { return; }
        if (data.type === 'highlight') {
            highlight(data.uid || null, data.field || null, !!data.scroll);
        }
    });

    window.addEventListener('scroll', schedule, true);
    window.addEventListener('resize', schedule);
    // Blocks patched in place, images loading, layers toggling: keep the boxes on target.
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, attributes: true, characterData: true });

    document.body.appendChild(overlay);
    send({ type: 'ready' });
})();
