// The blocks editor's cards (assets/blocks.js with admin.js, blocks/card.twig): the "…" menu (the list
// rows' menu) with Move up / Move down following the card's place, Remove keeping the focus in the list,
// the title toggling the card, "Sections · N blocks" and Collapse all / Expand all, cards that htmx adds
// (announced as an edit, guarded), and the card selected in the preview (a sticky highlight).
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const BLOCKS_JS = fileURLToPath(new URL('../../assets/blocks.js', import.meta.url));

/**
 * A record form with one blocks field of three cards (b1 open), as the templates render them.
 * autosave: the form has hx-post (folio:edit); otherwise it is a guarded plain form.
 * split: the editor's split view with a preview frame whose messages are logged.
 */
function editor({ autosave = true, split = false } = {}) {
    const w = createWindow();
    const doc = w.document;
    doc.documentElement.setAttribute('lang', 'en');
    const log = { edits: 0, posted: [], selected: [] };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    let host = doc.body;
    let frame = null;
    if (split) {
        w.innerWidth = 800; // starts in form mode: nothing is rendered until a test asks
        const splitEl = el(doc.body, 'div', { 'data-folio-split': '', 'data-mode': 'form' }, 'folio-split');
        splitEl.style = { setProperty() {} };
        host = el(splitEl, 'div', {}, 'folio-split-form');
        const stage = el(splitEl, 'section', { 'data-folio-stage': '', 'data-preview-url': '/folio/page/preview' });
        frame = el(stage, 'iframe', { name: 'folio-preview-frame' }, 'folio-preview-frame');
        frame.contentWindow.location = { href: 'https://site.test/folio/page/preview' };
        frame.contentWindow.postMessage = (msg) => { log.posted.push(msg); };
    }
    const wrap = el(host, 'div', {}, 'folio-form');
    const form = el(wrap, 'form', autosave ? { id: 'folio-record', 'hx-post': '/folio/page/p1/autosave', 'hx-target': '#folio-save-state' } : { id: 'folio-record' });
    form.addEventListener('folio:edit', () => { log.edits++; });
    const title = el(form, 'input', { name: 'title' });
    title.name = 'title';
    const field = el(form, 'div', { 'data-blocks-field': '' }, 'folio-blocks');
    const head = el(field, 'div', {}, 'folio-blocks-head');
    const count = el(head, 'span', { 'data-blocks-count': '', 'data-one': ':count block', 'data-other': ':count blocks' }, 'folio-blocks-count');
    count.textContent = '· 3 blocks';
    const all = el(head, 'button', { type: 'button', 'data-blocks-collapse-all': '', 'data-text-collapse': 'Collapse all', 'data-text-expand': 'Expand all' }, 'folio-blocks-collapse-all');
    all.hidden = true;
    const list = el(field, 'div', { 'data-blocks-list': '' }, 'folio-blocks-list');
    const card = (uid, open) => {
        const c = el(list, 'div', { id: 'block-' + uid, 'data-block-card': '', 'data-block-uid': uid }, 'folio-block-card' + (open ? ' is-open' : ''));
        const h = el(c, 'div', {}, 'folio-block-head');
        el(h, 'button', { type: 'button', 'data-block-handle': '' }, 'folio-block-handle');
        const titleEl = el(h, 'span', { 'data-block-title': '' }, 'folio-block-title');
        el(titleEl, 'span', {}, 'folio-block-label').textContent = 'Block ' + uid;
        const menu = el(h, 'details', { 'data-folio-row-menu': '' }, 'folio-row-menu folio-block-menu');
        const summary = el(menu, 'summary', { 'aria-haspopup': 'menu' });
        const panel = el(menu, 'div', { role: 'menu' }, 'folio-menu folio-row-menu-panel');
        const up = el(panel, 'button', { type: 'button', role: 'menuitem', 'data-block-up': '' }, 'folio-menu-item');
        const down = el(panel, 'button', { type: 'button', role: 'menuitem', 'data-block-down': '' }, 'folio-menu-item');
        const duplicate = el(panel, 'button', { type: 'button', role: 'menuitem', 'data-block-duplicate': '' }, 'folio-menu-item');
        const remove = el(panel, 'button', { type: 'button', role: 'menuitem', 'data-block-remove': '' }, 'folio-menu-item is-danger');
        const toggle = el(h, 'button', { type: 'button', 'data-block-collapse': '', 'aria-expanded': open ? 'true' : 'false' }, 'folio-block-toggle');
        const body = el(c, 'div', {}, 'folio-block-body');
        const input = el(body, 'input', { name: `sections[${uid}][headline]` });
        return { c, title: titleEl, menu, summary, up, down, duplicate, remove, toggle, input };
    };
    const cards = [card('b1', true), card('b2', false), card('b3', false)];
    const picker = el(list, 'div', { 'data-blocks-picker': '' }, 'folio-blocks-picker');
    const add = el(picker, 'button', { type: 'button' }, 'btn folio-blocks-add');
    el(doc.body, 'div', { 'data-folio-toasts': '' });
    load(w, ADMIN_JS);
    load(w, BLOCKS_JS);
    if (frame) { frame.dispatchEvent(new w.Event('load')); }
    return { w, doc, form, title, field, count, all, list, cards, card, add, log, el };
}

