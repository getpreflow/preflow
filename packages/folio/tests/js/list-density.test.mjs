// The lists' row height (list.twig, _layout.twig, assets/admin.js): Compact (44px) or Comfortable (60px,
// the default), a per-browser convenience in localStorage 'folio.density'. The layout's head script puts
// it on <html> as data-folio-density before the first paint; the toolbar's toggle (hidden without
// script) changes and stores it. Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const LAYOUT = readFileSync(fileURLToPath(new URL('../../templates/admin/_layout.twig', import.meta.url)), 'utf8');
const CSS = readFileSync(fileURLToPath(new URL('../../assets/admin.css', import.meta.url)), 'utf8');

/** A localStorage that keeps what is set; throws: a browser that refuses storage. */
function storage(initial = {}, throws = false) {
    const items = new Map(Object.entries(initial));
    const fail = () => { throw new Error('SecurityError'); };
    return {
        items,
        getItem: (k) => (throws ? fail() : items.has(k) ? items.get(k) : null),
        setItem: (k, v) => (throws ? fail() : items.set(k, String(v))),
        removeItem: (k) => (throws ? fail() : items.delete(k)),
    };
}

/** The toolbar's toggle as list.twig renders it: hidden, both buttons not pressed. */
function toolbar(store, density = null) {
    const w = createWindow();
    w.localStorage = store;
    const doc = w.document;
    if (density) { doc.documentElement.setAttribute('data-folio-density', density); }
    const group = doc.body.appendChild(doc.createElement('div'));
    group.setAttribute('data-folio-density', '');
    group.setAttribute('role', 'group');
    group.hidden = true;
    const buttons = ['compact', 'comfortable'].map((d) => {
        const b = group.appendChild(doc.createElement('button'));
        b.setAttribute('type', 'button');
        b.setAttribute('data-folio-density-choice', d);
        b.setAttribute('aria-pressed', 'false');
        return b;
    });
    load(w, ADMIN_JS);
    return { w, doc, group, compact: buttons[0], comfortable: buttons[1] };
}

/** Runs the layout's first inline script (the head script) against a bare <html> and this storage. */
function headScript(store) {
    const script = LAYOUT.match(/<script nonce="\{\{ csp_nonce\(\) \}\}">([\s\S]*?)<\/script>/)[1];
    const attrs = {};
    const root = {
        classList: { add() {}, remove() {} },
        setAttribute: (k, v) => { attrs[k] = String(v); },
        getAttribute: (k) => attrs[k] ?? null,
    };
    const context = {
        document: { documentElement: root, querySelector: () => null },
        localStorage: store,
        sessionStorage: storage(),
        window: { addEventListener() {} },
    };
    vm.runInNewContext(script, context);
    return attrs;
}

test('the head script applies a stored density before the first paint', () => {
    assert.equal(headScript(storage({ 'folio.density': 'compact' }))['data-folio-density'], 'compact');
    assert.equal(headScript(storage({ 'folio.density': 'comfortable' }))['data-folio-density'], 'comfortable');
});

test('the head script ignores an unknown value and a refused storage (comfortable, the default)', () => {
    assert.equal(headScript(storage({ 'folio.density': 'huge' }))['data-folio-density'], undefined);
    assert.equal(headScript(storage({}, true))['data-folio-density'], undefined);
});

test('the toggle shows with script and marks the current density (comfortable by default)', () => {
    const t = toolbar(storage());
    assert.equal(t.group.hidden, false);
    assert.equal(t.comfortable.getAttribute('aria-pressed'), 'true');
    assert.equal(t.compact.getAttribute('aria-pressed'), 'false');
    const c = toolbar(storage(), 'compact');
    assert.equal(c.compact.getAttribute('aria-pressed'), 'true');
});

test('a click sets the density on <html>, stores it and moves the pressed state', () => {
    const store = storage();
    const t = toolbar(store);
    t.compact.click();
    assert.equal(t.doc.documentElement.getAttribute('data-folio-density'), 'compact');
    assert.equal(store.items.get('folio.density'), 'compact');
    assert.equal(t.compact.getAttribute('aria-pressed'), 'true');
    assert.equal(t.comfortable.getAttribute('aria-pressed'), 'false');
    t.comfortable.click();
    assert.equal(t.doc.documentElement.getAttribute('data-folio-density'), 'comfortable');
    assert.equal(store.items.get('folio.density'), 'comfortable');
});

test('a browser that refuses storage still switches the page', () => {
    const t = toolbar(storage({}, true));
    t.compact.click();
    assert.equal(t.doc.documentElement.getAttribute('data-folio-density'), 'compact');
});

test('the rows are 60px comfortable and 44px compact', () => {
    assert.match(CSS, /\.folio-list-table tbody td \{ height: 60px;/);
    assert.match(CSS, /:root\[data-folio-density="compact"\] \.folio-list-table tbody td \{ height: 44px;/);
});
