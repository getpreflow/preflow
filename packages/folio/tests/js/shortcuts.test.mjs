// Keyboard shortcuts (assets/admin.js initShortcuts()): one registry the screens add to
// (Folio.shortcuts.add), a "?" list of them, and the list's keys: / search, n new, j / k row focus,
// Enter or e open, x tick, . menu, d duplicate. Never while typing or with Ctrl, Alt or ⌘ held (a ⌘
// entry may opt in: the editor's ⌘S). Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** A list page: toolbar search, New, a table of three rows with tick boxes and "…" menus (Duplicate in rows 1 and 2). */
function page() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const i18n = el(doc.body, 'script', { type: 'application/json', id: 'folio-i18n' });
    i18n.textContent = JSON.stringify({ shortcuts: 'Keyboard shortcuts', shortcut_help: 'Show these shortcuts', shortcut_next: 'Next row', shortcut_search: 'Search the list' });
    const newLink = el(doc.body, 'a', { href: '/folio/news/new', 'data-folio-new': '' }, 'btn btn-primary');
    const form = el(doc.body, 'form', { 'data-folio-list-form': '', action: '/folio/news' }, 'folio-list-toolbar');
    const search = el(form, 'input', { type: 'search', name: 'q', 'data-folio-list-search': '' });
    const textarea = el(doc.body, 'textarea');
    const select = el(doc.body, 'select');
    const editable = el(doc.body, 'div', { contenteditable: 'true' });
    const bar = el(doc.body, 'form', { id: 'folio-bulk', 'data-folio-bulk': '' }, 'folio-bulk-bar');
    el(bar, 'span', { 'data-folio-bulk-count': '' });
    const table = el(doc.body, 'table', { 'data-folio-list-table': '' }, 'folio-table folio-list-table');
    const body = el(table, 'tbody');
    const rows = ['n1', 'n2', 'n3'].map((id, i) => {
        const tr = el(body, 'tr', { 'data-folio-row': '' });
        const box = el(el(tr, 'td'), 'input', { type: 'checkbox', form: 'folio-bulk', 'data-folio-bulk-item': '' });
        box.type = 'checkbox';
        box.value = id;
        const link = el(el(tr, 'td'), 'a', { href: `/folio/news/${id}/edit` }, 'folio-row-link');
        const menu = el(el(tr, 'td'), 'details', { 'data-folio-row-menu': '' }, 'folio-row-menu');
        const summary = el(menu, 'summary', { 'aria-haspopup': 'menu' });
        const panel = el(menu, 'div', { role: 'menu' }, 'folio-menu');
        const edit = el(panel, 'a', { role: 'menuitem', href: `/folio/news/${id}/edit` }, 'folio-menu-item');
        const duplicate = i < 2 ? el(el(panel, 'form', { method: 'post' }), 'button', { type: 'submit', role: 'menuitem', 'data-folio-menu-duplicate': '' }, 'folio-menu-item') : null;
        const clicks = [];
        if (duplicate) { duplicate.addEventListener('click', () => clicks.push(id)); }
        return { tr, box, link, menu, summary, edit, duplicate, clicks };
    });
    const newClicks = [];
    newLink.addEventListener('click', () => newClicks.push(1));
    load(w, ADMIN_JS);
    return { w, doc, search, textarea, select, editable, rows, newClicks, bar };
}

/** A key press with modifiers, from an element (default: the body, nothing focused). */
function press(p, key, { from = null, ctrl = false, alt = false, meta = false, shift = false } = {}) {
    const target = from || p.doc.activeElement || p.doc.body;
    const event = new p.w.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'key', { value: key });
    Object.defineProperty(event, 'ctrlKey', { value: ctrl });
    Object.defineProperty(event, 'altKey', { value: alt });
    Object.defineProperty(event, 'metaKey', { value: meta });
    Object.defineProperty(event, 'shiftKey', { value: shift });
    target.dispatchEvent(event);
    return event;
}

const active = (p) => p.doc.activeElement;

test('j and k move the row focus (the row\'s link takes the focus); k at the top stays there', () => {
    const p = page();
    press(p, 'j');
    assert.equal(active(p), p.rows[0].link, 'the first j starts at the first row');
    press(p, 'j');
    press(p, 'j');
    assert.equal(active(p), p.rows[2].link);
    press(p, 'j');
    assert.equal(active(p), p.rows[2].link, 'the last row stays');
    press(p, 'k');
    assert.equal(active(p), p.rows[1].link);
    press(p, 'k');
    press(p, 'k');
    assert.equal(active(p), p.rows[0].link);
});

test('e and Enter open the focused row; Enter on anything else keeps its own meaning', () => {
    const p = page();
    press(p, 'j');
    press(p, 'j');
    assert.equal(press(p, 'e').defaultPrevented, true);
    assert.equal(p.w.location.href, '/folio/news/n2/edit');
    p.w.location.href = '/folio/news';
    press(p, 'Enter');
    assert.equal(p.w.location.href, '/folio/news/n2/edit');
    p.w.location.href = '/folio/news';
    p.rows[1].summary.focus();
    assert.equal(press(p, 'Enter').defaultPrevented, false, 'Enter on the menu button opens the menu as usual');
    assert.equal(p.w.location.href, '/folio/news');
});

test('x ticks the focused row and the bulk bar follows; x again unticks it', () => {
    const p = page();
    press(p, 'j');
    press(p, 'x');
    assert.equal(p.rows[0].box.checked, true);
    assert.equal(p.bar.hidden, false, 'the bar shows for a ticked row');
    press(p, 'x');
    assert.notEqual(p.rows[0].box.checked, true, "the focused box itself is not ticked");
    assert.equal(p.bar.hidden, true);
});

