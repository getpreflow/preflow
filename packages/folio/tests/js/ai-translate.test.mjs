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

test('queue mode posts and shows the message without filling inputs', async () => {
    const calls = [];
    const post = (url, params) => {
        calls.push({ url, params });
        return Promise.resolve({ job: 'j1', state: 'queued', existing: false, message: 'Queued.', worker: false, texts: { title: { text: 'DE:Hello' } } });
    };
    const params = { locale: 'de', scope: 'needed', note: '', confirm_live: '1' };

    const result = await core.queue(post, '/folio/post/p1/_ai/queue', params, 'No worker: * * * * * cd <app> && php vendor/bin/preflow translate:work');

    assert.deepEqual(calls, [{ url: '/folio/post/p1/_ai/queue', params }], 'one request, to the queue endpoint');
    assert.deepEqual(result, { job: 'j1', existing: false, lines: ['Queued.', 'No worker: * * * * * cd <app> && php vendor/bin/preflow translate:work'] });
    assert.equal('texts' in result, false, 'nothing to fill in');

    const running = await core.queue(() => Promise.resolve({ job: 'j1', state: 'queued', existing: true, message: 'Already queued.', worker: true }), '/q', {}, 'No worker');
    assert.deepEqual(running.lines, ['Already queued.'], 'no cron hint while the worker runs');
    assert.equal(running.existing, true);
});