const ids = (e) => e.list.children.filter((c) => c.hasAttribute('data-block-card')).map((c) => c.getAttribute('data-block-uid'));

test('Collapse all shows with cards and names what it will do', () => {
    const e = editor();
    assert.equal(e.all.hidden, false);
    assert.equal(e.all.textContent, 'Collapse all', 'one card is open');
    e.all.click();
    assert.deepEqual(e.cards.map((c) => c.c.classList.contains('is-open')), [false, false, false]);
    assert.deepEqual(e.cards.map((c) => c.toggle.getAttribute('aria-expanded')), ['false', 'false', 'false']);
    assert.equal(e.all.textContent, 'Expand all');
    e.all.click();
    assert.deepEqual(e.cards.map((c) => c.c.classList.contains('is-open')), [true, true, true]);
    assert.equal(e.all.textContent, 'Collapse all');
});

test('the title toggles its card like the chevron; the label follows', () => {
    const e = editor();
    e.cards[0].toggle.click();
    assert.equal(e.all.textContent, 'Expand all', 'none open now');
    e.cards[1].title.click();
    assert.equal(e.cards[1].c.classList.contains('is-open'), true);
    assert.equal(e.cards[1].toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(e.all.textContent, 'Collapse all');
});

test('Move up is off on the first card, Move down on the last, and they follow a move', () => {
    const e = editor();
    assert.deepEqual(e.cards.map((c) => [c.up.disabled, c.down.disabled]), [[true, false], [false, false], [false, true]]);
    e.cards[0].summary.click();
    assert.equal(e.cards[0].menu.open, true);
    assert.equal(e.doc.activeElement, e.cards[0].down, 'the menu opens on its first item that works');
    e.cards[0].down.click();
    assert.deepEqual(ids(e), ['b2', 'b1', 'b3']);
    assert.equal(e.cards[0].menu.open, false, 'the action closes the menu');
    assert.equal(e.doc.activeElement, e.cards[0].summary, 'and the focus goes back to its button');
    assert.deepEqual(e.cards.map((c) => [c.up.disabled, c.down.disabled]), [[false, false], [true, false], [false, true]]);
});

test('Remove counts down, keeps the focus in the list and is an edit', () => {
    const e = editor();
    e.cards[1].summary.click();
    e.cards[1].remove.click();
    assert.deepEqual(ids(e), ['b1', 'b3']);
    assert.equal(e.count.textContent, '· 2 blocks');
    assert.equal(e.doc.activeElement, e.cards[2].toggle, 'on the next card');
    assert.ok(e.log.edits > 0);
    e.cards[2].remove.click();
    e.cards[0].remove.click();
    assert.equal(e.count.textContent, '· 0 blocks');
    assert.equal(e.all.hidden, true, 'nothing to collapse');
    assert.equal(e.doc.activeElement, e.add, 'the last one gone: on "Add block"');
});

test('one block is "1 block"', () => {
    const e = editor();
    e.cards[0].remove.click();
    e.cards[1].remove.click();
    assert.equal(e.count.textContent, '· 1 block');
});

test('a card htmx adds is counted, gets its menu and is announced as an edit', async () => {
    const e = editor();
    const before = e.log.edits;
    const added = e.card('b4', true);
    e.list.appendChild(e.list.children.find((c) => c.hasAttribute('data-blocks-picker'))); // the picker stays last
    added.c.dispatchEvent(new e.w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: added.c } }));
    assert.equal(e.count.textContent, '· 4 blocks');
    assert.equal(added.menu.getAttribute('data-folio-row-menu-ready'), '', 'its menu works like the others');
    assert.equal(added.up.disabled, false);
    assert.equal(e.cards[2].down.disabled, false, 'the card above is no longer the last');
    assert.ok(e.log.edits > before, 'folio:edit: the autosave hears it');
    added.input.value = 'Hello';
    added.input.dispatchEvent(new e.w.Event('input', { bubbles: true }));
    assert.ok(e.log.edits > before + 1, 'and its fields like any other');
});

