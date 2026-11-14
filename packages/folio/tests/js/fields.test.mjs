// The field controls (assets/fields.js, board LibFields, 0.75): the relative date hint, the date's clear
// button, the address copy and a URL's open button, the text counter, the number stepper, the relation
// rows and their search, moving rows by keyboard, and a file dropped on an asset field.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const FIELDS_JS = fileURLToPath(new URL('../../assets/fields.js', import.meta.url));
const ID = 'a'.repeat(32);

/** A page with fields.js; Folio.copyLater and folioT stubbed (admin.js's), the admin language English. */
function page(build, { folio = {} } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    doc.documentElement.setAttribute('lang', 'en');
    const copied = [];
    w.Folio = Object.assign({ copyLater: (p) => Promise.resolve(p).then((text) => { copied.push(text); return true; }) }, folio);
    w.folioT = (key, params = {}) => Object.entries(params).reduce((text, [k, v]) => text.split(':' + k).join(String(v)), {
        'fields.move_named': 'Move :label', 'fields.open_named': 'Open :label', 'fields.remove_named': 'Remove :label',
        position: ':index of :total', at_position: ':label, position :position.',
    }[key] ?? '');
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        if (attrs.type) { e.type = attrs.type; }
        if (attrs.name) { e.name = attrs.name; }
        if (attrs.value !== undefined) { e.value = attrs.value; }
        return e;
    };
    const form = el(doc.body, 'form', { id: 'folio-record' });
    const parts = build({ w, doc, el, form }) || {};
    load(w, FIELDS_JS);
    return { w, doc, el, form, copied, ...parts };
}

function type(w, input, value) {
    input.value = value;
    input.dispatchEvent(new w.Event('input', { bubbles: true }));
}

const tick = (ms = 5) => new Promise((resolve) => setTimeout(resolve, ms));

