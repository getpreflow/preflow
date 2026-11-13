// The blocks editor's "Add block" picker (assets/blocks.js, blocks/field.twig): with script the groups
// move into a popover behind the button, with a search that filters by label and description; Enter
// adds the first match, the arrows move through the visible items, Escape and a click outside close it.
// Typing in the search is no edit of the record. Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const BLOCKS_JS = fileURLToPath(new URL('../../assets/blocks.js', import.meta.url));

const GROUPS = [
    ['Intro', [['Hero', 'hero big opening section'], ['Showcase', 'showcase your models, editable']]],
    ['Code', [['Code tabs', 'code tabs'], ['Compare', 'compare before and after']]],
    ['Media', [['Recordings', 'recordings screen videos']]],
];

/** One blocks field as blocks/field.twig renders it (no cards), in an autosaving record form. */
function picker() {
    const w = createWindow();
    const doc = w.document;
    const log = { edits: 0, added: [] };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const frame = el(doc.body, 'div', {}, 'folio-form');
    const form = el(frame, 'form', { id: 'folio-record', 'hx-post': '/folio/page/p1/autosave', 'hx-target': '#folio-save-state' });
    form.addEventListener('folio:edit', () => { log.edits++; });
    const field = el(form, 'div', { 'data-blocks-field': '' }, 'folio-blocks');
    const list = el(field, 'div', { 'data-blocks-list': '' }, 'folio-blocks-list');
    const root = el(list, 'div', { 'data-blocks-picker': '' }, 'folio-blocks-picker has-popover');
    const button = el(root, 'button', { type: 'button', 'data-blocks-picker-open': '', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-controls': 'folio-picker-1' }, 'btn btn-secondary folio-blocks-open');
    button.hidden = true;
    const popover = el(root, 'div', { id: 'folio-picker-1', 'aria-label': 'Add block', 'data-blocks-popover': '' }, 'folio-blocks-popover');
    const label = el(popover, 'span', {}, 'folio-blocks-picker-label');
    const box = el(popover, 'label', { 'data-blocks-search-box': '' }, 'folio-blocks-search');
    box.hidden = true;
    const search = el(box, 'input', { type: 'search', 'data-blocks-search': '', 'data-folio-no-edit': '' }, 'folio-blocks-search-input');
    const items = {};
    const groups = GROUPS.map(([name, entries]) => {
        const group = el(popover, 'div', { 'data-blocks-group': '' }, 'folio-blocks-group');
        el(group, 'span', {}, 'folio-blocks-group-label').textContent = name;
        const row = el(group, 'div', { role: 'group' }, 'folio-blocks-items');
        entries.forEach(([text, haystack]) => {
            const item = el(row, 'button', { type: 'button', 'data-blocks-item': '', 'data-search': haystack }, 'folio-blocks-add');
            item.textContent = text;
            item.addEventListener('click', () => { log.added.push(text); }); // htmx's hx-get
            items[text] = item;
        });
        return group;
    });
    const empty = el(popover, 'p', { 'data-blocks-empty': '', 'data-text': 'No block matches “:query”.' }, 'folio-blocks-empty');
    empty.hidden = true;
    const elsewhere = el(doc.body, 'button', { type: 'button' });
    load(w, ADMIN_JS);
    load(w, BLOCKS_JS);
    const type = (text) => {
        search.value = text;
        search.dispatchEvent(new w.Event('input', { bubbles: true }));
    };
    const visible = () => Object.entries(items).filter(([, item]) => !item.hidden).map(([text]) => text);
    return { w, doc, form, root, button, popover, label, box, search, items, groups, empty, elsewhere, log, type, visible };
}

const isOpen = (p) => !p.popover.hidden && p.button.getAttribute('aria-expanded') === 'true';

test('the script turns the listed groups into a closed popover behind "Add block"', () => {
    const p = picker();
    assert.equal(p.button.hidden, false, 'the button shows');
    assert.equal(p.popover.hidden, true, 'the groups wait behind it');
    assert.equal(p.popover.getAttribute('role'), 'dialog');
    assert.equal(p.box.hidden, false, 'the search shows');
    assert.equal(p.label.hidden, true, 'the no-script heading goes');
});

test('the button opens the popover with the focus in the search; again closes it', () => {
    const p = picker();
    p.button.focus();
    p.button.click();
    assert.ok(isOpen(p));
    assert.equal(p.doc.activeElement, p.search);
    p.button.click();
    assert.equal(p.popover.hidden, true);
    assert.equal(p.button.getAttribute('aria-expanded'), 'false');
});

test('typing filters by label and description; groups without a match hide', () => {
    const p = picker();
    p.button.click();
    p.type('co');
    assert.deepEqual(p.visible(), ['Code tabs', 'Compare', 'Recordings'], '"recordings" has a "co" too');
    assert.deepEqual(p.groups.map((g) => g.hidden), [true, false, false]);
    p.type('compare');
    assert.deepEqual(p.groups.map((g) => g.hidden), [true, false, true]);
    p.type('EDITABLE');
    assert.deepEqual(p.visible(), ['Showcase'], 'the description counts, in any case');
    p.type('');
    assert.equal(p.visible().length, 5, 'an empty search shows them all');
    assert.equal(p.empty.hidden, true);
});

test('no match shows the empty line with the query', () => {
    const p = picker();
    p.button.click();
    p.type('zebra');
    assert.deepEqual(p.visible(), []);
    assert.equal(p.empty.hidden, false);
    assert.equal(p.empty.textContent, 'No block matches “zebra”.');
});

test('typing in the search is no edit: no autosave, no unsaved mark', () => {
    const p = picker();
    p.button.click();
    p.type('hero');
    p.search.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.log.edits, 0);
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
});

test('Enter adds the first match, never submits the form, and closes the popover', () => {
    const p = picker();
    p.button.click();
    p.type('co');
    const event = p.search.keydown('Enter');
    assert.equal(event.defaultPrevented, true, 'no implicit submit of the record form');
    assert.deepEqual(p.log.added, ['Code tabs']);
    assert.equal(p.popover.hidden, true);
});

test('Enter without a match adds nothing', () => {
    const p = picker();
    p.button.click();
    p.type('zebra');
    p.search.keydown('Enter');
    assert.deepEqual(p.log.added, []);
    assert.ok(isOpen(p));
});

test('the arrows move through the visible items and back to the search', () => {
    const p = picker();
    p.button.click();
    p.type('co'); // Code tabs, Compare (Code), Recordings (Media)
    assert.equal(p.search.keydown('ArrowDown').defaultPrevented, true);
    assert.equal(p.doc.activeElement, p.items['Code tabs']);
    p.items['Code tabs'].keydown('ArrowDown');
    assert.equal(p.doc.activeElement, p.items.Compare);
    p.items.Compare.keydown('ArrowRight');
    assert.equal(p.doc.activeElement, p.items.Recordings, 'across groups, skipping the hidden ones');
    p.items.Recordings.keydown('ArrowRight');
    assert.equal(p.doc.activeElement, p.items['Code tabs'], 'wraps around');
    p.items['Code tabs'].keydown('ArrowLeft');
    assert.equal(p.doc.activeElement, p.items.Recordings);
    p.items.Recordings.keydown('Home');
    assert.equal(p.doc.activeElement, p.items['Code tabs']);
    p.items['Code tabs'].keydown('End');
    assert.equal(p.doc.activeElement, p.items.Recordings);
    p.items.Recordings.keydown('Home');
    p.items['Code tabs'].keydown('ArrowUp');
    assert.equal(p.doc.activeElement, p.search, 'up from the first item goes back to the search');
});

test('Escape closes the popover and returns the focus to the button', () => {
    const p = picker();
    p.button.click();
    p.search.keydown('ArrowDown');
    const event = p.doc.activeElement.keydown('Escape');
    assert.equal(event.defaultPrevented, true);
    assert.equal(p.popover.hidden, true);
    assert.equal(p.button.getAttribute('aria-expanded'), 'false');
    assert.equal(p.doc.activeElement, p.button);
});

test('a click outside or the focus moving elsewhere closes it', () => {
    const p = picker();
    p.button.click();
    p.elsewhere.click();
    assert.equal(p.popover.hidden, true);
    p.button.click();
    p.elsewhere.focus();
    assert.equal(p.popover.hidden, true);
});

test('picking an item closes the popover; reopening starts with an empty search', () => {
    const p = picker();
    p.button.click();
    p.type('rec');
    p.items.Recordings.click();
    assert.deepEqual(p.log.added, ['Recordings']);
    assert.equal(p.popover.hidden, true);
    p.button.click();
    assert.equal(p.search.value, '');
    assert.equal(p.visible().length, 5);
});

test('a picker that arrives with a new card (htmx) is set up too', () => {
    const p = picker();
    const card = p.doc.createElement('div');
    card.setAttribute('data-block-card', '');
    const nested = card.appendChild(p.doc.createElement('div'));
    nested.setAttribute('data-blocks-picker', '');
    const button = nested.appendChild(p.doc.createElement('button'));
    button.setAttribute('data-blocks-picker-open', '');
    button.hidden = true;
    const popover = nested.appendChild(p.doc.createElement('div'));
    popover.setAttribute('data-blocks-popover', '');
    p.root.parentNode.appendChild(card);
    card.dispatchEvent(new p.w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: card } }));
    assert.equal(button.hidden, false);
    assert.equal(popover.hidden, true);
});
