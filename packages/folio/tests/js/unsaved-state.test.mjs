// A form without autosave (0.76): admin.js snapshots its submittable values at boot (its own controls
// and those naming it with form="…"), counts the field names that differ (Folio.unsaved.count; a field
// kept in hidden inputs counts, a radio group counts once), shows "N unsaved changes" — or "Unsaved
// changes" while the form is dirty and the count is 0, "Not saved" after a refused save — with Cancel
// in [data-folio-unsaved-state] / [data-folio-discard] (form.twig, beside Save). Cancel
// (Folio.unsaved.discard) puts visible controls back in place, clears the unsaved mark, sends a
// bubbling "folio:discard" and resyncs fields.js's counters; a hidden-input change (or a refused save)
// reloads the edit page past the leave guard instead.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const FIELDS_JS = fileURLToPath(new URL('../../assets/fields.js', import.meta.url));

function el(w, parent, tag, attrs = {}, text = '') {
    const node = parent.appendChild(w.document.createElement(tag));
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'class') { node.className = v; } else if (k === 'type' || k === 'name' || k === 'value') { node[k] = v; node.setAttribute(k, v); } else { node.setAttribute(k, v); }
    });
    if (text) { node.textContent = text; }
    return node;
}

/**
 * A guarded form with every kind of control, one control outside naming it (the side panel's), and the
 * topbar's state and Cancel for it. formId/formClass: the record form ("folio-record" in .folio-form)
 * or the asset Details form.
 */
