// The tag field (assets/fields.js, 0.77): chips and an "Add…" input. Enter and comma add, a paste of
// "a, b, c" adds three, Backspace on the empty input removes the last, ✕ removes one; each change is
// a change event on a control of the record form (admin.js turns it into folio:edit) and is announced.
// Run: node --test packages/folio/tests/js/tags.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const FIELDS_JS = fileURLToPath(new URL('../../assets/fields.js', import.meta.url));

const tick = (ms = 50) => new Promise((resolve) => setTimeout(resolve, ms));

/** admin.js and fields.js on an autosaving record form with a tags field holding $tags, as TagsFieldType renders it. */
function page(tags = ['Folio'], { max = 20, maxLength = 40 } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    doc.documentElement.setAttribute('lang', 'en');
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        if (attrs.type) { e.type = attrs.type; }
        if (attrs.name) { e.name = attrs.name; }
        if (attrs.value !== undefined) { e.value = attrs.value; }
        return e;
    };
    const chip = (parent, tag) => {
        const li = el(parent, 'li', { 'data-folio-tag': '' }, 'folio-tag-chip');
        el(li, 'span', {}, 'folio-tag-text').textContent = tag;
        el(li, 'button', { type: 'button', 'data-folio-tag-remove': '', 'aria-label': 'Remove ' + tag }, 'folio-tag-remove');
        el(li, 'input', { type: 'hidden', value: tag, 'data-folio-tag-value': '' });
        return li;
    };
    const form = el(doc.body, 'form', { id: 'folio-record', 'hx-post': '/folio/reference/r1/autosave' });
    const field = el(form, 'div', {
        'data-folio-tags': '', 'data-folio-tags-name': 'uses[]', 'data-max': String(max), 'data-max-length': String(maxLength),
        'data-text-remove': 'Remove :tag', 'data-text-added': 'Added :tag.', 'data-text-removed': 'Removed :tag.',
        'data-text-too-many': 'At most :max tags.', 'data-text-too-long': 'A tag can have at most :max characters.',
    }, 'form-group folio-tags-field');
    const box = el(field, 'div', {}, 'folio-tags-box');
    const list = el(box, 'ul', { 'data-folio-tags-list': '' }, 'folio-tags-list');
    tags.forEach((t) => chip(list, t));
    const label = el(field, 'label', { for: 'uses' });
    const add = el(box, 'input', { type: 'text', id: 'uses-tags-add', 'data-folio-tags-input': '', 'data-folio-no-edit': '' }, 'folio-tags-input');
    chip(el(box, 'template', { 'data-folio-tags-template': '' }), '');
    const plain = el(field, 'input', { type: 'text', id: 'uses', name: 'uses', value: tags.join(', '), 'data-folio-tags-plain': '' }, 'folio-tags-plain');
    load(w, ADMIN_JS);
    load(w, FIELDS_JS);
    const edits = [];
    form.addEventListener('folio:edit', (e) => edits.push(e.detail.source));
    return { w, doc, form, field, list, add, plain, label, edits };
}

/** What the form posts for the field: the enabled controls named uses or uses[]. */
function posted(p) {
    return p.form.querySelectorAll('input').filter((i) => !i.disabled && (i.name === 'uses' || i.name === 'uses[]')).map((i) => `${i.name}=${i.value}`);
}

function chips(p) {
    return p.list.querySelectorAll('[data-folio-tag]').map((li) => li.querySelector('.folio-tag-text').textContent);
}

function key(p, k) {
    return p.add.keydown(k);
}

function announced(p) {
    const live = p.doc.querySelector('[data-folio-fields-live]');
    return live ? live.textContent : '';
}

test('with script the chips post as uses[] and the plain input is off', () => {
    const p = page(['Folio', 'members']);
    assert.equal(p.plain.disabled, true);
    assert.deepEqual(posted(p), ['uses[]=Folio', 'uses[]=members']);
    assert.equal(p.label.getAttribute('for'), 'uses-tags-add', 'the label names the add input');
    assert.equal(p.edits.length, 0, 'taking the field over is no edit');
});

test('Enter adds the typed tag (never submitting), clears the input and announces it', async () => {
    const p = page(['Folio']);
    p.add.value = '  AI translation ';
    const e = key(p, 'Enter');
    assert.equal(e.defaultPrevented, true);
    assert.deepEqual(chips(p), ['Folio', 'AI translation']);
    assert.equal(p.add.value, '');
    assert.deepEqual(posted(p), ['uses[]=Folio', 'uses[]=AI translation']);
    const last = p.list.querySelectorAll('[data-folio-tag]').pop();
    assert.equal(last.querySelector('[data-folio-tag-remove]').getAttribute('aria-label'), 'Remove AI translation');
    assert.equal(p.edits.length, 1, 'one folio:edit');
    await tick();
    assert.equal(announced(p), 'Added AI translation.');
});

