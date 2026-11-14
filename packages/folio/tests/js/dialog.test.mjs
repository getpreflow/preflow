// The styled dialog (assets/admin.js): window.folioConfirm / window.folioAlert on one native <dialog>, and
// what asks through it: data-confirm forms and buttons, the delete plan, htmx's hx-confirm (htmx:confirm).
// window.confirm and window.alert throw here: Folio never shows the browser's own boxes.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

const settle = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

/** admin.js with the browser's boxes forbidden; fetch answers the delete plan (`plan`, null: the request fails). */
function admin({ plan = null, markup = false } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const log = { fetched: [], bodies: [] };
    w.confirm = () => { throw new Error('window.confirm called'); };
    w.alert = () => { throw new Error('window.alert called'); };
    w.prompt = () => { throw new Error('window.prompt called'); };
    w.fetch = (url, options = {}) => {
        log.fetched.push(url);
        log.bodies.push(options.body ? String(options.body) : null);
        return plan === null
            ? Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new Error('no json')) })
            : Promise.resolve({ ok: true, json: () => Promise.resolve(plan) });
    };
    w.HTMLFormElement = { prototype: { submit() { throw new Error('form.submit() skips the submit handlers'); } } };
    if (markup) { serverDialog(w); }
    load(w, ADMIN_JS);
    return { w, log };
}

/** The layouts' _dialog.twig, as the server renders it (labels in the interface language). */
function serverDialog(w) {
    const doc = w.document;
    const el = (parent, tag, attrs = {}, text = '') => {
        const node = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => { if (k === 'class') { node.className = v; } else { node.setAttribute(k, v); } });
        if (text) { node.textContent = text; }
        return node;
    };
    const dialog = el(doc.body, 'dialog', { class: 'folio-dialog', id: 'folio-dialog', 'data-folio-dialog': '', 'aria-labelledby': 'folio-dialog-title', 'aria-describedby': 'folio-dialog-message', 'data-text-ok': 'OK', 'data-text-confirm': 'Bestätigen' });
    const panel = el(dialog, 'div', { class: 'folio-dialog-panel' });
    el(el(el(panel, 'span', { class: 'folio-dialog-icon', 'data-folio-dialog-icon': '' }), 'svg', { class: 'folio-icon' }), 'use', { href: '/assets/icons.svg?v=1#ui-info' });
    el(panel, 'h2', { class: 'folio-dialog-title', id: 'folio-dialog-title', 'data-folio-dialog-title': '' });
    el(panel, 'p', { class: 'folio-dialog-message', id: 'folio-dialog-message', 'data-folio-dialog-message': '' });
    el(panel, 'ul', { class: 'folio-dialog-items', 'data-folio-dialog-items': '' }).hidden = true;
    const actions = el(panel, 'div', { class: 'folio-dialog-actions' });
    el(actions, 'button', { type: 'button', class: 'btn btn-secondary', 'data-folio-dialog-cancel': '' }, 'Abbrechen');
    el(actions, 'button', { type: 'button', class: 'btn btn-secondary', 'data-folio-dialog-extra': '' }).hidden = true;
    el(actions, 'button', { type: 'button', class: 'btn btn-primary', 'data-folio-dialog-confirm': '' }, 'Bestätigen');
    return dialog;
}

const theDialog = (w) => w.document.body.querySelector('dialog[data-folio-dialog]');
const press = (w, el) => el.dispatchEvent(new w.Event('pointerdown', { bubbles: true }));
/** A press and click on the backdrop (the dialog element itself). */
function backdropClick(w) {
    press(w, theDialog(w));
    theDialog(w).click();
}
const part = (w, name) => theDialog(w).querySelector('[data-folio-dialog-' + name + ']');

function button(w, parent, text, attrs = {}) {
    const b = parent.appendChild(w.document.createElement('button'));
    b.type = 'submit';
    b.textContent = text;
    Object.entries(attrs).forEach(([k, v]) => { if (k === 'class') { b.className = v; } else { b.setAttribute(k, v); } });
    return b;
}

