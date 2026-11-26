// Conditional fields (show_when, 0.91): assets/show-when-core.js decides as Preflow\Data\FieldVisibility
// does (every case of packages/data/tests/Fixtures/show-when-cases.json, which the PHP test reads too), and
// fields.js shows or hides a field of the record form or of a block card as its source changes, without a
// round trip: a hidden field gets hidden and aria-hidden, its controls are disabled so they do not post.
// Run: node --test packages/folio/tests/js/show-when.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const require = createRequire(import.meta.url);
const core = require('../../assets/show-when-core.js');
const CORE_JS = fileURLToPath(new URL('../../assets/show-when-core.js', import.meta.url));
const FIELDS_JS = fileURLToPath(new URL('../../assets/fields.js', import.meta.url));
const cases = JSON.parse(readFileSync(fileURLToPath(new URL('../../../data/tests/Fixtures/show-when-cases.json', import.meta.url)), 'utf8'));

test('the fixture has both lists', () => {
    assert.ok(cases.holds.length >= 30);
    assert.ok(cases.hidden.length >= 1);
});

for (const c of cases.holds) {
    test('holds: ' + c.name, () => {
        assert.equal(core.holds(c.rule, c.values, c.fields || {}), c.holds);
    });
}

for (const c of cases.hidden) {
    test('hidden: ' + c.name, () => {
        assert.deepEqual(core.hiddenFields(c.fields, c.values), c.hidden);
    });
}

// ---- The editor ------------------------------------------------------------------------------------

/** A wrapper as the server renders it: the rule, the source types, the values of sources without a control, the name prefix. */
function wrap(doc, parent, config, hidden = false) {
    const el = parent.appendChild(doc.createElement('div'));
    el.setAttribute('data-folio-show-when', JSON.stringify({ rule: config.rule, types: config.types || {}, values: config.values || {}, prefix: config.prefix || '' }));
    if (hidden) { el.hidden = true; el.setAttribute('hidden', ''); el.setAttribute('aria-hidden', 'true'); }
    return el;
}

function page(build) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    w.Folio = {};
    const el = (parent, tag, attrs = {}) => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (attrs.type) { e.type = attrs.type; }
        if (attrs.name) { e.name = attrs.name; }
        if (attrs.value !== undefined) { e.value = attrs.value; }
        return e;
    };
    const form = el(doc.body, 'form', { id: 'folio-record' });
    const parts = build({ w, doc, el, form, wrap: (parent, config, hidden) => wrap(doc, parent, config, hidden) }) || {};
    load(w, CORE_JS);
    load(w, FIELDS_JS);
    return { w, doc, form, ...parts };
}

function choose(w, control, value) {
    control.value = value;
    control.dispatchEvent(new w.Event('change', { bubbles: true }));
}

function shown(el) {
    return el.hidden === false && el.getAttribute('hidden') === null && el.getAttribute('aria-hidden') === null;
}

test('a record field shows when its source holds the value and hides again, its input disabled while hidden', () => {
    const p = page(({ el, form, wrap }) => {
        const kind = el(form, 'select', { name: 'kind', value: 'text' });
        const box = wrap(form, { rule: { field: 'kind', '=': 'link' } }, true);
        const url = el(box, 'input', { type: 'text', name: 'button_url', value: '/a' });
        return { kind, box, url };
    });
    assert.equal(p.box.hidden, true, 'hidden as the server rendered it');
    assert.equal(p.url.disabled, true, 'its input does not post');
    assert.equal(p.box.getAttribute('aria-hidden'), 'true');

    choose(p.w, p.kind, 'link');
    assert.ok(shown(p.box), 'shown without a round trip');
    assert.equal(p.url.disabled, false);
    assert.equal(p.url.value, '/a', 'the value is still there');

    choose(p.w, p.kind, 'video');
    assert.equal(p.box.hidden, true);
    assert.equal(p.box.getAttribute('aria-hidden'), 'true');
    assert.equal(p.url.disabled, true);
});

test('a field that names a hidden field hides with it, whatever that field holds', () => {
    const p = page(({ el, form, wrap }) => {
        const kind = el(form, 'select', { name: 'kind', value: 'link' });
        const urlBox = wrap(form, { rule: { field: 'kind', '=': 'link' } });
        el(urlBox, 'input', { type: 'text', name: 'button_url', value: '/a' });
        const labelBox = wrap(form, { rule: { field: 'button_url', filled: true } });
        const label = el(labelBox, 'input', { type: 'text', name: 'button_label', value: 'Go' });
        return { kind, urlBox, labelBox, label };
    });
    assert.ok(shown(p.labelBox));

    choose(p.w, p.kind, 'text');
    assert.equal(p.urlBox.hidden, true);
    assert.equal(p.labelBox.hidden, true, 'its source is hidden');
    assert.equal(p.label.disabled, true);

    choose(p.w, p.kind, 'link');
    assert.ok(shown(p.labelBox));
    assert.equal(p.label.disabled, false);
});

test('a checkbox source reads as the form posts it: the checked box, else the hidden 0', () => {
    const p = page(({ el, form, wrap }) => {
        el(form, 'input', { type: 'hidden', name: 'featured', value: '0' });
        const box = el(form, 'input', { type: 'checkbox', name: 'featured', value: '1' });
        const note = wrap(form, { rule: { field: 'featured', '=': true } }, true);
        el(note, 'textarea', { name: 'note' });
        return { box, note };
    });
    p.box.checked = true;
    p.box.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.ok(shown(p.note));
    p.box.checked = false;
    p.box.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.note.hidden, true);
});