const pad = (n) => String(n).padStart(2, '0');
const localDate = (days) => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const localDateTime = (ms) => {
    const d = new Date(Date.now() + ms);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// ---- Relative time and the date field ----------------------------------------------------------

test('Folio.relativeTime says how far a date is, in the admin language, capitalised', () => {
    const { w } = page(() => {});
    assert.equal(w.Folio.relativeTime(localDate(2)), 'In 2 days');
    assert.equal(w.Folio.relativeTime(localDate(1)), 'Tomorrow');
    assert.equal(w.Folio.relativeTime(localDate(0)), 'Today');
    assert.equal(w.Folio.relativeTime(localDate(-3)), '3 days ago');
    assert.match(w.Folio.relativeTime(localDateTime(3 * 3600 * 1000 + 60000)), /^In 3 hours$/);
    // From a day on, calendar days: two days ahead at 00:01 is "In 2 days", however late it is now.
    assert.equal(w.Folio.relativeTime(`${localDate(2)}T00:01`), 'In 2 days');
    assert.equal(w.Folio.relativeTime(`${localDate(2)}T23:59`), 'In 2 days');
    assert.equal(w.Folio.relativeTime(`${localDate(14)}T12:00`), 'In 2 weeks');
    assert.equal(w.Folio.relativeTime('not a date'), '');
    assert.equal(w.Folio.relativeTime(''), '');
});

test('the date hint follows the date (with the zone of a date and time); clear empties it', () => {
    const p = page(({ el, form }) => {
        const box = el(form, 'div', { 'data-folio-date': '' }, 'folio-date-input');
        const input = el(box, 'input', { type: 'datetime-local', name: 'publish', 'aria-describedby': 'publish-hint', value: '' });
        const clear = el(box, 'button', { type: 'button', 'data-folio-date-clear': '' });
        clear.hidden = true;
        const hint = el(form, 'p', { id: 'publish-hint', 'data-folio-date-hint': '', 'data-zone': 'Europe/Zurich' }, 'folio-date-hint');
        return { input, clear, hint };
    });
    assert.equal(p.hint.textContent, 'Europe/Zurich', 'empty: the zone only');

    type(p.w, p.input, localDateTime(2 * 86400 * 1000 + 60000));
    assert.equal(p.hint.textContent, 'In 2 days · Europe/Zurich');
    assert.equal(p.clear.hidden, false);

    const seen = [];
    p.form.addEventListener('input', () => seen.push('input'));
    p.form.addEventListener('change', () => seen.push('change'));
    p.clear.click();
    assert.equal(p.input.value, '');
    assert.deepEqual(seen, ['input', 'change'], 'autosave, the preview and the guard hear the cleared date');
    assert.equal(p.hint.textContent, 'Europe/Zurich');
    assert.equal(p.clear.hidden, true);
    assert.equal(p.doc.activeElement, p.input, 'the focus goes back to the date');
});

// ---- String: address copy, URL open ------------------------------------------------------------

function affix(extra = {}) {
    return page(({ el, form }) => {
        const box = el(form, 'div', { 'data-folio-affix': '' }, 'folio-input-affix');
        const input = el(box, 'input', { type: 'text', name: 'slug', value: 'jobs-in-bern' });
        const copy = el(box, 'button', Object.assign({ type: 'button', 'data-folio-copy-address': '', 'data-address-before': 'https://example.com/jobs/', 'data-address-after': '', 'data-text-copied': 'Copied' }, extra));
        return { input, copy };
    });
}

test('copy puts the whole address on the clipboard, within the click', async () => {
    const p = affix();
    p.copy.click();
    await tick();
    assert.deepEqual(p.copied, ['https://example.com/jobs/jobs-in-bern']);
    assert.ok(p.copy.classList.contains('is-copied'));
    await tick(40);
    assert.equal(p.doc.querySelector('[data-folio-fields-live]').textContent, 'Copied', 'said aloud');

    type(p.w, p.input, '  ');
    p.copy.click();
    await tick();
    assert.equal(p.copied.length, 1, 'nothing to copy without a value');
});

test('the home value copies as the site root', async () => {
    const p = affix({ 'data-address-home': 'home', 'data-address-root': 'https://example.com/' });
    type(p.w, p.input, 'home');
    p.copy.click();
    await tick();
    assert.deepEqual(p.copied, ['https://example.com/']);
});

test("a URL field's open button links only web addresses", () => {
    const p = page(({ el, form }) => {
        const box = el(form, 'div', { 'data-folio-affix': '' });
        const input = el(box, 'input', { type: 'text', name: 'website', value: '' });
        const link = el(box, 'a', { 'data-folio-open-url': '' });
        link.hidden = true;
        return { input, link };
    });
    type(p.w, p.input, 'https://example.com/about');
    assert.equal(p.link.getAttribute('href'), 'https://example.com/about');
    assert.equal(p.link.hidden, false);
    type(p.w, p.input, 'javascript:alert(1)');
    assert.equal(p.link.getAttribute('href'), null);
    assert.equal(p.link.hidden, true);
});

// ---- Text: counter ----------------------------------------------------------------------------

test('the counter counts characters and is announced only near the limit', () => {
    const p = page(({ el, form }) => {
        const counter = el(form, 'span', { id: 'summary-counter', 'data-folio-counter': '', 'data-max': '20', 'data-text': ':count / :max' }, 'folio-counter');
        const area = el(form, 'textarea', { name: 'summary', 'aria-describedby': 'summary-counter' });
        return { counter, area };
    });
    type(p.w, p.area, 'Grüezi');
    assert.equal(p.counter.textContent, '6 / 20');
    assert.equal(p.counter.getAttribute('aria-live'), null, 'far from the limit: silent');

    type(p.w, p.area, 'a\r\nb' + 'x'.repeat(15));
    assert.equal(p.counter.textContent, '18 / 20', 'a line break is one character');
    assert.equal(p.counter.getAttribute('aria-live'), 'polite');
    assert.ok(p.counter.classList.contains('is-near'));
    assert.ok(!p.counter.classList.contains('is-over'));

    type(p.w, p.area, 'x'.repeat(21));
    assert.ok(p.counter.classList.contains('is-over'));

    type(p.w, p.area, 'x');
    assert.equal(p.counter.getAttribute('aria-live'), null, 'back below: silent again');
});

// ---- Number: stepper --------------------------------------------------------------------------

function stepper({ value = '', step = null, min = null, max = null } = {}) {
    return page(({ el, form }) => {
        const attrs = { 'data-folio-stepper': '' };
        if (min !== null) { attrs['data-min'] = String(min); }
        if (max !== null) { attrs['data-max'] = String(max); }
        const box = el(form, 'div', attrs, 'folio-stepper');
        const down = el(box, 'button', { type: 'button', 'data-folio-step': '-1' });
        const input = el(box, 'input', Object.assign({ type: 'number', name: 'qty', value }, step !== null ? { step: String(step) } : {}));
        const up = el(box, 'button', { type: 'button', 'data-folio-step': '1' });
        return { input, down, up };
    });
}

test('the stepper steps by "step" and announces the edit', () => {
    const p = stepper({ value: '1', step: 0.5 });
    const seen = [];
    p.form.addEventListener('change', () => seen.push(p.input.value));
    p.up.click();
    assert.equal(p.input.value, '1.5');
    p.down.click();
    p.down.click();
    assert.equal(p.input.value, '0.5');
    assert.deepEqual(seen, ['1.5', '1', '0.5']);

    const tenth = stepper({ value: '0.1', step: 0.1 });
    tenth.up.click();
    tenth.up.click();
    assert.equal(tenth.input.value, '0.3', 'no 0.30000000000000004');

    const whole = stepper({ value: '3' });
    whole.up.click();
    assert.equal(whole.input.value, '4', 'without "step": whole numbers');
});

test('the stepper stays within min and max and disables the button at a bound', () => {
    const p = stepper({ value: '9', min: 0, max: 10, step: 2 });
    p.up.click();
    assert.equal(p.input.value, '10', 'clamped to max');
    assert.equal(p.up.disabled, true);
    p.up.click();
    assert.equal(p.input.value, '10');

    const low = stepper({ value: '0', min: 0, max: 10 });
    assert.equal(low.down.disabled, true, 'at min from the start');
    assert.equal(low.up.disabled, false);

    // A bound off the step grid: the nearest on-step value inside it (the server's step rule counts from 0).
    const grid = stepper({ value: '1', min: 0.5, max: 2.5, step: 1 });
    grid.down.click();
    assert.equal(grid.input.value, '1', 'not 0.5, which step 1 refuses');
    grid.up.click();
    grid.up.click();
    assert.equal(grid.input.value, '2', 'not 2.5');

    const empty = stepper({ value: '', min: 5 });
    empty.up.click();
    assert.equal(empty.input.value, '5', 'an empty value starts at min');
});

// ---- Relation ---------------------------------------------------------------------------------

function relation({ multiple = false, selected = [] } = {}) {
    return page(({ el, form }) => {
        const name = multiple ? 'writers[]' : 'writer';
        const attrs = { 'data-folio-relation': '', 'data-folio-relation-name': name, 'data-folio-relation-open': '/folio/author/{id}/edit' };
        if (multiple) { attrs['data-folio-relation-multiple'] = ''; }
        const field = el(form, 'fieldset', attrs, 'folio-relation');
        const rows = el(field, 'ul', { 'data-folio-relation-selected': '' });
        const row = (parent, id, label) => {
            const li = el(parent, 'li', { 'data-folio-relation-item': '' }, 'folio-relation-row');
            if (multiple) { el(li, 'button', { type: 'button', 'data-folio-sort-handle': '' }); }
            el(li, 'span', {}, 'folio-relation-avatar');
            const text = el(li, 'span', {}, 'folio-relation-text');
            el(text, 'span', {}, 'folio-relation-label').textContent = label;
            el(text, 'span', {}, 'folio-relation-meta').hidden = true;
            el(li, 'a', { 'data-folio-relation-open-item': '', href: '/folio/author/' + id + '/edit' });
            el(li, 'button', { type: 'button', 'data-folio-relation-remove': '' });
            el(li, 'input', { type: 'hidden', name, value: id });
            return li;
        };
        selected.forEach(([id, label]) => row(rows, id, label));
        if (!multiple && selected.length === 0) {
            const holder = el(rows, 'li');
            holder.hidden = true;
            el(holder, 'input', { type: 'hidden', name, value: '' });
        }
        const label = el(field, 'label', { 'data-folio-no-edit': '' });
        const find = el(label, 'input', { type: 'search', 'data-folio-relation-find': '' });
        const options = el(field, 'script', { type: 'application/json', 'data-folio-relation-options': '' });
        options.textContent = JSON.stringify([
            { id: 'a1', label: 'Ann Müller', meta: 'ann@example.org' },
            { id: 'a2', label: 'Bob', meta: null },
            { id: 'a3', label: 'Émile Zola', meta: 'writer' },
        ]);
        const results = el(field, 'ul', { 'data-folio-relation-results': '', 'data-text-more': 'Show more', 'data-text-empty': 'Nothing found.' });
        results.hidden = true;
        const template = el(field, 'template', { 'data-folio-relation-template': '' });
        row(template, '', '');
        return { field, rows, find, results };
    });
}

const ids = (p) => p.rows.querySelectorAll('[data-folio-relation-item] input').map((i) => i.value);

test('typing searches the options (accents and case ignored) and leaves linked ones out', async () => {
    const p = relation({ multiple: true, selected: [['a2', 'Bob']] });
    type(p.w, p.find, 'emile');
    await tick();
    assert.equal(p.results.hidden, false);
    assert.deepEqual(p.results.querySelectorAll('[data-folio-relation-pick]').map((b) => b.getAttribute('data-id')), ['a3']);

    type(p.w, p.find, '');
    await tick();
    assert.deepEqual(p.results.querySelectorAll('[data-folio-relation-pick]').map((b) => b.getAttribute('data-id')), ['a1', 'a3'], 'Bob is linked already');

    type(p.w, p.find, 'nobody');
    await tick();
    assert.equal(p.results.querySelector('.folio-relation-empty').textContent, 'Nothing found.');
});

test('without admin.js a text the template did not give is empty, never the raw key', async () => {
    const p = relation({ multiple: true });
    p.results.removeAttribute('data-text-empty');
    delete p.w.folioT;
    type(p.w, p.find, 'nobody');
    await tick();
    assert.equal(p.results.querySelector('.folio-relation-empty').textContent, '');
});

test('a pick adds a row from the template, with its name, meta and open link; Enter never submits', async () => {
    const p = relation({ multiple: true, selected: [['a2', 'Bob']] });
    const changes = [];
    p.form.addEventListener('change', (e) => changes.push(e.target.value));
    type(p.w, p.find, 'ann');
    await tick();
    const enter = p.find.keydown('Enter');
    assert.equal(enter.defaultPrevented, true, 'not the record form’s implicit submit');
    assert.deepEqual(ids(p), ['a2', 'a1']);
    const row = p.rows.querySelectorAll('[data-folio-relation-item]')[1];
    assert.equal(row.querySelector('input').name, 'writers[]');
    assert.equal(row.querySelector('.folio-relation-label').textContent, 'Ann Müller');
    assert.equal(row.querySelector('.folio-relation-meta').textContent, 'ann@example.org');
    assert.equal(row.querySelector('.folio-relation-meta').hidden, false);
    assert.equal(row.querySelector('.folio-relation-avatar').textContent, 'AM');
    assert.equal(row.querySelector('[data-folio-relation-open-item]').getAttribute('href'), '/folio/author/a1/edit');
    assert.equal(row.querySelector('[data-folio-relation-remove]').getAttribute('aria-label'), 'Remove Ann Müller');
    assert.deepEqual(changes, ['a1'], 'autosave and the guard hear it');
    assert.equal(p.find.value, '');
    assert.equal(p.results.hidden, true);
});

test('a single relation replaces its record; removing it still posts the empty name', async () => {
    const p = relation({ selected: [['a1', 'Ann Müller']] });
    type(p.w, p.find, 'bob');
    await tick();
    p.results.querySelector('[data-folio-relation-pick]').click();
    assert.deepEqual(ids(p), ['a2']);

    let changed = 0;
    p.form.addEventListener('change', () => { changed++; });
    p.rows.querySelector('[data-folio-relation-remove]').click();
    assert.deepEqual(ids(p), []);
    const blank = p.rows.querySelector('input');
    assert.equal(blank.name, 'writer');
    assert.equal(blank.value, '');
    assert.equal(changed, 1);
    assert.equal(p.doc.activeElement, p.find, 'the focus goes to the search');
});

test('"Show more" pages through a long list', async () => {
    const p = relation({ multiple: true });
    const many = Array.from({ length: 45 }, (_, i) => ({ id: 'p' + i, label: 'Person ' + i, meta: null }));
    p.field.querySelector('[data-folio-relation-options]').textContent = JSON.stringify(many);
    p.field._folioOptions = undefined;
    type(p.w, p.find, 'person');
    await tick();
    assert.equal(p.results.querySelectorAll('[data-folio-relation-pick]').length, 20);
    p.results.querySelector('[data-folio-relation-more]').click();
    assert.equal(p.results.querySelectorAll('[data-folio-relation-pick]').length, 40);
    p.results.querySelector('[data-folio-relation-more]').click();
    assert.equal(p.results.querySelectorAll('[data-folio-relation-pick]').length, 45);
    assert.equal(p.results.querySelector('[data-folio-relation-more]'), null);
});

test('a large target searches the endpoint', async () => {
    const asked = [];
    const p = relation({ multiple: true });
    p.field.querySelector('[data-folio-relation-options]').remove();
    p.field._folioOptions = undefined;
    p.find.setAttribute('data-folio-relation-url', '/folio/_relation/author?meta=email');
    p.w.fetch = (url) => { asked.push(url); return Promise.resolve({ ok: true, json: () => Promise.resolve({ items: [{ id: 'z9', label: 'Zed', meta: 'zed@example.org' }], more: true }) }); };
    type(p.w, p.find, 'ze');
    await tick(300);
    assert.deepEqual(asked, ['/folio/_relation/author?meta=email&q=ze&page=1']);
    assert.equal(p.results.querySelector('.folio-relation-result-meta').textContent, 'zed@example.org');
    p.results.querySelector('[data-folio-relation-more]').click();
    await tick();
    assert.equal(asked[1], '/folio/_relation/author?meta=email&q=ze&page=2');
});

test('open shows the record in the drawer', () => {
    const opened = [];
    const p = relation({ selected: [['a1', 'Ann Müller']] });
    p.w.Folio.openDrawer = (url) => opened.push(url);
    p.rows.querySelector('[data-folio-relation-open-item]').click();
    assert.deepEqual(opened, ['/folio/author/a1/edit?_drawer=1']);
});

test('the arrow keys on a grip move the row and say where it is', async () => {
    const p = relation({ multiple: true, selected: [['a1', 'Ann'], ['a2', 'Bob'], ['a3', 'Cy']] });
    const handle = p.rows.querySelectorAll('[data-folio-sort-handle]')[0];
    handle.focus();
    let changed = 0;
    p.form.addEventListener('change', () => { changed++; });
    assert.equal(handle.keydown('ArrowDown').defaultPrevented, true);
    assert.deepEqual(ids(p), ['a2', 'a1', 'a3']);
    assert.equal(p.doc.activeElement, handle, 'the focus stays on the grip');
    assert.equal(changed, 1);
    await tick(40);
    assert.equal(p.doc.querySelector('[data-folio-fields-live]').textContent, 'Ann, position 2 of 3.');
    handle.keydown('ArrowUp');
    handle.keydown('ArrowUp');
    assert.deepEqual(ids(p), ['a1', 'a2', 'a3'], 'not past the first');
});

// ---- Asset: drop ------------------------------------------------------------------------------

const CARD = { id: ID, thumb: '/media/t.webp', label: 'Logo & Co', meta: 'image · 160 × 100', warn: true, focal: '50,50' };

/** A JSON answer as fetch gives it. */
const answer = (status, data, type = 'application/json; charset=UTF-8') => Promise.resolve({
    ok: status >= 200 && status < 300, status, headers: { get: (h) => (h.toLowerCase() === 'content-type' ? type : null) },
    json: () => (typeof data === 'string' ? Promise.reject(new Error('not json')) : Promise.resolve(data)),
});

function assetField({ accept = 'image/*', multiple = false } = {}) {
    const filled = [];
    const p = page(({ el, form }) => {
        el(form, 'input', { type: 'hidden', name: '_csrf_token', value: 'tok' });
        const field = el(form, 'div', { 'data-folio-asset-field': '', 'data-multiple': multiple ? '1' : '0', 'data-upload-url': '/folio/assets', 'data-accept': accept, 'data-private': '0', 'data-locale': 'de', 'data-text-refused': ':file can’t go here.', 'data-text-uploading': 'Uploading…', 'data-text-failed': 'The upload failed.' }, 'folio-asset-field');
        const zone = el(field, 'div', { 'data-folio-asset-drop': '' });
        const label = el(zone, 'label', {}, 'folio-asset-upload');
        const file = el(label, 'input', { type: 'file', name: 'cover' }, 'folio-asset-file');
        const name = el(zone, 'span', { 'data-folio-upload-name': '', 'aria-live': 'polite' });
        const error = el(field, 'div', { 'data-folio-asset-error': '' }, 'form-error');
        error.hidden = true;
        return { field, zone, error, file, name };
    }, { folio: { fillAssetField: (field, items) => filled.push(items) } });
    const sent = [];
    p.w.FormData = class { constructor() { this.entries = []; } append(k, v) { this.entries.push([k, typeof v === 'string' ? v : v.name]); } };
    p.w.fetch = (url, init) => {
        sent.push({ url, body: init.body.entries, accept: init.headers.Accept });
        return answer(200, { items: [CARD], errors: [] });
    };
    return { ...p, filled, sent };
}

function drop(p, files, target = p.zone) {
    const event = new p.w.Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', { value: { types: ['Files'], files } });
    target.dispatchEvent(event);
    return event;
}

test('a dragged file shows the drop state', () => {
    const p = assetField();
    const over = new p.w.Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperty(over, 'dataTransfer', { value: { types: ['Files'] } });
    p.zone.dispatchEvent(over);
    assert.equal(over.defaultPrevented, true, 'the field takes the drop');
    assert.ok(p.field.classList.contains('is-dragover'));
    drop(p, []);
    assert.ok(!p.field.classList.contains('is-dragover'));
});

test('a file dropped beside a field inside the editor does not open in its place', () => {
    const p = assetField();
    const editor = p.form.appendChild(p.doc.createElement('div'));
    editor.className = 'folio-form';
    const beside = editor.appendChild(p.doc.createElement('p'));
    const event = drop(p, [{ name: 'x.png', type: 'image/png' }], beside);
    assert.equal(event.defaultPrevented, true);
    assert.equal(p.sent.length, 0, 'and nothing is uploaded');
});

test('a dropped image goes to the upload route as JSON (CSRF, picker, language) and its card is chosen', async () => {
    const p = assetField();
    const event = drop(p, [{ name: 'logo.png', type: 'image/png' }]);
    assert.equal(event.defaultPrevented, true, 'the browser does not open the file');
    assert.ok(p.field.classList.contains('is-uploading'));
    assert.equal(p.name.textContent, 'logo.png', 'the live region names the file');
    await tick(20);
    assert.equal(p.sent.length, 1);
    assert.equal(p.sent[0].url, '/folio/assets');
    assert.equal(p.sent[0].accept, 'application/json');
    assert.deepEqual(p.sent[0].body, [['files[]', 'logo.png'], ['picker', '1'], ['multiple', '0'], ['private', '0'], ['locale', 'de'], ['_csrf_token', 'tok']]);
    assert.deepEqual(JSON.parse(JSON.stringify(p.filled)), [[CARD]]);
    assert.ok(!p.field.classList.contains('is-uploading'));
    assert.equal(p.error.hidden, true);
    assert.equal(p.name.textContent, '');
});

test('a file chosen in the Upload button goes the same way, and the input is cleared', async () => {
    const p = assetField();
    p.file.files = [{ name: 'chosen.png', type: 'image/png' }];
    p.file.value = 'C:\\fakepath\\chosen.png';
    p.file.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.file.value, '', 'the save does not upload it again');
    await tick(20);
    assert.deepEqual(p.sent[0].body[0], ['files[]', 'chosen.png']);
    assert.deepEqual(JSON.parse(JSON.stringify(p.filled)), [[CARD]]);
});