function form(w, attrs = {}) {
    const f = w.document.body.appendChild(w.document.createElement('form'));
    f.setAttribute('method', 'post');
    Object.entries(attrs).forEach(([k, v]) => f.setAttribute(k, v));
    return f;
}

/** A submit event sent by a button (event.submitter), as the browser sends it on a click. */
function submitBy(w, f, submitter = null) {
    const event = new w.Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'submitter', { value: submitter });
    f.dispatchEvent(event);
    return event;
}

// ---- folioConfirm / folioAlert -------------------------------------------------

test('folioConfirm shows title, message and items, and resolves "confirm"', async () => {
    const { w } = admin();
    const answer = w.folioConfirm({ title: 'Delete “Winter”?', message: "This can't be undone.", items: ['Also deletes 2 games.', '1 page loses a link.'], confirm: 'Delete', tone: 'danger' });
    const d = theDialog(w);
    assert.ok(d, 'one dialog in the page');
    assert.equal(d.open, true, 'opened with showModal()');
    assert.equal(part(w, 'title').textContent, 'Delete “Winter”?');
    assert.equal(part(w, 'message').textContent, "This can't be undone.");
    assert.equal(part(w, 'items').hidden, false);
    assert.deepEqual(part(w, 'items').querySelectorAll('li').map((li) => li.textContent), ['Also deletes 2 games.', '1 page loses a link.']);
    assert.equal(d.getAttribute('aria-labelledby'), part(w, 'title').id);
    assert.equal(d.getAttribute('aria-describedby'), part(w, 'message').id);
    assert.equal(part(w, 'confirm').textContent, 'Delete');
    assert.ok(part(w, 'confirm').classList.contains('btn-danger'), 'danger: .btn-danger');
    assert.ok(!part(w, 'confirm').classList.contains('btn-primary'));
    assert.equal(part(w, 'extra').hidden, true, 'no third button unless asked');

    part(w, 'confirm').click();
    assert.equal(await answer, 'confirm');
    assert.equal(d.open, false);
    assert.equal(d.hasAttribute('open'), false);
});

test('Cancel, Escape and a click on the backdrop resolve "cancel"', async () => {
    const { w } = admin();
    let answer = w.folioConfirm({ title: 'Restore?' });
    part(w, 'cancel').click();
    assert.equal(await answer, 'cancel');

    answer = w.folioConfirm({ title: 'Restore?' });
    const esc = theDialog(w).escape();
    assert.equal(await answer, 'cancel', 'Escape');
    assert.equal(theDialog(w).open, false);
    assert.equal(esc.defaultPrevented, true, 'the dialog closes itself, after resolving');

    answer = w.folioConfirm({ title: 'Restore?' });
    await settle(320); // past the grace time after opening
    backdropClick(w); // the ::backdrop is the dialog element itself; the panel fills the rest
    assert.equal(await answer, 'cancel', 'backdrop');

    answer = w.folioConfirm({ title: 'Restore?' });
    theDialog(w).querySelector('[data-folio-dialog-title]').click();
    assert.equal(theDialog(w).open, true, 'a click inside the panel is no backdrop click');
    part(w, 'cancel').click();
    await answer;
});

test('only a press and click both on the backdrop cancel, and not right after opening', async () => {
    const { w } = admin();
    const answer = w.folioConfirm({ title: 'Delete?', tone: 'danger' });
    backdropClick(w);
    assert.equal(theDialog(w).open, true, 'the second click of a double click on the opener');
    await settle(320);
    // A text selection dragged out of the panel: pressed inside, released on the backdrop.
    press(w, theDialog(w).querySelector('[data-folio-dialog-title]'));
    theDialog(w).click();
    assert.equal(theDialog(w).open, true, 'pressed in the panel');
    theDialog(w).click();
    assert.equal(theDialog(w).open, true, 'a click without a press on the backdrop');
    backdropClick(w);
    assert.equal(await answer, 'cancel');
});

