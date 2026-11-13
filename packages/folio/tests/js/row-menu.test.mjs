// The list rows' "…" menu (assets/admin.js, _list_results.twig): a <details> whose role="menu" panel
// the script opens one at a time, places next to its button and drives by the keyboard; Delete in it
// still goes through the delete plan (Review Focus 4). Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const settle = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * A list table with two rows as _list_results.twig renders them: the row link, then the menu with Edit,
 * Move up (disabled on the first row), Duplicate, Copy preview link (hidden until the script shows it)
 * and the Delete form (data-confirm, data-delete-plan, data-delete-id). fetch answers the delete plan.
 */
function list({ plan = { restricted: false, lines: [], confirm: "This can't be undone.", addresses: 0 } } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const log = { fetched: [], confirmed: [], submitted: [] };
    w.URLSearchParams = URLSearchParams;
    w.confirm = (text) => { log.confirmed.push(text); return true; };
    w.fetch = (url) => { log.fetched.push(url); return Promise.resolve({ ok: true, json: () => Promise.resolve(plan) }); };
    w.HTMLFormElement = { prototype: { submit() { log.submitted.push(this); } } };
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const table = el(doc.body, 'table', { 'data-folio-list-table': '' }, 'folio-table folio-list-table');
    const body = el(table, 'tbody');
    const rows = ['n1', 'n2'].map((id, i) => {
        const tr = el(body, 'tr', { 'data-folio-row': '' });
        const link = el(el(tr, 'td'), 'a', { href: `/folio/news/${id}/edit` }, 'folio-row-link');
        const menu = el(el(tr, 'td'), 'details', { 'data-folio-row-menu': '' }, 'folio-row-menu');
        const summary = el(menu, 'summary', { 'aria-label': `More actions for ${id}`, 'aria-haspopup': 'menu' }, 'btn btn-ghost btn-icon btn-sm');
        const panel = el(menu, 'div', { role: 'menu' }, 'folio-menu folio-row-menu-panel');
        const edit = el(panel, 'a', { role: 'menuitem', href: `/folio/news/${id}/edit` }, 'folio-menu-item');
        const up = el(el(panel, 'form', { method: 'post', action: `/folio/news/${id}/move` }), 'button', { type: 'submit', role: 'menuitem' }, 'folio-menu-item');
        if (i === 0) { up.disabled = true; }
        const duplicate = el(el(panel, 'form', { method: 'post', action: `/folio/news/${id}/duplicate` }), 'button', { type: 'submit', role: 'menuitem', 'data-folio-menu-duplicate': '' }, 'folio-menu-item');
        const copy = el(panel, 'button', { type: 'button', role: 'menuitem', 'data-folio-copy-link': `/folio/news/${id}/share` }, 'folio-menu-item');
        copy.hidden = true;
        const del = el(panel, 'form', { method: 'post', action: `/folio/news/${id}/delete`, 'data-confirm': 'Delete this record?', 'data-delete-plan': '/folio/news/_delete-plan', 'data-delete-id': id });
        const delButton = el(del, 'button', { type: 'submit', role: 'menuitem' }, 'folio-menu-item is-danger');
        return { tr, link, menu, summary, panel, edit, up, duplicate, copy, del, delButton };
    });
    const elsewhere = el(doc.body, 'a', { href: '/folio' });
    load(w, ADMIN_JS);
    return { w, doc, rows, elsewhere, log };
}

const active = (l) => l.doc.activeElement;
const isOpen = (r) => r.menu.open === true && r.summary.getAttribute('aria-expanded') === 'true';
const isClosed = (r) => !r.menu.open && r.summary.getAttribute('aria-expanded') === 'false';

test('the script takes the menus over: ready, closed, its button says so', () => {
    const l = list();
    l.rows.forEach((r) => {
        assert.equal(r.menu.getAttribute('data-folio-row-menu-ready'), '', 'the CSS floats the panel only then');
        assert.ok(isClosed(r));
    });
});

