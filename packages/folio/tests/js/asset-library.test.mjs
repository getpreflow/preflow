// The asset library (templates/admin/assets/index.twig, assets/admin.js, 0.76): the selection bar on the cards
// (Download for exactly one, Edit alt text in the drawer for the ticked images, Move to bin naming them), the
// header's Upload and the drop zone (each file to the JSON upload route, then the library refreshes), the
// toolbar's filters that submit at once, and a saved alt-text drawer that refreshes the library.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const A = 'a'.repeat(32);
const B = 'b'.repeat(32);
const C = 'c'.repeat(32);

/** The library page as the server renders it: toolbar, upload form, cards with tick boxes, drop zone, selection bar. */
function library({ fetch = null } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const el = (parent, tag, attrs = {}, text = '') => {
        const node = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'class') { node.className = v; } else { node.setAttribute(k, v); }
            if (['type', 'name', 'value'].includes(k)) { node[k] = v; }
        });
        if (text) { node.textContent = text; }
        return node;
    };
    const choose = el(doc.body, 'button', { type: 'button', 'data-folio-assets-choose': '' }, 'Upload');
    choose.hidden = true;
    const errors = el(doc.body, 'div', { class: 'folio-alert folio-alert-error', 'data-folio-assets-errors': '' });
    errors.hidden = true;
    el(errors, 'ul');
    const root = el(doc.body, 'div', { class: 'folio-assets', 'data-folio-assets': '', 'data-folio-assets-upload-url': '/folio/assets', 'data-text-uploading': 'Uploading :done of :total…', 'data-text-failed': 'The upload failed.' });
    const toolbar = el(root, 'form', { method: 'get', action: '/folio/assets', 'data-folio-assets-toolbar': '' });
    const kind = el(toolbar, 'select', { name: 'f[usage]' });
    const unused = el(toolbar, 'input', { type: 'checkbox', name: 'unused', value: '1' });
    const submit = el(toolbar, 'button', { type: 'submit', 'data-folio-assets-submit': '' }, 'Filter');
    const upload = el(root, 'form', { method: 'post', action: '/folio/assets', 'data-folio-assets-upload': '' });
    el(upload, 'input', { type: 'hidden', name: '_csrf_token', value: 'tok' });
    const input = el(upload, 'input', { type: 'file', name: 'files[]', id: 'folio-assets-files', 'data-folio-assets-files': '' });
    const grid = el(root, 'ul', { class: 'folio-asset-grid' });
    const box = (id, label, kindName) => el(el(grid, 'li', { class: 'folio-asset' }), 'input', { type: 'checkbox', name: 'ids[]', value: id, form: 'folio-bulk', 'data-folio-bulk-item': '', 'data-label': label, 'data-kind': kindName, 'data-download': '/media/' + label });
    const boxes = [box(A, 'barn.png', 'image'), box(B, 'rules.pdf', 'document'), box(C, 'field.png', 'image')];
    const zone = el(el(grid, 'li', { class: 'folio-asset-drop' }), 'label', { for: 'folio-assets-files', 'data-folio-assets-drop': '' });
    const status = el(zone, 'span', { 'data-folio-assets-drop-status': '' }, 'or click to choose');
    const bar = el(root, 'form', { class: 'folio-bulk-bar', id: 'folio-bulk', method: 'post', action: '/folio/assets/bulk', 'data-folio-bulk': '' });
    el(bar, 'input', { type: 'hidden', name: '_csrf_token', value: 'tok' });
    el(bar, 'span', { 'data-folio-bulk-count': '' }, 'With selected:');
    const alt = el(bar, 'button', { type: 'button', 'data-folio-bulk-drawer': '/folio/assets/alt', 'data-folio-bulk-when': '[data-kind="image"]' }, 'Edit alt text');
    alt.hidden = true;
    const download = el(bar, 'a', { href: '#', download: '', 'data-folio-bulk-one': 'data-download' }, 'Download');
    download.hidden = true;
    const bin = el(bar, 'button', { type: 'submit', 'data-tone': 'danger', name: '_action', value: 'bin', 'data-confirm': 'Move the selected files to the bin?', 'data-confirm-selected': '' }, 'Move to bin');

    const log = { fetched: [], bodies: [], reloads: 0, clicked: 0 };
    w.FormData = class { constructor() { this.entries = []; } append(k, v) { this.entries.push([k, typeof v === 'string' ? v : v.name]); } };
    w.fetch = fetch || ((url, init) => {
        log.fetched.push(url);
        log.bodies.push(init && init.body ? init.body.entries : null);
        return answer(200, { items: [{ id: A }], errors: [] });
    });
    w.location.reload = () => { log.reloads++; };
    input.click = () => { log.clicked++; };
    w.HTMLFormElement = { prototype: { submit() { throw new Error('form.submit() skips the submit handlers'); } } };
    load(w, ADMIN_JS);
    return { w, doc, root, toolbar, kind, unused, submit, upload, input, boxes, zone, status, bar, alt, download, bin, choose, errors, log };
}

/** A JSON answer as fetch gives it. */
const answer = (status, data) => Promise.resolve({
    ok: status >= 200 && status < 300, status, headers: { get: (h) => (h.toLowerCase() === 'content-type' ? 'application/json; charset=UTF-8' : null) },
    json: () => Promise.resolve(data),
});