test('a press on the backdrop released over the panel is no cancel; a closed dialog forgets its press', async () => {
    const { w } = admin();
    const answer = w.folioConfirm({ title: 'Delete?', tone: 'danger' });
    await settle(320);
    const title = theDialog(w).querySelector('[data-folio-dialog-title]');
    // Pressed on the backdrop, released on the panel: the click goes to the common ancestor, the dialog.
    press(w, theDialog(w));
    title.dispatchEvent(new w.Event('pointerup', { bubbles: true }));
    theDialog(w).click();
    assert.equal(theDialog(w).open, true, 'released over the panel');
    // A press on the backdrop, then the dialog closes another way (Cancel): the next question starts clean.
    press(w, theDialog(w));
    part(w, 'cancel').click();
    assert.equal(await answer, 'cancel');
    const next = w.folioConfirm({ title: 'Archive?' });
    await settle(320);
    theDialog(w).click();
    assert.equal(theDialog(w).open, true, 'no press left over from the last question');
    backdropClick(w);
    assert.equal(await next, 'cancel');
});

test('chained questions: the first one\'s queued close event does not cancel the next', async () => {
    const { w } = admin();
    const flow = (async () => {
        const first = await w.folioConfirm({ title: 'Archive?' });
        const second = await w.folioAlert({ title: 'Archived' }).then(() => 'alerted');
        return [first, second];
    })();
    part(w, 'confirm').click();
    await settle(5); // the close event of the first question has run by now
    assert.equal(theDialog(w).open, true, 'the alert is still open');
    assert.equal(part(w, 'title').textContent, 'Archived');
    part(w, 'confirm').click();
    assert.deepEqual(await flow, ['confirm', 'alerted']);
});

test('danger dialogs start on Cancel, others on the confirm button; the focus returns to the opener', async () => {
    const { w } = admin();
    const opener = button(w, w.document.body, 'Delete');
    opener.focus();

    let answer = w.folioConfirm({ title: 'Delete?', tone: 'danger' });
    assert.equal(w.document.activeElement, part(w, 'cancel'), 'danger: focus on Cancel');
    part(w, 'cancel').click();
    await answer;
    assert.equal(w.document.activeElement, opener, 'back to the opener');

    answer = w.folioConfirm({ title: 'Restore version 3?', confirm: 'Restore' });
    assert.equal(w.document.activeElement, part(w, 'confirm'), 'not danger: focus on the confirm button');
    assert.ok(part(w, 'confirm').classList.contains('btn-primary'));
    assert.ok(!part(w, 'confirm').classList.contains('btn-danger'));
    part(w, 'confirm').click();
    await answer;
    assert.equal(w.document.activeElement, opener);
});

test('a third button resolves "extra"; labels default to the server-rendered words', async () => {
    const { w } = admin({ markup: true });
    assert.equal(w.document.body.querySelectorAll('dialog[data-folio-dialog]').length, 1, 'the layout\'s dialog is used, none added');
    const answer = w.folioConfirm({ title: 'Leave?', extra: 'Save and leave' });
    assert.equal(part(w, 'extra').hidden, false);
    assert.equal(part(w, 'extra').textContent, 'Save and leave');
    assert.equal(part(w, 'cancel').textContent, 'Abbrechen', 'the layout\'s Cancel label');
    assert.equal(part(w, 'confirm').textContent, 'Bestätigen', 'the layout\'s confirm label');
    part(w, 'extra').click();
    assert.equal(await answer, 'extra');
    assert.equal(w.document.body.querySelectorAll('dialog[data-folio-dialog]').length, 1);
});

test('folioAlert has one OK button and resolves when closed', async () => {
    const { w } = admin({ markup: true });
    const done = w.folioAlert({ title: 'Can’t delete this yet', message: 'Other records still point here.', items: ['2 jobs'] });
    assert.equal(part(w, 'cancel').hidden, true, 'no Cancel');
    assert.equal(part(w, 'extra').hidden, true);
    assert.equal(part(w, 'confirm').textContent, 'OK');
    assert.equal(w.document.activeElement, part(w, 'confirm'));
    part(w, 'confirm').click();
    assert.equal(await done, undefined);

    // The next confirm has its Cancel back.
    const answer = w.folioConfirm({ title: 'Sure?' });
    assert.equal(part(w, 'cancel').hidden, false);
    part(w, 'cancel').click();
    await answer;
});

