// The editor frame (assets/admin.js, form.twig 0.75): Save draft and Publish sit in the topbar, outside
// the record form, and the side panel holds record controls outside it too; all name it with
// form="folio-record". The unsaved guard, the save state, the busy buttons, "needs change" and the editor
// shortcuts (⌘S / Ctrl+S, ⌘↵ / Ctrl+Enter, Esc) must work across that gap.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/**
 * An editor page: topbar (save state, Save draft, Publish, the side panel toggle), the record form in
 * .folio-form (a title input; hx-post when it autosaves) and the side panel (a slug input and a
 * visible-in box naming the form, a close button, an extension panel form with a "needs change" submit).
 */
function editor({ autosave = false, publish = true } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const i18n = el(doc.body, 'script', { type: 'application/json', id: 'folio-i18n' });
    i18n.textContent = JSON.stringify({ shortcuts: 'Keyboard shortcuts', shortcut_help: 'Show these shortcuts', shortcut_save: 'Save the draft', shortcut_publish: 'Publish', shortcut_close_side: 'Close the side panel' });
    const topbar = el(doc.body, 'header', {}, 'folio-topbar');
    const state = el(topbar, 'span', { id: 'folio-save-state', 'data-text-unsaved': 'Unsaved changes', 'data-text-saving': 'Saving…' }, 'folio-save-state');
    const stateText = el(state, 'span', { 'data-folio-save-text': '' }, 'folio-save-state-text');
    stateText.textContent = 'No changes';
    const toggle = el(topbar, 'button', { type: 'button', 'data-folio-side-toggle': '', 'aria-controls': 'folio-side', 'aria-expanded': 'false' });
    toggle.hidden = true;
    const button = (attrs, text) => {
        const b = el(topbar, 'button', { type: 'submit', form: 'folio-record', ...attrs }, 'btn');
        b.type = 'submit';
        b.disabled = false;
        b.textContent = text;
        return b;
    };
    const save = button({ 'data-folio-save': '', 'data-busy-label': 'Saving…' }, 'Save draft');
    const pub = publish ? button({ name: '_action', value: 'publish', 'data-folio-publish': '', 'data-busy-label': 'Publishing…' }, 'Publish') : null;
    if (pub) { pub.name = '_action'; pub.value = 'publish'; }
    const wrap = el(doc.body, 'div', {}, 'folio-form folio-editor');
    const form = el(wrap, 'form', autosave ? { id: 'folio-record', 'hx-post': '/folio/news/n1/autosave', 'hx-target': '#folio-save-state' } : { id: 'folio-record' });
    const title = el(form, 'input', { type: 'text', name: 'title' });
    title.type = 'text';
    const side = el(doc.body, 'aside', { id: 'folio-side', 'data-folio-side': '' }, 'folio-side');
    const close = el(side, 'button', { type: 'button', 'data-folio-side-close': '' });
    const slug = el(side, 'input', { type: 'text', name: 'slug', form: 'folio-record' });
    slug.type = 'text';
    const visible = el(side, 'input', { type: 'checkbox', name: '_locales[]', value: 'de', form: 'folio-record' });
    visible.type = 'checkbox';
    const panelForm = el(side, 'form', { method: 'post', action: '/folio/news/n1/_action/transfer' });
    const owner = el(panelForm, 'select', { name: 'owner' });
    const transfer = el(panelForm, 'button', { type: 'submit', 'data-folio-needs-change': '' });
    transfer.type = 'submit';
    transfer.disabled = false;
    load(w, ADMIN_JS);
    return { w, doc, state, stateText, toggle, save, pub, form, title, side, close, slug, visible, panelForm, owner, transfer };
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function fire(target, name) {
    const e = new Event(name, { bubbles: true, cancelable: true });
    target.dispatchEvent(e);
    return e;
}

/** Whether leaving the page now would ask (the guard cancels beforeunload). */
function asksToLeave(w) {
    const e = { defaultPrevented: false, returnValue: undefined, preventDefault() { this.defaultPrevented = true; } };
    (w.listeners.beforeunload || []).forEach((fn) => fn(e));
    return e.defaultPrevented;
}

/** A key press from an element (default: what has the focus). */
function press(p, key, { from = null, ctrl = false, meta = false } = {}) {
    const target = from || p.doc.activeElement || p.doc.body;
    const event = new p.w.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'key', { value: key });
    Object.defineProperty(event, 'ctrlKey', { value: ctrl });
    Object.defineProperty(event, 'altKey', { value: false });
    Object.defineProperty(event, 'metaKey', { value: meta });
    Object.defineProperty(event, 'shiftKey', { value: false });
    target.dispatchEvent(event);
    return event;
}

