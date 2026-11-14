// The ⌘K palette and the g-chords (assets/command.js, after admin.js): ⌘K / Ctrl K opens it from
// anywhere (inside an input too), screens and actions from #folio-commands filter as you type, records
// come from {search}?q= 150 ms after the last key (an older or aborted answer is dropped), ↑/↓ move,
// ↵ opens, ⌘↵ opens in a new tab, Esc closes and gives the focus back. Titles go in with textContent.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const COMMAND_JS = fileURLToPath(new URL('../../assets/command.js', import.meta.url));

const COMMANDS = {
    search: '/folio/_search',
    screens: [
        { label: 'Dashboard', url: '/folio', icon: 'ui-dashboard', family: null },
        { label: 'Pages', url: '/folio/page', icon: 'ui-file', family: 'page' },
        { label: 'Assets', url: '/folio/assets', icon: 'ui-library', family: 'asset' },
    ],
    actions: [{ label: 'New page', url: '/folio/page/new', icon: 'ui-file', family: 'page' }],
    jumps: { d: { label: 'Dashboard', url: '/folio' }, p: { label: 'Pages', url: '/folio/page' } },
};

const ANSWER = {
    groups: [
        { type: 'page', label: 'Pages', family: 'page', icon: 'ui-file', items: [{ id: 'p1', title: 'Werkhalle Dubach', url: '/folio/page/p1/edit' }] },
        { type: 'assets', label: 'Assets', family: 'asset', icon: 'ui-library', items: [{ id: 'a1', title: 'dub.png', url: '/folio/assets/a1' }] },
    ],
};

/** A page with the sidebar's search entry, a text field, the palette data; fetch answers by hand (p.answer()). */
function page({ platform = 'MacIntel', commands = COMMANDS } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    w.navigator = { platform };
    w.AbortController = AbortController;
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const entry = el(doc.body, 'a', { href: '/folio', 'data-folio-command': '' }, 'folio-command');
    const svg = el(entry, 'svg', {}, 'folio-icon');
    el(svg, 'use', { href: '/folio/_assets/icons.svg#ui-search' });
    const chip = el(entry, 'kbd', { 'data-folio-command-key': '', 'aria-hidden': 'true' }, 'folio-command-key');
    chip.textContent = '⌘K';
    const field = el(doc.body, 'input', { type: 'text', name: 'title' });
    const json = el(doc.body, 'script', { type: 'application/json', id: 'folio-commands', nonce: 'n' });
    json.textContent = JSON.stringify(commands);

    const requests = [];
    w.fetch = (url, options) => new Promise((resolve, reject) => {
        const req = { url, options, resolve, reject };
        requests.push(req);
        if (options && options.signal) {
            options.signal.addEventListener('abort', () => { const e = new Error('aborted'); e.name = 'AbortError'; reject(e); });
        }
    });
    const opened = [];
    w.open = (...args) => { opened.push(args); return null; };
    load(w, ADMIN_JS);
    load(w, COMMAND_JS);
    const answer = (req, body) => req.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
    return { w, doc, entry, chip, field, requests, opened, answer };
}

function press(p, key, { from = null, ctrl = false, meta = false, shift = false } = {}) {
    const target = from || p.doc.activeElement || p.doc.body;
    const event = new p.w.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'key', { value: key });
    Object.defineProperty(event, 'ctrlKey', { value: ctrl });
    Object.defineProperty(event, 'altKey', { value: false });
    Object.defineProperty(event, 'metaKey', { value: meta });
    Object.defineProperty(event, 'shiftKey', { value: shift });
    target.dispatchEvent(event);
    return event;
}

function type(p, value) {
    const input = palette(p).querySelector('input');
    input.value = value;
    input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
}

const palette = (p) => p.doc.querySelector('dialog.folio-command-dialog');
const rows = (p) => palette(p).querySelectorAll('[role="option"]');
const titles = (p) => rows(p).map((r) => r.querySelector('.folio-command-title').textContent);
const flush = () => new Promise((resolve) => setImmediate(resolve));

test('⌘K opens the palette from inside an input; empty, it lists the screens and actions', () => {
    const p = page();
    p.field.focus();
    assert.equal(press(p, 'k', { meta: true }).defaultPrevented, true);
    const d = palette(p);
    assert.ok(d, 'a dialog');
    assert.equal(d.open, true);
    assert.equal(d.getAttribute('aria-label'), 'Search or jump to');
    const input = d.querySelector('input');
    assert.equal(p.doc.activeElement, input, 'the focus is in the search');
    assert.equal(input.getAttribute('role'), 'combobox');
    assert.equal(d.querySelector('.folio-command-esc').textContent, 'esc');
    assert.deepEqual(titles(p), ['Dashboard', 'Pages', 'Assets', 'New page']);
    assert.equal(d.querySelector('.folio-command-group-label').textContent, 'Screens and actions');
    assert.equal(d.querySelector('[aria-live="polite"]').textContent, '4 results');
    assert.equal(rows(p)[1].querySelector('.folio-type-icon').getAttribute('data-family'), 'page');
});

