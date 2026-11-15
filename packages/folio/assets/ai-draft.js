/* Folio "Write a draft" (since 0.77): an AI action at the right of every rich-text (Trix) toolbar of
 * the edit form, also of block cards added later (it listens to trix-initialize on the document).
 * Vanilla, no build step; the pure parts are in ai-draft-core.js (window.FolioAiDraftCore).
 *
 * The button opens the form's one dialog ([data-ai-draft], partials/ai-draft-dialog.twig) for its
 * field: "What should it say?", the length, Write. Write posts type, field (its label), brief,
 * length, language and title to data-url and shows the answer (already sanitised by the server, and
 * copied into the preview element by element, never as markup) with the cost line. Insert at
 * cursor puts it where the caret was when the dialog opened, Replace text over the field's text;
 * both through Trix's own parser as one undo step, and Trix's trix-change makes it an ordinary
 * edit for the unsaved state and autosave. Try again writes anew; Cancel and Escape leave the field
 * as it was, and so does a failure.
 */
(function () {
    'use strict';

    var core = window.FolioAiDraftCore;
    var SVG = 'http://www.w3.org/2000/svg';

    function init() {
        var root = document.querySelector('[data-ai-draft]');
        var dialog = root ? root.querySelector('[data-ai-draft-dialog]') : null;
        if (!root || !dialog || !core) { return; }

        var brief = dialog.querySelector('[data-ai-draft-brief]');
        var fieldEl = dialog.querySelector('[data-ai-draft-field]');
        var result = dialog.querySelector('[data-ai-draft-result]');
        var preview = dialog.querySelector('[data-ai-draft-preview]');
        var costEl = dialog.querySelector('[data-ai-draft-cost]');
        var statusEl = dialog.querySelector('[data-ai-draft-status]');
        var errorEl = dialog.querySelector('[data-ai-draft-error]');
        var writeBtn = dialog.querySelector('[data-ai-draft-write]');
        var againBtn = dialog.querySelector('[data-ai-draft-again]');
        var insertBtn = dialog.querySelector('[data-ai-draft-insert]');
        var replaceBtn = dialog.querySelector('[data-ai-draft-replace]');
        var cancelBtn = dialog.querySelector('[data-ai-draft-cancel]');
        var announceEl = root.querySelector('[data-ai-draft-announce]');
        var writeLabel = writeBtn.textContent;
        var againLabel = againBtn.textContent;
        // The field the dialog is open for: {element, opener, range, html}; request counts answers, so a late one is ignored.
        var state = null;
        var request = 0;
        var briefs = typeof WeakMap === 'function' ? new WeakMap() : null; // the last brief per field

        function msg(name, params) {
            return core.format(root.getAttribute('data-msg-' + name) || '', params);
        }

        function post(url, params) {
            var body = new FormData();
            Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
            var token = document.querySelector('input[name="_csrf_token"]');
            if (token) { body.append('_csrf_token', token.value); }
            return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        if (!res.ok) { throw new Error(data && data.error ? data.error : res.status + ' ' + res.statusText); }
                        return data;
                    });
                });
        }

        function labelOf(element) {
            var id = element.getAttribute('input');
            var label = id ? document.querySelector('label[for="' + id.replace(/(["\\])/g, '\\$1') + '"]') : null;
            return core.fieldLabel(label ? label.textContent : '');
        }

        function length() {
            var value = 'medium';
            Array.prototype.forEach.call(dialog.querySelectorAll('input[name="folio_ai_draft_length"]'), function (radio) {
                if (radio.checked) { value = radio.value; }
            });
            return value;
        }

        function showError(text) {
            errorEl.textContent = text;
            errorEl.hidden = text === '';
        }

        function setStep(written) {
            result.hidden = !written;
            writeBtn.hidden = written;
            againBtn.hidden = !written;
            insertBtn.hidden = !written;
            replaceBtn.hidden = !written;
        }

        function syncWrite() {
            var empty = brief.value.trim() === '';
            writeBtn.disabled = empty || writeBtn.getAttribute('aria-busy') === 'true';
            againBtn.disabled = empty || againBtn.getAttribute('aria-busy') === 'true';
        }

        function busy(on) {
            [writeBtn, againBtn].forEach(function (b) {
                if (on) { b.setAttribute('aria-busy', 'true'); } else { b.removeAttribute('aria-busy'); }
            });
            writeBtn.textContent = on ? msg('writing') : writeLabel;
            againBtn.textContent = on && !againBtn.hidden ? msg('writing') : againLabel;
            insertBtn.disabled = on;
            replaceBtn.disabled = on;
            statusEl.textContent = on ? msg('writing') : '';
            syncWrite();
        }

        function open(element, opener) {
            var editor = element.editor;
            if (!editor) { return; }
            state = { element: element, opener: opener, range: editor.getSelectedRange(), html: null };
            var field = labelOf(element);
            fieldEl.textContent = field === '' ? '' : msg('for-field', { field: field });
            brief.value = briefs && briefs.has(element) ? briefs.get(element) : '';
            preview.textContent = '';
            costEl.textContent = '';
            showError('');
            setStep(false);
            busy(false);
            if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
            brief.focus();
        }

        function close() {
            request++;
            if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
        }

        function write() {
            if (!state || brief.value.trim() === '') { return; }
            if (briefs) { briefs.set(state.element, brief.value); }
            var mine = ++request;
            showError('');
            busy(true);
            var params = core.params({
                type: root.getAttribute('data-type'),
                field: labelOf(state.element),
                brief: brief.value,
                length: length(),
                language: root.getAttribute('data-language'),
                title: root.getAttribute('data-title'),
            });
            core.write(post, root.getAttribute('data-url'), params).then(function (draft) {
                if (mine !== request || !state) { return; }
                state.html = draft.html;
                showPreview(draft.html);
                costEl.textContent = core.costLine(draft.cost, { known: msg('cost'), unknown: msg('cost-unknown') });
                setStep(true);
                busy(false);
                insertBtn.focus();
            }).catch(function (e) {
                if (mine !== request) { return; }
                busy(false);
                showError(msg('error', { message: e && e.message ? e.message : String(e) }));
            });
        }

        // The draft is shown, read only, through an inert document: copied node by node (core.copySafe).
        function showPreview(html) {
            preview.textContent = '';
            if (typeof DOMParser !== 'function') { preview.textContent = html.replace(/<[^>]*>/g, ' '); return; }
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            core.copySafe(document, parsed.body, preview);
        }

        function put(mode) {
            if (!state || state.html === null || !state.element.editor) { return; }
            var element = state.element;
            var done = core.apply(element.editor, state.html, mode, state.range);
            state = null;
            close();
            element.focus();
            if (announceEl) { announceEl.textContent = msg(done === 'replace' ? 'replaced' : 'inserted'); }
        }

        brief.addEventListener('input', syncWrite);
        brief.addEventListener('keydown', function (e) {
            // Cmd/Ctrl+Enter writes (a plain Enter is a new line of the brief).
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); if (!writeBtn.hidden) { write(); } }
        });
        writeBtn.addEventListener('click', write);
        againBtn.addEventListener('click', write);
        insertBtn.addEventListener('click', function () { put('insert'); });
        replaceBtn.addEventListener('click', function () { put('replace'); });
        cancelBtn.addEventListener('click', close);
        dialog.addEventListener('close', function () {
            request++;
            var opener = state ? state.opener : null;
            state = null;
            if (opener && opener.isConnected !== false) { opener.focus(); }
        });

        // The button, at the right of the toolbar of every editor: those there now and those initialised later.
        function addButton(element) {
            if (!element || element._folioAiDraft || !element.getAttribute || element.tagName !== 'TRIX-EDITOR') { return; }
            if (element.closest && element.closest('fieldset[disabled]')) { return; } // a read-only form
            var toolbar = element.toolbarElement || (element.getAttribute('toolbar') ? document.getElementById(element.getAttribute('toolbar')) : null);
            var row = toolbar ? toolbar.querySelector('.trix-button-row') : null;
            if (!row) { return; }
            element._folioAiDraft = true;
            var group = document.createElement('span');
            group.className = 'trix-button-group folio-ai-draft-group';
            group.setAttribute('data-trix-button-group', 'folio-ai');
            var button = document.createElement('button');
            button.setAttribute('type', 'button');
            button.className = 'trix-button folio-ai-draft-button';
            button.setAttribute('data-ai-draft-open', '');
            button.setAttribute('aria-haspopup', 'dialog');
            var svg = document.createElementNS(SVG, 'svg');
            svg.setAttribute('class', 'folio-icon');
            svg.setAttribute('aria-hidden', 'true');
            var use = document.createElementNS(SVG, 'use');
            use.setAttribute('href', root.getAttribute('data-icon') || '');
            svg.appendChild(use);
            button.appendChild(svg);
            var text = document.createElement('span');
            text.className = 'folio-ai-draft-button-text';
            text.textContent = msg('button');
            button.appendChild(text);
            button.setAttribute('title', msg('button'));
            button.addEventListener('click', function (e) {
                e.preventDefault();
                open(element, button);
            });
            group.appendChild(button);
            row.appendChild(group);
        }

        document.addEventListener('trix-initialize', function (e) { addButton(e.target); });
        Array.prototype.forEach.call(document.querySelectorAll('trix-editor'), function (element) {
            if (element.editor) { addButton(element); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
