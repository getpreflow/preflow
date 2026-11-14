// Leaving with unsaved changes (assets/admin.js): in-admin navigation asks through the styled dialog
// (Leave without saving / Stay / Save and leave) while a guarded form is dirty; an autosaving form
// saves its pending edits first and asks only when that save fails. The browser's own beforeunload
// question stays for tab close, reload and typed addresses, and is suppressed once for a leave the
// dialog allowed. Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

const settle = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

function el(w, parent, tag, attrs = {}, text = '') {
    const node = parent.appendChild(w.document.createElement(tag));
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'class') { node.className = v; } else if (k === 'type' || k === 'name' || k === 'value') { node[k] = v; } else { node.setAttribute(k, v); }
    });
    if (text) { node.textContent = text; }
    return node;
}

/**
 * A page with the sidebar (links), a guarded editor form (#rec, not autosaving) with a text field
 * and a Save button outside it (form="rec"), and a Log out form. save: whether there is a Save button.
 */
function guardedPage({ save = true, second = false } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    w.location.href = 'https://site.test/folio/news/n1/edit';
    w.confirm = () => { throw new Error('window.confirm called'); };
    w.alert = () => { throw new Error('window.alert called'); };
    const doc = w.document;
    const nav = el(w, doc.body, 'nav');
    const links = {
        list: el(w, nav, 'a', { href: '/folio/news' }, 'News'),
        hash: el(w, nav, 'a', { href: '#seo' }, 'SEO'),
        samePageHash: el(w, nav, 'a', { href: '/folio/news/n1/edit#seo' }, 'SEO'),
        blank: el(w, nav, 'a', { href: '/folio/news', target: '_blank' }, 'News'),
        download: el(w, nav, 'a', { href: '/folio/news/_export', download: '' }, 'Export'),
        other: el(w, nav, 'a', { href: 'https://elsewhere.test/' }, 'Elsewhere'),
        mail: el(w, nav, 'a', { href: 'mailto:ada@site.test' }, 'Mail'),
    };
    const topbar = el(w, doc.body, 'header');
    const saveButton = save ? el(w, topbar, 'button', { type: 'submit', form: 'rec', 'data-folio-save': '' }, 'Save') : null;
    const wrap = el(w, doc.body, 'div', { class: 'folio-form' });
    const form = el(w, wrap, 'form', { id: 'rec', method: 'post', action: '/folio/news/n1' });
    const title = el(w, form, 'input', { type: 'text', name: 'title' });
    // second: a side panel form of its own (guarded too), with a note field.
    const note = second ? el(w, el(w, el(w, doc.body, 'aside', { class: 'folio-side' }), 'form', { method: 'post', action: '/folio/news/n1/note' }), 'input', { type: 'text', name: 'note' }) : null;
    const logout = el(w, doc.body, 'form', { method: 'post', action: '/folio/logout' });
    const logoutButton = el(w, logout, 'button', { type: 'submit' }, 'Log out');
    load(w, ADMIN_JS);
    return { w, links, form, title, note, saveButton, logout, logoutButton };
}

function edit(w, input, value = 'Winter') {
    input.value = value;
    input.dispatchEvent(new w.Event('input', { bubbles: true }));
}

/** Whether leaving now would ask the browser's own question (a guard cancels beforeunload). */
function asksToLeave(w) {
    const e = { type: 'beforeunload', defaultPrevented: false, returnValue: undefined, preventDefault() { this.defaultPrevented = true; } };
    (w.listeners.beforeunload || []).forEach((fn) => fn(e));
    return e.defaultPrevented;
}

const theDialog = (w) => w.document.body.querySelector('dialog[data-folio-dialog]');
const part = (w, name) => theDialog(w).querySelector('[data-folio-dialog-' + name + ']');
const isOpen = (w) => !!theDialog(w) && theDialog(w).open === true;

function submitBy(w, f, submitter = null) {
    const event = new w.Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'submitter', { value: submitter });
    f.dispatchEvent(event);
    return event;
}

test('a clean form: links leave without a dialog', () => {
    const p = guardedPage();
    const click = p.links.list.click();
    assert.equal(click.defaultPrevented, false, 'the browser follows the link');
    assert.equal(isOpen(p.w), false);
});