test('Ctrl K elsewhere; the entry\'s chip names the platform\'s keys and a click opens the palette', () => {
    const mac = page();
    assert.equal(mac.chip.textContent, '⌘K');
    assert.equal(mac.entry.getAttribute('role'), 'button');
    assert.equal(mac.entry.click().defaultPrevented, true, 'no trip to the dashboard');
    assert.equal(palette(mac).open, true);

    const pc = page({ platform: 'Win32' });
    assert.equal(pc.chip.textContent, 'Ctrl K');
    press(pc, 'k', { ctrl: true });
    assert.equal(palette(pc).open, true);
});

test('typing filters the screens at once and asks for records after 150 ms, once', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const p = page();
        press(p, 'k', { meta: true });
        type(p, 'p');
        assert.deepEqual(titles(p), ['Pages', 'New page'], 'screens filter as you type');
        type(p, 'pa');
        type(p, 'pag');
        mock.timers.tick(149);
        assert.equal(p.requests.length, 0, 'not before 150 ms');
        mock.timers.tick(1);
        assert.equal(p.requests.length, 1, 'one request for the last term');
        assert.equal(p.requests[0].url, '/folio/_search?q=pag');
        assert.equal(p.requests[0].options.headers.Accept, 'application/json');
    } finally {
        mock.timers.reset();
    }
});

test('records come grouped by type after the screens, the match in <strong>, assets last', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const p = page();
        press(p, 'k', { meta: true });
        type(p, 'dub');
        mock.timers.tick(150);
        p.answer(p.requests[0], ANSWER);
        await flush();
        const labels = palette(p).querySelectorAll('.folio-command-group-label').map((l) => l.textContent);
        assert.deepEqual(labels, ['Pages', 'Assets'], 'no screen matches "dub": records only');
        assert.deepEqual(titles(p), ['Werkhalle Dubach', 'dub.png']);
        const title = rows(p)[0].querySelector('.folio-command-title');
        assert.deepEqual(title.children.map((c) => [c.tagName, c.textContent]), [['SPAN', 'Werkhalle '], ['STRONG', 'Dub'], ['SPAN', 'ach']]);
        assert.equal(rows(p)[1].querySelector('.folio-type-icon').getAttribute('data-family'), 'asset');
        assert.equal(palette(p).querySelector('[aria-live="polite"]').textContent, '2 results');
    } finally {
        mock.timers.reset();
    }
});

test('a title is text: markup in it stays text', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const p = page();
        press(p, 'k', { meta: true });
        type(p, 'img');
        mock.timers.tick(150);
        p.answer(p.requests[0], { groups: [{ type: 'page', label: 'Pages', family: 'page', icon: 'ui-file', items: [{ id: 'x', title: '<img src=x onerror=alert(1)>', url: '/folio/page/x/edit' }] }] });
        await flush();
        assert.equal(titles(p)[0], '<img src=x onerror=alert(1)>');
        assert.equal(palette(p).querySelectorAll('img').length, 0);
        const src = readFileSync(COMMAND_JS, 'utf8');
        assert.equal(/innerHTML\s*=(?!\s*'')/.test(src), false, 'innerHTML only clears');
        assert.equal(/insertAdjacentHTML|outerHTML|\.style\.|setAttribute\('style'/.test(src), false, 'no markup strings, no inline style');
    } finally {
        mock.timers.reset();
    }
});

test('an older answer and an aborted request are dropped', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const p = page();
        press(p, 'k', { meta: true });
        type(p, 'du');
        mock.timers.tick(150);
        type(p, 'dub');
        mock.timers.tick(150);
        assert.equal(p.requests.length, 2);
        assert.equal(p.requests[0].options.signal.aborted, true, 'the older request is aborted');
        p.answer(p.requests[0], { groups: [{ type: 'page', label: 'Pages', family: 'page', icon: 'ui-file', items: [{ id: 'old', title: 'Old du', url: '/folio/page/old/edit' }] }] });
        await flush();
        assert.equal(titles(p).includes('Old du'), false, 'the older answer is not shown');
        assert.equal(palette(p).querySelector('[aria-live="polite"]').textContent, 'No results', 'and no error either');
        p.answer(p.requests[1], ANSWER);
        await flush();
        assert.deepEqual(titles(p), ['Werkhalle Dubach', 'dub.png']);
    } finally {
        mock.timers.reset();
    }
});