test('a file the field does not take shows a field error, not an upload', async () => {
    const p = assetField();
    drop(p, [{ name: 'rules.pdf', type: 'application/pdf' }]);
    await tick(20);
    assert.equal(p.sent.length, 0);
    assert.equal(p.error.hidden, false);
    assert.equal(p.error.textContent, 'rules.pdf can’t go here.');
    assert.equal(p.filled.length, 0);

    const docs = assetField({ accept: '.pdf,.docx' });
    drop(docs, [{ name: 'Rules.PDF', type: '' }]);
    await tick(20);
    assert.equal(docs.sent.length, 1, 'an extension token matches regardless of case');
});

test('a single field takes the first of several dropped files', async () => {
    const p = assetField({ multiple: false });
    drop(p, [{ name: 'a.png', type: 'image/png' }, { name: 'b.png', type: 'image/png' }]);
    await tick(20);
    assert.deepEqual(p.sent[0].body.filter(([k]) => k === 'files[]'), [['files[]', 'a.png']]);
});

test('a 422 chooses what was stored and shows the refusals', async () => {
    const p = assetField({ multiple: true });
    p.w.fetch = () => answer(422, { items: [CARD], errors: ['big.png is larger than the 10 MB limit.'] });
    drop(p, [{ name: 'logo.png', type: 'image/png' }, { name: 'big.png', type: 'image/png' }]);
    await tick(20);
    assert.equal(p.error.textContent, 'big.png is larger than the 10 MB limit.');
    assert.equal(p.error.hidden, false);
    assert.deepEqual(JSON.parse(JSON.stringify(p.filled)), [[CARD]]);
});

