// Linked records in the preview (0.92): an element a page template marked with preview_mark() (Folio's
// data-folio-record, data-folio-record-id, data-folio-label) is outlined with its label on hover, and a
// click on it, also inside a block, asks the editor to open that record (assets/preview-editor.js); the
// editor opens {prefix}/{type}/{id}/edit?_drawer=1 for a well-formed type and id only, from either
// preview frame (assets/admin.js); a saved drawer (folio:drawer-saved) renders the live preview again
// and reloads the whole-page frame (assets/page-preview.js).
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const PREVIEW_JS = fileURLToPath(new URL('../../assets/preview-editor.js', import.meta.url));
const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const PAGE_PREVIEW_JS = fileURLToPath(new URL('../../assets/page-preview.js', import.meta.url));

/** The preview frame's page: a block holding a linked record (with a field target inside it), and a record outside blocks. */
function previewPage() {
    const w = createWindow();
    const doc = w.document;
    const sent = [];
    w.parent = { postMessage(msg) { sent.push(msg); }, document: { getElementById: () => null } };
    w.requestAnimationFrame = (fn) => { fn(); return 0; };
    w.CSS = { escape: (s) => s };
    w.innerHeight = 900;
    w.Object = Object;
    const origCreate = doc.createElement;
    doc.createElement = (tag) => { const e = origCreate(tag); e.style = {}; return e; };
    const el = (parent, tag, attrs = {}) => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        e.getBoundingClientRect = () => ({ left: 10, top: 20, width: 300, height: 120, bottom: 140 });
        return e;
    };
    const block = el(doc.body, 'section', { 'data-folio-block': 'aaaaaaaaaaaaaaaa', 'data-folio-label': 'Team' });
    const heading = el(block, 'h2', { 'data-folio-edit': 'title' });
    const teaser = el(block, 'a', { href: '/team/ann', 'data-folio-record': 'member', 'data-folio-record-id': 'm1', 'data-folio-label': 'Member' });
    const name = el(teaser, 'span', { 'data-folio-edit': 'name' });
    const footer = el(doc.body, 'footer', {});
    const contact = el(footer, 'a', { href: '/contact', 'data-folio-record': 'page', 'data-folio-record-id': 'contact', 'data-folio-label': 'Page' });
    load(w, PREVIEW_JS);
    const overlay = doc.getElementById('folio-pe-overlay');
    const box = (state) => overlay.children.find((b) => b.classList.contains(state));
    const hover = (target) => target.dispatchEvent(new w.Event('mouseover', { bubbles: true }));
    return { w, doc, sent, block, heading, teaser, name, contact, box, hover };
}

const last = (p) => p.sent[p.sent.length - 1];

test('a click on a linked record inside a block opens the record, not the block', () => {
    const p = previewPage();

    assert.equal(p.name.click().defaultPrevented, true, 'its link is not followed');
    assert.deepEqual({ type: last(p).type, recordType: last(p).recordType, id: last(p).id, source: last(p).source },
        { type: 'open', recordType: 'member', id: 'm1', source: 'folio-preview' });
    assert.equal(p.sent.filter((m) => m.type === 'select').length, 0, 'no select for the block around it');

    p.heading.click();
    assert.deepEqual({ type: last(p).type, uid: last(p).uid, field: last(p).field }, { type: 'select', uid: 'aaaaaaaaaaaaaaaa', field: 'title' }, 'the block\'s own parts still select it');
});

test('a linked record outside every block opens too', () => {
    const p = previewPage();

    assert.equal(p.contact.click().defaultPrevented, true);
    assert.deepEqual({ type: last(p).type, recordType: last(p).recordType, id: last(p).id }, { type: 'open', recordType: 'page', id: 'contact' });
});

test('hovering a linked record outlines it with the type\'s label, the innermost one', () => {
    const p = previewPage();
    const hoverBox = p.box('is-hover');

    p.hover(p.name);
    assert.equal(hoverBox.hidden, false);
    assert.equal(hoverBox.querySelector('.folio-pe-label').textContent, 'Member');
    assert.equal(p.box('is-field').hidden, true, 'the block\'s field target inside the record is not underlined');

    p.hover(p.heading);
    assert.equal(hoverBox.querySelector('.folio-pe-label').textContent, 'Team', 'outside the record: the block');
});