test('a message alone becomes the title; no message, no aria-describedby', async () => {
    const { w } = admin();
    const answer = w.folioConfirm({ message: 'Delete this redirect?' });
    assert.equal(part(w, 'title').textContent, 'Delete this redirect?');
    assert.equal(part(w, 'message').hidden, true);
    assert.equal(theDialog(w).getAttribute('aria-describedby'), null);
    assert.equal(part(w, 'items').hidden, true);
    part(w, 'cancel').click();
    await answer;
});

test('a second ask while one is open cancels only the first', async () => {
    const { w } = admin();
    const first = w.folioConfirm({ title: 'One?' });
    const second = w.folioConfirm({ title: 'Two?' });
    assert.equal(await first, 'cancel');
    await settle(5); // the first one's close event
    assert.equal(theDialog(w).open, true, 'the second stays open');
    assert.equal(part(w, 'title').textContent, 'Two?');
    part(w, 'confirm').click();
    assert.equal(await second, 'confirm');
});

// ---- data-confirm ----------------------------------------------------------------

test('data-confirm holds the submit and resubmits the same submitter once confirmed', async () => {
    const { w } = admin();
    const f = form(w, { action: '/folio/redirects/r1/delete', 'data-confirm': 'Delete this redirect?' });
    const del = button(w, f, 'Delete', { name: '_action', value: 'delete', class: 'btn btn-danger' });

    const first = submitBy(w, f, del);
    assert.equal(first.defaultPrevented, true, 'held');
    assert.equal(theDialog(w).open, true);
    assert.equal(part(w, 'title').textContent, 'Delete this redirect?');
    assert.equal(part(w, 'confirm').textContent, 'Delete', 'the confirm button says what the submitter says');
    assert.ok(part(w, 'confirm').classList.contains('btn-danger'), 'a danger submitter makes a danger dialog');

    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(f.sent, [del], 'requestSubmit(submitter): its name/value, busy state and the form\'s CSRF token go along');

    // Cancelled: nothing is sent.
    submitBy(w, f, del);
    part(w, 'cancel').click();
    await settle();
    assert.deepEqual(f.sent, [del]);
});

test('data-confirm-message puts a line under the title', async () => {
    const { w } = admin();
    const f = form(w, { action: '/folio/cache/clear', 'data-confirm': 'Clear every cache?', 'data-confirm-message': '9.0 MB in 489 files.', 'data-confirm-label': 'Clear all', 'data-confirm-tone': 'danger' });
    const go = button(w, f, 'Clear all…', {});

    submitBy(w, f, go);
    assert.equal(part(w, 'title').textContent, 'Clear every cache?');
    assert.equal(part(w, 'message').textContent, '9.0 MB in 489 files.');
    assert.equal(part(w, 'confirm').textContent, 'Clear all');
    part(w, 'cancel').click();
    await settle();
    assert.equal(f.sent, undefined, 'cancelled: nothing sent');
});

test('data-confirm on a bulk button wins over the form; data-confirm-tone and -label', async () => {
    const { w } = admin();
    const f = form(w, { action: '/folio/news/_bulk', 'data-confirm': 'Form question' });
    const archive = button(w, f, 'Archive', { formaction: '/folio/news/_bulk/archive', 'data-confirm': 'Archive 3 records?' });
    const restore = button(w, f, 'x', { 'data-confirm': 'Restore?', 'data-confirm-tone': 'danger', 'data-confirm-label': 'Restore now' });

    submitBy(w, f, archive);
    assert.equal(part(w, 'title').textContent, 'Archive 3 records?');
    assert.equal(part(w, 'confirm').textContent, 'Archive');
    assert.ok(part(w, 'confirm').classList.contains('btn-primary'));
    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(f.sent, [archive]);

    submitBy(w, f, restore);
    assert.equal(part(w, 'confirm').textContent, 'Restore now');
    assert.ok(part(w, 'confirm').classList.contains('btn-danger'));
    part(w, 'cancel').click();
    await settle();
});

