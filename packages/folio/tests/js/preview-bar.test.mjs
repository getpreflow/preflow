// The preview bar's Copy preview link (assets/editor-frame.js, form.twig): the button posts the share
// route (htmx) and its answer lands under the bar; then the link goes to the clipboard and the button
// reads "Link copied · 7 days" (accent-soft, aria-live) for a few seconds. Without a clipboard the answer
// with its address field and Copy stays the way. Run: node --test packages/folio/tests/js/*.test.mjs
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const EDITOR_JS = fileURLToPath(new URL('../../assets/editor-frame.js', import.meta.url));

function bar({ clipboard = true } = {}) {
    const w = createWindow();
    const doc = w.document;
    const log = { copied: [] };
    if (clipboard) {
        w.navigator = { clipboard: { writeText: (text) => { log.copied.push(text); return Promise.resolve(); } } };
    } else {
        w.navigator = {};
    }
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const stage = el(doc.body, 'section', {}, 'folio-split-stage');
    const row = el(stage, 'div', {}, 'folio-preview-bar');
    const button = el(row, 'button', { type: 'button', 'data-folio-share-copy': '', 'data-text-copied': 'Link copied · 7 days', 'aria-label': 'Copy preview link' }, 'btn btn-secondary btn-icon btn-sm folio-share-button');
    const status = el(button, 'span', { 'aria-live': 'polite' }, 'folio-share-status');
    el(stage, 'div', { id: 'folio-share' }, 'folio-share');
    el(doc.body, 'form', { id: 'folio-record' });
    load(w, ADMIN_JS);
    load(w, EDITOR_JS);
    /** htmx's answer: the share fragment replaces #folio-share, then afterRequest on the button. */
    const answer = ({ url = 'https://example.test/hello?folio_preview=abc', successful = true } = {}) => {
        const old = doc.getElementById('folio-share');
        const box = doc.createElement('div');
        box.setAttribute('id', 'folio-share');
        box.className = 'folio-share';
        if (url) {
            const input = box.appendChild(doc.createElement('input'));
            input.className = 'folio-share-url';
            input.value = url;
        } else {
            box.appendChild(doc.createElement('p')).textContent = 'This record has no URL in this language yet.';
        }
        old.parentNode.insertBefore(box, old);
        old.remove();
        button.dispatchEvent(new w.CustomEvent('htmx:afterRequest', { bubbles: true, detail: { elt: button, successful } }));
    };
    return { w, doc, button, status, answer, log };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('the link goes to the clipboard and the button says so, with the lifetime', async () => {
    const b = bar();
    b.answer();
    await settle();
    assert.deepEqual(b.log.copied, ['https://example.test/hello?folio_preview=abc']);
    assert.equal(b.button.classList.contains('is-copied'), true);
    assert.equal(b.status.textContent, 'Link copied · 7 days');
});

test('after a few seconds the button is the icon again', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const b = bar();
        b.answer();
        await settle();
        mock.timers.tick(4000);
        assert.equal(b.button.classList.contains('is-copied'), false);
        assert.equal(b.status.textContent, '');
    } finally {
        mock.timers.reset();
    }
});

test('without a clipboard the answer under the bar is the way: nothing is claimed', async () => {
    const b = bar({ clipboard: false });
    b.answer();
    await settle();
    assert.equal(b.button.classList.contains('is-copied'), false);
    assert.equal(b.status.textContent, '');
    assert.ok(b.doc.querySelector('.folio-share-url'), 'the address to copy by hand');
});

test('no link (no address in this language, a failed request): nothing is copied', async () => {
    const b = bar();
    b.answer({ url: null });
    b.answer({ successful: false });
    await settle();
    assert.deepEqual(b.log.copied, []);
    assert.equal(b.button.classList.contains('is-copied'), false);
});
