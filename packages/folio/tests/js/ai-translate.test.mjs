// The Translate button's pure logic (assets/ai-translate-core.js). Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const core = require('../../assets/ai-translate-core.js');

test('path_to_name', () => {
    assert.equal(core.pathToName('title'), 'title');
    assert.equal(core.pathToName('sections.aaaa.headline'), 'sections[aaaa][headline]');
    assert.equal(core.pathToName('sections.aaaa.tradeoff.cccc.chose'), 'sections[aaaa][tradeoff][cccc][chose]');
    assert.equal(core.pathToName('faq.0.question'), 'faq[0][question]');
});

test('unchecked_hidden_inputs_only_for_unaccepted_unchanged', () => {
    const entries = [
        { path: 'title', accepted: false, ai: 'Hallo', value: 'Hallo' }, // filled, untouched: not checked
        { path: 'slug', accepted: true, ai: 'hallo', value: 'hallo' }, // accepted: checked
        { path: 'body', accepted: false, ai: 'Text', value: 'Text, edited' }, // edited: checked
        { path: 'sections.a.headline', accepted: false, ai: '<div>Kopf</div>', value: '<div>Kopf</div>' },
        { path: 'pending', accepted: false, ai: null, value: '' }, // not filled yet
    ];
    assert.deepEqual(core.uncheckedPaths(entries), ['title', 'sections.a.headline']);
    assert.deepEqual(core.uncheckedPaths([]), []);
});

test('checked_hidden_inputs_only_for_accepted', () => {
    const entries = [
        { path: 'title', accepted: true, ai: 'Hallo', value: 'Hallo' },
        { path: 'slug', accepted: false, ai: 'hallo', value: 'hallo' },
        { path: 'body', accepted: true, ai: 'Text', value: 'Text, edited after accepting' },
    ];
    assert.deepEqual(core.checkedPaths(entries), ['title', 'body']);
    assert.deepEqual(core.uncheckedPaths(entries), ['slug'], 'an accepted path is never also unchecked');
});

test('discard_restores_value_before_fill', () => {
    // The stored translation was "Alt", the editor had typed "Neu, ungespeichert" before translating.
    assert.equal(core.discardValue({ before: 'Neu, ungespeichert', previous: 'Alt' }), 'Neu, ungespeichert');
    assert.equal(core.discardValue({ before: '', previous: 'Alt' }), '', 'an empty field stays empty');
    assert.equal(core.discardValue({ before: null }), '');
});

test('word_diff', () => {
    assert.deepEqual(core.wordDiff('Setze einen Arbeiter', 'Setze zwei Arbeiter'), [
        { op: 'same', text: 'Setze ' },
        { op: 'del', text: 'einen ' },
        { op: 'ins', text: 'zwei ' },
        { op: 'same', text: 'Arbeiter' },
    ]);
    assert.deepEqual(core.wordDiff('', 'Neu'), [{ op: 'ins', text: 'Neu' }]);
    assert.deepEqual(core.wordDiff('Gleich', 'Gleich'), [{ op: 'same', text: 'Gleich' }]);
    const joined = (parts, ops) => parts.filter((p) => ops.includes(p.op)).map((p) => p.text).join('');
    const a = 'one two three four five';
    const b = 'one 2 three five six';
    const diff = core.wordDiff(a, b);
    assert.equal(joined(diff, ['same', 'del']), a, 'old text = same + deleted');
    assert.equal(joined(diff, ['same', 'ins']), b, 'new text = same + inserted');
});

test('format_and_cost', () => {
    assert.equal(core.format(':count texts in :requests requests', { count: 3, requests: 1 }), '3 texts in 1 requests');
    assert.equal(core.formatCost(null), null);
    assert.equal(core.formatCost(0.0004), '< $0.01');
    assert.equal(core.formatCost(0), '$0.00');
    assert.equal(core.formatCost(1.234), '$1.23');
});