test('a submit without submitter (Enter in a field) resubmits without one', async () => {
    const { w } = admin();
    const f = form(w, { 'data-confirm': 'Disable this account?' });
    submitBy(w, f, null);
    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(f.sent, [null]);
});

// ---- The delete plan ----------------------------------------------------------------

function deleteForm(w) {
    const f = form(w, { action: '/folio/news/n1/delete', 'data-confirm': 'Delete this record?', 'data-delete-plan': '/folio/news/_delete-plan', 'data-delete-id': 'n1' });
    const token = f.appendChild(w.document.createElement('input'));
    token.type = 'hidden';
    token.name = '_csrf_token';
    token.value = 'tok';
    const del = button(w, f, 'Delete', { 'data-tone': 'danger' });
    return { f, del };
}

test('delete plan: its lines are the dialog\'s list, and Delete resubmits', async () => {
    const { w, log } = admin({ plan: { restricted: false, lines: ['Also deletes 2 games.', '3 posts lose a link.'], confirm: "This can't be undone.\nAlso deletes 2 games.\n3 posts lose a link.", addresses: 0 } });
    const { f, del } = deleteForm(w);

    assert.equal(submitBy(w, f, del).defaultPrevented, true);
    await settle();
    assert.deepEqual(log.fetched, ['/folio/news/_delete-plan']);
    assert.equal(log.bodies[0], '_csrf_token=tok&ids%5B%5D=n1');
    assert.equal(part(w, 'title').textContent, 'Delete this record?');
    assert.equal(part(w, 'message').textContent, "This can't be undone.");
    assert.deepEqual(part(w, 'items').querySelectorAll('li').map((li) => li.textContent), ['Also deletes 2 games.', '3 posts lose a link.']);
    assert.ok(part(w, 'confirm').classList.contains('btn-danger'));
    assert.equal(w.document.activeElement, part(w, 'cancel'));

    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(f.sent, [del], 'the Delete button again, through every submit handler');
    assert.equal(log.fetched.length, 1, 'the confirmed submit does not ask for the plan again');
});

test('delete plan: a restricted plan only tells, with the records that still point here', async () => {
    const { w } = admin({ plan: { restricted: true, lines: ["Can't delete: 2 jobs and 1 page still point here."], confirm: "Can't delete: 2 jobs and 1 page still point here.", addresses: 0, used_by: ['2 jobs', '1 page'] } });
    const { f, del } = deleteForm(w);
    submitBy(w, f, del);
    await settle();
    assert.equal(part(w, 'cancel').hidden, true, 'an alert: nothing to choose');
    assert.equal(part(w, 'message').textContent, "Can't delete: 2 jobs and 1 page still point here.");
    assert.deepEqual(part(w, 'items').querySelectorAll('li').map((li) => li.textContent), ['2 jobs', '1 page']);
    part(w, 'confirm').click();
    await settle();
    assert.equal(f.sent, undefined, 'nothing is sent');
});

test('delete plan: when it cannot be fetched, the form\'s own question is asked', async () => {
    const { w } = admin({ plan: null });
    const { f, del } = deleteForm(w);
    submitBy(w, f, del);
    await settle();
    assert.equal(part(w, 'title').textContent, 'Delete this record?');
    assert.equal(part(w, 'items').hidden, true);
    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(f.sent, [del]);
});

// ---- htmx:confirm ----------------------------------------------------------------

function htmxConfirm(w, elt, question) {
    const calls = [];
    const event = new w.CustomEvent('htmx:confirm', { bubbles: true, cancelable: true, detail: { elt, question, issueRequest: (skip) => calls.push(skip) } });
    elt.dispatchEvent(event);
    return { event, calls };
}

