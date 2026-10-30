/* Folio AI translation: the pure parts of the edit form's Translate button (no DOM), so node can
   test them (packages/folio/tests/js). The browser gets window.FolioAiTranslateCore. */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.FolioAiTranslateCore = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** A text's path ("sections.<uid>.headline") as the form names its input ("sections[<uid>][headline]"). */
    function pathToName(path) {
        var parts = String(path).split('.');
        var name = parts.shift();
        parts.forEach(function (part) { name += '[' + part + ']'; });
        return name;
    }

    /**
     * The paths to post as _ai_unchecked[]: filled by AI, not accepted, and still holding the AI
     * text (an edited text counts as checked). entries: [{path, accepted, ai, value}], where ai is
     * the field's value right after it was filled and value its value now.
     */
    function uncheckedPaths(entries) {
        return entries
            .filter(function (e) { return !e.accepted && e.ai !== null && e.ai !== undefined && e.value === e.ai; })
            .map(function (e) { return e.path; });
    }

    /** ":name" placeholders filled from params. */
    function format(text, params) {
        var out = String(text);
        Object.keys(params || {}).forEach(function (name) {
            out = out.split(':' + name).join(String(params[name]));
        });
        return out;
    }

    /** A USD amount for the estimate: null (no price) stays null, small amounts read "< $0.01". */
    function formatCost(usd) {
        if (usd === null || usd === undefined) { return null; }
        if (usd > 0 && usd < 0.01) { return '< $0.01'; }
        return '$' + Number(usd).toFixed(2);
    }

    /**
     * Word-level changes from text a to text b: [{op: 'same'|'del'|'ins', text}], whitespace kept
     * with the words. Longest common subsequence over the words; very long texts (whose table would
     * be too big) come back as one deletion and one insertion.
     */
    function wordDiff(a, b) {
        var x = tokens(a);
        var y = tokens(b);
        if (x.length * y.length > 250000) {
            return merge([{ op: 'del', text: String(a) }, { op: 'ins', text: String(b) }]);
        }
        var n = x.length, m = y.length, i, j;
        var lcs = [];
        for (i = 0; i <= n; i++) { lcs.push(new Array(m + 1).fill(0)); }
        for (i = n - 1; i >= 0; i--) {
            for (j = m - 1; j >= 0; j--) {
                lcs[i][j] = x[i] === y[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
            }
        }
        var out = [];
        i = 0; j = 0;
        while (i < n && j < m) {
            if (x[i] === y[j]) { out.push({ op: 'same', text: x[i] }); i++; j++; }
            else if (lcs[i + 1][j] >= lcs[i][j + 1]) { out.push({ op: 'del', text: x[i] }); i++; }
            else { out.push({ op: 'ins', text: y[j] }); j++; }
        }
        for (; i < n; i++) { out.push({ op: 'del', text: x[i] }); }
        for (; j < m; j++) { out.push({ op: 'ins', text: y[j] }); }
        return merge(out);
    }

    /** Words with the whitespace after them, so joining the tokens gives the text back. */
    function tokens(text) {
        return String(text || '').match(/\S+\s*|\s+/g) || [];
    }

    function merge(parts) {
        var out = [];
        parts.forEach(function (p) {
            if (p.text === '') { return; }
            var last = out[out.length - 1];
            if (last && last.op === p.op) { last.text += p.text; } else { out.push({ op: p.op, text: p.text }); }
        });
        return out;
    }

    return { pathToName: pathToName, uncheckedPaths: uncheckedPaths, format: format, formatCost: formatCost, wordDiff: wordDiff };
}));