test('. opens the focused row\'s menu; d clicks its Duplicate (only where the menu has one)', () => {
    const p = page();
    press(p, 'j');
    press(p, 'j');
    press(p, '.');
    assert.equal(p.rows[1].menu.open, true);
    assert.equal(active(p), p.rows[1].edit);
    press(p, 'd');
    assert.deepEqual(p.rows[1].clicks, ['n2'], 'd works from inside the open menu too');
    press(p, 'Escape');
    press(p, 'j');
    assert.equal(active(p), p.rows[2].link);
    assert.equal(press(p, 'd').defaultPrevented, false, 'no Duplicate in this row (no create permission): nothing happens');
});

test('/ focuses the search and n follows New', () => {
    const p = page();
    assert.equal(press(p, '/').defaultPrevented, true, 'the slash is not typed into the field');
    assert.equal(active(p), p.search);
    p.doc.body.focus();
    p.doc.activeElement = p.doc.body;
    press(p, 'n');
    assert.deepEqual(p.newClicks, [1]);
});

test('typing guard: no shortcut fires in an input, textarea, select or editable text', () => {
    const p = page();
    for (const field of [p.search, p.textarea, p.select, p.editable]) {
        field.focus();
        const event = press(p, 'j');
        assert.equal(event.defaultPrevented, false);
        assert.equal(active(p), field, 'the key is typed, the focus stays');
        press(p, 'n');
        press(p, '?');
    }
    assert.deepEqual(p.newClicks, []);
    assert.equal(p.doc.querySelector('dialog'), null, 'no "?" list while typing');
});

test('typing guard: a tick box is no text field: j moves on from it and x ticks the next row', () => {
    const p = page();
    p.rows[0].box.focus();
    assert.equal(press(p, 'j').defaultPrevented, true);
    assert.equal(active(p), p.rows[1].link);
    press(p, 'x');
    assert.equal(p.rows[1].box.checked, true);
    assert.notEqual(p.rows[0].box.checked, true, "the focused box itself is not ticked");
});

test('typing guard: a search or text input still blocks', () => {
    const p = page();
    const text = p.doc.body.appendChild(p.doc.createElement('input'));
    text.setAttribute('type', 'text');
    for (const field of [p.search, text]) {
        field.focus();
        assert.equal(press(p, 'x').defaultPrevented, false);
        assert.equal(press(p, 'j').defaultPrevented, false);
        assert.equal(active(p), field);
    }
});

test('j, k, x and . work from inside an open row menu: it closes first', () => {
    const p = page();
    press(p, 'j');
    press(p, '.');
    assert.equal(p.rows[0].menu.open, true);
    assert.equal(active(p), p.rows[0].edit);
    press(p, 'j');
    assert.equal(p.rows[0].menu.open, false, 'the menu closes');
    assert.equal(active(p), p.rows[1].link);
    press(p, '.');
    press(p, 'x');
    assert.equal(p.rows[1].menu.open, false);
    assert.equal(p.rows[1].box.checked, true);
    press(p, '.');
    assert.equal(press(p, 'Enter').defaultPrevented, false, 'Enter on a menu item keeps its meaning');
    assert.equal(p.rows[1].menu.open, true);
});

test('typing guard: no plain shortcut fires with Ctrl, Alt or ⌘ held', () => {
    const p = page();
    for (const mod of [{ ctrl: true }, { alt: true }, { meta: true }]) {
        assert.equal(press(p, 'j', mod).defaultPrevented, false);
        press(p, 'n', mod);
    }
    assert.equal(active(p), p.doc.body);
    assert.deepEqual(p.newClicks, []);
});

test('a ⌘ entry fires only with ⌘ or Ctrl, and inside a field only when it says typing: true', () => {
    const p = page();
    const saves = [];
    const drafts = [];
    p.w.Folio.shortcuts.add({ key: 's', mod: true, typing: true, label: 'Save draft', run: () => { saves.push(1); } });
    p.w.Folio.shortcuts.add({ key: 'k', mod: true, label: 'Palette', run: () => { drafts.push(1); } });
    p.search.focus();
    assert.equal(press(p, 's', { meta: true }).defaultPrevented, true, 'the browser\'s own save dialog is prevented');
    press(p, 's', { ctrl: true });
    press(p, 's');
    assert.deepEqual(saves, [1, 1], '⌘S and Ctrl+S save, a plain s is typed');
    press(p, 'k', { meta: true });
    assert.deepEqual(drafts, [], 'without typing: true, not from inside a field');
});

test('? lists every registered shortcut with its key; nothing else fires while it is open', () => {
    const p = page();
    press(p, '?', { shift: true });
    const dialog = p.doc.querySelector('dialog');
    assert.ok(dialog, 'the list is a dialog');
    assert.equal(dialog.hasAttribute('open'), true);
    assert.equal(dialog.querySelector('h2').textContent, 'Keyboard shortcuts');
    const keys = dialog.querySelectorAll('dt').map((dt) => dt.textContent);
    const labels = dialog.querySelectorAll('dd').map((dd) => dd.textContent);
    assert.deepEqual(keys, ['?', '/', 'N', 'J', 'K', 'E / ↵', 'X', '.', 'D']);
    assert.equal(labels[0], 'Show these shortcuts');
    assert.equal(labels[3], 'Next row');
    press(p, 'j');
    assert.equal(active(p), p.doc.body, 'j does nothing behind the open list');
});
