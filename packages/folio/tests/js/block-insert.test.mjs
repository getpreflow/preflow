// The blocks editor's insert line and "/" (assets/blocks.js, 0.77): a "+" line before every card (so
// before the first and between two), a real button that opens the field's picker at that line; a pick
// loads the same fragment URL with htmx.ajax, after the card above the line (before the first card for
// the top line), so the saved order (the [_order][] inputs in DOM order) is the shown order. The focus
// goes to the new card. "/" opens the picker of the blocks field holding the focus, else the first one,
// at the end; never while typing or with a dialog open. A field with one block type adds it directly.
// The end picker keeps working as before (htmx on the item itself).
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const BLOCKS_JS = fileURLToPath(new URL('../../assets/blocks.js', import.meta.url));

/**
 * A record form with a blocks field (cards b1, b2, b3 and the picker), as blocks/field.twig and
 * card.twig render them. single: the field has one block type (one "Add Hero" button). nested: b2 holds
 * a nested blocks field with one card (n1) and its own picker.
 */
function editor({ single = false, nested = false, autosave = true } = {}) {
    const w = createWindow();
    const doc = w.document;
    doc.documentElement.setAttribute('lang', 'en');
    const log = { edits: 0, ajax: [], added: [] };
    w.htmx = { ajax: (verb, url, ctx) => { log.ajax.push({ verb, url, ...ctx }); return Promise.resolve(); } };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const wrap = el(doc.body, 'div', {}, 'folio-form');
    const form = el(wrap, 'form', autosave ? { id: 'folio-record', 'hx-post': '/folio/page/p1/autosave', 'hx-target': '#folio-save-state' } : { id: 'folio-record' });
    form.addEventListener('folio:edit', () => { log.edits++; });
    const title = el(form, 'input', { name: 'title' });

    /** A card as card.twig renders it (not yet in a list). */
    const makeCard = (name, uid) => {
        const c = doc.createElement('div');
        c.className = 'folio-block-card';
        Object.entries({ id: 'block-' + uid, 'data-block-card': '', 'data-block-uid': uid }).forEach(([k, v]) => c.setAttribute(k, v));
        const order = el(c, 'input', { type: 'hidden', name: name + '[_order][]', value: uid });
        order.value = uid;
        const h = el(c, 'div', {}, 'folio-block-head');
        el(h, 'button', { type: 'button', 'data-block-handle': '' }, 'folio-block-handle');
        el(h, 'span', { 'data-block-title': '' }, 'folio-block-title');
        const toggle = el(h, 'button', { type: 'button', 'data-block-collapse': '', 'aria-expanded': 'false' }, 'folio-block-toggle');
        const body = el(c, 'div', {}, 'folio-block-body');
        return { c, toggle, body };
    };

    /** A blocks field with its cards and its picker in parent. */
    const makeField = (parent, name, uids, oneType, pickerId) => {
        const field = el(parent, 'div', { 'data-blocks-field': '' }, 'folio-blocks');
        const list = el(field, 'div', { 'data-blocks-list': '' }, 'folio-blocks-list');
        const cards = {};
        uids.forEach((uid) => { cards[uid] = makeCard(name, uid); list.appendChild(cards[uid].c); });
        const picker = el(list, 'div', { 'data-blocks-picker': '' }, 'folio-blocks-picker' + (oneType ? '' : ' has-popover'));
        const url = `/folio/_blocks/hero/new?name=${name}&locale=en&depth=0`;
        const f = { field, list, cards, picker, url, name };
        if (oneType) {
            f.add = el(picker, 'button', { type: 'button', 'hx-get': url, 'hx-target': 'closest [data-blocks-picker]', 'hx-swap': 'beforebegin' }, 'btn btn-secondary folio-blocks-add');
            el(f.add, 'svg', {}, 'folio-icon');
            f.add.addEventListener('click', () => { log.added.push(name + ':single'); }); // htmx's hx-get
            return f;
        }
        f.button = el(picker, 'button', { type: 'button', 'data-blocks-picker-open': '', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-controls': pickerId }, 'btn btn-secondary folio-blocks-open');
        el(f.button, 'svg', {}, 'folio-icon');
        f.button.hidden = true;
        f.popover = el(picker, 'div', { id: pickerId, role: 'group', 'data-blocks-popover': '' }, 'folio-blocks-popover');
        const box = el(f.popover, 'label', { 'data-blocks-search-box': '' }, 'folio-blocks-search');
        box.hidden = true;
        f.search = el(box, 'input', { type: 'search', 'data-blocks-search': '', 'data-folio-no-edit': '' }, 'folio-blocks-search-input');
        const group = el(f.popover, 'div', { 'data-blocks-group': '' }, 'folio-blocks-group');
        const row = el(group, 'div', { role: 'group' }, 'folio-blocks-items');
        f.items = {};
        ['Hero', 'Quote'].forEach((label) => {
            const item = el(row, 'button', { type: 'button', 'data-blocks-item': '', 'data-search': label.toLowerCase(), 'hx-get': `/folio/_blocks/${label.toLowerCase()}/new?name=${name}&locale=en&depth=0`, 'hx-target': 'closest [data-blocks-picker]', 'hx-swap': 'beforebegin' }, 'folio-blocks-add');
            item.textContent = label;
            item.addEventListener('click', () => { log.added.push(name + ':' + label); }); // htmx's hx-get
            f.items[label] = item;
        });
        return f;
    };

    const outer = makeField(form, 'sections', ['b1', 'b2', 'b3'], single, 'folio-picker-1');
    const inner = nested ? makeField(outer.cards.b2.body, 'sections[b2][items]', ['n1'], false, 'folio-picker-2') : null;
    const dialog = el(doc.body, 'dialog');
    el(doc.body, 'div', { 'data-folio-toasts': '' });
    load(w, ADMIN_JS);
    load(w, BLOCKS_JS);

    /** htmx's answer to log.ajax[i]: the new card swapped in at the target, then htmx:load on it. */
    const respond = (call, uid) => {
        const target = call.target;
        const name = target.closest('[data-blocks-field]') === (inner && inner.field) ? inner.name : outer.name;
        const made = makeCard(name, uid);
        if (call.swap === 'afterend') { target.parentNode.insertBefore(made.c, target.nextElementSibling); }
        else if (call.swap === 'beforebegin') { target.parentNode.insertBefore(made.c, target); }
        else { throw new Error('unexpected swap ' + call.swap); }
        made.c.dispatchEvent(new w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: made.c } }));
        return made;
    };
    return { w, doc, form, title, outer, inner, dialog, log, respond };
}