function htmx(elt, name, detail = {}) {
    const e = new CustomEvent(name, { bubbles: true, cancelable: true, detail: { elt, ...detail } });
    elt.dispatchEvent(e);
    return e;
}

test('the guard marks the record form when a side-panel control (form="…") changes, and a submit clears it', () => {
    const p = editor();
    assert.equal(asksToLeave(p.w), false, 'nothing edited yet');

    fire(p.slug, 'input');
    assert.equal(asksToLeave(p.w), true, 'an edit outside the form counts for the form');
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);
    assert.deepEqual([...p.w.Folio.unsaved.dirtyForms()], [p.form]);

    p.form.requestSubmit(p.save);
    assert.equal(asksToLeave(p.w), false, 'the save that goes ahead clears it before the page unloads');
});

test('the guard: a change of the visible-in box counts; a cancelled submit keeps the form dirty', () => {
    const p = editor();
    fire(p.visible, 'change');
    p.form.addEventListener('submit', (e) => e.preventDefault()); // a confirm answered "Cancel"
    p.form.requestSubmit(p.save);
    assert.equal(asksToLeave(p.w), true);
    p.w.Folio.unsaved.clear(p.form);
    assert.equal(asksToLeave(p.w), false, 'Folio.unsaved.clear() for a page that saved in another way');
});

test('the guard: a leave check (Folio.unsaved.onLeave, the styled dialog later) can let the page go', () => {
    const p = editor();
    fire(p.title, 'input');
    p.w.Folio.unsaved.onLeave(() => true);
    assert.equal(asksToLeave(p.w), false);
});

test('an autosaving form is not guarded; its first edit says "Unsaved changes", autosave says "Saving…"', () => {
    const p = editor({ autosave: true });
    fire(p.visible, 'change');
    assert.equal(asksToLeave(p.w), false, 'autosave keeps the work');
    assert.ok(p.state.classList.contains('is-unsaved'));
    assert.equal(p.stateText.textContent, 'Unsaved changes', 'the side panel edit shows in the topbar');

    htmx(p.form, 'htmx:beforeRequest', { requestConfig: { triggeringEvent: { type: 'change' } } });
    assert.ok(p.state.classList.contains('is-saving'));
    assert.ok(!p.state.classList.contains('is-unsaved'));
    assert.equal(p.stateText.textContent, 'Saving…');

    fire(p.title, 'input');
    assert.equal(p.stateText.textContent, 'Saving…', 'an edit while it saves keeps "Saving…"; the answer replaces the element');
});

test('a failed autosave says so in the save state (and keeps its caret element)', () => {
    const p = editor({ autosave: true });
    htmx(p.form, 'htmx:responseError', { xhr: { status: 500 } });
    assert.ok(p.state.classList.contains('is-conflict'));
    assert.match(p.stateText.textContent, /not_saved_offline|Not saved/);
    assert.equal(p.state.querySelector('[data-folio-save-text]'), p.stateText);
});

test('busy: the topbar Publish an htmx request belongs to is released when the request fails', () => {
    const p = editor();
    const requestConfig = { triggeringEvent: { submitter: p.pub } };
    const xhr = {};
    htmx(p.form, 'htmx:beforeRequest', { requestConfig, xhr });
    assert.ok(p.pub.classList.contains('is-busy'));
    assert.equal(p.pub.disabled, true);
    htmx(p.form, 'htmx:responseError', { requestConfig, xhr });
    assert.ok(!p.pub.classList.contains('is-busy'));
    assert.equal(p.pub.disabled, false);
    assert.equal(p.pub.textContent, 'Publish');
});

