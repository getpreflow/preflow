// Stepped runs (assets/admin.js): when a self-polling progress partial's step request fails, htmx
// swaps nothing and the polling stops; the partial's hidden "stalled" alert (with Continue) shows.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** A progress partial (id, marker attribute) with its hidden stalled alert. */
function page({ id, marker, stalledAttr }) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const progress = doc.body.appendChild(doc.createElement('div'));
    if (id) { progress.setAttribute('id', id); }
    if (marker) { progress.setAttribute(marker, ''); }
    const stalled = progress.appendChild(doc.createElement('div'));
    stalled.setAttribute(stalledAttr, '');
    stalled.hidden = true;
    load(w, ADMIN_JS);
    return { progress, stalled };
}

function fail(elt, name) {
    elt.dispatchEvent(new CustomEvent(name, { bubbles: true, cancelable: true, detail: { elt } }));
}

for (const name of ['htmx:responseError', 'htmx:sendError', 'htmx:timeout']) {
    test(`the SEO audit's progress shows its stalled alert on ${name}`, () => {
        const { progress, stalled } = page({ id: 'folio-audit-progress', marker: 'data-folio-run-progress', stalledAttr: 'data-folio-run-stalled' });

        fail(progress, name);

        assert.equal(stalled.hidden, false);
    });
}

test('the AI run progress still shows its stalled alert', () => {
    const { progress, stalled } = page({ id: 'folio-ai-progress', marker: null, stalledAttr: 'data-folio-ai-stalled' });

    fail(progress, 'htmx:responseError');

    assert.equal(stalled.hidden, false);
});

test('another element failing leaves a stalled alert inside it alone', () => {
    const { progress, stalled } = page({ id: 'something-else', marker: null, stalledAttr: 'data-folio-run-stalled' });

    fail(progress, 'htmx:responseError');

    assert.equal(stalled.hidden, true);
});