function tick(p, box, on = true) {
    box.checked = on;
    box.dispatchEvent(new p.w.Event('change', { bubbles: true }));
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('with script the upload form hides, Upload shows, and the toolbar filters submit at once', () => {
    const p = library();
    assert.equal(p.upload.hidden, true, 'the no-script form gives way');
    assert.equal(p.choose.hidden, false, 'the header Upload is shown');
    assert.equal(p.submit.hidden, true, 'no Filter button: a change submits');

    p.choose.click();
    assert.equal(p.log.clicked, 1, 'Upload opens the file input');
    const zoneClick = p.zone.click();
    assert.equal(p.log.clicked, 2, 'so does the drop zone');
    assert.equal(zoneClick.defaultPrevented, true, 'once: not also through the label');

    const sent = [];
    p.toolbar.requestSubmit = () => sent.push('submit');
    p.unused.checked = true;
    p.unused.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    p.kind.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.deepEqual(sent, ['submit', 'submit']);
});

test('Download is there for exactly one ticked file, Edit alt text while an image is ticked', () => {
    const p = library();
    assert.equal(p.bar.hidden, true, 'nothing ticked: no bar');

    tick(p, p.boxes[1]);
    assert.equal(p.bar.hidden, false);
    assert.equal(p.download.hidden, false);
    assert.equal(p.download.getAttribute('href'), '/media/rules.pdf');
    assert.equal(p.alt.hidden, true, 'a document has no alt text');

    tick(p, p.boxes[0]);
    assert.equal(p.download.hidden, true, 'no download for two files (no archive)');
    assert.equal(p.alt.hidden, false);
    assert.equal(p.bar.querySelector('[data-folio-bulk-count]').textContent, '2 selected');

    tick(p, p.boxes[1], false);
    assert.equal(p.download.hidden, false);
    assert.equal(p.download.getAttribute('href'), '/media/barn.png');
});

test('Edit alt text opens the drawer with the ticked images, and a saved drawer refreshes the library', () => {
    const p = library();
    tick(p, p.boxes[0]);
    tick(p, p.boxes[1]);
    tick(p, p.boxes[2]);
    p.alt.click();
    const frame = p.doc.body.querySelector('.folio-drawer iframe');
    assert.ok(frame, 'the drawer opened');
    assert.equal(frame.src, '/folio/assets/alt?ids%5B%5D=' + A + '&ids%5B%5D=' + C + '&_drawer=1', 'images only, in the drawer layout');

    p.w.receive({ source: 'folio-drawer', type: 'asset-alt', id: '' });
    assert.equal(p.doc.body.querySelector('.folio-drawer'), null, 'the drawer closes');
    assert.equal(p.log.reloads, 1, 'the library is shown again (here: reloaded, no DOMParser)');

    p.w.receive({ source: 'folio-drawer', type: 'asset-alt', id: '' });
    assert.equal(p.log.reloads, 1, 'a message from no drawer is ignored');
});

test('Move to bin asks with the ticked names and then posts the bar', async () => {
    const p = library();
    tick(p, p.boxes[0]);
    tick(p, p.boxes[2]);
    const event = new p.w.Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'submitter', { value: p.bin });
    p.bar.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true, 'held for the question');
    const dialog = p.doc.body.querySelector('dialog[data-folio-dialog]');
    assert.equal(dialog.querySelector('[data-folio-dialog-title]').textContent, 'Move the selected files to the bin?');
    assert.deepEqual(dialog.querySelector('[data-folio-dialog-items]').querySelectorAll('li').map((li) => li.textContent), ['barn.png', 'field.png']);
    assert.ok(dialog.classList.contains('is-danger'));
    dialog.querySelector('[data-folio-dialog-confirm]').click();
    await flush();
    assert.deepEqual(p.bar.sent, [p.bin], 'sent with its _action=bin');
});

test('dropped files go one by one to the JSON upload route, then the library refreshes', async () => {
    const p = library();
    const over = new p.w.Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperty(over, 'dataTransfer', { value: { types: ['Files'] } });
    p.zone.dispatchEvent(over);
    assert.equal(over.defaultPrevented, true);
    assert.ok(p.root.classList.contains('is-dragover'));

    const drop = new p.w.Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(drop, 'dataTransfer', { value: { types: ['Files'], files: [{ name: 'a.png' }, { name: 'b.png' }] } });
    p.zone.dispatchEvent(drop);
    assert.equal(drop.defaultPrevented, true);
    assert.ok(!p.root.classList.contains('is-dragover'));
    assert.ok(p.root.classList.contains('is-uploading'));
    assert.equal(p.status.textContent, 'Uploading 1 of 2…');
    await flush(); await flush(); await flush(); await flush();

    assert.deepEqual(p.log.fetched, ['/folio/assets', '/folio/assets'], 'one request per file');
    assert.deepEqual(p.log.bodies, [[['files[]', 'a.png'], ['_csrf_token', 'tok']], [['files[]', 'b.png'], ['_csrf_token', 'tok']]]);
    assert.ok(!p.root.classList.contains('is-uploading'));
    assert.equal(p.status.textContent, 'or click to choose');
    assert.equal(p.errors.hidden, true);
    assert.equal(p.log.reloads, 1);
});

test('files chosen in the dialog upload too, and what the route refused is listed', async () => {
    let n = 0;
    const p = library({ fetch: () => (++n === 1 ? answer(422, { items: [], errors: ['big.png is larger than the 10 MB limit.'] }) : Promise.reject(new TypeError('Failed to fetch'))) });
    Object.defineProperty(p.input, 'files', { value: [{ name: 'big.png' }, { name: 'c.png' }], configurable: true });
    p.input.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    for (let i = 0; i < 6; i++) { await flush(); }

    assert.equal(p.errors.hidden, false);
    assert.deepEqual(p.errors.querySelectorAll('li').map((li) => li.textContent), ['big.png is larger than the 10 MB limit.', 'c.png: The upload failed.']);
    assert.equal(p.log.reloads, 1, 'the files that went in are shown');
});
