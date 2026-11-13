// The editor rail (assets/admin.js, partials/_rail.twig): its expand button shows the sidebar for the
// rest of the session (sessionStorage 'folio-shell'), the sidebar's collapse button brings the rail back.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** A rail page as the layout renders it: the shell with the rail (expand) and the hidden sidebar (collapse). */
function admin({ storage = null, rail = true } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    if (storage) { w.sessionStorage = storage; }
    const doc = w.document;
    const shell = doc.body.appendChild(doc.createElement('div'));
    shell.className = rail ? 'folio-shell is-rail' : 'folio-shell';
    const railEl = shell.appendChild(doc.createElement('aside'));
    railEl.className = 'folio-rail';
    const expand = railEl.appendChild(doc.createElement('button'));
    expand.setAttribute('data-shell-expand', '');
    const sidebar = shell.appendChild(doc.createElement('aside'));
    sidebar.className = 'folio-sidebar';
    const collapse = sidebar.appendChild(doc.createElement('button'));
    collapse.setAttribute('data-shell-collapse', '');
    load(w, ADMIN_JS);
    return { w, doc, root: doc.documentElement, expand, collapse };
}

test('expand shows the sidebar, remembers it for the session and focuses the collapse button', () => {
    const r = admin();
    r.expand.click();
    assert.equal(r.root.getAttribute('data-folio-shell'), 'sidebar');
    assert.equal(r.w.sessionStorage.getItem('folio-shell'), 'sidebar');
    assert.equal(r.doc.activeElement, r.collapse);
});

test('collapse goes back to the rail, forgets the choice and focuses the expand button', () => {
    const r = admin();
    r.expand.click();
    r.collapse.click();
    assert.equal(r.root.getAttribute('data-folio-shell'), null);
    assert.equal(r.w.sessionStorage.getItem('folio-shell'), null);
    assert.equal(r.doc.activeElement, r.expand);
});

test('a browser that refuses storage still expands this page and throws nothing', () => {
    const refusing = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }, removeItem() { throw new Error('denied'); } };
    const r = admin({ storage: refusing });
    assert.doesNotThrow(() => r.expand.click());
    assert.equal(r.root.getAttribute('data-folio-shell'), 'sidebar');
    assert.doesNotThrow(() => r.collapse.click());
    assert.equal(r.root.getAttribute('data-folio-shell'), null);
});

test('a sidebar page has no rail behaviour', () => {
    const r = admin({ rail: false });
    r.expand.click();
    assert.equal(r.root.getAttribute('data-folio-shell'), null);
    assert.equal(r.w.sessionStorage.getItem('folio-shell'), null);
});

test('a rail strip that scrolls (phone width) brings the current item into view', () => {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const shell = doc.body.appendChild(doc.createElement('div'));
    shell.className = 'folio-shell is-rail';
    const railEl = shell.appendChild(doc.createElement('aside'));
    railEl.className = 'folio-rail';
    const nav = railEl.appendChild(doc.createElement('nav'));
    nav.className = 'folio-rail-nav';
    // A 200px strip holding 600px of icons; the current item sits at 420–458px, out of view.
    Object.assign(nav, { scrollWidth: 600, clientWidth: 200, scrollHeight: 38, clientHeight: 38, scrollLeft: 0, scrollTop: 0 });
    nav.getBoundingClientRect = () => ({ left: 60, right: 260, top: 0, bottom: 38 });
    const other = nav.appendChild(doc.createElement('a'));
    other.setAttribute('href', '/folio');
    const current = nav.appendChild(doc.createElement('a'));
    current.setAttribute('href', '/folio/assets');
    current.setAttribute('aria-current', 'page');
    current.getBoundingClientRect = () => ({ left: 480, right: 518, top: 0, bottom: 38 });
    load(w, ADMIN_JS);

    assert.equal(nav.scrollLeft, 480 - 60 - 16, 'scrolled so the current item starts 16px in');
    assert.equal(nav.scrollTop, 0, 'no vertical scroll in a row');

    // After load (icons and fonts in place) the strip is measured once more.
    nav.scrollLeft = 0;
    (w.listeners.load || []).forEach((fn) => fn({}));
    assert.equal(nav.scrollLeft, 480 - 60 - 16, 'revealed again on load');
});