test('a comma adds the tag too; an empty Enter or a duplicate (any case) adds nothing', () => {
    const p = page(['Folio']);
    p.add.value = 'members';
    assert.equal(key(p, ',').defaultPrevented, true);
    assert.deepEqual(chips(p), ['Folio', 'members']);
    p.add.value = '';
    key(p, 'Enter');
    p.add.value = 'FOLIO';
    key(p, 'Enter');
    assert.deepEqual(chips(p), ['Folio', 'members']);
    assert.equal(p.edits.length, 1);
});

test('typing a comma (a phone keyboard) adds what stands before it', () => {
    const p = page([]);
    p.add.value = 'a, b';
    p.add.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    assert.deepEqual(chips(p), ['a']);
    assert.equal(p.add.value, 'b');
});

test('a paste of "a, b, c" adds three, each an edit', () => {
    const p = page([]);
    const e = new p.w.Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(e, 'clipboardData', { value: { getData: () => 'a, b,\nc, a' } });
    p.add.dispatchEvent(e);
    assert.equal(e.defaultPrevented, true);
    assert.deepEqual(chips(p), ['a', 'b', 'c']);
    assert.equal(p.edits.length, 3);
});

test('a plain paste without a comma is left to the input', () => {
    const p = page([]);
    const e = new p.w.Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(e, 'clipboardData', { value: { getData: () => 'one' } });
    p.add.dispatchEvent(e);
    assert.equal(e.defaultPrevented, false);
    assert.deepEqual(chips(p), []);
});

test('Backspace on the empty input removes the last tag; with text it edits the text', async () => {
    const p = page(['Folio', 'members']);
    p.add.value = 'x';
    assert.equal(key(p, 'Backspace').defaultPrevented, false);
    assert.deepEqual(chips(p), ['Folio', 'members']);
    p.add.value = '';
    key(p, 'Backspace');
    assert.deepEqual(chips(p), ['Folio']);
    assert.deepEqual(posted(p), ['uses[]=Folio']);
    assert.equal(p.edits.length, 1);
    await tick();
    assert.equal(announced(p), 'Removed members.');
});

test('✕ removes its tag and moves focus to the next one, the input after the last', () => {
    const p = page(['a', 'b', 'c']);
    p.list.querySelectorAll('[data-folio-tag-remove]')[1].click();
    assert.deepEqual(chips(p), ['a', 'c']);
    assert.equal(p.doc.activeElement, p.list.querySelectorAll('[data-folio-tag-remove]')[1], 'the next chip\'s ✕');
    p.list.querySelectorAll('[data-folio-tag-remove]')[1].click();
    p.list.querySelectorAll('[data-folio-tag-remove]')[0].click();
    assert.deepEqual(chips(p), []);
    assert.equal(p.doc.activeElement, p.add);
    assert.deepEqual(posted(p), [], 'an emptied field posts nothing under its name: the server stores an empty list');
    assert.equal(p.edits.length, 3, 'a folio:edit per removal');
});

test('the limits: no tag over the count or the length, and it says why', async () => {
    const p = page(['a', 'b'], { max: 2, maxLength: 5 });
    p.add.value = 'c';
    key(p, 'Enter');
    assert.deepEqual(chips(p), ['a', 'b']);
    assert.equal(p.add.value, 'c', 'the text stays to edit');
    await tick();
    assert.equal(announced(p), 'At most 2 tags.');

    p.list.querySelectorAll('[data-folio-tag-remove]')[0].click();
    p.add.value = 'toolong';
    key(p, 'Enter');
    assert.deepEqual(chips(p), ['b']);
    await tick();
    assert.equal(announced(p), 'A tag can have at most 5 characters.');
});

test('leaving the input adds what was typed', () => {
    const p = page([]);
    p.add.value = 'pending';
    p.add.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.deepEqual(chips(p), ['pending']);
    assert.equal(p.edits.length, 1, 'the add input itself is no edit, the new tag is');
});

test('a second init (Cancel, htmx) changes nothing', () => {
    const p = page(['Folio']);
    p.w.Folio.initFields(p.doc);
    assert.deepEqual(posted(p), ['uses[]=Folio']);
});
