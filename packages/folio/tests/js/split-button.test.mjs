// The Publish split button (assets/admin.js, partials/_publish_button.twig): Publish, and a chevron
// (<summary>, a <details> that works without script) opening "Publish at…". With script: aria-expanded,
// the focus moves to the date on open, Escape and a click elsewhere close it (Escape returns the focus
// to the chevron), and a date set turns Publish into "Schedule" with a relative hint under the date.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const EDITOR_JS = fileURLToPath(new URL('../../assets/editor-frame.js', import.meta.url));
const FIELDS_JS = fileURLToPath(new URL('../../assets/fields.js', import.meta.url));

function page({ date = '' } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const outside = el(doc.body, 'p');
    const group = el(doc.body, 'div', { 'data-folio-split-button': '' }, 'folio-split-button');
    const main = el(group, 'button', { type: 'submit', form: 'folio-record', name: '_action', value: date ? 'schedule' : 'publish', 'data-folio-publish': '', 'data-busy-label': date ? 'Saving…' : 'Publishing…', 'data-label-publish': 'Publish', 'data-label-schedule': 'Schedule', 'data-busy-publish': 'Publishing…', 'data-busy-schedule': 'Saving…' });
    main.textContent = date ? 'Schedule' : 'Publish';
    const menu = el(group, 'details', { 'data-folio-publish-menu': '' });
    menu.open = false;
    const summary = el(menu, 'summary', { 'aria-haspopup': 'dialog' });
    const panel = el(menu, 'div', { role: 'dialog' }, 'folio-split-panel');
    const box = el(panel, 'div', {}, 'folio-date-input');
    const input = el(box, 'input', { type: 'datetime-local', name: '_publish_at', form: 'folio-record', 'data-folio-publish-at': '' });
    input.type = 'datetime-local';
    input.value = date;
    const clear = el(box, 'button', { type: 'button', 'data-folio-date-clear': '' });
    const hint = el(panel, 'p', { 'data-folio-date-hint': '' });
    const at = el(panel, 'button', { type: 'submit', form: 'folio-record', name: '_action', value: 'schedule', 'data-folio-schedule': '', 'data-busy-label': 'Saving…' });
    at.disabled = false;
    const form = el(doc.body, 'form', { id: 'folio-record' });
    load(w, ADMIN_JS);
    load(w, FIELDS_JS); // the date's clear button and Folio.relativeTime()
    load(w, EDITOR_JS);
    return { w, doc, outside, group, main, menu, summary, input, clear, hint, at, form };
}

/** The browser opens or closes the <details> (a click on the summary) and sends "toggle". */
function toggle(p, open) {
    p.menu.open = open;
    p.menu.dispatchEvent(new p.w.Event('toggle'));
}

function key(p, k, from) {
    const event = new p.w.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'key', { value: k });
    (from || p.doc.activeElement).dispatchEvent(event);
    return event;
}

function fire(target, name) {
    target.dispatchEvent(new Event(name, { bubbles: true, cancelable: true }));
}

const localValue = (ms) => {
    const d = new Date(Date.now() + ms);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

test('closed at first: the chevron says so (aria-expanded false)', () => {
    const p = page();
    assert.equal(p.summary.getAttribute('aria-expanded'), 'false');
    assert.ok(p.group.hasAttribute('data-folio-split-ready'));
});

test('opening it (the chevron) sets aria-expanded and moves the focus to the date', () => {
    const p = page();
    toggle(p, true);
    assert.equal(p.summary.getAttribute('aria-expanded'), 'true');
    assert.equal(p.doc.activeElement, p.input);
    toggle(p, false);
    assert.equal(p.summary.getAttribute('aria-expanded'), 'false');
});

test('Escape closes it and returns the focus to the chevron', () => {
    const p = page();
    toggle(p, true);
    const e = key(p, 'Escape', p.input);
    assert.equal(e.defaultPrevented, true);
    assert.equal(p.menu.open, false);
    assert.equal(p.summary.getAttribute('aria-expanded'), 'false');
    assert.equal(p.doc.activeElement, p.summary);
});

test('Escape while closed is left alone', () => {
    const p = page();
    p.summary.focus();
    assert.equal(key(p, 'Escape').defaultPrevented, false);
});

test('a click elsewhere closes it; a click inside does not', () => {
    const p = page();
    toggle(p, true);
    p.input.click();
    assert.equal(p.menu.open, true);
    p.outside.click();
    assert.equal(p.menu.open, false);
    assert.equal(p.summary.getAttribute('aria-expanded'), 'false');
});

test('a date turns Publish into "Schedule" (a save that keeps the date) with a relative hint; clearing it turns it back', () => {
    const p = page();
    assert.equal(p.main.textContent, 'Publish');
    assert.equal(p.at.disabled, true, '"Publish at this time" waits for a date');
    // Noon two calendar days ahead: "In 2 days" at any time of day (Folio.relativeTime counts calendar days from a day on).
    const d = new Date();
    d.setDate(d.getDate() + 2);
    p.input.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T12:00`;
    fire(p.input, 'input');
    assert.equal(p.main.textContent, 'Schedule');
    assert.equal(p.main.getAttribute('value'), 'schedule', '_action=publish would publish now and drop the date');
    assert.equal(p.main.getAttribute('data-busy-label'), 'Saving…');
    assert.equal(p.at.disabled, false);
    assert.match(p.main.getAttribute('title'), /^Schedule \((⌘|Ctrl\+)↵\)$/);
    assert.match(p.hint.textContent, /2 days/);

    const seen = [];
    p.doc.addEventListener('input', () => seen.push('input'));
    p.doc.addEventListener('change', () => seen.push('change'));
    p.clear.click();
    assert.equal(p.input.value, '');
    assert.deepEqual(seen, ['input', 'change'], 'the guard, autosave and the save state see the cleared date');
    assert.equal(p.main.textContent, 'Publish');
    assert.equal(p.main.getAttribute('value'), 'publish');
    assert.equal(p.main.getAttribute('data-busy-label'), 'Publishing…');
    assert.equal(p.at.disabled, true);
    assert.equal(p.hint.textContent, '');
});

test('a stored date shows "Schedule" and its hint from the start', () => {
    const p = page({ date: localValue(3 * 3600000 + 120000) });
    assert.equal(p.main.textContent, 'Schedule');
    assert.match(p.hint.textContent, /3 hours/);
});

test('the busy Publish keeps its busy label while the date changes', async () => {
    const p = page();
    p.form.requestSubmit(p.main);
    await new Promise((resolve) => setTimeout(resolve, 0));
    p.input.value = localValue(86400000 * 5);
    fire(p.input, 'input');
    assert.equal(p.main.textContent, 'Publishing…');
    assert.equal(p.main.getAttribute('value'), 'publish', 'the running request keeps what it sent');
});