/** The editor's split view (admin.js) with the live frame, the whole-page frame and the device stage, starting in form mode. */
function editor({ pagePreview = false } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    w.innerWidth = 800; // form mode: nothing renders until the view switches
    const fetched = [];
    w.fetch = (url, init) => { fetched.push({ url, method: init && init.method }); return Promise.resolve({ ok: false }); };
    w.FormData = class { forEach() {} };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const switcher = el(doc.body, 'div', { 'data-folio-preview': '' });
    const split = el(switcher, 'button', { 'data-folio-view': 'split' });
    const splitEl = el(doc.body, 'div', { 'data-folio-split': '', 'data-mode': 'form' }, 'folio-split');
    splitEl.style = { setProperty() {} };
    const host = el(splitEl, 'div', {}, 'folio-split-form');
    const form = el(host, 'form', { id: 'folio-record', 'data-folio-record-type': 'post', 'data-folio-record-id': 'p1' });
    el(form, 'input', { type: 'hidden', name: '_version' });
    const title = el(form, 'input', { type: 'text', name: 'title' });
    const stage = el(splitEl, 'section', pagePreview
        ? { 'data-folio-stage': '', 'data-preview-url': '/folio/post/p1/preview', 'data-page-preview': '', 'data-page-preview-url': '/folio/preview/page/post/p1' }
        : { 'data-folio-stage': '', 'data-preview-url': '/folio/post/p1/preview' });
    const pageTab = el(stage, 'button', { 'data-preview-tab': 'page', 'aria-pressed': 'false' });
    const live = el(stage, 'iframe', { name: 'folio-preview-frame' }, 'folio-preview-frame');
    live.contentWindow.location = { href: 'https://site.test/folio/post/p1/preview' };
    live.contentWindow.postMessage = () => {};
    const page = el(stage, 'iframe', { name: 'folio-page-frame' }, 'folio-preview-frame folio-page-frame');
    page.contentWindow.location = { href: 'about:blank' };
    page.style = {};
    load(w, ADMIN_JS);
    if (pagePreview) { load(w, PAGE_PREVIEW_JS); }
    live.dispatchEvent(new w.Event('load'));
    const drawer = () => doc.querySelector('.folio-drawer iframe');
    const from = (frame, msg) => w.receive({ source: 'folio-preview', type: 'open', ...msg }, 'https://site.test', frame.contentWindow);
    const saved = () => doc.dispatchEvent(new w.CustomEvent('folio:drawer-saved', { detail: { type: 'member', id: 'm1' } }));
    return { w, doc, fetched, split, live, page, pageTab, drawer, from, saved, title };
}

test('the editor opens the linked record in the drawer, from the live and the whole-page preview', () => {
    const e = editor();

    e.from(e.live, { recordType: 'member', id: 'm1' });
    assert.equal(e.drawer().src, '/folio/member/m1/edit?_drawer=1');

    e.from(e.page, { recordType: 'team_member', id: 'A-b_9' });
    assert.equal(e.drawer().src, '/folio/team_member/A-b_9/edit?_drawer=1', 'the whole-page frame\'s bridge too');
});

test('the record open in the editor opens no drawer: its form takes the focus', () => {
    const e = editor();

    e.from(e.live, { recordType: 'post', id: 'p1' });
    assert.equal(e.drawer(), null, 'no second copy of the record, whose save would make this form\'s next save conflict');
    assert.equal(e.doc.activeElement, e.title, 'the form\'s first field');

    e.from(e.page, { recordType: 'post', id: 'p2' });
    assert.equal(e.drawer().src, '/folio/post/p2/edit?_drawer=1', 'another record of the same type opens');
});

test('a forged type or id is ignored', () => {
    const e = editor();
    const forged = [
        { recordType: 'Member', id: 'm1' },
        { recordType: '../settings', id: 'm1' },
        { recordType: 'member/x', id: 'm1' },
        { recordType: '1member', id: 'm1' },
        { recordType: 'member', id: '../../users/1' },
        { recordType: 'member', id: 'm1?x=1' },
        { recordType: 'member', id: 'm1\n' },
        { recordType: 'member', id: 'x'.repeat(65) },
        { recordType: 'member', id: '' },
        { recordType: 'member', id: 7 },
        { recordType: ['member'], id: 'm1' },
        { id: 'm1' },
    ];
    forged.forEach((msg) => e.from(e.live, msg));
    assert.equal(e.drawer(), null, 'no drawer for any of them');

    // Nor from a window that is not one of the preview frames.
    e.w.receive({ source: 'folio-preview', type: 'open', recordType: 'member', id: 'm1' }, 'https://site.test', { postMessage() {} });
    e.w.receive({ source: 'folio-preview', type: 'open', recordType: 'member', id: 'm1' }, 'https://evil.test', e.live.contentWindow);
    assert.equal(e.drawer(), null);

    e.from(e.live, { recordType: 'member', id: 'x'.repeat(64) });
    assert.equal(e.drawer().src, '/folio/member/' + 'x'.repeat(64) + '/edit?_drawer=1', 'an id of 64 characters is one');
});

test('a saved drawer renders the live preview again; in form mode it waits for the preview', () => {
    const e = editor();
    const renders = () => e.fetched.filter((f) => f.url === '/folio/post/p1/preview' && f.method === 'POST').length;

    e.saved();
    assert.equal(renders(), 0, 'form mode: nothing shows, nothing renders');
    e.split.click();
    assert.equal(renders(), 1, 'the preview shows: it renders');

    e.saved();
    assert.equal(renders(), 2, 'rendered again after the drawer\'s save');
});

test('a saved drawer reloads the whole-page frame', () => {
    const e = editor({ pagePreview: true });
    e.pageTab.click();
    assert.equal(e.page.getAttribute('src'), '/folio/preview/page/post/p1');
    e.page.setAttribute('src', 'about:blank');

    e.saved();
    assert.equal(e.page.getAttribute('src'), '/folio/preview/page/post/p1', 'loaded again with the saved record');
});
