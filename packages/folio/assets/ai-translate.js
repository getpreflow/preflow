/* Folio AI translation: the edit form's Translate button, also the one of Settings → Translations
 * and of the media panel. Vanilla, no build step.
 *
 * The dialog asks the scope and a note and shows the estimate; Translate fetches the texts
 * (POST …/_ai/suggest, nothing is saved) and fills the form's inputs: path a.b.c is the input
 * a[b][c], a rich-text field is filled through its Trix editor. Under each filled field a review
 * row shows the default-language text, the previous translation with the changed words marked,
 * warnings, and Accept / Discard (Discard puts back what the field held right before the fill).
 *
 * The form carries one hidden _ai_unchecked[] per path that was filled, not accepted and still
 * holds the AI text, and one _ai_checked[] per accepted path, kept current on every change, so a
 * save (or the autosave the filling triggers like any typing) marks exactly the first as not
 * checked and clears the marks of the second (also ones an earlier autosave set).
 *
 * The other two screens set attributes on the root: data-ai-form (CSS selector of what holds the
 * inputs; the edit form when absent), data-extra (JSON, more fields to post), data-ai-marks="none"
 * (labels and alt texts have no "not checked" state: nothing is marked, Accept / Discard still work),
 * no data-estimate-url (no estimate). An input names its text by data-ai-path, else by its name
 * (path a.b is a[b]).
 */
(function () {
    'use strict';

    var core = window.FolioAiTranslateCore;

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ai-translate]'), function (root) {
            var dialog = root.querySelector('[data-ai-dialog]');
            var selector = root.getAttribute('data-ai-form');
            var localeInput = document.querySelector('form input[name="_locale"]'); // the edit form (record action forms have none)
            var form = selector ? document.querySelector(selector) : (localeInput ? localeInput.form : null);
            if (!dialog || !form || !core) { return; }
            setup(root, form);
        });
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
        var entries = {}; // path => {path, el, editor, ai, before, accepted, row}; accepted ones stay (posted as _ai_checked[])
        var estimateRequest = 0;
        var holder = null;
        var marks = root.getAttribute('data-ai-marks') !== 'none';
        var extra = {};
        try { extra = JSON.parse(root.getAttribute('data-extra') || '{}') || {}; } catch (e) { extra = {}; }

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
            var token = form.querySelector('input[name="_csrf_token"]') || document.querySelector('input[name="_csrf_token"]');
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
            if (!root.getAttribute('data-estimate-url')) { // nothing to estimate: the dialog goes straight to Translate
                estimateEl.textContent = '';
                startBtn.disabled = false;
                return;
            }
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
            var params = { locale: root.getAttribute('data-locale'), scope: scope(), note: dialog.querySelector('[data-ai-note]').value };
            Object.keys(extra).forEach(function (k) { params[k] = extra[k]; });
            post(root.getAttribute('data-suggest-url'), params).then(function (data) {
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
            var named = form.querySelector('[data-ai-path="' + cssEscape(path) + '"]'); // a text whose key is no form field name
            if (named) { return named; }
            var name = core.pathToName(path);
            var found = null;
            Array.prototype.forEach.call(form.elements || form.querySelectorAll('input, textarea, select'), function (el) {
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
            var group = input ? input.closest('.form-group, label.folio-field') : null;
            var label = group ? (group.tagName === 'LABEL' ? group : group.querySelector('label')) : null;
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
            var editor = editorFor(el);
            // Discard puts back what the field held right before the first fill (unsaved typing included).
            var before = entries[path] ? entries[path].before : el.value;
            if (entries[path]) { forget(entries[path]); }
            var entry = { path: path, el: el, editor: editor, ai: null, before: before, accepted: false, row: null };
            entries[path] = entry;
            if (entry.editor) {
                // Trix writes its input when it renders: take the AI text as Trix stores it once that
                // happened (its first change event, or the next tick), not a later edit.
                var filledAt = Date.now();
                var settle = function () {
                    if (entries[path] === entry && !entry.accepted && Date.now() - filledAt < 1000) { entry.ai = el.value; sync(); }
                };
                entry.editor.addEventListener('trix-change', settle, { once: true });
                setTimeout(settle, 0);
            }
            setValue(entry, data.text);
            entry.ai = el.value;
            entry.row = reviewRow(entry, data);
            var host = (entry.editor || el).closest('.form-group, td');
            if (host) {
                host.appendChild(entry.row);
            } else { // the media panel's label wraps its input: the row goes after the label
                var wrap = el.closest('label');
                (wrap || el).parentNode.insertBefore(entry.row, (wrap || el).nextSibling);
            }
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

        /** Accepted: posted as _ai_checked[] from now on; the change event lets the autosave send it. */
        function accept(entry) {
            entry.accepted = true;
            removeRow(entry);
            sync();
            entry.el.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function discard(entry) {
            forget(entry);
            setValue(entry, core.discardValue(entry));
            sync();
        }

        function removeRow(entry) {
            if (entry.row && entry.row.parentNode) { entry.row.parentNode.removeChild(entry.row); }
            acceptAllBtn.hidden = pending().length === 0;
        }

        function forget(entry) {
            if (entries[entry.path] === entry) { delete entries[entry.path]; }
            removeRow(entry);
        }

        function all() {
            return Object.keys(entries).map(function (p) { return entries[p]; });
        }

        function pending() {
            return all().filter(function (e) { return !e.accepted; });
        }

        /** Rewrites the hidden _ai_unchecked[] and _ai_checked[] inputs from the current values. */
        function sync() {
            if (!marks) { return; }
            var states = all().map(function (e) {
                return { path: e.path, accepted: e.accepted, ai: e.ai, value: e.el.value };
            });
            var lists = { '_ai_unchecked[]': core.uncheckedPaths(states), '_ai_checked[]': core.checkedPaths(states) };
            if (holder === null) {
                if (states.length === 0) { return; }
                holder = document.createElement('div');
                holder.hidden = true;
                holder.setAttribute('data-ai-marks', '');
                form.appendChild(holder);
            }
            holder.textContent = '';
            Object.keys(lists).forEach(function (name) {
                lists[name].forEach(function (path) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = path;
                    holder.appendChild(input);
                });
            });
        }

        root.querySelector('[data-ai-open]').addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        startBtn.addEventListener('click', translate);
        acceptAllBtn.addEventListener('click', function () {
            var accepted = pending();
            accepted.forEach(function (e) { e.accepted = true; removeRow(e); });
            sync();
            if (accepted.length > 0) { accepted[0].el.dispatchEvent(new Event('change', { bubbles: true })); }
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
