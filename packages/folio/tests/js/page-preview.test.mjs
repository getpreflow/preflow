// The editor's whole-page preview (assets/page-preview.js, form.twig's preview stage): "Live | Whole
// page" shows one frame at a time, Whole page loads the record's real page and loads it again after
// each autosave of the record form, "View as" reloads it as the chosen role, the device switch sizes
// it too, and a record that was never saved stays on Live. Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const PAGE_PREVIEW_JS = fileURLToPath(new URL('../../assets/page-preview.js', import.meta.url));
const URL_BASE = '/folio/preview/page/post/p1?locale=de';

function stage({ saved = true, viewAs = true, remembered = null } = {}) {
    const w = createWindow();
    const doc = w.document;
    const stored = new Map(remembered === null ? [] : [['folio-preview-tab', remembered]]);
    w.localStorage = { getItem: (k) => (stored.has(k) ? stored.get(k) : null), setItem: (k, v) => { stored.set(k, String(v)); }, removeItem: (k) => { stored.delete(k); } };
    const el = (parent, tag, attrs = {}) => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        return e;
    };
    const form = el(doc.body, 'form', { 'hx-post': '/folio/post/p1/autosave' });
    const root = el(doc.body, 'section', saved
        ? { 'data-folio-stage': '', 'data-page-preview': '', 'data-page-preview-url': URL_BASE }
        : { 'data-folio-stage': '', 'data-page-preview': '' });
    const live = el(root, 'button', { 'data-preview-tab': 'live', 'aria-pressed': 'true' });
    const page = el(root, 'button', { 'data-preview-tab': 'page', 'aria-pressed': 'false' });
    if (!saved) { page.disabled = true; }
    const phone = el(root, 'button', { 'data-preview-viewport': '375px' });
    // The view switch of a type with "preview" (0.94): admin.js renders the chosen view in the live frame.
    const view = el(root, 'button', { type: 'button', 'data-preview-view': 'card', 'aria-pressed': 'false' });
    let field = null, select = null;
    if (viewAs) {
        field = el(root, 'label', { 'data-preview-as-field': '' });
        field.hidden = true;
        select = el(field, 'select', { 'data-preview-as': '' });
    }
    const liveFrame = el(root, 'iframe');
    liveFrame.className = 'folio-preview-frame';
    let pageFrame = null;
    if (saved) {
        pageFrame = el(root, 'iframe');
        pageFrame.className = 'folio-preview-frame folio-page-frame';
    }
    if (pageFrame) { pageFrame.hidden = true; pageFrame.style = {}; }
    load(w, PAGE_PREVIEW_JS);
    const autosaved = (successful = true, elt = form) => doc.dispatchEvent(Object.assign(new w.Event('htmx:afterRequest'), { detail: { elt, successful } }));
    return { w, live, page, phone, view, field, select, liveFrame, pageFrame, autosaved, stored };
}

test('Whole page shows the record\'s page in its own frame, and Live brings the live preview back', () => {
    const s = stage();
    assert.equal(s.pageFrame.hidden, true, 'Live first');
    assert.equal(s.pageFrame.getAttribute('src'), null, 'nothing loaded until it is asked for');

    s.page.click();
    assert.equal(s.pageFrame.hidden, false);
    assert.equal(s.liveFrame.hidden, true);
    assert.equal(s.pageFrame.getAttribute('src'), URL_BASE);
    assert.equal(s.page.getAttribute('aria-pressed'), 'true');
    assert.equal(s.live.getAttribute('aria-pressed'), 'false');
    assert.equal(s.field.hidden, false, 'View as shows with the whole page');
    assert.equal(s.stored.get('folio-preview-tab'), 'page', 'remembered');

    s.live.click();
    assert.equal(s.pageFrame.hidden, true);
    assert.equal(s.liveFrame.hidden, false);
    assert.equal(s.field.hidden, true);
});

test('each autosave of the record form loads the page again; elsewhere it waits for the tab', () => {
    const s = stage({ remembered: 'page' });
    assert.equal(s.pageFrame.hidden, false, 'the remembered tab');
    s.pageFrame.setAttribute('src', 'about:blank');

    s.autosaved();
    assert.equal(s.pageFrame.getAttribute('src'), URL_BASE, 'reloaded after the autosave');

    s.pageFrame.setAttribute('src', 'about:blank');
    s.autosaved(false);
    s.autosaved(true, s.w.document.createElement('form'));
    assert.equal(s.pageFrame.getAttribute('src'), 'about:blank', 'a failed autosave, or another form, changes nothing');

    s.live.click();
    s.autosaved();
    assert.equal(s.pageFrame.getAttribute('src'), 'about:blank', 'not while Live shows');
    s.page.click();
    assert.equal(s.pageFrame.getAttribute('src'), URL_BASE, 'but when the page shows again');
});

test('View as reloads the page as the chosen role', () => {
    const s = stage({ remembered: 'page' });
    s.select.value = 'member:gold';
    s.select.dispatchEvent(new s.w.Event('change', { bubbles: true }));

    assert.equal(s.pageFrame.getAttribute('src'), URL_BASE + '&as=member%3Agold');
});

test('the device switch sizes the page frame too', () => {
    const s = stage();
    s.phone.click();

    assert.equal(s.pageFrame.style.width, '375px');
    assert.equal(s.pageFrame.classList.contains('is-device'), true);
});

test('a record that was never saved stays on Live', () => {
    const s = stage({ saved: false, remembered: 'page' });

    assert.equal(s.liveFrame.hidden, false);
    s.page.click();
    assert.equal(s.liveFrame.hidden, false, 'its Whole page tab is disabled');
    assert.equal(s.live.getAttribute('aria-pressed'), 'true');
});

test('a view of the type\'s preview shows on Live: a click on the view switch leaves Whole page', () => {
    const s = stage({ remembered: 'page' });
    assert.equal(s.pageFrame.hidden, false, 'the remembered Whole page');

    s.view.click();
    assert.equal(s.liveFrame.hidden, false, 'the live frame, where admin.js renders the view');
    assert.equal(s.pageFrame.hidden, true);
    assert.equal(s.live.getAttribute('aria-pressed'), 'true');
    assert.equal(s.page.getAttribute('aria-pressed'), 'false');
    assert.equal(s.field.hidden, true, 'View as goes with the whole page');
    assert.equal(s.stored.get('folio-preview-tab'), 'live', 'Live remembered');
});