const own = (list, attr) => list.children.filter((c) => c.hasAttribute(attr));
const lines = (f) => own(f.list, 'data-blocks-insert');
const lineButton = (line) => line.querySelector('[data-blocks-insert-open]');
/** What each child of the list is, in DOM order (the Tab order): line, a card's uid, picker. */
const shape = (f) => f.list.children.map((c) => (c.hasAttribute('data-blocks-insert') ? '+' : c.hasAttribute('data-block-card') ? c.getAttribute('data-block-uid') : c.hasAttribute('data-blocks-picker') ? 'picker' : '?'));
/** The saved order: the field's own [_order][] inputs in document order, as the form posts them. */
const saved = (e, f) => e.form.querySelectorAll(`input[name="${f.name}[_order][]"]`).map((i) => i.value);
const settle = () => new Promise((resolve) => setTimeout(resolve, 5));

test('a line before every card: before the first and between two, none after the last', () => {
    const e = editor();
    assert.deepEqual(shape(e.outer), ['+', 'b1', '+', 'b2', '+', 'b3', 'picker'], 'Tab reaches each line after its card');
    const b = lineButton(lines(e.outer)[1]);
    assert.equal(b.tagName, 'BUTTON');
    assert.equal(b.getAttribute('type'), 'button');
    assert.equal(b.getAttribute('aria-label'), 'Insert block here');
    assert.equal(b.getAttribute('aria-haspopup'), 'dialog');
    assert.equal(b.getAttribute('aria-expanded'), 'false');
    assert.equal(b.getAttribute('aria-controls'), 'folio-picker-1');
    assert.equal(lines(e.outer)[0].querySelector('[name]'), null, 'a line posts nothing');
});

test('a line opens the field\'s picker there, with the focus in the search', () => {
    const e = editor();
    const line = lines(e.outer)[1];
    lineButton(line).focus();
    lineButton(line).click();
    assert.equal(e.outer.popover.hidden, false);
    assert.ok(line.contains(e.outer.popover), 'the popover sits at the line');
    assert.equal(lineButton(line).getAttribute('aria-expanded'), 'true');
    assert.equal(e.outer.button.getAttribute('aria-expanded'), 'false', '"Add block" stays closed');
    assert.equal(e.doc.activeElement, e.outer.search);
    assert.deepEqual(shape(e.outer), ['+', 'b1', '+', 'b2', '+', 'b3', 'picker'], 'the line keeps its place');
});