test('a list source (tags, relations: name[]) is empty without an entry', () => {
    const p = page(({ el, form, wrap }) => {
        const list = el(form, 'div');
        const note = wrap(form, { rule: { field: 'tags', filled: true } }, true);
        el(note, 'input', { type: 'text', name: 'note' });
        return { list, note, el };
    });
    const tag = p.el(p.list, 'input', { type: 'hidden', name: 'tags[]', value: 'news' });
    tag.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.ok(shown(p.note));
});

test('a number source compares as whole numbers', () => {
    const p = page(({ el, form, wrap }) => {
        const count = el(form, 'input', { type: 'number', name: 'count', value: '1' });
        const box = wrap(form, { rule: { field: 'count', '=': 3 }, types: { count: 'number' } }, true);
        el(box, 'input', { type: 'text', name: 'extra' });
        return { count, box };
    });
    p.count.value = '3.0';
    p.count.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    assert.ok(shown(p.box));
});

test('a source without a control (read-only) reads the value the server gave', () => {
    const p = page(({ el, form, wrap }) => {
        const box = wrap(form, { rule: { field: 'kind', '=': 'link' }, values: { kind: 'link' } }, true);
        const url = el(box, 'input', { type: 'text', name: 'button_url' });
        return { box, url };
    });
    assert.ok(shown(p.box), 'the stored link shows it');
    assert.ok(!p.url.disabled);
});

test('two block cards of one type each hide by their own source', () => {
    const p = page(({ el, form, wrap }) => {
        const card = (uid, value) => {
            const c = el(form, 'div', { 'data-block-card': '', 'data-block-uid': uid });
            const kind = el(c, 'select', { name: 'body[' + uid + '][kind]', value });
            const box = wrap(c, { rule: { field: 'kind', '=': 'link' }, prefix: 'body[' + uid + ']' }, value !== 'link');
            const url = el(box, 'input', { type: 'text', name: 'body[' + uid + '][button_url]' });
            return { kind, box, url };
        };
        return { one: card('a'.repeat(16), 'link'), two: card('b'.repeat(16), 'text') };
    });
    assert.ok(shown(p.one.box));
    assert.equal(p.two.box.hidden, true);

    choose(p.w, p.two.kind, 'link');
    assert.ok(shown(p.two.box));
    choose(p.w, p.one.kind, 'text');
    assert.equal(p.one.box.hidden, true);
    assert.equal(p.one.url.disabled, true);
    assert.ok(shown(p.two.box), 'the other card keeps its own state');
});

test('a card htmx adds later is decided at once', () => {
    const p = page(({ form }) => ({ form }));
    const { doc, el } = { doc: p.doc, el: (parent, tag, attrs = {}) => {
        const e = parent.appendChild(p.doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (attrs.name) { e.name = attrs.name; }
        if (attrs.value !== undefined) { e.value = attrs.value; }
        return e;
    } };
    const card = el(p.form, 'div', { 'data-block-card': '' });
    el(card, 'select', { name: 'body[cccccccccccccccc][kind]', value: 'text' });
    const box = wrap(doc, card, { rule: { field: 'kind', '=': 'link' }, prefix: 'body[cccccccccccccccc]' });
    const url = el(box, 'input', { type: 'text', name: 'body[cccccccccccccccc][button_url]' });
    doc.dispatchEvent(Object.assign(new p.w.Event('htmx:load'), { detail: { elt: card } }));
    assert.equal(box.hidden, true);
    assert.equal(url.disabled, true);
});

test('a hidden blocks field disables the cards inside; shown again, each card field follows its own rule', () => {
    const p = page(({ el, form, wrap }) => {
        const layout = el(form, 'select', { name: 'layout', value: 'blocks' });
        const outer = wrap(form, { rule: { field: 'layout', '=': 'blocks' } });
        const kind = el(outer, 'select', { name: 'body[dddddddddddddddd][kind]', value: 'text' });
        const inner = wrap(outer, { rule: { field: 'kind', '=': 'link' }, prefix: 'body[dddddddddddddddd]' }, true);
        const url = el(inner, 'input', { type: 'text', name: 'body[dddddddddddddddd][button_url]' });
        const own = el(outer, 'input', { type: 'text', name: 'body[dddddddddddddddd][locked]' });
        own.disabled = true; // disabled on its own (read-only): never enabled by show_when
        return { layout, outer, kind, inner, url, own };
    });
    choose(p.w, p.layout, 'plain');
    assert.equal(p.outer.hidden, true);
    assert.equal(p.kind.disabled, true);

    choose(p.w, p.layout, 'blocks');
    assert.ok(shown(p.outer));
    assert.equal(p.kind.disabled, false);
    assert.equal(p.inner.hidden, true, 'the card field still hides by its own source');
    assert.equal(p.url.disabled, true);
    assert.equal(p.own.disabled, true, 'a control disabled on its own stays disabled');
});

test('a form without show_when is left alone', () => {
    const p = page(({ el, form }) => ({ input: el(form, 'input', { type: 'text', name: 'title' }) }));
    p.input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    assert.equal(p.input.disabled, undefined, 'never touched');
});
