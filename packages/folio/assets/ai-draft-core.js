/* Folio "Write a draft": the pure parts of the rich-text toolbar's AI action, so node can test them
   (packages/folio/tests/js). The browser gets window.FolioAiDraftCore; ai-draft.js wires the DOM. */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.FolioAiDraftCore = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** The elements a draft's preview keeps when the page names none (the dialog's data-elements does). */
    var ALLOWED = ['P', 'H2', 'H3', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'A', 'BR'];
    /** Elements dropped with everything in them (the rest of an unknown element is kept as its text). */
    var DROPPED = ['SCRIPT', 'STYLE', 'TEMPLATE', 'IFRAME', 'OBJECT', 'EMBED', 'NOSCRIPT', 'SVG', 'MATH', 'TEXTAREA', 'SELECT', 'BUTTON', 'IMG', 'VIDEO', 'AUDIO'];

    /** ":name" placeholders filled from params. */
    function format(text, params) {
        var out = String(text);
        Object.keys(params || {}).forEach(function (name) {
            out = out.split(':' + name).join(String(params[name]));
        });
        return out;
    }

    /** A field's label as the form shows it, without the required star. */
    function fieldLabel(text) {
        return String(text || '').replace(/\s+/g, ' ').replace(/\s*\*\s*$/, '').trim();
    }

    /** What POST …/_ai/draft takes; the brief trimmed. */
    function params(input) {
        return {
            type: String(input.type || ''),
            id: String(input.id || ''),
            new: input.isNew ? '1' : '0',
            field: fieldLabel(input.field),
            brief: String(input.brief || '').trim(),
            length: String(input.length || 'medium'),
            language: String(input.language || ''),
            title: String(input.title || ''),
        };
    }

    /**
     * Asks for a draft: post(url, params) answers the JSON (or rejects with the server's error).
     * Resolves {html, cost}; an answer without HTML rejects ({status, error}, as post's own failures),
     * so nothing reaches the field.
     */
    function write(post, url, body) {
        return post(url, body).then(function (data) {
            var html = data && typeof data.html === 'string' ? data.html : '';
            if (html.trim() === '') { throw { status: 500, error: data && typeof data.error === 'string' ? data.error : '' }; }
            return { html: html, cost: data.cost === null || data.cost === undefined ? null : String(data.cost) };
        });
    }

    /** The dialog's cost line: the server's formatted cost, or that it is unknown. */
    function costLine(cost, texts) {
        return cost === null || cost === undefined || cost === '' ? String(texts.unknown) : format(texts.known, { cost: cost });
    }

    /**
     * What a failed request tells the person: the server's own (translated) error, else by what
     * happened: no answer at all (status 0), a refusal without JSON (403: the CSRF check), anything else.
     */
    function errorText(failure, texts) {
        if (failure && typeof failure.error === 'string' && failure.error !== '') { return failure.error; }
        var status = failure && failure.status ? failure.status : 0;
        if (status === 0) { return String(texts.offline); }
        if (status === 403) { return String(texts.forbidden); }
        return String(texts.generic);
    }

    /** A link the preview may keep: http(s), mailto, tel, a path or an anchor; anything else (javascript:, data:) is null. */
    function safeHref(href) {
        var value = String(href || '').trim();
        if (value === '') { return null; }
        if (/^(https?:|mailto:|tel:)/i.test(value)) { return value; }
        if (/^[a-z][a-z0-9+.-]*:/i.test(value) || /^\/\//.test(value)) { return null; }
        return value;
    }

    /**
     * Copies source's children into target, keeping only text and the allowed elements (allowed:
     * lower- or upper-case names, default ALLOWED; links with
     * a safe href and nothing else); a dropped element goes with its content, any other unknown
     * one leaves its text. No attribute but href survives. Builds with doc.createElement and
     * createTextNode, so no markup is ever parsed into the page.
     */
    function copySafe(doc, source, target, allowed) {
        var keep = (allowed && allowed.length ? allowed : ALLOWED).map(function (t) { return String(t).toUpperCase(); });
        var nodes = source.childNodes || [];
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (node.nodeType === 3) {
                target.appendChild(doc.createTextNode(node.data !== undefined ? node.data : node.textContent));
                continue;
            }
            if (node.nodeType !== 1) { continue; }
            var tag = String(node.tagName || '').toUpperCase();
            if (DROPPED.indexOf(tag) >= 0) { continue; }
            if (keep.indexOf(tag) < 0) { copySafe(doc, node, target, keep); continue; }
            var el = doc.createElement(tag.toLowerCase());
            if (tag === 'A') {
                var href = safeHref(node.getAttribute('href'));
                if (href === null) { copySafe(doc, node, target, keep); continue; }
                el.setAttribute('href', href);
                el.setAttribute('rel', 'noopener');
                el.setAttribute('target', '_blank');
            }
            copySafe(doc, node, el, keep);
            target.appendChild(el);
        }
        return target;
    }

    /**
     * The draft without the white space around block tags (p, h2, h3, ul, ol, li, blockquote, div):
     * Trix keeps a line break between two blocks as an empty line. Spaces between inline tags stay.
     */
    function compact(html) {
        return String(html).replace(/\s*(<\/?(?:p|h[1-6]|ul|ol|li|blockquote|div|br)\b[^>]*>)\s*/gi, '$1').trim();
    }

    /**
     * Puts the draft into a Trix editor (element.editor) through Trix's own parser, as one undo step:
     * 'insert' at the range the caret had when the dialog opened (inside a line after a line break,
     * so the draft starts a block of its own), 'replace' over the whole text.
     * Trix sends trix-change for it as for any edit, so the unsaved state and autosave see it.
     */
    function apply(editor, html, mode, range) {
        if (typeof editor.recordUndoEntry === 'function') { editor.recordUndoEntry('Write a draft'); }
        if (mode === 'replace') {
            var length = editor.getDocument().getLength();
            editor.setSelectedRange([0, length]);
        } else if (range && range.length === 2) {
            editor.setSelectedRange([range[0], range[1]]);
            // Inside a line, a new block first: Trix would run the draft's first block into the line's text.
            var before = range[0] > 0 ? editor.getDocument().toString().charAt(range[0] - 1) : '\n';
            if (before !== '\n' && typeof editor.insertLineBreak === 'function') { editor.insertLineBreak(); }
        }
        editor.insertHTML(compact(html));
        return mode === 'replace' ? 'replace' : 'insert';
    }

    return { ALLOWED: ALLOWED, format: format, fieldLabel: fieldLabel, params: params, write: write, costLine: costLine, errorText: errorText, safeHref: safeHref, copySafe: copySafe, compact: compact, apply: apply };
}));