test('a refusal that is no JSON (403, an expired session) or no answer at all says the upload failed', async () => {
    for (const fetch of [
        () => answer(403, 'Forbidden', 'text/html; charset=UTF-8'),
        () => answer(200, '<html>login</html>', 'text/html'),
        () => Promise.reject(new TypeError('Failed to fetch')),
    ]) {
        const p = assetField();
        p.w.fetch = fetch;
        drop(p, [{ name: 'logo.png', type: 'image/png' }]);
        await tick(20);
        assert.equal(p.error.textContent, 'The upload failed.');
        assert.equal(p.error.hidden, false);
        assert.equal(p.filled.length, 0);
        assert.ok(!p.field.classList.contains('is-uploading'));
    }
});

// ---- Side panel: scripted controls name the record form at once ---------------------------------

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** admin.js and fields.js on an editor whose relation and asset fields sit in the side panel. */
function sidePanel() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    doc.documentElement.setAttribute('lang', 'en');
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        if (attrs.type) { e.type = attrs.type; }
        if (attrs.name) { e.name = attrs.name; }
        if (attrs.value !== undefined) { e.value = attrs.value; }
        return e;
    };
    const form = el(doc.body, 'form', { id: 'folio-record', 'hx-post': '/folio/post/p1/autosave' });
    const side = el(doc.body, 'fieldset', { form: 'folio-record' }, 'folio-side-fields');
    // relation (single, small target)
    const rel = el(side, 'fieldset', { form: 'folio-record', 'data-folio-relation': '', 'data-folio-relation-name': 'company' }, 'folio-relation');
    const rows = el(rel, 'ul', { 'data-folio-relation-selected': '' });
    const holder = el(rows, 'li');
    holder.hidden = true;
    el(holder, 'input', { type: 'hidden', name: 'company', value: '', form: 'folio-record' });
    const find = el(el(rel, 'label', { 'data-folio-no-edit': '' }), 'input', { type: 'search', 'data-folio-relation-find': '' });
    el(rel, 'script', { 'data-folio-relation-options': '' }).textContent = JSON.stringify([{ id: 'c1', label: 'Acme', meta: null }]);
    const results = el(rel, 'ul', { 'data-folio-relation-results': '' });
    results.hidden = true;
    const tpl = el(rel, 'template', { 'data-folio-relation-template': '' });
    const row = el(tpl, 'li', { 'data-folio-relation-item': '' }, 'folio-relation-row');
    el(row, 'span', {}, 'folio-relation-label');
    el(row, 'button', { type: 'button', 'data-folio-relation-remove': '' });
    el(row, 'input', { type: 'hidden', name: 'company', value: '' });
    // asset field
    const asset = el(side, 'div', { 'data-folio-asset-field': '', 'data-selection-name': 'logo_ids[]', 'data-multiple': '0' });
    el(asset, 'input', { type: 'hidden', name: 'logo_ids[]', value: '', form: 'folio-record' });
    el(asset, 'ul', {}, 'folio-asset-selection');
    const card = el(el(asset, 'template', { 'data-folio-asset-template': '' }), 'li', { 'data-asset-item': '' }, 'folio-asset-item');
    el(card, 'span', {}, 'folio-asset-name');
    el(card, 'input', { type: 'hidden', name: 'logo_ids[]', value: '' });
    el(card, 'button', { type: 'button', 'data-folio-asset-remove': '' });
    load(w, ADMIN_JS);
    load(w, FIELDS_JS);
    const edits = [];
    form.addEventListener('folio:edit', (e) => edits.push(e.detail.source));
    return { w, doc, form, rows, find, results, asset, edits };
}

