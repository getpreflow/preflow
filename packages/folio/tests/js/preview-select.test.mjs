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
    // From the editor: the frame's parent window (the bridge ignores messages from anywhere else).
    const send = (msg, from = w.parent) => w.receive({ source: 'folio-editor', type: 'highlight', scroll: false, ...msg }, w.location.origin, from);
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

test('a message that does not come from the editor window is ignored', () => {
    const p = preview();
    p.send({ uid: 'aaaaaaaaaaaaaaaa', field: null, sticky: true }, { postMessage() {} });
    assert.equal(p.box('is-selected').hidden, true);
    p.send({ uid: 'aaaaaaaaaaaaaaaa', field: null, sticky: true });
    assert.equal(p.box('is-selected').hidden, false);
});

test('links leave nothing: an in-page anchor scrolls in the preview instead of loading the page address', () => {
    const p = preview();
    const team = p.doc.body.appendChild(p.doc.createElement('section'));
    team.id = 'team';
    let scrolled = 0;
    team.scrollIntoView = () => { scrolled++; };
    const anchor = p.doc.body.appendChild(p.doc.createElement('a'));
    anchor.setAttribute('href', '#team');
    const away = p.doc.body.appendChild(p.doc.createElement('a'));
    away.setAttribute('href', 'other-page');

    assert.equal(anchor.click().defaultPrevented, true, 'the page\'s <base> never turns it into a load');
    assert.equal(scrolled, 1, 'it scrolls to its target');
    assert.equal(away.click().defaultPrevented, true, 'any other link stays in the preview');
    assert.equal(scrolled, 1);
});

test('an anchor that is no valid escape neither throws nor leaves the preview', () => {
    const p = preview();
    const broken = p.doc.body.appendChild(p.doc.createElement('a'));
    broken.setAttribute('href', '#%E0');

    assert.equal(broken.click().defaultPrevented, true);
});

// Interactive parts switched off (the bar marks it: the site's key can't sign a preview token).
function lockedPreview() {
    const w = createWindow();
    const doc = w.document;
    w.parent = { postMessage() {}, document: { getElementById: () => null } };
    w.requestAnimationFrame = (fn) => { fn(); return 0; };
    w.CSS = { escape: (s) => s };
    w.Object = Object;
    const origCreate = doc.createElement;
    doc.createElement = (tag) => { const e = origCreate(tag); e.style = {}; return e; };
    const el = (parent, tag, attrs = {}) => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        return e;
    };
    const button = el(doc.body, 'button', { 'hx-post': '/--component/action?token=x' });
    const form = el(doc.body, 'form', { action: '/subscribe', method: 'post' });
    const block = el(doc.body, 'section', { 'data-folio-block': 'aaaaaaaaaaaaaaaa' });
    const inBlock = el(block, 'button', {});
    const bar = el(doc.body, 'aside', { class: 'folio-pp-bar' });
    el(bar, 'span', { 'data-folio-preview-locked': '', 'data-folio-locked-text': 'Switched off in this preview.' });
    load(w, PREVIEW_JS);
    const notice = () => doc.body.children.find((c) => c.className === 'folio-pe-notice') || null;
    return { w, doc, button, form, inBlock, notice };
}

test('switched off: a click on something interactive is stopped and says why', () => {
    const p = lockedPreview();
    let reached = false;
    p.button.addEventListener('click', () => { reached = true; });

    assert.equal(p.button.click().defaultPrevented, true);
    assert.equal(reached, false, 'htmx on the button never sees it');
    assert.ok(p.notice(), 'a notice in the frame');
    assert.equal(p.notice().hidden, false);
    assert.equal(p.notice().textContent, 'Switched off in this preview.');
    assert.equal(p.notice().getAttribute('role'), 'status');
});

test('switched off: a form never submits and an htmx request is prevented', () => {
    const p = lockedPreview();
    let reached = false;
    p.form.addEventListener('submit', () => { reached = true; });
    const submit = new p.w.Event('submit', { bubbles: true, cancelable: true });
    p.form.dispatchEvent(submit);
    assert.equal(submit.defaultPrevented, true);
    assert.equal(reached, false);
    assert.equal(p.notice().hidden, false);

    const request = new p.w.Event('htmx:beforeRequest', { bubbles: true, cancelable: true });
    p.button.dispatchEvent(request);
    assert.equal(request.defaultPrevented, true, 'htmx sends nothing');
});

test('switched off: a click in a block still selects it for editing, with no notice', () => {
    const p = lockedPreview();
    assert.equal(p.inBlock.click().defaultPrevented, true);
    assert.equal(p.notice(), null);
});

test('not switched off: htmx requests go out as before', () => {
    const p = preview();
    const request = new p.w.Event('htmx:beforeRequest', { bubbles: true, cancelable: true });
    p.title.dispatchEvent(request);
    assert.equal(request.defaultPrevented, false);
});
