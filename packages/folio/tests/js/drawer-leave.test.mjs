// A drawer with unsaved changes (assets/admin.js): the drawer is its own page in an iframe, and removing
// the frame sends no beforeunload. So Escape in the drawer, and the page behind's Close button, Escape and
// a click beside the drawer, ask the drawer's page first (Folio.leaveDrawer): while a guarded form there
// is dirty (Folio.unsaved), its styled leave dialog asks, and only "Leave without saving" closes the
// drawer. A clean drawer closes at once. The redirect form and the alt-text form are guarded
// (data-folio-guard), the relation's "New record" drawer is the record form (.folio-form).
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * The page behind with a drawer open, and the drawer's own page in its frame. form: 'redirect' (a guarded
 * form of its own), 'alt' (the alt-text form) or 'record' (the create drawer's record form in .folio-form).
 */
function drawer(form = 'redirect') {
    const page = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    load(page, ADMIN_JS);
    page.Folio.openDrawer('/folio/redirects/new?_drawer=1');
    const frame = page.document.body.querySelector('.folio-drawer iframe');

    const inside = createWindow({ body: { 'data-folio-prefix': '/folio', class: 'folio-drawer-body' } });
    inside.document.body.className = 'folio-drawer-body';
    const doc = inside.document;
    const host = form === 'record' ? doc.body.appendChild(doc.createElement('div')) : doc.body;
    if (form === 'record') { host.className = 'folio-form'; }
    const f = host.appendChild(doc.createElement('form'));
    f.setAttribute('method', 'post');
    if (form === 'redirect') { f.setAttribute('data-redirect-form', ''); f.setAttribute('data-folio-guard', ''); }
    if (form === 'alt') { f.className = 'folio-assets-alt-form'; f.setAttribute('data-folio-guard', ''); }
    const input = f.appendChild(doc.createElement('input'));
    input.type = 'text';
    input.name = form === 'alt' ? 'alt[a1]' : 'title';
    // The frame's window is the drawer's page; its messages reach the page behind from that frame.
    frame.contentWindow = inside;
    inside.parent = { postMessage: (data, origin) => page.receive(data, origin, inside) };
    load(inside, ADMIN_JS);
    const open = () => page.document.body.querySelector('.folio-drawer') !== null;
    const dialog = () => doc.body.querySelector('dialog[data-folio-dialog]');
    const answer = async (name) => {
        dialog().querySelector('[data-folio-dialog-' + name + ']').click();
        await settle();
    };
    const type = (value) => {
        input.value = value;
        input.dispatchEvent(new inside.Event('input', { bubbles: true }));
    };
    return { page, inside, frame, input, open, dialog, answer, type };
}

test('a dirty drawer survives Escape, Close, Escape behind it and a click beside it until "Leave without saving"', async () => {
    const d = drawer('redirect');
    d.type('/old-page');
    assert.equal(d.inside.Folio.unsaved.isDirty(), true);

    // Escape in the drawer: its leave dialog asks; the drawer stays.
    assert.equal(d.inside.document.body.keydown('Escape').defaultPrevented, true);
    assert.equal(d.open(), true);
    assert.equal(d.dialog().open, true);
    assert.equal(d.dialog().querySelector('[data-folio-dialog-confirm]').textContent, 'Leave without saving');
    await d.answer('cancel'); // Stay
    assert.equal(d.open(), true);
    assert.equal(d.input.value, '/old-page');

    // The page behind's Close button asks the drawer's page.
    d.page.document.body.querySelector('.folio-drawer-close').click();
    assert.equal(d.open(), true);
    assert.equal(d.dialog().open, true);
    await d.answer('cancel');

    // Escape on the page behind, and a click beside the drawer, ask too.
    d.page.document.body.keydown('Escape');
    assert.equal(d.open(), true);
    assert.equal(d.dialog().open, true);
    await d.answer('cancel');
    d.page.document.body.querySelector('.folio-drawer').click();
    assert.equal(d.open(), true);
    assert.equal(d.dialog().open, true);

    await d.answer('confirm'); // Leave without saving
    assert.equal(d.open(), false, 'now the drawer closes');
    assert.equal(d.inside.Folio.unsaved.isDirty(), false);
});

test('a clean drawer closes at once, by Escape inside or Close behind', async () => {
    const d = drawer('redirect');
    d.page.document.body.querySelector('.folio-drawer-close').click();
    assert.equal(d.open(), false);
    assert.equal(d.dialog(), null, 'nothing asked');

    const e = drawer('redirect');
    e.inside.document.body.keydown('Escape');
    assert.equal(e.open(), false);
});

test('the alt-text form and the create drawer\'s record form are guarded as well', async () => {
    for (const form of ['alt', 'record']) {
        const d = drawer(form);
        d.type('A red barn');
        d.inside.document.body.keydown('Escape');
        assert.equal(d.open(), true, form + ': the drawer stays');
        assert.equal(d.dialog().open, true);
        await d.answer('confirm');
        assert.equal(d.open(), false);
    }
});