test('an htmx element\'s data-confirm-message is the dialog\'s message too', async () => {
    const { w } = admin();
    const f = form(w, { 'hx-post': '/folio/x', 'data-confirm': 'Clear every cache?', 'data-confirm-message': '9.0 MB in 489 files.' });
    const { calls } = htmxConfirm(w, f, 'Clear every cache?');
    assert.equal(part(w, 'title').textContent, 'Clear every cache?');
    assert.equal(part(w, 'message').textContent, '9.0 MB in 489 files.');
    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(calls, [true]);
});

test('hx-confirm asks through the dialog: htmx:confirm is held and issueRequest(true) sent on confirm', async () => {
    const { w } = admin();
    const trash = w.document.body.appendChild(w.document.createElement('button'));
    trash.setAttribute('hx-post', '/folio/x/remove');
    trash.setAttribute('hx-confirm', 'Remove this row?');

    let { event, calls } = htmxConfirm(w, trash, 'Remove this row?');
    assert.equal(event.defaultPrevented, true, 'htmx waits (and asks no window.confirm)');
    assert.equal(part(w, 'title').textContent, 'Remove this row?');
    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(calls, [true]);

    ({ event, calls } = htmxConfirm(w, trash, 'Remove this row?'));
    part(w, 'cancel').click();
    await settle();
    assert.deepEqual(calls, [], 'cancelled: no request');

    // An htmx element with data-confirm (no hx-confirm) asks too; one with neither goes ahead untouched.
    const form2 = form(w, { 'hx-post': '/folio/x', 'data-confirm': 'Send it?' });
    ({ event, calls } = htmxConfirm(w, form2, null));
    assert.equal(event.defaultPrevented, true);
    assert.equal(part(w, 'title').textContent, 'Send it?');
    part(w, 'confirm').click();
    await settle();
    assert.deepEqual(calls, [true]);

    // A boosted link is a navigation: with a clean page it goes, unasked (href null: no "Save and leave").
    const nav = w.document.body.appendChild(w.document.createElement('div'));
    nav.setAttribute('hx-boost', 'true');
    const boosted = nav.appendChild(w.document.createElement('a'));
    boosted.setAttribute('href', '/folio/news');
    ({ event, calls } = htmxConfirm(w, boosted, null));
    assert.equal(event.defaultPrevented, false, 'nothing unsaved: htmx goes on');

    const autosave = form(w, { 'hx-post': '/folio/x/autosave' });
    ({ event, calls } = htmxConfirm(w, autosave, null));
    assert.equal(event.defaultPrevented, false, 'autosave and other requests without a question are not touched');
    assert.equal(theDialog(w).open, false);
});

test('an htmx form\'s submit (htmx prevented it) is not asked twice by the data-confirm handler', () => {
    const { w } = admin();
    const f = form(w, { 'hx-post': '/folio/x', 'data-confirm': 'Send it?' });
    f.addEventListener('submit', (e) => e.preventDefault()); // htmx's own submit handler
    submitBy(w, f, null);
    assert.ok(!theDialog(w) || theDialog(w).open === false, 'htmx:confirm asks instead');
});

test('the icon tile: ui-info, ui-warn for danger, or the asker\'s icon', async () => {
    const { w } = admin({ markup: true });
    const use = () => theDialog(w).querySelector('[data-folio-dialog-icon] use').getAttribute('href');
    let answer = w.folioConfirm({ title: 'Restore?' });
    assert.equal(use(), '/assets/icons.svg?v=1#ui-info');
    part(w, 'cancel').click();
    await answer;
    answer = w.folioConfirm({ title: 'Delete?', tone: 'danger' });
    assert.equal(use(), '/assets/icons.svg?v=1#ui-warn');
    assert.ok(theDialog(w).classList.contains('is-danger'));
    part(w, 'cancel').click();
    await answer;
    answer = w.folioConfirm({ title: 'Delete?', tone: 'danger', icon: 'ui-trash' });
    assert.equal(use(), '/assets/icons.svg?v=1#ui-trash');
    part(w, 'cancel').click();
    await answer;
    answer = w.folioConfirm({ title: 'X', icon: '"><script>' });
    assert.equal(use(), '/assets/icons.svg?v=1#ui-info', 'only a sprite id');
    part(w, 'cancel').click();
    await answer;
});
