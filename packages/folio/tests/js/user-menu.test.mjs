// The user menu (assets/admin.js, partials/_user_menu.twig): a button that opens a non-modal dialog
// with the account link, the theme and language rows and Log out, driven by the keyboard.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** The sidebar foot as the layout renders it, plus a link elsewhere on the page to tab to. */
function admin() {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        return e;
    };
    const menu = el(doc.body, 'div', { 'data-user-menu': '' }, 'folio-user-menu');
    const button = el(menu, 'button', { type: 'button', id: 'folio-user-button-sidebar', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-controls': 'folio-user-panel-sidebar' }, 'folio-user');
    const panel = el(menu, 'div', { id: 'folio-user-panel-sidebar', role: 'dialog', 'data-user-panel': '' }, 'folio-user-panel folio-menu');
    const account = el(panel, 'a', { href: '/folio/_account', 'data-menu-row': '' }, 'folio-menu-item');
    const themeRow = el(panel, 'div', { 'data-menu-row': '' }, 'folio-user-row');
    const theme = el(themeRow, 'div', { 'data-theme-switch': '', role: 'group' }, 'folio-theme-switch');
    const themes = ['system', 'light', 'dark'].map((c) => el(theme, 'button', { type: 'button', 'data-theme-choice': c, 'aria-pressed': c === 'light' ? 'true' : 'false' }));
    const langRow = el(panel, 'div', { 'data-menu-row': '' }, 'folio-user-row');
    const form = el(langRow, 'form', { method: 'post', action: '/folio/_locale' }, 'folio-theme-switch folio-locale-switch');
    const langs = ['de', 'en', 'fr'].map((c) => el(form, 'button', { type: 'submit', name: 'locale', value: c, 'aria-pressed': c === 'en' ? 'true' : 'false' }));
    const logoutForm = el(panel, 'form', { method: 'post', action: '/folio/logout' }, 'folio-logout');
    const logout = el(logoutForm, 'button', { type: 'submit', 'data-menu-row': '' }, 'folio-menu-item');
    const elsewhere = el(doc.body, 'a', { href: '/folio/page' });
    load(w, ADMIN_JS);
    return { w, doc, menu, button, panel, account, themes, langs, logout, elsewhere };
}

const isOpen = (m) => !m.panel.hidden && m.button.getAttribute('aria-expanded') === 'true' && m.menu.classList.contains('is-open');
const isClosed = (m) => m.panel.hidden && m.button.getAttribute('aria-expanded') === 'false' && !m.menu.classList.contains('is-open');
const active = (m) => m.doc.activeElement;

test('the script closes the panel that is open without script, and the button says so', () => {
    const m = admin();
    assert.ok(isClosed(m));
});

test('Enter or Space on the button (its click) opens the panel and focuses the first row', () => {
    const m = admin();
    m.button.focus();
    m.button.click();
    assert.ok(isOpen(m));
    assert.equal(active(m), m.account);
});

test('a second click on the button closes it', () => {
    const m = admin();
    m.button.click();
    m.button.click();
    assert.ok(isClosed(m));
});

test('ArrowDown and ArrowUp move between the rows; a segmented row takes focus on its pressed button', () => {
    const m = admin();
    m.button.click();
    m.account.keydown('ArrowDown');
    assert.equal(active(m), m.themes[1], 'the pressed theme (light)');
    m.themes[1].keydown('ArrowDown');
    assert.equal(active(m), m.langs[1], 'the pressed language (en)');
    m.langs[1].keydown('ArrowDown');
    assert.equal(active(m), m.logout);
    m.logout.keydown('ArrowDown');
    assert.equal(active(m), m.account, 'wraps round to the first row');
    m.account.keydown('ArrowUp');
    assert.equal(active(m), m.logout, 'and back to the last');
    m.logout.keydown('Home');
    assert.equal(active(m), m.account);
    m.account.keydown('End');
    assert.equal(active(m), m.logout);
});

test('the arrow keys are taken from the page (no scrolling while the panel is open)', () => {
    const m = admin();
    m.button.click();
    assert.equal(m.account.keydown('ArrowDown').defaultPrevented, true);
});

test('ArrowLeft and ArrowRight move inside a segmented row', () => {
    const m = admin();
    m.button.click();
    m.account.keydown('ArrowDown');
    m.themes[1].keydown('ArrowRight');
    assert.equal(active(m), m.themes[2]);
    m.themes[2].keydown('ArrowRight');
    assert.equal(active(m), m.themes[0], 'wraps');
    m.themes[0].keydown('ArrowLeft');
    assert.equal(active(m), m.themes[2]);
});

test('Escape closes the panel and returns focus to the button', () => {
    const m = admin();
    m.button.click();
    m.account.keydown('ArrowDown');
    const event = m.themes[1].keydown('Escape');
    assert.ok(isClosed(m));
    assert.equal(active(m), m.button);
    assert.equal(event.defaultPrevented, true);
});

test('ArrowDown on the closed button opens it at the first row, ArrowUp at the last', () => {
    const m = admin();
    m.button.focus();
    m.button.keydown('ArrowDown');
    assert.ok(isOpen(m));
    assert.equal(active(m), m.account);
    m.account.keydown('Escape');
    m.button.keydown('ArrowUp');
    assert.ok(isOpen(m));
    assert.equal(active(m), m.logout);
});

test('tabbing out of the menu closes it without pulling focus back', () => {
    const m = admin();
    m.button.click();
    m.logout.focus();
    m.elsewhere.focus();
    assert.ok(isClosed(m));
    assert.equal(active(m), m.elsewhere);
});

test('moving focus inside the menu keeps it open', () => {
    const m = admin();
    m.button.click();
    m.themes[0].focus();
    m.langs[2].focus();
    m.button.focus();
    assert.ok(isOpen(m));
});

test('a click elsewhere on the page closes it; a click inside does not', () => {
    const m = admin();
    m.button.click();
    m.themes[2].click();
    assert.ok(isOpen(m));
    m.elsewhere.click();
    assert.ok(isClosed(m));
});