test('a card htmx adds to a guarded form marks it unsaved', () => {
    const e = editor({ autosave: false });
    assert.equal(e.w.Folio.unsaved.isDirty(), false);
    const added = e.card('b4', true);
    added.c.dispatchEvent(new e.w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: added.c } }));
    assert.equal(e.w.Folio.unsaved.isDirty(), true);
});

test('the card with the focus stays selected in the preview until another card is', () => {
    const e = editor({ split: true });
    e.cards[1].input.focus();
    const last = () => e.log.posted[e.log.posted.length - 1];
    assert.deepEqual({ uid: last().uid, sticky: last().sticky }, { uid: 'b2', sticky: true });
    e.title.focus();
    assert.deepEqual({ uid: last().uid, field: last().field, sticky: last().sticky }, { uid: null, field: 'title', sticky: false });
    // A re-rendered preview gets the selected card again, then the focused field.
    e.log.posted = [];
    e.w.receive({ source: 'folio-preview', type: 'ready' }, 'https://site.test', e.doc.querySelector('iframe').contentWindow);
    assert.deepEqual(e.log.posted.map((m) => [m.uid, m.field, m.sticky]), [['b2', null, true], [null, 'title', false]]);
});

test('opening a card by its chevron selects it in the preview', () => {
    const e = editor({ split: true });
    e.cards[2].toggle.click();
    const last = e.log.posted[e.log.posted.length - 1];
    assert.equal(last.uid, 'b3');
    assert.equal(last.sticky, true);
});

test('Remove next to an unknown block moves the focus to its Remove, then to "Add block"', () => {
    const e = editor();
    const picker = e.list.children.find((c) => c.hasAttribute('data-blocks-picker'));
    const unknown = e.doc.createElement('div');
    unknown.setAttribute('data-block-card', '');
    unknown.setAttribute('data-block-uid', 'b9');
    unknown.className = 'folio-block-card is-unknown';
    const head = unknown.appendChild(e.doc.createElement('div'));
    head.className = 'folio-block-head';
    const remove = head.appendChild(e.doc.createElement('button'));
    remove.setAttribute('data-block-remove', '');
    e.list.insertBefore(unknown, picker);
    e.cards[0].remove.click();
    e.cards[1].remove.click();
    e.cards[2].remove.click();
    assert.equal(e.doc.activeElement, remove, 'the unknown card has no chevron: its Remove');
    remove.click();
    assert.equal(e.doc.activeElement, e.add);
});
