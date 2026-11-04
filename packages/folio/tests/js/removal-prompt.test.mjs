// "Send its old links to…" (assets/admin.js): deleting or unpublishing a record that has public addresses
// asks where its old links go before the form is sent. Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

const settle = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * The admin script with fetch, confirm and alert faked: `plan` is the delete plan's answer (null: the
 * plan request fails), `targets` the page picker's items. Every request, dialog question and submitted
 * form is logged.
 */
function admin({ plan = null, targets = [] } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const log = { fetched: [], bodies: [], confirmed: [], alerted: [], submitted: [] };
    w.confirmAnswer = true;
    w.confirm = (text) => { log.confirmed.push(text); return w.confirmAnswer; };
    w.alert = (text) => { log.alerted.push(text); };
    w.URLSearchParams = URLSearchParams;
    w.fetch = (url, options = {}) => {
        log.fetched.push(url);
        log.bodies.push(options.body ? String(options.body) : null);
        if (url.startsWith('/folio/redirects/_targets')) {
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ items: targets }) });
        }
        return plan === null
            ? Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new Error('no json')) })
            : Promise.resolve({ ok: true, json: () => Promise.resolve(plan) });
    };
    w.HTMLFormElement = { prototype: { submit() { log.submitted.push(this); } } };
    load(w, ADMIN_JS);
    return { w, log };
}

/** A list row's delete form: data-confirm, data-delete-plan, data-delete-id. */
function deleteForm(w) {
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    form.setAttribute('method', 'post');
    form.setAttribute('action', '/folio/news/n1/delete');
    form.setAttribute('data-confirm', 'Delete this record?');
    form.setAttribute('data-delete-plan', '/folio/news/_delete-plan');
    form.setAttribute('data-delete-id', 'n1');
    return form;
}

/** The editor's unpublish form with the record's address count. */
function unpublishForm(w, addresses) {
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    form.setAttribute('method', 'post');
    form.setAttribute('action', '/folio/page/p1/unpublish');
    form.setAttribute('data-confirm', 'Take this page off the site?');
    form.setAttribute('data-removal-addresses', String(addresses));
    return form;
}

function submit(w, form) {
    const event = new w.Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    return event;
}

const dialog = (w) => w.document.body.querySelector('dialog[data-removal-dialog]');
const posted = (form) => form.querySelector('input[name="_redirect_to"]')?.value ?? null;

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

test('test_no_addresses_keeps_the_plain_confirm', async () => {
    const { w, log } = admin({ plan: { restricted: false, lines: [], confirm: "This can't be undone.", addresses: 0 } });
    const form = deleteForm(w);

    assert.equal(submit(w, form).defaultPrevented, true, 'held while the plan is asked for');
    await settle();

    assert.deepEqual(log.confirmed, ["This can't be undone."]);
    assert.equal(dialog(w), null, 'no prompt');
    assert.deepEqual(log.submitted, [form]);
    assert.equal(posted(form), null);

    // The plan can't be fetched: the form's own question, as before.
    const failing = admin({ plan: null });
    const other = deleteForm(failing.w);
    submit(failing.w, other);
    await settle();
    assert.deepEqual(failing.log.confirmed, ['Delete this record?']);
    assert.equal(dialog(failing.w), null);
    assert.deepEqual(failing.log.submitted, [other]);

    // An unpublish form without addresses: its data-confirm, and nothing else.
    const plain = admin();
    const off = unpublishForm(plain.w, 0);
    plain.w.confirmAnswer = false;
    assert.equal(submit(plain.w, off).defaultPrevented, true, 'cancelled by the plain confirm');
    assert.deepEqual(plain.log.confirmed, ['Take this page off the site?']);
    assert.equal(dialog(plain.w), null);
});