function screen({ formId = 'folio-record', asset = false, refused = false, fields = false } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const topbar = el(w, doc.body, 'header', { class: 'folio-topbar' });
    const state = el(w, topbar, 'span', { class: 'folio-save-state is-unsaved', 'data-folio-unsaved-state': formId });
    state.hidden = true;
    const text = el(w, state, 'span', { class: 'folio-save-state-text', 'data-folio-save-text': '' });
    const cancel = el(w, topbar, 'button', { type: 'button', class: 'btn btn-ghost', 'data-folio-discard': formId }, 'Cancel');
    cancel.hidden = true;
    const save = el(w, topbar, 'button', { type: 'submit', form: formId, 'data-folio-save': '' }, 'Save');
    const host = asset ? doc.body : el(w, doc.body, 'div', { class: 'folio-form' });
    const form = el(w, host, 'form', { method: 'post', action: '/folio/x', id: formId, 'data-folio-edit-url': '/folio/x/edit?locale=de', ...(asset ? { class: 'folio-asset-form' } : {}), ...(refused ? { 'data-folio-refused': '' } : {}) });
    const c = {};
    c.csrf = el(w, form, 'input', { type: 'hidden', name: '_csrf_token', value: 't' });
    // A field kept in a hidden input (rich text) and its editor.
    c.rich = el(w, form, 'input', { type: 'hidden', name: 'story', value: '<p>Once</p>' });
    c.title = el(w, form, 'input', { type: 'text', name: 'title', value: 'Hello' });
    c.body = el(w, form, 'textarea', { name: 'body', 'aria-describedby': 'body-count' });
    c.body.value = 'Text';
    c.counter = el(w, form, 'span', { id: 'body-count', 'data-folio-counter': '', 'data-max': '160', 'data-text': ':count / :max' });
    c.flag = el(w, form, 'input', { type: 'checkbox', name: 'flag', value: '1' });
    c.red = el(w, form, 'input', { type: 'radio', name: 'tone', value: 'red' });
    c.blue = el(w, form, 'input', { type: 'radio', name: 'tone', value: 'blue' });
    c.blue.checked = true;
    c.kind = el(w, form, 'select', { name: 'kind' });
    c.a = el(w, c.kind, 'option', { value: 'a' });
    c.b = el(w, c.kind, 'option', { value: 'b' });
    c.a.selected = true;
    c.locked = el(w, form, 'input', { type: 'checkbox', name: 'locked', value: '1' });
    c.locked.disabled = true;
    c.search = el(w, form, 'input', { type: 'search', name: 'q', 'data-folio-no-edit': '' });
    const side = el(w, doc.body, 'aside', { class: 'folio-side' });
    c.label = el(w, side, 'input', { type: 'text', name: 'label', value: 'Side', form: formId });
    c.other = el(w, side, 'input', { type: 'text', name: 'other', value: 'Not ours', form: 'another-form' });
    // As the browser keeps them from the markup.
    [c.title, c.body, c.label, c.other, c.search].forEach((i) => { i.defaultValue = i.value; });
    [c.flag, c.red, c.blue, c.locked].forEach((i) => { i.defaultChecked = i.checked; });
    [c.a, c.b].forEach((o) => { o.defaultSelected = o.selected; });
    w.location.href = 'https://site.test/folio/x';
    load(w, ADMIN_JS);
    if (fields) { load(w, FIELDS_JS); }
    return { w, doc, form, state, text, cancel, save, c };
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const edit = (w, input, value, type = 'input') => {
    if (input.type === 'checkbox' || input.type === 'radio') { input.checked = value; } else { input.value = value; }
    input.dispatchEvent(new w.Event(type, { bubbles: true }));
};

test('each changed control counts once, outside controls naming the form too; back to its default it no longer counts', () => {
    const p = screen();
    const { c } = p;
    assert.equal(p.w.Folio.unsaved.count(p.form), 0);
    assert.equal(p.state.hidden, true);
    assert.equal(p.cancel.hidden, true);

    edit(p.w, c.title, 'Hello!');
    assert.equal(p.state.hidden, false);
    assert.equal(p.cancel.hidden, false);
    assert.equal(p.text.textContent, '1 unsaved change');
    edit(p.w, c.body, 'More', 'input');
    edit(p.w, c.flag, true, 'change');
    c.blue.checked = false;
    edit(p.w, c.red, true, 'change');
    c.a.selected = false; c.b.selected = true;
    c.kind.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    edit(p.w, c.label, 'Side panel');
    assert.equal(p.w.Folio.unsaved.count(p.form), 6, 'title, body, flag, the radio group once, kind, and the side field');
    assert.equal(p.text.textContent, '6 unsaved changes');

    // Not counted: a disabled control, a filter box (data-folio-no-edit), another form's control, names with "_".
    c.locked.checked = true;
    edit(p.w, c.search, 'abc');
    edit(p.w, c.other, 'Changed');
    c.csrf.value = 'u';
    assert.equal(p.w.Folio.unsaved.count(p.form), 6);

    edit(p.w, c.title, 'Hello');
    assert.equal(p.w.Folio.unsaved.count(p.form), 5);
    assert.equal(p.text.textContent, '5 unsaved changes');
});

test('Cancel puts every control back, clears the unsaved mark and announces folio:discard', () => {
    const p = screen();
    const { c } = p;
    const heard = [];
    p.doc.addEventListener('folio:discard', (e) => heard.push(e.target));
    edit(p.w, c.title, 'Changed');
    edit(p.w, c.flag, true, 'change');
    c.a.selected = false; c.b.selected = true;
    c.kind.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    edit(p.w, c.label, 'Changed too');
    c.locked.checked = true; // a disabled control a script changed: restored as well
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);

    p.cancel.click();
    assert.equal(c.title.value, 'Hello');
    assert.equal(c.flag.checked, false);
    assert.equal(c.a.selected, true);
    assert.equal(c.b.selected, false);
    assert.equal(c.label.value, 'Side');
    assert.equal(c.locked.checked, false);
    assert.equal(c.other.value, 'Not ours', 'another form is left alone');
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
    assert.equal(p.w.Folio.unsaved.count(p.form), 0);
    assert.equal(p.state.hidden, true);
    assert.equal(p.cancel.hidden, true);
    assert.deepEqual(heard, [p.form]);
});

test('any non-autosaving form can carry the state: the asset Details form', () => {
    const p = screen({ formId: 'folio-asset-form', asset: true });
    edit(p.w, p.c.title, 'A caption');
    assert.equal(p.state.hidden, false);
    assert.equal(p.text.textContent, '1 unsaved change');
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true, 'guarded as before');
    p.w.Folio.unsaved.discard(p.form);
    assert.equal(p.c.title.value, 'Hello');
    assert.equal(p.state.hidden, true);
});

