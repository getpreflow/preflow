// The asset page's Details tab (templates/admin/assets/panel.twig, assets/admin.js, 0.76): a click on the image
// sets the focal point and the crop previews follow it (--folio-fx / --folio-fy on the panel), a value button
// (swatch, file address) copies its value with the "Copied" toast, and Replace file sends a chosen or dropped
// file at once to the replace route.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** A style object as elements have it: left/top and custom properties (setProperty / getPropertyValue). */
function style() {
    const props = {};
    return { setProperty: (k, v) => { props[k] = v; }, getPropertyValue: (k) => props[k] || '', props };
}

function page({ clipboard = true } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, text = '') => {
        const node = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'class') { node.className = v; } else { node.setAttribute(k, v); }
            if (['type', 'name', 'value'].includes(k)) { node[k] = v; }
        });
        node.style = style();
        if (text) { node.textContent = text; }
        return node;
    };
    el(doc.body, 'div', { class: 'folio-toasts', 'data-folio-toasts': '' });
    const panel = el(doc.body, 'section', { class: 'folio-asset-panel' });
    const stage = el(panel, 'div', { class: 'folio-asset-stage' });
    const picker = el(stage, 'div', { class: 'folio-focal-picker', 'data-folio-focal': '' });
    el(picker, 'img', { src: '/media/a.webp', alt: '' });
    const marker = el(picker, 'span', { class: 'folio-focal-marker', 'aria-hidden': 'true' });
    picker.getBoundingClientRect = () => ({ left: 100, top: 50, width: 400, height: 200 });
    const crops = el(stage, 'div', { class: 'folio-crops', 'data-folio-crops': '' });
    crops.hidden = true;
    const side = el(panel, 'div', { class: 'folio-asset-side' });
    const form = el(side, 'form', { class: 'folio-asset-form', id: 'folio-asset-form' });
    const fx = el(form, 'input', { type: 'hidden', name: 'focal_x', value: '30' });
    const fy = el(form, 'input', { type: 'hidden', name: 'focal_y', value: '70' });
    const copyPath = el(side, 'button', { type: 'button', 'data-copy': '/media/2026/11/a.webp' });
    const swatch = el(side, 'button', { type: 'button', class: 'folio-swatch-chip', 'data-copy': '#1976d2' });
    const replace = el(side, 'form', { method: 'post', action: '/folio/assets/x/replace', class: 'folio-asset-replace', 'data-folio-replace': '' });
    const zone = el(replace, 'label', { class: 'folio-asset-dropzone' });
    const file = el(zone, 'input', { type: 'file', name: 'file', 'data-folio-replace-file': '' });
    const submit = el(replace, 'button', { type: 'submit', 'data-folio-replace-submit': '' }, 'Replace');

    const copied = [];
    w.navigator = clipboard ? { clipboard: { writeText: (t) => { copied.push(t); return Promise.resolve(); } } } : {};
    w.HTMLFormElement = { prototype: { submit() { throw new Error('form.submit() skips the submit handlers'); } } };
    load(w, ADMIN_JS);
    return { w, doc, panel, picker, marker, crops, form, fx, fy, copyPath, swatch, replace, zone, file, submit, copied };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('on load the marker and the crop previews take the saved focal point, and the previews show', () => {
    const p = page();
    assert.equal(p.marker.style.left, '30%');
    assert.equal(p.marker.style.top, '70%');
    assert.equal(p.panel.style.props['--folio-fx'], '30%');
    assert.equal(p.panel.style.props['--folio-fy'], '70%');
    assert.equal(p.crops.hidden, false, 'shown once script can keep them right');
});

test('a click on the image moves the focal point, the form fields and every crop preview', () => {
    const p = page();
    const click = new p.w.Event('click', { bubbles: true, cancelable: true });
    Object.defineProperty(click, 'clientX', { value: 200 }); // a quarter across
    Object.defineProperty(click, 'clientY', { value: 200 }); // three quarters down
    p.picker.dispatchEvent(click);

    assert.equal(p.fx.value, '25');
    assert.equal(p.fy.value, '75');
    assert.equal(p.marker.style.left, '25%');
    assert.equal(p.panel.style.props['--folio-fx'], '25%');
    assert.equal(p.panel.style.props['--folio-fy'], '75%');
});

test('a value button copies its value and says so in the toast', async () => {
    const p = page();
    p.swatch.click();
    await flush(); await flush();
    assert.deepEqual(p.copied, ['#1976d2']);
    const toast = p.doc.body.querySelector('[data-folio-toasts] .folio-toast');
    assert.ok(toast, 'a toast');
    assert.equal(toast.textContent, 'Copied');
    assert.ok(!toast.classList.contains('is-error'));
    assert.ok(p.swatch.classList.contains('is-copied'));

    p.copyPath.click();
    await flush(); await flush();
    assert.deepEqual(p.copied, ['#1976d2', '/media/2026/11/a.webp']);
});

