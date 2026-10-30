/* Folio AI translation: the edit form's Translate button. Vanilla, no build step.
 *
 * The dialog asks the scope and a note and shows the estimate; Translate fetches the texts
 * (POST …/_ai/suggest, nothing is saved) and fills the form's inputs: path a.b.c is the input
 * a[b][c], a rich-text field is filled through its Trix editor. Under each filled field a review
 * row shows the default-language text, the previous translation with the changed words marked,
 * warnings, and Accept / Discard (Discard puts the previous value back).
 *
 * The form carries one hidden _ai_unchecked[] per path that was filled, not accepted and still
 * holds the AI text, kept current on every change, so a save (or the autosave the filling
 * triggers like any typing) marks exactly those as not checked.
 */
(function () {
    'use strict';

    var core = window.FolioAiTranslateCore;

    function init() {
        var root = document.querySelector('[data-ai-translate]');
        var dialog = root ? root.querySelector('[data-ai-dialog]') : null;
        var localeInput = document.querySelector('form input[name="_locale"]'); // the edit form (record action forms have none)
        var form = localeInput ? localeInput.form : null;
        if (!root || !dialog || !form || !core) { return; }
        setup(root, form);
    }

    function setup(root, form) {
        var dialog = root.querySelector('[data-ai-dialog]');
        var setupPart = dialog.querySelector('[data-ai-setup]');
        var resultPart = dialog.querySelector('[data-ai-result]');
        var estimateEl = dialog.querySelector('[data-ai-estimate]');
        var errorEl = dialog.querySelector('[data-ai-error]');
        var startBtn = dialog.querySelector('[data-ai-start]');
        var closeBtn = dialog.querySelector('[data-ai-close]');
        var acceptAllBtn = dialog.querySelector('[data-ai-accept-all]');
        var template = root.querySelector('[data-ai-review-template]');
        var closeLabel = closeBtn.textContent;
        var entries = {}; // path => {path, el, editor, ai, previous, accepted, row}
        var estimateRequest = 0;
        var holder = null;

        function msg(name, params) {
            return core.format(root.getAttribute('data-msg-' + name) || '', params);
        }

        function scope() {
            var checked = dialog.querySelector('input[name="folio_ai_scope"]:checked');
            return checked ? checked.value : 'needed';
        }

        function post(url, params) {
            var body = new FormData();
            Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
            var token = form.querySelector('input[name="_csrf_token"]');
            if (token) { body.append('_csrf_token', token.value); }
            return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        if (!res.ok) { throw new Error(data && data.error ? data.error : res.status + ' ' + res.statusText); }
                        return data;
                    });
                });
        }

        function showError(text) {
            errorEl.textContent = text;
            errorEl.hidden = text === '';
        }

        function estimate() {
            var mine = ++estimateRequest;
            estimateEl.textContent = msg('estimating');
            post(root.getAttribute('data-estimate-url'), { locale: root.getAttribute('data-locale'), scope: scope() })
                .then(function (data) {
                    if (mine !== estimateRequest) { return; }
                    var cost = core.formatCost(data.costUsd);
                    estimateEl.textContent = data.units === 0 ? msg('nothing')
                        : cost === null ? msg('estimate-unpriced', { units: data.units, requests: data.requests })
                            : msg('estimate', { units: data.units, requests: data.requests, cost: cost });
                    startBtn.disabled = data.units === 0;
                })
                .catch(function (e) {
                    if (mine === estimateRequest) { estimateEl.textContent = ''; showError(msg('error', { message: e.message })); }
                });
        }

        function open() {
            setupPart.hidden = false;
            resultPart.hidden = true;
            acceptAllBtn.hidden = true;
            startBtn.hidden = false;
            startBtn.disabled = false;
            closeBtn.textContent = closeLabel;
            showError('');
            if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
            estimate();
        }

        function close() {
            if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
        }

        function translate() {
            startBtn.disabled = true;
            showError('');
            estimateEl.textContent = msg('working');
            post(root.getAttribute('data-suggest-url'), {
                locale: root.getAttribute('data-locale'),
                scope: scope(),
                note: dialog.querySelector('[data-ai-note]').value,
            }).then(function (data) {
                var missing = [];
                var filled = 0;
                Object.keys(data.texts || {}).forEach(function (path) {
                    if (fill(path, data.texts[path])) { filled++; } else { missing.push(path); }
                });
                sync();
                showResult(filled, data.failed || {}, missing);
            }).catch(function (e) {
                startBtn.disabled = false;
                estimateEl.textContent = '';
                showError(msg('error', { message: e.message }));
            });
        }

        function showResult(filled, failed, missing) {
            setupPart.hidden = true;
            resultPart.hidden = false;
            startBtn.hidden = true;
            acceptAllBtn.hidden = pending().length === 0;
            closeBtn.textContent = root.getAttribute('data-msg-close') || closeLabel;
            resultPart.querySelector('[data-ai-summary]').textContent = msg('filled', { count: filled });
            var list = resultPart.querySelector('[data-ai-failed-list]');
            list.textContent = '';
            Object.keys(failed).forEach(function (path) { list.appendChild(item(labelFor(path) + ': ' + failed[path])); });
            missing.forEach(function (path) { list.appendChild(item(path + ': ' + msg('missing-input'))); });
            resultPart.querySelector('[data-ai-failed]').hidden = list.children.length === 0;
        }

        function item(text) {
            var li = document.createElement('li');
            li.textContent = text;
            return li;
        }

        /** The input named after the path (a rich-text field's is the hidden input its editor writes to). */
        function inputFor(path) {
            var name = core.pathToName(path);
            var found = null;
            Array.prototype.forEach.call(form.elements, function (el) {
                if (found === null && el.name === name && el.type !== 'file') { found = el; }
            });
            return found;
        }

        function editorFor(input) {
            if (!input.id) { return null; }
            var editor = document.querySelector('trix-editor[input="' + cssEscape(input.id) + '"]');
            return editor && editor.editor ? editor : null;
        }

        function labelFor(path) {
            var input = inputFor(path);
            var group = input ? input.closest('.form-group') : null;
            var label = group ? group.querySelector('label') : null;
            return label && label.textContent.trim() !== '' ? label.textContent.trim().replace(/\s*\*$/, '') : path;
        }

        function setValue(entry, text) {
            if (entry.editor) {
                entry.editor.editor.loadHTML(text);
            } else {
                entry.el.value = text;
            }
            entry.el.dispatchEvent(new Event('input', { bubbles: true }));
            entry.el.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function fill(path, data) {
            var el = inputFor(path);
            if (!el) { return false; }
            if (entries[path]) { forget(entries[path]); }
            var entry = { path: path, el: el, editor: editorFor(el), ai: null, previous: data.previous, accepted: false, row: null };
            entries[path] = entry;
            if (entry.editor) {
                // Trix writes its input when it renders: take the AI text as Trix stores it once that
                // happened (its first change event, or the next tick), not a later edit.
                var filledAt = Date.now();
                var settle = function () {
                    if (entries[path] === entry && Date.now() - filledAt < 1000) { entry.ai = el.value; sync(); }
                };
                entry.editor.addEventListener('trix-change', settle, { once: true });
                setTimeout(settle, 0);
            }
            setValue(entry, data.text);
            entry.ai = el.value;
            entry.row = reviewRow(entry, data);
            var group = (entry.editor || el).closest('.form-group') || el.parentNode;
            group.appendChild(entry.row);
            return true;
        }

        function reviewRow(entry, data) {
            var row = template.content.firstElementChild.cloneNode(true);
            row.setAttribute('data-ai-path', entry.path);
            row.querySelector('[data-ai-source]').textContent = plain(data.source);
            var previous = plain(data.previous || '');
            if (previous.trim() !== '') {
                var target = row.querySelector('[data-ai-previous]');
                core.wordDiff(previous, plain(data.text)).forEach(function (part) {
                    var node = part.op === 'same' ? document.createTextNode(part.text) : document.createElement(part.op);
                    if (part.op !== 'same') { node.textContent = part.text; }
                    target.appendChild(node);
                });
                row.querySelector('[data-ai-previous-part]').hidden = false;
            }
            var warnings = row.querySelector('[data-ai-warnings]');
            (data.warnings || []).forEach(function (w) { warnings.appendChild(item(w)); });
            row.querySelector('[data-ai-warnings-part]').hidden = warnings.children.length === 0;
            row.querySelector('[data-ai-accept]').addEventListener('click', function () { accept(entry); });
            row.querySelector('[data-ai-discard]').addEventListener('click', function () { discard(entry); });
            return row;
        }

        function accept(entry) {
            entry.accepted = true;
            forget(entry);
            sync();
        }

        function discard(entry) {
            forget(entry);
            setValue(entry, entry.previous === null || entry.previous === undefined ? '' : entry.previous);
            sync();
        }

        function forget(entry) {
            if (entry.row && entry.row.parentNode) { entry.row.parentNode.removeChild(entry.row); }
            if (entries[entry.path] === entry) { delete entries[entry.path]; }
            acceptAllBtn.hidden = pending().length === 0;
        }

        function pending() {
            return Object.keys(entries).map(function (p) { return entries[p]; }).filter(function (e) { return !e.accepted; });
        }

        /** Rewrites the hidden _ai_unchecked[] inputs from the current values. */
        function sync() {
            var paths = core.uncheckedPaths(pending().map(function (e) {
                return { path: e.path, accepted: e.accepted, ai: e.ai, value: e.el.value };
            }));
            if (holder === null) {
                if (paths.length === 0) { return; }
                holder = document.createElement('div');
                holder.hidden = true;
                holder.setAttribute('data-ai-unchecked', '');
                form.appendChild(holder);
            }
            holder.textContent = '';
            paths.forEach(function (path) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_ai_unchecked[]';
                input.value = path;
                holder.appendChild(input);
            });
        }

        root.querySelector('[data-ai-open]').addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        startBtn.addEventListener('click', translate);
        acceptAllBtn.addEventListener('click', function () {
            pending().forEach(function (e) { e.accepted = true; forget(e); });
            sync();
            close();
        });
        Array.prototype.forEach.call(dialog.querySelectorAll('input[name="folio_ai_scope"]'), function (radio) {
            radio.addEventListener('change', estimate);
        });
        // Typing in a filled field makes it checked: keep the hidden list current before any save.
        ['input', 'change', 'trix-change'].forEach(function (type) { form.addEventListener(type, sync, true); });
        form.addEventListener('submit', sync, true);
    }

    function plain(html) {
        var text = String(html == null ? '' : html);
        if (text.indexOf('<') === -1) { return text; }
        var doc = new DOMParser().parseFromString(text, 'text/html');
        return doc.body.textContent || '';
    }

    function cssEscape(s) {
        return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