test('Enter or Space on the button (its click) opens the menu on the first item', () => {
    const l = list();
    const r = l.rows[1];
    r.summary.focus();
    r.summary.click();
    assert.ok(isOpen(r));
    assert.equal(active(l), r.edit);
});

test('the click on the button is the script\'s: the browser does not toggle the <details> a second time', () => {
    const l = list();
    const event = new l.w.Event('click', { bubbles: true, cancelable: true });
    l.rows[0].summary.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
});

test('one menu is open at a time', () => {
    const l = list();
    l.rows[0].summary.click();
    l.rows[1].summary.click();
    assert.ok(isClosed(l.rows[0]));
    assert.ok(isOpen(l.rows[1]));
});

test('ArrowDown and ArrowUp move between the items, skipping disabled and hidden ones, and wrap', () => {
    const l = list();
    const r = l.rows[0]; // Move up is disabled on the first row, Copy preview link hidden in this window (no clipboard)
    r.summary.click();
    r.edit.keydown('ArrowDown');
    assert.equal(active(l), r.duplicate);
    r.duplicate.keydown('ArrowDown');
    assert.equal(active(l), r.delButton);
    r.delButton.keydown('ArrowDown');
    assert.equal(active(l), r.edit, 'wraps to the first');
    r.edit.keydown('ArrowUp');
    assert.equal(active(l), r.delButton, 'wraps to the last');
});

test('Home and End jump to the first and the last item', () => {
    const l = list();
    const r = l.rows[1];
    r.summary.click();
    r.edit.keydown('End');
    assert.equal(active(l), r.delButton);
    r.delButton.keydown('Home');
    assert.equal(active(l), r.edit);
});

test('ArrowDown on the closed button opens at the first item, ArrowUp at the last', () => {
    const l = list();
    const r = l.rows[1];
    r.summary.focus();
    assert.equal(r.summary.keydown('ArrowDown').defaultPrevented, true);
    assert.equal(active(l), r.edit);
    r.edit.keydown('Escape');
    r.summary.keydown('ArrowUp');
    assert.equal(active(l), r.delButton);
});

test('Escape closes the menu and returns the focus to its button', () => {
    const l = list();
    const r = l.rows[1];
    r.summary.click();
    r.edit.keydown('ArrowDown');
    const event = active(l).keydown('Escape');
    assert.equal(event.defaultPrevented, true);
    assert.ok(isClosed(r));
    assert.equal(active(l), r.summary);
});

test('Tab out of the menu or a click elsewhere closes it', () => {
    const l = list();
    const r = l.rows[0];
    r.summary.click();
    r.edit.keydown('Tab');
    assert.ok(isClosed(r));
    r.summary.click();
    l.elsewhere.click();
    assert.ok(isClosed(r));
    r.summary.click();
    r.edit.click();
    assert.ok(isOpen(r), 'a click inside the menu leaves it to the item');
});

test('a second click on the button closes it', () => {
    const l = list();
    l.rows[0].summary.click();
    l.rows[0].summary.click();
    assert.ok(isClosed(l.rows[0]));
});

test('Copy preview link shows only where a POST and the clipboard work', () => {
    const l = list();
    assert.equal(l.rows[0].copy.hidden, true, 'no clipboard in this window');
});

test('Review Focus 4: Delete in the menu still asks for the delete plan, then confirms it', async () => {
    const l = list();
    const r = l.rows[1];
    r.summary.click();
    const event = new l.w.Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'submitter', { value: r.delButton });
    r.del.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true, 'held while the plan is asked for');
    await settle();
    assert.deepEqual(l.log.fetched, ['/folio/news/_delete-plan']);
    assert.deepEqual(l.log.confirmed, ["This can't be undone."]);
    assert.equal(l.log.submitted.length, 1);
    assert.equal(l.log.submitted[0], r.del);
});
