// The live preview's view switch (0.94, assets/admin.js initSplit(), form.twig's preview stage): a type
// with "preview" views shows them as segmented buttons beside the width switch; a click puts the view
// key in the form's hidden _preview_view, marks the button pressed, and renders the preview again as a
// full render (the form posted into the frame), never as a fetch patch: another view is another
// component, whose markup a patch cannot carry over.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** The editor in split mode, its live frame showing a document, and the view switch card | wide. */
function editor() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    w.innerWidth = 1440;
    const fetched = [];
    w.fetch = (url, init) => { fetched.push({ url, body: init && init.body }); return new Promise(() => {}); };
    // FormData over the form's inputs: their names and current values.
    w.FormData = class {
        constructor(form) { this.entries = form.querySelectorAll('input').map((i) => [i.getAttribute('name'), i.value]); }
        forEach(fn) { this.entries.forEach(([k, v]) => fn(v, k)); }
        get(name) { const e = this.entries.find(([k]) => k === name); return e ? e[1] : null; }
    };
    // A full render posts a hidden form into the frame: each submit() is kept with what it sends.
    const posted = [];
    const origCreate = doc.createElement;
    doc.createElement = (tag) => {
        const e = origCreate(tag);
        if (tag === 'form') {
            e.submit = () => posted.push({
                action: e.action,
                target: e.target,
                fields: Object.fromEntries(e.querySelectorAll('input').map((i) => [i.name, i.value])),
            });
        }
        return e;
    };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const splitEl = el(doc.body, 'div', { 'data-folio-split': '', 'data-mode': 'split' }, 'folio-split');
    splitEl.style = { setProperty() {} };
    splitEl.getBoundingClientRect = () => ({ left: 0, width: 1440 });
    const host = el(splitEl, 'div', {}, 'folio-split-form');
    const form = el(host, 'form', { id: 'folio-record', action: '/folio/job/j1' });
    const view = el(form, 'input', { type: 'hidden', name: '_preview_view', value: 'card' });
    view.name = '_preview_view';
    view.value = 'card';
    const title = el(form, 'input', { type: 'text', name: 'title' });
    title.name = 'title';
    title.value = 'Cook';
    const stage = el(splitEl, 'section', { 'data-folio-stage': '', 'data-preview-url': '/folio/job/j1/preview' });
    const views = el(stage, 'div', { role: 'group' }, 'folio-segmented folio-preview-views');
    const card = el(views, 'button', { type: 'button', 'data-preview-view': 'card', 'aria-pressed': 'true' });
    const wide = el(views, 'button', { type: 'button', 'data-preview-view': 'wide', 'aria-pressed': 'false' });
    const phone = el(stage, 'button', { type: 'button', 'data-preview-viewport': '375px', 'aria-pressed': 'false' });
    const frame = el(stage, 'iframe', { name: 'folio-preview-frame' }, 'folio-preview-frame');
    frame.name = 'folio-preview-frame';
    frame.style = {};
    frame.contentWindow.location = { href: 'https://site.test/folio/job/j1/preview' };
    frame.contentWindow.postMessage = () => {};
    frame.contentWindow.scrollY = 0;
    frame.contentWindow.scrollTo = () => {};
    load(w, ADMIN_JS);
    frame.dispatchEvent(new w.Event('load')); // the first render's document is in the frame
    posted.length = 0;
    fetched.length = 0;
    return { w, doc, form, view, card, wide, phone, frame, posted, fetched };
}

test('a view button sets _preview_view, is pressed, and renders the preview in full', () => {
    const e = editor();

    e.wide.click();

    assert.equal(e.view.value, 'wide', 'the hidden input the form posts');
    assert.equal(e.wide.getAttribute('aria-pressed'), 'true');
    assert.equal(e.card.getAttribute('aria-pressed'), 'false');
    assert.equal(e.posted.length, 1, 'one full render');
    assert.deepEqual({ action: e.posted[0].action, target: e.posted[0].target }, { action: '/folio/job/j1/preview', target: 'folio-preview-frame' });
    assert.equal(e.posted[0].fields._preview_view, 'wide', 'the render posts the chosen view');
    assert.equal(e.posted[0].fields.title, 'Cook', 'with the form as typed');
    assert.equal(e.fetched.length, 0, 'no fetch patch');
});

test('the pressed view again, or a click beside the buttons, renders nothing', () => {
    const e = editor();

    e.card.click();
    e.card.parentNode.click();

    assert.equal(e.posted.length, 0);
    assert.equal(e.fetched.length, 0);
    assert.equal(e.view.value, 'card');
});

test('the width switch still sizes the frame and leaves the view as it is', () => {
    const e = editor();

    e.wide.click();
    e.phone.click();

    assert.equal(e.frame.style.width, '375px');
    assert.equal(e.phone.getAttribute('aria-pressed'), 'true');
    assert.equal(e.wide.getAttribute('aria-pressed'), 'true', 'the view buttons are a group of their own');
    assert.equal(e.posted.length, 1, 'only the view click rendered');
});

test('an edit after the switch keeps posting the chosen view', async () => {
    const e = editor();

    e.wide.click();
    e.form.querySelector('input[name="title"]').dispatchEvent(new e.w.Event('input', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 450));

    assert.equal(e.fetched.length, 1, 'an edit still updates through a fetch');
    assert.equal(e.fetched[0].body.get('_preview_view'), 'wide');
});