test('a dirty form: a same-origin link click opens the dialog, Stay keeps everything', async () => {
    const p = guardedPage();
    edit(p.w, p.title);
    const click = p.links.list.click();
    assert.equal(click.defaultPrevented, true, 'held');
    assert.equal(isOpen(p.w), true);
    assert.equal(part(p.w, 'title').textContent, 'leave_title');
    assert.equal(part(p.w, 'confirm').textContent, 'leave_confirm', '"Leave without saving"');
    assert.ok(part(p.w, 'confirm').classList.contains('btn-danger'));
    assert.equal(part(p.w, 'cancel').textContent, 'leave_stay', '"Stay"');
    assert.equal(p.w.document.activeElement, part(p.w, 'cancel'), 'the focus starts on Stay');
    assert.equal(part(p.w, 'extra').hidden, false, '"Save and leave": the form has a Save button');
    assert.equal(part(p.w, 'extra').textContent, 'save_and_leave');

    part(p.w, 'cancel').click();
    await settle();
    assert.equal(p.w.location.href, 'https://site.test/folio/news/n1/edit', 'stayed');
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);
    assert.equal(asksToLeave(p.w), true, 'closing the tab still asks');
});

test('"Leave without saving" navigates, and the browser\'s own question is not asked as well', async () => {
    const p = guardedPage();
    edit(p.w, p.title);
    p.links.list.click();
    part(p.w, 'confirm').click();
    await settle();
    assert.equal(p.w.location.href, '/folio/news');
    assert.equal(asksToLeave(p.w), false, 'suppressed once, for this leave');
    assert.equal(asksToLeave(p.w), true, 'only once');
});

test('"Save and leave" saves with _folio_next and lets the save navigate', async () => {
    const p = guardedPage();
    edit(p.w, p.title);
    p.links.list.click();
    part(p.w, 'extra').click();
    await settle();
    assert.deepEqual(p.form.sent, [p.saveButton], 'the Save button submits the form');
    assert.equal(p.form.querySelector('input[name="_folio_next"]').value, '/folio/news', 'the server goes there once the save succeeds');
    assert.equal(p.w.location.href, 'https://site.test/folio/news/n1/edit', 'the save itself navigates, not the script');
    assert.equal(asksToLeave(p.w), false, 'the save\'s own navigation is not asked about');
});

test('"Save and leave" whose save the browser does not send leaves no _folio_next behind', async () => {
    const p = guardedPage();
    edit(p.w, p.title);
    p.form.addEventListener('submit', (e) => e.preventDefault()); // e.g. a script refusing it
    p.links.list.click();
    part(p.w, 'extra').click();
    await settle();
    assert.equal(p.form.querySelector('input[name="_folio_next"]'), null);
    assert.equal(asksToLeave(p.w), true, 'the guard is on again');
});

test('no Save button: Leave and Stay only', () => {
    const p = guardedPage({ save: false });
    edit(p.w, p.title);
    p.links.list.click();
    assert.equal(part(p.w, 'extra').hidden, true);
});