test('insert in the middle: after the card above the line; saved order = shown order; focus on the new card', async () => {
    const e = editor();
    const before = e.log.edits;
    lineButton(lines(e.outer)[1]).click();
    e.outer.items.Quote.click();
    assert.deepEqual(e.log.added, [], 'not the end slot\'s request');
    assert.equal(e.log.ajax.length, 1);
    const call = e.log.ajax[0];
    assert.equal(call.verb, 'GET');
    assert.equal(call.url, '/folio/_blocks/quote/new?name=sections&locale=en&depth=0', 'the same fragment URL');
    assert.equal(call.target, e.outer.cards.b1.c);
    assert.equal(call.swap, 'afterend');
    assert.equal(call.source, e.outer.items.Quote, 'its events and disabled state are the item\'s');
    assert.equal(e.outer.popover.hidden, true, 'the popover closes');
    assert.ok(e.outer.picker.contains(e.outer.popover), 'and goes back to the end');
    assert.equal(e.doc.activeElement, lineButton(lines(e.outer)[1]), 'the focus waits on the line');
    const made = e.respond(call, 'x1');
    await settle();
    assert.deepEqual(shape(e.outer), ['+', 'b1', '+', 'x1', '+', 'b2', '+', 'b3', 'picker']);
    assert.deepEqual(saved(e, e.outer), ['b1', 'x1', 'b2', 'b3']);
    assert.equal(e.doc.activeElement, made.toggle, 'the new card\'s chevron');
    assert.ok(e.log.edits > before, 'an edit, like an added card');
});

test('insert at the start: before the first card', async () => {
    const e = editor();
    lineButton(lines(e.outer)[0]).click();
    e.outer.search.value = 'hero';
    e.outer.search.dispatchEvent(new e.w.Event('input', { bubbles: true }));
    assert.equal(e.outer.search.keydown('Enter').defaultPrevented, true);
    const call = e.log.ajax[0];
    assert.equal(call.target, e.outer.cards.b1.c);
    assert.equal(call.swap, 'beforebegin');
    const made = e.respond(call, 'x1');
    await settle();
    assert.deepEqual(saved(e, e.outer), ['x1', 'b1', 'b2', 'b3']);
    assert.deepEqual(shape(e.outer), ['+', 'x1', '+', 'b1', '+', 'b2', '+', 'b3', 'picker']);
    assert.equal(e.doc.activeElement, made.toggle);
});

test('insert at the end: the end picker works as before (htmx on the item), and its card lands last', async () => {
    const e = editor();
    e.outer.button.click();
    assert.ok(e.outer.picker.contains(e.outer.popover));
    e.outer.items.Hero.click();
    assert.deepEqual(e.log.added, ['sections:Hero'], 'the item\'s own hx-get');
    assert.equal(e.log.ajax.length, 0);
    assert.equal(e.doc.activeElement, e.outer.button);
    const made = e.respond({ target: e.outer.picker, swap: 'beforebegin' }, 'x9');
    await settle();
    assert.deepEqual(saved(e, e.outer), ['b1', 'b2', 'b3', 'x9']);
    assert.deepEqual(shape(e.outer), ['+', 'b1', '+', 'b2', '+', 'b3', '+', 'x9', 'picker']);
    assert.equal(e.doc.activeElement, made.toggle);
});

test('Escape closes the picker at a line and gives the focus back to that line', () => {
    const e = editor();
    const b = lineButton(lines(e.outer)[2]);
    b.focus();
    b.click();
    const event = e.outer.search.keydown('Escape');
    assert.equal(event.defaultPrevented, true);
    assert.equal(e.outer.popover.hidden, true);
    assert.equal(b.getAttribute('aria-expanded'), 'false');
    assert.equal(e.doc.activeElement, b);
    assert.ok(e.outer.picker.contains(e.outer.popover));
});

test('the line again closes it; another line moves it there; the focus elsewhere closes it', () => {
    const e = editor();
    const [l0, l1] = lines(e.outer);
    lineButton(l0).focus();
    lineButton(l0).click();
    lineButton(l0).focus();
    lineButton(l0).click();
    assert.equal(e.outer.popover.hidden, true, 'toggled shut');
    lineButton(l0).click();
    lineButton(l1).focus();
    lineButton(l1).click();
    assert.equal(e.outer.popover.hidden, false);
    assert.ok(l1.contains(e.outer.popover));
    assert.equal(lineButton(l0).getAttribute('aria-expanded'), 'false');
    e.title.focus();
    assert.equal(e.outer.popover.hidden, true);
});

test('a failed pick at a line puts the focus back on that line', async () => {
    const e = editor();
    const b = lineButton(lines(e.outer)[1]);
    b.click();
    e.outer.items.Hero.click();
    e.doc.activeElement = e.doc.body; // as when the browser drops it
    e.outer.items.Hero.dispatchEvent(new e.w.CustomEvent('htmx:responseError', { bubbles: true, detail: { elt: e.outer.items.Hero } }));
    await settle();
    assert.equal(e.doc.activeElement, b);
});