test('↑/↓ move the active row (↵ marks it), ↵ opens it, ⌘↵ opens it in a new tab', () => {
    const p = page();
    press(p, 'k', { meta: true });
    const input = palette(p).querySelector('input');
    const selected = () => rows(p).findIndex((r) => r.getAttribute('aria-selected') === 'true');
    assert.equal(selected(), 0, 'the first row starts active');
    assert.equal(input.getAttribute('aria-activedescendant'), rows(p)[0].id);
    assert.equal(rows(p)[0].querySelector('.folio-command-enter').textContent, '↵');
    press(p, 'ArrowDown');
    press(p, 'ArrowDown');
    assert.equal(selected(), 2);
    assert.equal(rows(p)[0].querySelector('.folio-command-enter'), null, 'only the active row shows ↵');
    assert.ok(rows(p)[2].querySelector('.folio-command-enter'));
    press(p, 'ArrowUp');
    assert.equal(selected(), 1);
    press(p, 'ArrowUp');
    press(p, 'ArrowUp');
    assert.equal(selected(), 3, 'up from the top wraps to the end');
    assert.equal(press(p, 'Enter', { meta: true }).defaultPrevented, true);
    assert.deepEqual(p.opened, [['/folio/page/new', '_blank', 'noopener']]);
    assert.equal(palette(p).open, true, '⌘↵ leaves the palette open');
    press(p, 'ArrowDown');
    press(p, 'Enter');
    assert.equal(p.w.location.href, '/folio');
    assert.equal(palette(p).open, false);
});

test('Esc closes the palette and gives the focus back to where it was', async () => {
    const p = page();
    p.field.focus();
    press(p, 'k', { meta: true });
    palette(p).escape();
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(palette(p).open, false);
    assert.equal(p.doc.activeElement, p.field);
    press(p, 'k', { meta: true, from: p.field });
    assert.equal(palette(p).open, true, 'it opens again');
});

test('no ⌘K behind another open dialog; the g-chords come from the data', () => {
    const p = page();
    const other = p.doc.body.appendChild(p.doc.createElement('dialog'));
    other.showModal();
    press(p, 'k', { meta: true });
    assert.equal(palette(p), null);
    other.close();
    press(p, 'g');
    press(p, 'p');
    assert.equal(p.w.location.href, '/folio/page');
    p.w.location.href = '/folio/x';
    press(p, 'g');
    press(p, 'a');
    assert.equal(p.w.location.href, '/folio/x', 'no g a without assets.view');
});

test('a term change inside the 150 ms window drops the answer in flight', async () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const p = page();
        press(p, 'k', { meta: true });
        type(p, 'du');
        mock.timers.tick(150);
        assert.equal(p.requests.length, 1);
        type(p, 'dub');
        assert.equal(p.requests[0].options.signal.aborted, true, 'the term changed: the request in flight is aborted at once');
        p.answer(p.requests[0], { groups: [{ type: 'page', label: 'Pages', family: 'page', icon: 'ui-file', items: [{ id: 'old', title: 'Old du', url: '/folio/page/old/edit' }] }] });
        await flush();
        assert.equal(titles(p).includes('Old du'), false, 'an answer to the old term never shows, even before the new request');
        mock.timers.tick(150);
        assert.equal(p.requests.length, 2);
        p.answer(p.requests[1], ANSWER);
        await flush();
        assert.deepEqual(titles(p), ['Werkhalle Dubach', 'dub.png']);
    } finally {
        mock.timers.reset();
    }
});

test('accessible names and states: the esc chip, the count line, aria-expanded', () => {
    const p = page();
    press(p, 'k', { meta: true });
    const d = palette(p);
    const esc = d.querySelector('.folio-command-esc');
    assert.equal(esc.textContent, 'esc');
    assert.ok(esc.getAttribute('aria-label').startsWith('esc'), 'the name contains the visible text (WCAG 2.5.3)');
    assert.ok(esc.getAttribute('aria-label').includes('Close'));
    const status = d.querySelector('[aria-live="polite"]');
    assert.ok(status.classList.contains('folio-sr'), 'the count is for screen readers only, as on the board');
    const input = d.querySelector('input');
    assert.equal(input.getAttribute('aria-expanded'), 'true', 'rows listed');
    type(p, 'zz');
    assert.equal(input.getAttribute('aria-expanded'), 'false', 'no rows');
});