test('without a clipboard nothing claims a copy', async () => {
    const p = page({ clipboard: false });
    p.swatch.click();
    await flush(); await flush();
    assert.equal(p.doc.body.querySelector('[data-folio-toasts] .folio-toast'), null);
});

test('Replace file: with script the button gives way and a chosen file is sent at once', () => {
    const p = page();
    assert.ok(p.replace.classList.contains('is-live'));
    assert.equal(p.submit.hidden, true);

    Object.defineProperty(p.file, 'files', { value: [{ name: 'new.png' }], configurable: true, writable: true });
    p.file.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal((p.replace.sent || []).length, 1, 'the replace form went off');
    assert.ok(p.replace.classList.contains('is-uploading'));
});

test('Replace file: a file dropped on the zone becomes the input\'s file and is sent', () => {
    const p = page();
    const over = new p.w.Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperty(over, 'dataTransfer', { value: { types: ['Files'] } });
    p.zone.dispatchEvent(over);
    assert.equal(over.defaultPrevented, true);
    assert.ok(p.replace.classList.contains('is-dragover'));

    const dropped = [{ name: 'photo.png' }];
    const drop = new p.w.Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(drop, 'dataTransfer', { value: { types: ['Files'], files: dropped } });
    p.zone.dispatchEvent(drop);
    assert.equal(drop.defaultPrevented, true);
    assert.ok(!p.replace.classList.contains('is-dragover'));
    assert.equal(p.file.files, dropped);
    assert.equal((p.replace.sent || []).length, 1);
});

test('Replace file: something dragged that is no file is left alone', () => {
    const p = page();
    const over = new p.w.Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperty(over, 'dataTransfer', { value: { types: ['text/plain'] } });
    p.zone.dispatchEvent(over);
    assert.equal(over.defaultPrevented, false);
    assert.ok(!p.replace.classList.contains('is-dragover'));
});

test('Replace file: back from the back/forward cache, the zone is idle again', () => {
    const p = page();
    Object.defineProperty(p.file, 'files', { value: [{ name: 'new.png' }], configurable: true, writable: true });
    p.file.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.ok(p.replace.classList.contains('is-uploading'));
    assert.equal(p.replace.getAttribute('aria-busy'), 'true');

    (p.w.listeners.pageshow || []).forEach((fn) => fn({ persisted: true }));

    assert.ok(!p.replace.classList.contains('is-uploading'));
    assert.equal(p.replace.getAttribute('aria-busy'), null);
});

test('the Details form is under the unsaved guard: a typed change and a focal click both count', () => {
    const p = page();
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), false);
    const click = new p.w.Event('click', { bubbles: true, cancelable: true });
    Object.defineProperty(click, 'clientX', { value: 300 });
    Object.defineProperty(click, 'clientY', { value: 100 });
    p.picker.dispatchEvent(click);
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true, 'a new focal point is an unsaved edit');
    p.w.Folio.unsaved.clear(p.form);

    const title = p.form.appendChild(p.doc.createElement('input'));
    title.setAttribute('name', 'title');
    title.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);
});

const flushAll = async () => { for (let i = 0; i < 4; i++) { await flush(); } };

test('Replace file with unsaved details asks first; Cancel keeps the page and the changes', async () => {
    const p = page();
    p.fx.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);

    Object.defineProperty(p.file, 'files', { value: [{ name: 'new.png' }], configurable: true, writable: true });
    p.file.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    const dialog = p.doc.body.querySelector('dialog[data-folio-dialog]');
    assert.ok(dialog && dialog.open, 'the question');
    assert.equal(dialog.querySelector('[data-folio-dialog-title]').textContent, 'Replace the file with unsaved changes?');
    assert.ok(dialog.classList.contains('is-danger'));
    assert.equal(dialog.querySelector('[data-folio-dialog-confirm]').textContent, 'Replace anyway');
    assert.equal((p.replace.sent || []).length, 0, 'nothing sent yet');

    dialog.querySelector('[data-folio-dialog-cancel]').click();
    await flushAll();
    assert.equal((p.replace.sent || []).length, 0);
    assert.ok(!p.replace.classList.contains('is-uploading'));
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true, 'the changes stay');
});

test('Replace file with unsaved details: "Replace anyway" drops them and sends the file', async () => {
    const p = page();
    p.fx.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    Object.defineProperty(p.file, 'files', { value: [{ name: 'new.png' }], configurable: true, writable: true });
    p.file.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    p.doc.body.querySelector('dialog[data-folio-dialog] [data-folio-dialog-confirm]').click();
    await flushAll();

    assert.equal((p.replace.sent || []).length, 1);
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), false, 'no leave prompt on the way out');
});
