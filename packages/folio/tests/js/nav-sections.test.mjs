// The sidebar's collapsible sections (assets/admin.js): each toggle is saved with a fetch.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** The sidebar as the layout renders it: a closed settings section, an open content section, the logout form's token. */
function admin(fetchImpl, { save = '/folio/_prefs/nav' } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const nav = doc.body.appendChild(doc.createElement('nav'));
    nav.className = 'folio-nav';
    if (save !== null) { nav.setAttribute('data-nav-save', save); }
    const section = (name, open) => {
        const d = nav.appendChild(doc.createElement('details'));
        d.className = 'folio-nav-section';
        d.setAttribute('data-section', name);
        d.open = open;
        if (open) { d.setAttribute('open', ''); }
        d.appendChild(doc.createElement('summary'));
        return d;
    };
    section('content', true);
    section('settings', false);
    const form = doc.body.appendChild(doc.createElement('form'));
    const token = form.appendChild(doc.createElement('input'));
    token.name = '_csrf_token';
    token.value = 'tok123';

    w.calls = [];
    w.fetch = (url, options) => { w.calls.push({ url, options }); return fetchImpl(); };
    w.URLSearchParams = URLSearchParams;
    load(w, ADMIN_JS);
    return w;
}

const details = (w, name) => w.document.querySelector('details[data-section="' + name + '"]');
const toggle = (d, open) => { d.open = open; d.dispatchEvent(new Event('toggle')); };
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

test('opening a closed section posts section and open=1 with the CSRF token', async () => {
    const w = admin(() => Promise.resolve({ ok: true, status: 204 }));

    toggle(details(w, 'settings'), true);

    assert.equal(w.calls.length, 1);
    const { url, options } = w.calls[0];
    assert.equal(url, '/folio/_prefs/nav');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    const body = new URLSearchParams(String(options.body));
    assert.equal(body.get('section'), 'settings');
    assert.equal(body.get('open'), '1');
    assert.equal(body.get('_csrf_token'), 'tok123');
    await tick();
});

test('closing an open section posts open=0', () => {
    const w = admin(() => Promise.resolve({ ok: true, status: 204 }));

    toggle(details(w, 'content'), false);

    assert.equal(w.calls.length, 1);
    const body = new URLSearchParams(String(w.calls[0].options.body));
    assert.equal(body.get('section'), 'content');
    assert.equal(body.get('open'), '0');
});

test('a toggle event without a change (the browser\'s first one for an open section) posts nothing', () => {
    const w = admin(() => Promise.resolve({ ok: true, status: 204 }));

    toggle(details(w, 'content'), true);

    assert.equal(w.calls.length, 0);
});

test('a failed save throws nothing and leaves the section as it is', async () => {
    const errors = [];
    const onError = (e) => errors.push(e);
    process.on('unhandledRejection', onError);
    try {
        const w = admin(() => Promise.reject(new Error('offline')));
        const d = details(w, 'settings');

        assert.doesNotThrow(() => toggle(d, true));
        await tick();
        await tick();

        assert.equal(d.open, true, 'still open, as the person left it');
        assert.equal(d.hasAttribute('open'), false, 'the script does not touch the attribute');
        assert.equal(w.document.querySelector('.folio-toast'), null, 'no message');
        assert.deepEqual(errors, []);
    } finally {
        process.off('unhandledRejection', onError);
    }
});

test('a refused save (422) shows nothing', async () => {
    const w = admin(() => Promise.resolve({ ok: false, status: 422, text: () => Promise.resolve('Invalid section.') }));

    toggle(details(w, 'settings'), true);
    await tick();

    assert.equal(w.calls.length, 1);
    assert.equal(w.document.querySelector('.folio-toast'), null);
});

test('without a save URL (signed out) a toggle posts nothing', () => {
    const w = admin(() => Promise.resolve({ ok: true, status: 204 }), { save: null });

    toggle(details(w, 'settings'), true);

    assert.equal(w.calls.length, 0);
});
