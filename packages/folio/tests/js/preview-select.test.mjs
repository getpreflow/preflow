// The preview bridge (assets/preview-editor.js, inside the preview iframe): a block selected in the form
// (a sticky highlight) is outlined with its label and stays so until another block is selected; a
// top-level field is outlined while it has the focus, next to it. Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const PREVIEW_JS = fileURLToPath(new URL('../../assets/preview-editor.js', import.meta.url));

function preview() {
    const w = createWindow();
    const doc = w.document;
    w.parent = { postMessage() {}, document: { getElementById: () => null } };
    w.requestAnimationFrame = (fn) => { fn(); return 0; }; // drawn at once (0: no frame pending)
    w.CSS = { escape: (s) => s };
    w.innerHeight = 900;
    w.Object = Object;
    const el = (parent, tag, attrs = {}) => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        e.getBoundingClientRect = () => ({ left: 10, top: 20, width: 300, height: 120, bottom: 140 });
        e.style = {};
        return e;
    };
    const title = el(doc.body, 'h1', { 'data-folio-field': 'title' });
    const hero = el(doc.body, 'section', { 'data-folio-block': 'aaaaaaaaaaaaaaaa', 'data-folio-label': 'Hero' });
    const text = el(doc.body, 'section', { 'data-folio-block': 'bbbbbbbbbbbbbbbb', 'data-folio-label': 'Text' });
    const origCreate = doc.createElement;
    doc.createElement = (tag) => { const e = origCreate(tag); e.style = {}; return e; };
    load(w, PREVIEW_JS);
    const overlay = doc.getElementById('folio-pe-overlay');
    const box = (state) => overlay.children.find((b) => b.classList.contains(state));
    const send = (msg) => w.receive({ source: 'folio-editor', type: 'highlight', scroll: false, ...msg });
    return { w, doc, title, hero, text, box, send };
}

test('a selected block is outlined with its label', () => {
    const p = preview();
    const selected = p.box('is-selected');
    assert.ok(selected, 'a box of its own');
    assert.equal(selected.hidden, true);
    p.send({ uid: 'aaaaaaaaaaaaaaaa', field: null, sticky: true });
    assert.equal(selected.hidden, false);
    assert.equal(selected.querySelector('.folio-pe-label').textContent, 'Hero');
});

test('the selection stays while a field has the focus, until another block is selected', () => {
    const p = preview();
    const selected = p.box('is-selected');
    const active = p.box('is-active');
    p.send({ uid: 'aaaaaaaaaaaaaaaa', field: null, sticky: true });
    p.send({ uid: null, field: 'title', sticky: false });
    assert.equal(active.hidden, false, 'the field is outlined');
    assert.equal(selected.hidden, false, 'the block stays selected');
    assert.equal(selected.querySelector('.folio-pe-label').textContent, 'Hero');
    p.send({ uid: null, field: null, sticky: false });
    assert.equal(active.hidden, true);
    assert.equal(selected.hidden, false);
    p.send({ uid: 'bbbbbbbbbbbbbbbb', field: null, sticky: true });
    assert.equal(selected.querySelector('.folio-pe-label').textContent, 'Text', 'another block takes the selection');
    assert.equal(active.hidden, true);
});
