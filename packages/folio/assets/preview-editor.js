/* Folio preview editor bridge: runs only inside the admin's preview iframe
   (injected by PreviewController, never on the public site). Clicking the page
   asks the editor to open that block or field; the editor asks back to
   highlight what has focus in the form. Messages are same-origin only. */
(function () {
    'use strict';

    if (window.parent === window) { return; }

    var active = null;
    var badge = null;

    function send(msg) {
        window.parent.postMessage(Object.assign({ source: 'folio-preview' }, msg), window.location.origin);
    }

    function label(block) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'folio-pe-badge';
        }
        badge.textContent = block.getAttribute('data-folio-label') || 'Block';
        block.appendChild(badge);
    }

    // Hover: outline the innermost block and show its label.
    document.addEventListener('mouseover', function (e) {
        var block = e.target.closest ? e.target.closest('[data-folio-block]') : null;
        document.querySelectorAll('.is-folio-hover').forEach(function (el) {
            if (el !== block) { el.classList.remove('is-folio-hover'); }
        });
        if (block) {
            block.classList.add('is-folio-hover');
            label(block);
        } else if (badge && badge.parentNode) {
            badge.parentNode.removeChild(badge);
        }
    });

    // Click: select instead of following links or pressing buttons.
    document.addEventListener('click', function (e) {
        if (!e.target.closest) { return; }
        var block = e.target.closest('[data-folio-block]');
        var edit = e.target.closest('[data-folio-edit]');
        var region = e.target.closest('[data-folio-field]');
        if (!block && !region) { return; }
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
        if (active) { active.classList.remove('is-folio-active'); }
        active = find(uid, field);
        if (!active) { return; }
        active.classList.add('is-folio-active');
        if (scroll) {
            var r = active.getBoundingClientRect();
            if (r.bottom < 0 || r.top > window.innerHeight) {
                active.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin) { return; }
        var data = e.data;
        if (!data || data.source !== 'folio-editor') { return; }
        if (data.type === 'highlight') {
            highlight(data.uid || null, data.field || null, !!data.scroll);
        }
    });

    send({ type: 'ready' });
})();
