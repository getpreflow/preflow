// The preview bar's Copy preview link (assets/editor-frame.js with admin.js's Folio.copyLater, form.twig):
// the button posts the share route (htmx) and its answer lands under the bar. The clipboard write starts
// in the click itself (a ClipboardItem whose text is still on its way, so Safari takes it), with writeText
// as the fallback. Then the button reads "Link copied · 7 days" for a few seconds, or that the link could
// not be copied; a role="status" next to it announces the same. Run: node --test packages/folio/tests/js/*.test.mjs
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const EDITOR_JS = fileURLToPath(new URL('../../assets/editor-frame.js', import.meta.url));

/**
 * clipboard: 'item' (write + ClipboardItem), 'text' (writeText only), 'refuse' (both refuse), 'none'.
 */
function bar({ clipboard = 'item' } = {}) {
    const w = createWindow();
    const doc = w.document;
    const log = { copied: [], writeCalls: 0 };
    const clip = {};
    if (clipboard === 'item' || clipboard === 'refuse') {
        w.ClipboardItem = class { constructor(data) { this.data = data; } };
        w.Blob = Blob;
        clip.write = (items) => {
            log.writeCalls++;
            if (clipboard === 'refuse') { return Promise.reject(new Error('NotAllowedError')); }
            return Promise.resolve(items[0].data['text/plain']).then((blob) => blob.text()).then((text) => { log.copied.push(text); });
        };
    }
    if (clipboard === 'text') {
        clip.writeText = (text) => { log.copied.push(text); return Promise.resolve(); };
    }
    if (clipboard === 'refuse') {
        clip.writeText = () => Promise.reject(new Error('NotAllowedError'));
    }
    w.navigator = clipboard === 'none' ? {} : { clipboard: clip };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    el(doc.body, 'script', { id: 'folio-i18n', type: 'application/json' }).textContent = JSON.stringify({ link_not_copied: 'The link could not be copied.' });
    const stage = el(doc.body, 'section', {}, 'folio-split-stage');
    const row = el(stage, 'div', {}, 'folio-preview-bar');
    const button = el(row, 'button', { type: 'button', 'data-folio-share-copy': '', 'data-text-copied': 'Link copied · 7 days', 'aria-label': 'Copy preview link' }, 'btn btn-secondary btn-icon btn-sm folio-share-button');
    const status = el(button, 'span', { 'aria-hidden': 'true' }, 'folio-share-status');
    const announce = el(row, 'span', { role: 'status', 'data-folio-share-announce': '' }, 'folio-sr');
    el(stage, 'div', { id: 'folio-share' }, 'folio-share');
    el(doc.body, 'form', { id: 'folio-record' });
    load(w, ADMIN_JS);
    load(w, EDITOR_JS);
    /** htmx's answer: the share fragment replaces #folio-share, then afterRequest on the button. */
    const answer = ({ url = 'https://example.test/hello?folio_preview=abc', successful = true } = {}) => {
        const old = doc.getElementById('folio-share');
        const box = doc.createElement('div');
        box.setAttribute('id', 'folio-share');
        if (url) {
            const input = box.appendChild(doc.createElement('input'));
            input.className = 'folio-share-url';
            input.value = url;
        }
        old.parentNode.insertBefore(box, old);
        old.remove();
        button.dispatchEvent(new w.CustomEvent('htmx:afterRequest', { bubbles: true, detail: { elt: button, successful } }));
    };
    return { w, doc, button, status, announce, answer, log };
}

const settle = async () => { for (let i = 0; i < 10; i++) { await new Promise((resolve) => setImmediate(resolve)); } };

test('the clipboard write starts in the click and takes the address when the answer comes', async () => {
    const b = bar();
    b.button.click();
    assert.equal(b.log.writeCalls, 1, 'inside the user\'s gesture, before any answer');
    b.answer();
    await settle();
    assert.deepEqual(b.log.copied, ['https://example.test/hello?folio_preview=abc']);
    assert.equal(b.button.classList.contains('is-copied'), true);
    assert.equal(b.status.textContent, 'Link copied · 7 days');
    assert.equal(b.announce.textContent, 'Link copied · 7 days', 'announced by the status next to the button');
});

test('without ClipboardItem it waits for the answer and uses writeText', async () => {
    const b = bar({ clipboard: 'text' });
    b.button.click();
    b.answer();
    await settle();
    assert.deepEqual(b.log.copied, ['https://example.test/hello?folio_preview=abc']);
    assert.equal(b.button.classList.contains('is-copied'), true);
});

test('after a few seconds the button is the icon again', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const b = bar();
        b.button.click();
        b.answer();
        await settle();
        mock.timers.tick(4000);
        assert.equal(b.button.classList.contains('is-copied'), false);
        assert.equal(b.status.textContent, '');
        assert.equal(b.announce.textContent, '');
    } finally {
        mock.timers.reset();
    }
});

test('a refused copy says so; the answer under the bar stays the way', async () => {
    const b = bar({ clipboard: 'refuse' });
    b.button.click();
    b.answer();
    await settle();
    assert.equal(b.button.classList.contains('is-copied'), false);
    assert.equal(b.button.classList.contains('is-copy-failed'), true);
    assert.equal(b.status.textContent, 'The link could not be copied.');
    assert.equal(b.announce.textContent, 'The link could not be copied.');
    assert.ok(b.doc.querySelector('.folio-share-url'), 'the address to copy by hand');
});

test('no clipboard at all: the same message', async () => {
    const b = bar({ clipboard: 'none' });
    b.button.click();
    b.answer();
    await settle();
    assert.equal(b.status.textContent, 'The link could not be copied.');
});

test('a failed request or an answer without a link: nothing is copied, and it says so', async () => {
    const b = bar();
    b.button.click();
    b.answer({ successful: false });
    await settle();
    assert.deepEqual(b.log.copied, []);
    assert.equal(b.button.classList.contains('is-copy-failed'), true);
    const c = bar({ clipboard: 'text' });
    c.button.click();
    c.answer({ url: null });
    await settle();
    assert.deepEqual(c.log.copied, []);
    assert.equal(c.status.textContent, 'The link could not be copied.');
});
