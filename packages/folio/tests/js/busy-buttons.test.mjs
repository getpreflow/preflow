// Busy buttons (assets/admin.js): a submit button with data-busy-label shows its request is running
// (is-busy, aria-busy, disabled, the label) and is always restored when the request ends.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** An editor form with Save draft and Publish (both with data-busy-label) and a plain Cancel-like button. */
function editor() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    const button = (text, label) => {
        const b = form.appendChild(doc.createElement('button'));
        b.type = 'submit';
        b.className = 'btn btn-primary';
        b.textContent = text;
        b.disabled = false;
        if (label) { b.setAttribute('data-busy-label', label); }
        return b;
    };
    const save = button('Save draft', 'Saving…');
    const publish = button('Publish', 'Publishing…');
    const plain = button('Still correct', null);
    load(w, ADMIN_JS);
    return { w, doc, form, save, publish, plain };
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function submit(form, submitter) {
    const e = new Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(e, 'submitter', { value: submitter });
    form.dispatchEvent(e);
    return e;
}

function htmx(elt, name, detail = {}) {
    const e = new CustomEvent(name, { bubbles: true, cancelable: true, detail: { elt, ...detail } });
    elt.dispatchEvent(e);
    return e;
}

const isBusy = (b) => b.classList.contains('is-busy') && b.getAttribute('aria-busy') === 'true' && b.disabled === true;
const isIdle = (b, text) => !b.classList.contains('is-busy') && b.getAttribute('aria-busy') === null && b.disabled === false && b.textContent === text;

test('a plain submit makes its submitter busy, after the form values are collected', async () => {
    const { form, publish, save } = editor();

    submit(form, publish);
    assert.equal(publish.disabled, false, 'still enabled while the browser builds the form data (keeps _action=publish)');

    await tick();
    assert.ok(isBusy(publish));
    assert.equal(publish.textContent, 'Publishing…');
    assert.ok(isIdle(save, 'Save draft'), 'only the submitter');
});

test('a cancelled submit (data-confirm said no) stays idle', async () => {
    const { form, save } = editor();
    form.addEventListener('submit', (e) => e.preventDefault());

    submit(form, save);
    await tick();

    assert.ok(isIdle(save, 'Save draft'));
});

test('a submitter without data-busy-label is left alone', async () => {
    const { form, plain } = editor();

    submit(form, plain);
    await tick();

    assert.ok(isIdle(plain, 'Still correct'));
});

test('coming back from the back/forward cache restores the button', async () => {
    const { w, form, save } = editor();
    submit(form, save);
    await tick();
    assert.ok(isBusy(save));

    (w.listeners.pageshow || []).forEach((fn) => fn({ persisted: true }));

    assert.ok(isIdle(save, 'Save draft'));
});

test('an htmx request from the form shows its submitter busy and clears it on success', () => {
    const { form, save } = editor();
    const requestConfig = { triggeringEvent: { submitter: save } };

    htmx(form, 'htmx:beforeRequest', { requestConfig });
    assert.ok(isBusy(save));
    assert.equal(save.textContent, 'Saving…');

    htmx(form, 'htmx:afterRequest', { requestConfig, successful: true });
    assert.ok(isIdle(save, 'Save draft'));
});

for (const failure of ['htmx:responseError', 'htmx:sendError', 'htmx:timeout']) {
    test(`an htmx request that fails (${failure}) clears the busy state`, () => {
        const { form, publish } = editor();
        const requestConfig = { triggeringEvent: { submitter: publish } };

        htmx(form, 'htmx:beforeRequest', { requestConfig });
        assert.ok(isBusy(publish));

        htmx(form, failure, { requestConfig });
        assert.ok(isIdle(publish, 'Publish'));
    });
}

test('a button that sends its own htmx request is busy itself', () => {
    const { doc } = editor();
    const btn = doc.body.appendChild(doc.createElement('button'));
    btn.textContent = 'Run';
    btn.disabled = false;
    btn.setAttribute('data-busy-label', 'Running…');

    htmx(btn, 'htmx:beforeRequest');
    assert.ok(isBusy(btn));
    htmx(btn, 'htmx:afterRequest');
    assert.ok(isIdle(btn, 'Run'));
});

test('an autosave request (no submitter) leaves the buttons alone', () => {
    const { form, save, publish } = editor();

    htmx(form, 'htmx:beforeRequest', { requestConfig: { triggeringEvent: { type: 'input' } } });

    assert.ok(isIdle(save, 'Save draft'));
    assert.ok(isIdle(publish, 'Publish'));
});

test('a request another handler stopped (autosave during a real save) does not make a button busy', () => {
    const { form, save } = editor();
    form.addEventListener('htmx:beforeRequest', (e) => e.preventDefault());

    htmx(form, 'htmx:beforeRequest', { requestConfig: { triggeringEvent: { submitter: save } } });

    assert.ok(isIdle(save, 'Save draft'));
});

test('a swap that replaces the busy button forgets it; a later pageshow touches nothing', () => {
    const { w, doc, form, save } = editor();
    htmx(form, 'htmx:beforeRequest', { requestConfig: { triggeringEvent: { submitter: save } } });
    assert.ok(isBusy(save));

    save.remove();
    htmx(doc.body, 'htmx:afterSwap');

    assert.equal(save.classList.contains('is-busy'), false, 'state dropped with the old markup');
    assert.equal(save.disabled, false);
    (w.listeners.pageshow || []).forEach((fn) => fn({ persisted: true }));
});

test('the busy markup and its CSS hook exist', async () => {
    const { readFileSync } = await import('node:fs');
    const css = readFileSync(fileURLToPath(new URL('../../assets/admin.css', import.meta.url)), 'utf8');
    assert.match(css, /\.btn\.is-busy::before \{[^}]*width: 14px;[^}]*animation: folio-spin/);
    const form = readFileSync(fileURLToPath(new URL('../../templates/admin/form.twig', import.meta.url)), 'utf8');
    assert.match(form, /value="publish" data-busy-label="\{\{ t\('folio\.form\.publishing'\) \}\}"/);
    assert.match(form, /data-busy-label="\{\{ t\('folio\.form\.saving'\) \}\}">\{\{ t\('folio\.form\.save_draft'\) \}\}/);
});