test('a relation picked in the side panel names the record form at once, so autosave hears it', async () => {
    const p = sidePanel();
    type(p.w, p.find, 'acme');
    await tick();
    assert.equal(p.edits.length, 0, 'typing in the search is no edit');
    p.results.querySelector('[data-folio-relation-pick]').click();
    const input = p.rows.querySelector('[data-folio-relation-item] input');
    assert.equal(input.getAttribute('form'), 'folio-record');
    assert.equal(p.w.Folio.formOf(input), p.form);
    assert.deepEqual(p.edits, [input], 'folio:edit (the autosave trigger) for the new row');
    assert.equal(p.rows.querySelector('[data-folio-relation-remove]').getAttribute('form'), null, 'a type=button stays without one');

    p.rows.querySelector('[data-folio-relation-remove]').click();
    const blank = p.rows.querySelector('input');
    assert.equal(blank.getAttribute('form'), 'folio-record');
    assert.equal(p.edits.length, 2, 'removing it is announced too');
    assert.equal(p.edits[1], blank);
});

test('an asset picked in the side panel names the record form at once, so autosave hears it', () => {
    const p = sidePanel();
    p.w.Folio.fillAssetField(p.asset, [{ id: ID, thumb: '', label: 'Logo', meta: '', warn: false, focal: '' }]);
    const input = p.asset.querySelector('.folio-asset-selection input');
    assert.equal(input.getAttribute('form'), 'folio-record');
    assert.equal(p.w.Folio.formOf(input), p.form);
    assert.deepEqual(p.edits, [input]);
});