test('a page without the state element gets no listeners from it, and count/discard still work', () => {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const main = el(w, w.document.body, 'div', { class: 'folio-form' });
    const form = el(w, main, 'form', { id: 'f' });
    const input = el(w, form, 'input', { type: 'text', name: 't', value: 'a' });
    input.defaultValue = 'a';
    load(w, ADMIN_JS);
    input.value = 'b';
    assert.equal(w.Folio.unsaved.count(form), 1);
    w.Folio.unsaved.discard(form);
    assert.equal(input.value, 'a');
    assert.equal(w.Folio.unsaved.count(null), 0);
});

test('a field kept in a hidden input counts, and Cancel then reloads the edit page past the guard', async () => {
    const p = screen();
    await tick(); // the field scripts have run: the snapshot is the page's
    const heard = [];
    p.doc.addEventListener('folio:discard', (e) => heard.push(e.target));
    p.c.rich.value = '<p>Once upon a time</p>';
    p.c.rich.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.w.Folio.unsaved.count(p.form), 1);
    assert.equal(p.text.textContent, '1 unsaved change');
    assert.equal(p.cancel.hidden, false);
    edit(p.w, p.c.title, 'Hello!');
    assert.equal(p.text.textContent, '2 unsaved changes');

    p.cancel.click();
    assert.equal(p.w.location.href, '/folio/x/edit?locale=de', 'the edit page again: no server draft, so the saved state');
    assert.deepEqual(heard, [], 'nothing restored in place');
    const unload = new p.w.Event('beforeunload', { cancelable: true });
    p.w.listeners.beforeunload.forEach((fn) => fn(unload));
    assert.equal(unload.defaultPrevented, false, 'the leave guard lets this reload go');
});

test('a hidden input removed (an asset taken off) counts as a change of its field', async () => {
    const p = screen();
    await tick();
    p.c.rich.remove();
    p.form.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.w.Folio.unsaved.count(p.form), 1);
});

test('the snapshot is taken once the field scripts have run: a hidden input they set at boot is no change', async () => {
    const p = screen();
    p.c.rich.value = '<p>Normalized</p>'; // a field script at boot, no event
    await tick();
    assert.equal(p.w.Folio.unsaved.count(p.form), 0);
    assert.equal(p.state.hidden, true);
});

test('a counter follows the text again after Cancel', async () => {
    const p = screen({ fields: true });
    await tick();
    assert.equal(p.c.counter.textContent, '4 / 160');
    edit(p.w, p.c.body, 'A longer text');
    assert.equal(p.c.counter.textContent, '13 / 160');
    p.cancel.click();
    assert.equal(p.c.body.value, 'Text');
    assert.equal(p.c.counter.textContent, '4 / 160');
    assert.equal(p.state.hidden, true);
});

test('dirty with nothing left to count: "Unsaved changes" without a number, and Cancel', async () => {
    const p = screen();
    await tick();
    edit(p.w, p.c.title, 'Hello!');
    edit(p.w, p.c.title, 'Hello');
    assert.equal(p.w.Folio.unsaved.count(p.form), 0);
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);
    assert.equal(p.state.hidden, false, 'never hidden while the form is dirty');
    assert.equal(p.text.textContent, 'Unsaved changes');
    assert.equal(p.cancel.hidden, false);
    p.cancel.click();
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
    assert.equal(p.state.hidden, true);
});

test('a refused save starts unsaved: "Not saved", the guard asks, and Cancel reloads the edit page', async () => {
    const p = screen({ refused: true });
    await tick();
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);
    assert.equal(p.state.hidden, false);
    assert.equal(p.text.textContent, 'Not saved');
    assert.equal(p.cancel.hidden, false);
    edit(p.w, p.c.title, 'Hello!');
    assert.equal(p.text.textContent, '1 unsaved change', 'its own count once something differs from the refused values');
    edit(p.w, p.c.title, 'Hello');
    assert.equal(p.text.textContent, 'Not saved');

    p.cancel.click();
    assert.equal(p.w.location.href, '/folio/x/edit?locale=de');
    assert.equal(p.c.title.value, 'Hello', 'not restored in place: the refused values are not the saved ones');
});