test('test_addresses_open_the_dialog_and_post_the_chosen_page', async () => {
    const { w, log } = admin({
        plan: { restricted: false, lines: ['Also deletes 2 games.'], confirm: "This can't be undone.\nAlso deletes 2 games.", addresses: 3 },
        targets: [
            { value: 'news:n2', label: 'Winter news', type: 'News', url: '/news/winter' },
            { value: 'page:p9', label: 'Archive', type: 'Pages', url: '/archive' },
        ],
    });
    const form = deleteForm(w);

    submit(w, form);
    await settle();

    assert.deepEqual(log.confirmed, [], 'the dialog replaces window.confirm');
    const d = dialog(w);
    assert.ok(d, 'the prompt is shown');
    assert.equal(d.open, true, 'as a modal');
    const text = d.descendants().map((el) => el.textContent).join('\n');
    assert.match(text, /This can't be undone\./);
    assert.match(text, /Also deletes 2 games\./);
    assert.equal(d.querySelector('input[value="none"]').checked, true, '"Leave them as not found" is the default');

    type(d.querySelector('input[data-removal-search]'), 'win');
    await settle(260);
    assert.ok(log.fetched.includes('/folio/redirects/_targets?q=win'), log.fetched.join(', '));
    const picks = d.querySelectorAll('button[data-removal-pick]');
    assert.equal(picks.length, 2);
    picks[1].click();
    assert.equal(d.querySelector('input[value="page"]').checked, true, 'picking a page chooses that option');

    d.querySelector('button[data-removal-confirm]').click();

    assert.equal(posted(form), 'page:p9', 'the type key from value, not the display type');
    assert.deepEqual(log.submitted, [form]);
    assert.equal(d.open, false);
});

test('test_url_target_is_posted', async () => {
    const { w, log } = admin();
    const form = unpublishForm(w, 2);

    assert.equal(submit(w, form).defaultPrevented, true);
    assert.deepEqual(log.confirmed, [], 'the prompt asks instead of data-confirm');
    const d = dialog(w);
    assert.ok(d);
    assert.match(d.descendants().map((el) => el.textContent).join('\n'), /Take this page off the site\?/);

    const url = d.querySelector('input[data-removal-url]');
    type(url, '//evil.example/x');
    assert.equal(d.querySelector('input[value="url"]').checked, true, 'typing a URL chooses that option');
    d.querySelector('button[data-removal-confirm]').click();
    assert.deepEqual(log.submitted, [], 'a protocol-relative address is refused');
    assert.ok(d.querySelector('[data-removal-error]').textContent !== '');
    assert.equal(d.open, true);

    type(url, ' https://example.org/archive ');
    d.querySelector('button[data-removal-confirm]').click();

    assert.equal(posted(form), 'https://example.org/archive');
    assert.deepEqual(log.submitted, [form]);
});

test('test_leave_as_not_found_posts_no_target', async () => {
    const { w, log } = admin({
        plan: { restricted: false, lines: [], confirm: "This can't be undone.", addresses: 1 },
        targets: [{ value: 'news:n2', label: 'Winter news', type: 'News', url: '/news/winter' }],
    });
    const form = deleteForm(w);

    submit(w, form);
    await settle();
    let d = dialog(w);
    d.querySelector('button[data-removal-cancel]').click();
    assert.deepEqual(log.submitted, [], 'cancel sends nothing');
    assert.equal(d.open, false);

    // Asked again: a page picked, then "Leave them as not found" chosen after all.
    submit(w, form);
    await settle();
    d = dialog(w);
    assert.equal(d.open, true);
    type(d.querySelector('input[data-removal-search]'), 'win');
    await settle(260);
    d.querySelector('button[data-removal-pick]').click();
    const none = d.querySelector('input[value="none"]');
    none.checked = true;
    d.querySelector('input[value="page"]').checked = false;
    none.dispatchEvent(new Event('change', { bubbles: true }));
    d.querySelector('button[data-removal-confirm]').click();

    assert.equal(posted(form), null, 'no _redirect_to: the old links answer 404');
    assert.deepEqual(log.submitted, [form]);
});

test('test_restricted_plan_only_alerts', async () => {
    const { w, log } = admin({ plan: { restricted: true, lines: ["Can't delete: 2 progress records still point here."], confirm: "Can't delete: 2 progress records still point here.", addresses: 4 } });
    const form = deleteForm(w);

    submit(w, form);
    await settle();

    assert.deepEqual(log.alerted, ["Can't delete: 2 progress records still point here."]);
    assert.deepEqual(log.confirmed, []);
    assert.equal(dialog(w), null);
    assert.deepEqual(log.submitted, []);
});

/** The list's bulk bar: ticked ids[] and a Delete button carrying data-delete-plan and its formaction. */
function bulkForm(w, ticked) {
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    form.setAttribute('method', 'post');
    form.setAttribute('action', '/folio/news/_bulk');
    const token = form.appendChild(doc.createElement('input'));
    token.type = 'hidden';
    token.name = '_csrf_token';
    token.value = 'tok';
    const boxes = ['n1', 'n2', 'n3'].map((id) => {
        const box = form.appendChild(doc.createElement('input'));
        box.type = 'checkbox';
        box.name = 'ids[]';
        box.value = id;
        box.checked = ticked.includes(id);
        return box;
    });
    form.elements = [token, ...boxes];
    const button = form.appendChild(doc.createElement('button'));
    button.setAttribute('formaction', '/folio/news/_bulk/delete');
    button.setAttribute('data-confirm', 'Delete the selected records?');
    button.setAttribute('data-delete-plan', '/folio/news/_delete-plan');
    return { form, button };
}

/** A submit event sent by a button (event.submitter), as the browser sends it. */
function submitBy(w, form, button) {
    const event = new w.Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'submitter', { value: button });
    form.dispatchEvent(event);
    return event;
}

test('test_bulk_delete_asks_for_the_ticked_ids_and_submits_the_button_again', async () => {
    const { w, log } = admin({ plan: { restricted: false, lines: [], confirm: "This can't be undone.", addresses: 2 } });
    const { form, button } = bulkForm(w, ['n1', 'n2']);
    const requested = [];
    form.requestSubmit = (submitter) => {
        requested.push({ submitter, target: posted(form), confirmed: form.getAttribute('data-delete-confirmed') });
    };

    assert.equal(submitBy(w, form, button).defaultPrevented, true, 'held while the plan is asked for');
    await settle();

    assert.deepEqual(log.fetched, ['/folio/news/_delete-plan']);
    assert.equal(log.bodies[0], '_csrf_token=tok&ids%5B%5D=n1&ids%5B%5D=n2', 'the ticked ids only');
    assert.deepEqual(log.confirmed, [], 'the dialog replaces window.confirm');
    const d = dialog(w);
    assert.ok(d, 'the prompt is shown');

    type(d.querySelector('input[data-removal-url]'), '/team');
    d.querySelector('button[data-removal-confirm]').click();

    assert.deepEqual(requested, [{ submitter: button, target: '/team', confirmed: '1' }], 'the Delete button is submitted again, with _redirect_to');
    assert.deepEqual(log.submitted, [], 'not a plain form.submit(): that would drop the button\'s formaction');

    // The browser's second submit (marked confirmed) goes through untouched.
    assert.equal(submitBy(w, form, button).defaultPrevented, false);
    assert.equal(form.getAttribute('data-delete-confirmed'), null);
    assert.equal(log.fetched.length, 1, 'the plan is not asked for again');
});