test('busy: a plain submit through the topbar button makes that button busy (e.submitter)', async () => {
    const p = editor();
    p.form.requestSubmit(p.pub);
    await tick();
    assert.ok(p.pub.classList.contains('is-busy'));
    assert.equal(p.pub.textContent, 'Publishing…');
    assert.ok(!p.save.classList.contains('is-busy'));
});

test('⌘S inside a text input still saves, with Save draft as the submitter, and no browser dialog', async () => {
    const p = editor();
    p.title.focus();
    const e = press(p, 's', { meta: true });
    assert.equal(e.defaultPrevented, true, 'the browser\'s own save dialog stays shut');
    assert.deepEqual(p.form.sent, [p.save], 'requestSubmit(Save draft): its busy state, the guard and the form\'s CSRF token apply');
    await tick();
    assert.ok(p.save.classList.contains('is-busy'));
});

test('Ctrl+S saves too; while Save is busy a second ⌘S sends nothing', async () => {
    const p = editor();
    press(p, 's', { ctrl: true, from: p.slug });
    await tick();
    const again = press(p, 's', { meta: true, from: p.slug });
    assert.equal(again.defaultPrevented, true);
    assert.deepEqual(p.form.sent, [p.save], 'one save only');
});

test('⌘↵ publishes through the Publish button; not while it is disabled', () => {
    const p = editor();
    p.pub.disabled = true;
    const blocked = press(p, 'Enter', { meta: true, from: p.title });
    assert.equal(blocked.defaultPrevented, false, 'nothing done: the key keeps its own meaning');
    assert.equal(p.form.sent, undefined);

    p.pub.disabled = false;
    const e = press(p, 'Enter', { ctrl: true, from: p.title });
    assert.equal(e.defaultPrevented, true);
    assert.deepEqual(p.form.sent, [p.pub], 'the submitter sends _action=publish');
});

test('without Publish (no publish right) ⌘↵ is no shortcut at all', () => {
    const p = editor({ publish: false });
    const e = press(p, 'Enter', { meta: true, from: p.title });
    assert.equal(e.defaultPrevented, false);
    assert.equal(p.form.sent, undefined);
});

test('the side panel sheet: the toggle shows, opens it (focus on ×), Esc closes it and returns the focus', () => {
    const p = editor();
    assert.equal(p.toggle.hidden, false, 'shown once script runs (CSS shows it at phone width only)');
    assert.ok(p.side.hasAttribute('data-folio-side-ready'));

    p.toggle.click();
    assert.ok(p.side.classList.contains('is-open'));
    assert.equal(p.toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(p.doc.activeElement, p.close);

    p.slug.focus();
    const e = press(p, 'Escape');
    assert.equal(e.defaultPrevented, true, 'Esc works from a field inside the sheet too');
    assert.ok(!p.side.classList.contains('is-open'));
    assert.equal(p.toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(p.doc.activeElement, p.toggle);

    const idle = press(p, 'Escape');
    assert.equal(idle.defaultPrevented, false, 'closed: Esc keeps its own meaning');
});

test('the × closes the sheet', () => {
    const p = editor();
    p.toggle.click();
    p.close.click();
    assert.ok(!p.side.classList.contains('is-open'));
    assert.equal(p.doc.activeElement, p.toggle);
});

test('"needs change": a submit stays disabled until a control of its own form changes', () => {
    const p = editor();
    assert.equal(p.transfer.disabled, true, 'disabled once script runs (without it, it works as a plain submit)');
    fire(p.slug, 'input');
    assert.equal(p.transfer.disabled, true, 'an edit of the record form leaves the panel\'s button alone');
    fire(p.owner, 'change');
    assert.equal(p.transfer.disabled, false);
});

test('"needs change": a reset of its form disables it again', async () => {
    const p = editor();
    fire(p.owner, 'change');
    fire(p.panelForm, 'reset');
    await tick();
    assert.equal(p.transfer.disabled, true);
});

test('the "?" list names the editor shortcuts with their hints', () => {
    const p = editor();
    press(p, '?');
    const dialog = p.doc.querySelector('dialog.folio-shortcuts');
    const text = dialog.textContent;
    assert.match(text, /⌘SSave the draft/);
    assert.match(text, /⌘↵Publish/);
    assert.match(text, /EscClose the side panel/);
});