test('Folio.ownControls keeps a form a control names, leaves controls outside the side panel alone, and is idempotent', () => {
    const p = sidePanel();
    const side = p.doc.querySelector('.folio-side-fields');
    const box = side.appendChild(p.doc.createElement('div'));
    const own = box.appendChild(p.doc.createElement('input'));
    own.setAttribute('form', 'elsewhere');
    const plain = box.appendChild(p.doc.createElement('input'));
    const button = box.appendChild(p.doc.createElement('button'));
    button.setAttribute('type', 'button');
    const submit = box.appendChild(p.doc.createElement('button'));

    p.w.Folio.ownControls(box);
    assert.equal(own.getAttribute('form'), 'elsewhere', 'a form it names is kept');
    assert.equal(plain.getAttribute('form'), 'folio-record');
    assert.equal(button.getAttribute('form'), null, 'type=button submits nothing');
    assert.equal(submit.getAttribute('form'), 'folio-record', 'a typeless button submits the record form');

    const snapshot = [own, plain, button, submit].map((el) => el.getAttribute('form'));
    p.w.Folio.ownControls(box);
    assert.deepEqual([own, plain, button, submit].map((el) => el.getAttribute('form')), snapshot, 'a second call changes nothing');

    const outside = p.doc.body.appendChild(p.doc.createElement('div'));
    const loose = outside.appendChild(p.doc.createElement('input'));
    p.w.Folio.ownControls(outside);
    p.w.Folio.ownControls(loose);
    assert.equal(loose.getAttribute('form'), null, 'no side panel around it: nothing changes');
    p.w.Folio.ownControls(null);
});
