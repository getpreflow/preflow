// A page's layout editor (AreasEditor, 0.92; areas/section.twig): the areas section that a layout switch or
// a Move swaps in (POST {admin}/_areas, htmx) is announced as an edit of the record form, so autosave saves
// it and a guarded form counts it as unsaved (admin.js onHtmxLoad).
// Run: node --test packages/folio/tests/js/areas.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** A record form with a page's areas section; autosave: the form has hx-post (folio:edit). */
function editor({ autosave = true } = {}) {
    const w = createWindow();
    const doc = w.document;
    doc.documentElement.setAttribute('lang', 'en');
    const log = { edits: 0 };
    const host = doc.body.appendChild(doc.createElement('div'));
    host.className = 'folio-form'; // the record editor's form, guarded when it does not autosave
    const form = host.appendChild(doc.createElement('form'));
    form.setAttribute('id', 'folio-record');
    if (autosave) {
        form.setAttribute('hx-post', '/folio/page/p1/autosave');
        form.setAttribute('hx-target', '#folio-save-state');
    }
    form.addEventListener('folio:edit', () => { log.edits++; });
    const title = form.appendChild(doc.createElement('input'));
    title.setAttribute('name', 'title');
    const layout = form.appendChild(doc.createElement('input'));
    layout.setAttribute('type', 'hidden');
    layout.setAttribute('name', '_layout');
    layout.value = 'landing';
    layout.defaultValue = 'landing';
    load(w, ADMIN_JS);

    /** The section as the endpoint's answer swaps it in, then htmx:load on it. */
    const swap = () => {
        const section = form.appendChild(doc.createElement('section'));
        section.setAttribute('id', 'folio-areas-1');
        section.setAttribute('data-areas-section', '');
        const input = section.appendChild(doc.createElement('input'));
        input.setAttribute('type', 'hidden');
        input.setAttribute('name', '_areas[main][_order][]');
        input.value = 'a1a1a1a1a1a1a1a1';
        section.dispatchEvent(new w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: section } }));
        return section;
    };

    return { w, form, layout, log, swap };
}

test('a swapped areas section is an edit: autosave hears it', () => {
    const e = editor();
    const before = e.log.edits;
    e.swap();
    assert.ok(e.log.edits > before, 'folio:edit on the record form');
});

test('a swapped areas section marks a guarded form unsaved and counts as a change', () => {
    const e = editor({ autosave: false });
    assert.equal(e.w.Folio.unsaved.isDirty(), false);
    assert.equal(e.w.Folio.unsaved.count(e.form), 0);
    e.swap();
    assert.equal(e.w.Folio.unsaved.isDirty(), true);
    assert.equal(e.w.Folio.unsaved.count(e.form), 1, 'its areas are record data, though their names start with "_"');
    e.layout.value = 'standard';
    assert.equal(e.w.Folio.unsaved.count(e.form), 2, 'so is the layout');
});

test('other content htmx loads is no edit', () => {
    const e = editor();
    const before = e.log.edits;
    const div = e.form.appendChild(e.w.document.createElement('div'));
    div.dispatchEvent(new e.w.CustomEvent('htmx:load', { bubbles: true, detail: { elt: div } }));
    assert.equal(e.log.edits, before);
});