test('links that do not leave, or leave elsewhere, are never held', () => {
    const p = guardedPage();
    edit(p.w, p.title);
    for (const name of ['hash', 'samePageHash', 'blank', 'download', 'other', 'mail']) {
        assert.equal(p.links[name].click().defaultPrevented, false, name);
        assert.equal(isOpen(p.w), false, name);
    }
    for (const mod of [{ metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        assert.equal(p.links.list.click(mod).defaultPrevented, false, JSON.stringify(mod));
    }
    // A link a script handles (a drawer, htmx) prevented its default already.
    p.links.list.addEventListener('click', (e) => e.preventDefault());
    p.links.list.click();
    assert.equal(isOpen(p.w), false);
});

test('a plain form that leaves (Log out) asks too; Leave sends it again', async () => {
    const p = guardedPage();
    edit(p.w, p.title);
    const first = submitBy(p.w, p.logout, p.logoutButton);
    assert.equal(first.defaultPrevented, true);
    assert.equal(isOpen(p.w), true);
    assert.equal(part(p.w, 'extra').hidden, true, 'no "Save and leave" for a submit');
    part(p.w, 'confirm').click();
    await settle();
    assert.deepEqual(p.logout.sent, [p.logoutButton]);
    assert.equal(asksToLeave(p.w), false);
});

test('the dirty form\'s own save is no leave', () => {
    const p = guardedPage();
    edit(p.w, p.title);
    assert.equal(submitBy(p.w, p.form, p.saveButton).defaultPrevented, false);
    assert.equal(isOpen(p.w), false);
});

test('a confirmed data-confirm submit (Delete) is not asked about again', async () => {
    const p = guardedPage();
    const del = el(p.w, p.w.document.body, 'form', { method: 'post', action: '/folio/news/n1/discard', 'data-confirm': 'Discard the draft?' });
    const discard = el(p.w, del, 'button', { type: 'submit', class: 'btn btn-danger' }, 'Discard');
    edit(p.w, p.title);
    submitBy(p.w, del, discard);
    assert.equal(part(p.w, 'title').textContent, 'Discard the draft?');
    part(p.w, 'confirm').click();
    await settle();
    assert.deepEqual(del.sent, [discard], 'one dialog, then it goes');
    assert.equal(isOpen(p.w), false);
    assert.equal(asksToLeave(p.w), false);
});

test('Folio.navigate (a shortcut opening a row) goes through the guard', async () => {
    const p = guardedPage();
    p.w.Folio.navigate('/folio/news/n2/edit');
    assert.equal(p.w.location.href, '/folio/news/n2/edit', 'clean: straight there');
    p.w.location.href = 'https://site.test/folio/news/n1/edit';
    edit(p.w, p.title);
    p.w.Folio.navigate('/folio/news/n2/edit');
    assert.equal(isOpen(p.w), true);
    part(p.w, 'confirm').click();
    await settle();
    assert.equal(p.w.location.href, '/folio/news/n2/edit');
});

// ---- The autosaving record form ------------------------------------------------------

/** The editor: #folio-record autosaves (hx-post) into #folio-save-state. */
function autosavePage() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    w.location.href = 'https://site.test/folio/post/p1/edit';
    w.confirm = () => { throw new Error('window.confirm called'); };
    const doc = w.document;
    const link = el(w, doc.body, 'a', { href: '/folio/post' }, 'Posts');
    const state = el(w, doc.body, 'span', { id: 'folio-save-state', class: 'folio-save-state', 'data-text-unsaved': 'Unsaved changes', 'data-text-saving': 'Saving…' });
    el(w, state, 'span', { 'data-folio-save-text': '' }, 'Draft saved 19:37');
    const wrap = el(w, doc.body, 'div', { class: 'folio-form' });
    const form = el(w, wrap, 'form', { id: 'folio-record', method: 'post', action: '/folio/post/p1', 'hx-post': '/folio/post/p1/autosave', 'hx-target': '#folio-save-state' });
    const title = el(w, form, 'input', { type: 'text', name: 'title' });
    const flushes = [];
    form.addEventListener('folio:save-now', () => flushes.push('save-now'));
    load(w, ADMIN_JS);
    return { w, link, state, form, title, flushes };
}

function setState(p, cls, text) {
    p.state.className = 'folio-save-state' + (cls ? ' ' + cls : '');
    p.state.querySelector('[data-folio-save-text]').textContent = text;
}

/** htmx's end of the autosave request: the swap (newState) happened, then afterRequest. */
function answer(p, { successful = true, swap = null, type = 'htmx:afterRequest' } = {}) {
    if (swap) { setState(p, swap.cls, swap.text); }
    p.form.dispatchEvent(new p.w.CustomEvent(type, { bubbles: true, detail: { elt: p.form, successful } }));
}

test('autosave pending: a link saves now, then leaves, without a dialog', async () => {
    const p = autosavePage();
    setState(p, 'is-unsaved', 'Unsaved changes');
    const click = p.link.click();
    assert.equal(click.defaultPrevented, true, 'held while it saves');
    assert.deepEqual(p.flushes, ['save-now'], 'saved at once, not after the 3 s delay');
    assert.equal(isOpen(p.w), false, 'nothing to answer');
    answer(p, { swap: { cls: '', text: 'Draft saved 19:38' } });
    await settle();
    assert.equal(p.w.location.href, '/folio/post');
});

test('autosave pending: the queued delayed autosave is dropped while leaving', () => {
    const p = autosavePage();
    setState(p, 'is-unsaved', 'Unsaved changes');
    p.link.click();
    const before = (trigger) => {
        const e = new p.w.CustomEvent('htmx:beforeRequest', { bubbles: true, cancelable: true, detail: { elt: p.form, requestConfig: { triggeringEvent: { type: trigger } } } });
        p.form.dispatchEvent(e);
        return e.defaultPrevented;
    };
    assert.equal(before('folio:save-now'), false, 'the flush goes');
    assert.equal(before('folio:edit'), true, 'the delayed one would race it');
});

test('autosave running: its answer is waited for; edits made meanwhile are saved in another round', async () => {
    const p = autosavePage();
    setState(p, 'is-saving', 'Saving…');
    // The running request started, then the person typed once more.
    p.form.dispatchEvent(new p.w.CustomEvent('htmx:beforeRequest', { bubbles: true, cancelable: true, detail: { elt: p.form, requestConfig: { triggeringEvent: { type: 'folio:edit' } } } }));
    edit(p.w, p.title);
    p.link.click();
    assert.deepEqual(p.flushes, [], 'no second request while one runs');
    answer(p, { swap: { cls: '', text: 'Draft saved 19:38' } });
    assert.deepEqual(p.flushes, ['save-now'], 'the later edit is saved too');
    assert.equal(p.w.location.href, 'https://site.test/folio/post/p1/edit', 'not yet');
    p.form.dispatchEvent(new p.w.CustomEvent('htmx:beforeRequest', { bubbles: true, cancelable: true, detail: { elt: p.form, requestConfig: { triggeringEvent: { type: 'folio:save-now' } } } }));
    answer(p, { swap: { cls: '', text: 'Draft saved 19:39' } });
    await settle();
    assert.equal(p.w.location.href, '/folio/post');
});

test('autosave refused (a conflict): the dialog says why; Leave goes, Stay stays', async () => {
    const p = autosavePage();
    setState(p, 'is-unsaved', 'Unsaved changes');
    p.link.click();
    answer(p, { swap: { cls: 'is-conflict', text: 'Not saved — someone else saved this meanwhile.' } });
    await settle();
    assert.equal(isOpen(p.w), true);
    assert.equal(part(p.w, 'message').textContent, 'Not saved — someone else saved this meanwhile.');
    assert.equal(part(p.w, 'confirm').textContent, 'leave_confirm');
    assert.equal(part(p.w, 'extra').hidden, true);
    part(p.w, 'cancel').click();
    await settle();
    assert.equal(p.w.location.href, 'https://site.test/folio/post/p1/edit', 'stayed');
    // The delayed autosave works again after "Stay".
    const e = new p.w.CustomEvent('htmx:beforeRequest', { bubbles: true, cancelable: true, detail: { elt: p.form, requestConfig: { triggeringEvent: { type: 'folio:edit' } } } });
    p.form.dispatchEvent(e);
    assert.equal(e.defaultPrevented, false);

    p.link.click();
    answer(p, { successful: false, type: 'htmx:sendError' });
    await settle();
    assert.equal(isOpen(p.w), true, 'offline: asks');
    part(p.w, 'confirm').click();
    await settle();
    assert.equal(p.w.location.href, '/folio/post');
});

test('autosave saved: links simply leave', () => {
    const p = autosavePage();
    assert.equal(p.link.click().defaultPrevented, false);
    assert.deepEqual(p.flushes, []);
});

test('two dirty forms: no "Save and leave" (it would save only one)', () => {
    const p = guardedPage({ second: true });
    edit(p.w, p.title);
    edit(p.w, p.note);
    assert.equal(p.w.Folio.unsaved.dirtyForms().length, 2);
    p.links.list.click();
    assert.equal(isOpen(p.w), true);
    assert.equal(part(p.w, 'extra').hidden, true);
    part(p.w, 'cancel').click();
});

test('a confirmed action in the record editor (Unpublish) saves the pending edit first', async () => {
    const p = autosavePage();
    const off = el(p.w, p.w.document.body, 'form', { method: 'post', action: '/folio/post/p1/unpublish', 'data-confirm': 'Take this page off the site?' });
    const button = el(p.w, off, 'button', { type: 'submit' }, 'Unpublish');
    setState(p, 'is-unsaved', 'Unsaved changes');
    submitBy(p.w, off, button);
    part(p.w, 'confirm').click();
    await settle();
    assert.deepEqual(p.flushes, ['save-now'], 'the edit is saved first');
    assert.equal(off.sent, undefined, 'the action waits for it');
    answer(p, { swap: { cls: '', text: 'Draft saved 19:38' } });
    await settle();
    assert.deepEqual(off.sent, [button], 'then Unpublish is sent');
});

test('a confirmed action whose pending save fails asks, and Stay sends nothing', async () => {
    const p = autosavePage();
    const off = el(p.w, p.w.document.body, 'form', { method: 'post', action: '/folio/post/p1/unpublish', 'data-confirm': 'Take this page off the site?' });
    const button = el(p.w, off, 'button', { type: 'submit' }, 'Unpublish');
    setState(p, 'is-unsaved', 'Unsaved changes');
    submitBy(p.w, off, button);
    part(p.w, 'confirm').click();
    await settle();
    answer(p, { swap: { cls: 'is-conflict', text: 'Not saved — someone else saved this meanwhile.' } });
    await settle();
    assert.equal(part(p.w, 'message').textContent, 'Not saved — someone else saved this meanwhile.');
    part(p.w, 'cancel').click();
    await settle();
    assert.equal(off.sent, undefined);
});