test('the lines follow moved, removed and restored cards', async () => {
    const e = editor();
    e.outer.list.insertBefore(e.outer.cards.b3.c, e.outer.cards.b1.c); // a drag
    await settle();
    assert.deepEqual(shape(e.outer), ['+', 'b3', '+', 'b1', '+', 'b2', 'picker']);
    e.outer.cards.b1.c.remove();
    e.outer.list.dispatchEvent(new e.w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: e.outer.list } }));
    await settle();
    assert.deepEqual(shape(e.outer), ['+', 'b3', '+', 'b2', 'picker']);
});

test('a nested field has its own lines and picker', async () => {
    const e = editor({ nested: true });
    assert.deepEqual(shape(e.inner), ['+', 'n1', 'picker']);
    assert.deepEqual(shape(e.outer), ['+', 'b1', '+', 'b2', '+', 'b3', 'picker'], 'the outer list counts its own cards only');
    const b = lineButton(lines(e.inner)[0]);
    assert.equal(b.getAttribute('aria-controls'), 'folio-picker-2');
    b.click();
    assert.ok(lines(e.inner)[0].contains(e.inner.popover));
    assert.equal(e.outer.popover.hidden, true);
    e.inner.items.Hero.click();
    const call = e.log.ajax[0];
    assert.equal(call.target, e.inner.cards.n1.c);
    assert.equal(call.swap, 'beforebegin');
    e.respond(call, 'm1');
    await settle();
    assert.deepEqual(saved(e, e.inner), ['m1', 'n1']);
    assert.deepEqual(saved(e, e.outer), ['b1', 'b2', 'b3']);
});

test('a field with one block type: the line adds it there directly', async () => {
    const e = editor({ single: true });
    const b = lineButton(lines(e.outer)[2]);
    assert.equal(b.getAttribute('aria-haspopup'), null, 'no popover to announce');
    assert.equal(b.getAttribute('aria-label'), 'Insert block here');
    b.focus();
    b.click();
    assert.equal(e.log.ajax.length, 1);
    const call = e.log.ajax[0];
    assert.equal(call.url, e.outer.url);
    assert.equal(call.target, e.outer.cards.b2.c);
    assert.equal(call.swap, 'afterend');
    assert.deepEqual(e.log.added, [], 'not the end button\'s request');
    const made = e.respond(call, 'x1');
    await settle();
    assert.deepEqual(saved(e, e.outer), ['b1', 'b2', 'x1', 'b3']);
    assert.equal(e.doc.activeElement, made.toggle);
});

test('"/" opens the picker of the field holding the focus, at the end', () => {
    const e = editor({ nested: true });
    e.inner.cards.n1.toggle.focus();
    assert.equal(e.inner.cards.n1.toggle.keydown('/').defaultPrevented, true);
    assert.equal(e.inner.popover.hidden, false, 'the nested field holds the focus');
    assert.ok(e.inner.picker.contains(e.inner.popover), 'at the end');
    assert.equal(e.inner.button.getAttribute('aria-expanded'), 'true');
    assert.equal(e.doc.activeElement, e.inner.search);
    e.inner.search.keydown('Escape');
    e.outer.cards.b3.toggle.focus();
    e.outer.cards.b3.toggle.keydown('/');
    assert.equal(e.outer.popover.hidden, false);
    assert.equal(e.inner.popover.hidden, true);
});

test('"/" with the focus outside any blocks field opens the first one', () => {
    const e = editor({ nested: true });
    const other = e.doc.body.appendChild(e.doc.createElement('button'));
    other.focus();
    other.keydown('/');
    assert.equal(e.outer.popover.hidden, false);
    assert.equal(e.inner.popover.hidden, true);
});

test('"/" does nothing while typing or with a dialog open', () => {
    const e = editor();
    e.title.focus();
    assert.equal(e.title.keydown('/').defaultPrevented, false, 'the slash is typed');
    assert.equal(e.outer.popover.hidden, true);
    e.outer.cards.b1.toggle.focus();
    e.dialog.setAttribute('open', '');
    e.outer.cards.b1.toggle.keydown('/');
    assert.equal(e.outer.popover.hidden, true);
    e.dialog.removeAttribute('open');
    e.outer.cards.b1.toggle.keydown('/');
    assert.equal(e.outer.popover.hidden, false);
});

test('"/" in a field with one block type adds it at the end', () => {
    const e = editor({ single: true });
    e.outer.cards.b1.toggle.focus();
    e.outer.cards.b1.toggle.keydown('/');
    assert.deepEqual(e.log.added, ['sections:single']);
});

test('"/" is listed under "?"', () => {
    const e = editor();
    e.doc.body.keydown('?');
    const dialog = e.doc.querySelector('dialog.folio-shortcuts');
    assert.ok(dialog);
    assert.ok(dialog.textContent.includes('Add a block'));
});
