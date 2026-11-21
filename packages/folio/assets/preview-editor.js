/* Folio preview editor bridge: runs only inside the admin's preview iframe
   (injected by PreviewController, never on the public site). Clicking the page
   asks the editor to open that block or field; the editor asks back to
   highlight what has focus in the form: the block card selected there stays outlined, with its
   label, until another card is selected (a "sticky" highlight); a top-level field is outlined while
   it has the focus. Messages are same-origin only.
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
    var selectedBox = box('is-selected');
    var selectedLabel = document.createElement('span');
    selectedLabel.className = 'folio-pe-label';
    selectedBox.appendChild(selectedLabel);
    var fieldBox = box('is-field');
    var hovered = null;
    var hoveredField = null;
    var active = null;
    var selected = null;
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
        place(selectedBox, selected);
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

    // Interactive parts switched off (the server marks it on the preview bar: the site's key can't
    // sign a preview token, so its requests would run on the live site): every click on something
    // interactive, every form and every htmx request is stopped, and an attempt says so.
    var lockedMark = document.querySelector('[data-folio-preview-locked]');
    var locked = !!lockedMark;
    var notice = null;
    var noticeTimer = 0;
    var INTERACTIVE = 'a[href],button,input,select,textarea,summary,label,[role=button],[onclick],'
        + '[hx-get],[hx-post],[hx-put],[hx-patch],[hx-delete],[data-hx-get],[data-hx-post],[data-hx-put],[data-hx-patch],[data-hx-delete]';
    function tellLocked() {
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'folio-pe-notice';
            notice.setAttribute('role', 'status');
            notice.textContent = lockedMark.getAttribute('data-folio-locked-text') || '';
            document.body.appendChild(notice);
        }
        notice.hidden = false;
        if (noticeTimer) { clearTimeout(noticeTimer); }
        noticeTimer = setTimeout(function () { notice.hidden = true; noticeTimer = 0; }, 2500);
    }
    if (locked) {
        document.addEventListener('htmx:beforeRequest', function (e) { e.preventDefault(); }, true);
    }

    // Click: select instead of following links or pressing buttons.
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        var block = e.target.closest('[data-folio-block]');
        var edit = e.target.closest('[data-folio-edit]');
        var region = e.target.closest('[data-folio-field]');
        if (locked && !block && !region && e.target.closest(INTERACTIVE)) {
            e.preventDefault();
            e.stopPropagation();
            tellLocked();
            return;
        }
        if (!block && !region) {
            // Header, footer, pages without markers: stay on the preview. An in-page anchor ("#team")
            // scrolls here: the page's <base> (its own address) would make it load the live page.
            var link = e.target.closest('a[href]');
            if (link) {
                e.preventDefault();
                var href = link.getAttribute('href') || '';
                var id = null;
                if (href.charAt(0) === '#' && href.length > 1) {
                    try { id = decodeURIComponent(href.slice(1)); } catch (err) { id = href.slice(1); }
                }
                var target = id !== null ? document.getElementById(id) : null;
                if (target && target.scrollIntoView) { target.scrollIntoView(); }
            }
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

    // Forms inside the preview never submit (and, switched off, never reach htmx either).
    document.addEventListener('submit', function (e) {
        e.preventDefault();
        if (locked) {
            e.stopPropagation();
            tellLocked();
        }
    }, true);

    function find(uid, field) {
        if (uid) {
            return document.querySelector('[data-folio-block="' + CSS.escape(uid) + '"]');
        }
        return field ? document.querySelector('[data-folio-field="' + CSS.escape(field) + '"]') : null;
    }

    function highlight(uid, field, scroll, sticky) {
        var target = find(uid, field);
        if (sticky && uid) {
            // The selected block: kept until another one is selected; the passing outline goes.
            selected = target;
            selectedLabel.textContent = target ? (target.getAttribute('data-folio-label') || blockWord()) : '';
            active = null;
        } else {
            active = target;
        }
        if (target && scroll) {
            var r = target.getBoundingClientRect();
            if (r.bottom < 0 || r.top > window.innerHeight) {
                target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
        schedule();
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || e.source !== window.parent) { return; }
        var data = e.data;
        if (!data || data.source !== 'folio-editor') { return; }
        if (data.type === 'highlight') {
            highlight(data.uid || null, data.field || null, !!data.scroll, !!data.sticky);
        }
    });

    window.addEventListener('scroll', schedule, true);
    window.addEventListener('resize', schedule);
    // Blocks patched in place, images loading, layers toggling: keep the boxes on target.
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, attributes: true, characterData: true });

    document.body.appendChild(overlay);
    send({ type: 'ready' });
})();
