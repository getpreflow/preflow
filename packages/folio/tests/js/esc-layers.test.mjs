// One Esc closes one layer (0.75): the editor's scripts load as the layout loads them (admin.js, then
// fields.js, then blocks.js and editor-frame.js). With the phone side sheet open, Esc in a side-panel
// relation search closes only its results; the asset drawer closes alone; the next Esc closes the sheet.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const asset = (name) => fileURLToPath(new URL('../../assets/' + name, import.meta.url));

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

/** The editor with the side sheet, a relation field (with its options) in the side panel's record fieldset. */
function editor() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        if (attrs.type) { e.type = attrs.type; }
        if (attrs.name) { e.name = attrs.name; }
        if (attrs.value !== undefined) { e.value = attrs.value; }
        return e;
    };
    const topbar = el(doc.body, 'header', {}, 'folio-topbar');
    const state = el(topbar, 'span', { id: 'folio-save-state' }, 'folio-save-state');
    el(state, 'span', { 'data-folio-save-text': '' });
    const toggle = el(topbar, 'button', { type: 'button', 'data-folio-side-toggle': '', 'aria-expanded': 'false' });
    el(topbar, 'button', { type: 'submit', form: 'folio-record', 'data-folio-save': '' }, 'btn');
    const wrap = el(doc.body, 'div', {}, 'folio-form folio-editor');
    el(wrap, 'form', { id: 'folio-record', 'hx-post': '/folio/news/n1/autosave', 'hx-target': '#folio-save-state' });
    const side = el(doc.body, 'aside', { id: 'folio-side', 'data-folio-side': '' }, 'folio-side');
    el(side, 'button', { type: 'button', 'data-folio-side-close': '' });
    const fields = el(side, 'fieldset', { form: 'folio-record' }, 'folio-side-fields');
    const field = el(fields, 'fieldset', { 'data-folio-relation': '', 'data-folio-relation-name': 'writer' }, 'folio-relation');
    const rows = el(field, 'ul', { 'data-folio-relation-selected': '' });
    const holder = el(rows, 'li');
    holder.hidden = true;
    el(holder, 'input', { type: 'hidden', name: 'writer', value: '', form: 'folio-record' });
    const find = el(el(field, 'label', { 'data-folio-no-edit': '' }), 'input', { type: 'search', 'data-folio-relation-find': '' });
    el(field, 'script', { type: 'application/json', 'data-folio-relation-options': '' }).textContent = JSON.stringify([{ id: 'a1', label: 'Ann' }, { id: 'a2', label: 'Bob' }]);
    const results = el(field, 'ul', { 'data-folio-relation-results': '', 'data-text-empty': 'Nothing found.' });
    results.hidden = true;
    const template = el(field, 'template', { 'data-folio-relation-template': '' });
    const li = el(template, 'li', { 'data-folio-relation-item': '' }, 'folio-relation-row');
    el(el(li, 'span', {}, 'folio-relation-text'), 'span', {}, 'folio-relation-label');
    el(li, 'input', { type: 'hidden', name: 'writer', value: '' });
    ['admin.js', 'fields.js', 'blocks.js', 'editor-frame.js'].forEach((name) => load(w, asset(name)));
    return { w, doc, toggle, side, find, results };
}

const isOpen = (p) => p.side.classList.contains('is-open');

async function showResults(p) {
    p.find.focus();
    p.find.value = '';
    p.find.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    await tick();
    assert.equal(p.results.hidden, false, 'the results show');
}

test('Esc in a side-panel relation search closes its results only; the next Esc closes the sheet', async () => {
    const p = editor();
    p.toggle.click();
    assert.ok(isOpen(p));
    await showResults(p);

    const first = p.find.keydown('Escape');
    assert.equal(first.defaultPrevented, true);
    assert.equal(p.results.hidden, true, 'the results close');
    assert.ok(isOpen(p), 'the sheet stays open');
    assert.equal(p.doc.activeElement, p.find, 'the focus stays in the search');

    const second = p.find.keydown('Escape');
    assert.equal(second.defaultPrevented, true);
    assert.ok(!isOpen(p), 'now the sheet closes');
    assert.equal(p.doc.activeElement, p.toggle);
});

test('Esc on a focused relation result closes the results, back to the search, the sheet stays', async () => {
    const p = editor();
    p.toggle.click();
    await showResults(p);
    const pick = p.results.querySelector('[data-folio-relation-pick]');
    pick.focus();

    pick.keydown('Escape');
    assert.equal(p.results.hidden, true);
    assert.equal(p.doc.activeElement, p.find);
    assert.ok(isOpen(p), 'the sheet stays open');
});

test('Esc with the asset drawer open closes the drawer only, then the sheet', () => {
    const p = editor();
    p.toggle.click();
    p.w.Folio.chooseAssets({}, () => {});
    const drawer = () => p.doc.body.querySelector('.folio-drawer');
    assert.ok(drawer());

    const first = drawer().querySelector('.folio-drawer-close').keydown('Escape');
    assert.equal(first.defaultPrevented, true);
    assert.equal(drawer(), null, 'the drawer closes');
    assert.ok(isOpen(p), 'the sheet stays open');

    p.find.focus();
    p.find.keydown('Escape');
    assert.ok(!isOpen(p), 'the next Esc closes the sheet');
});
