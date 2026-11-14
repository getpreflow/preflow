// Settings → Redirects (assets/admin.js): the form's "Send visitors to" (segmented, the page search as a combobox),
// the drawer links of the list (New redirect, Edit, "→ add a redirect") and the delete confirm about one address.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const settle = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

const TARGETS = [
    { value: 'page:p1', label: 'About', type: 'Pages', url: '/about' },
    { value: 'news:n2', label: 'About the news', type: 'News', url: '/news/about' },
];

/** The form as redirects/form.twig renders it (the parts admin.js reads), $kind: the checked mode. */
function redirectForm(doc, { kind = 'page', target = '', pageValue = '' } = {}) {
    const el = (tag, attrs = {}, parent = null) => {
        const node = doc.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => { if (v === true) { node.setAttribute(k, ''); } else { node.setAttribute(k, v); } });
        if (parent) { parent.appendChild(node); }
        return node;
    };
    const form = el('form', { method: 'post', action: '/folio/redirects', 'data-redirect-form': true }, doc.body);
    const kinds = el('div', { 'data-redirect-kinds': true }, form);
    kinds.hidden = true;
    const radios = ['page', 'address'].map((value) => {
        const r = el('input', { type: 'radio', name: 'target_kind', value }, el('label', {}, kinds));
        r.type = 'radio';
        r.name = 'target_kind';
        r.value = value;
        r.checked = value === kind;
        return r;
    });
    const pagePane = el('div', { 'data-redirect-page': true }, form);
    pagePane.hidden = true;
    const value = el('input', { type: 'hidden', name: 'target', 'data-redirect-page-value': true }, pagePane);
    value.value = pageValue;
    value.disabled = true;
    const search = el('input', { type: 'text', role: 'combobox', 'aria-expanded': 'false', 'aria-controls': 'folio-redirect-results', 'data-redirect-search': true, 'data-redirect-targets': '/folio/redirects/_targets' }, pagePane);
    const list = el('ul', { id: 'folio-redirect-results', role: 'listbox', 'data-redirect-results': true }, pagePane);
    list.hidden = true;
    const picked = el('div', { 'data-redirect-picked': true }, pagePane);
    picked.hidden = pageValue === '';
    const pickedLabel = el('span', { 'data-redirect-picked-label': true }, picked);
    const pickedMeta = el('span', { 'data-redirect-picked-meta': true }, picked);
    const addressPane = el('div', { 'data-redirect-address': true }, form);
    const address = el('input', { type: 'text', name: 'target', 'data-redirect-address-input': true }, addressPane);
    address.value = target;
    return { form, kinds, radios, pagePane, value, search, list, picked, pickedLabel, pickedMeta, addressPane, address };
}

function admin(build) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const log = { fetched: [], reloaded: 0 };
    w.fetch = (url) => {
        log.fetched.push(url);
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ items: TARGETS }) });
    };
    w.location.reload = () => { log.reloaded++; };
    const parts = build ? build(w.document) : null;
    load(w, ADMIN_JS);
    return { w, log, parts };
}

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

const options = (list) => list.querySelectorAll('[role="option"]');

test('test_send_to_shows_the_segmented_and_one_target_field', () => {
    const { parts: f } = admin((doc) => redirectForm(doc, { kind: 'page' }));

    assert.equal(f.kinds.hidden, false, 'the segmented shows with script');
    assert.equal(f.pagePane.hidden, false);
    assert.equal(f.addressPane.hidden, true);
    assert.deepEqual([f.value.disabled, f.address.disabled], [false, true], 'only the page value is posted as target');

    f.radios[0].checked = false;
    f.radios[1].checked = true;
    f.radios[1].dispatchEvent(new Event('change', { bubbles: true }));
    assert.equal(f.pagePane.hidden, true);
    assert.equal(f.addressPane.hidden, false);
    assert.deepEqual([f.value.disabled, f.address.disabled], [true, false], 'the address input is the target now');

    // A page posted by hand ("type:id", the page without script) is the page mode's pick.
    const { parts: typed } = admin((doc) => redirectForm(doc, { kind: 'page', target: 'page:p9' }));
    assert.equal(typed.value.value, 'page:p9');
});

test('test_page_search_is_a_keyboard_combobox', async () => {
    const { w, log, parts: f } = admin((doc) => redirectForm(doc));

    type(f.search, 'ab');
    await settle(260);
    assert.deepEqual(log.fetched, ['/folio/redirects/_targets?q=ab']);
    assert.equal(f.list.hidden, false);
    assert.equal(f.search.getAttribute('aria-expanded'), 'true');
    assert.deepEqual(options(f.list).map((o) => o.textContent), ['AboutPages · /about', 'About the newsNews · /news/about']);

    assert.equal(f.search.keydown('ArrowDown').defaultPrevented, true);
    assert.equal(f.search.getAttribute('aria-activedescendant'), options(f.list)[0].id);
    assert.equal(options(f.list)[0].getAttribute('aria-selected'), 'true');
    f.search.keydown('ArrowDown');
    assert.equal(f.search.getAttribute('aria-activedescendant'), options(f.list)[1].id);
    f.search.keydown('ArrowDown');
    assert.equal(f.search.getAttribute('aria-activedescendant'), options(f.list)[0].id, 'wraps round');
    f.search.keydown('ArrowUp');
    assert.equal(f.search.getAttribute('aria-activedescendant'), options(f.list)[1].id);

    const enter = f.search.keydown('Enter');
    assert.equal(enter.defaultPrevented, true, 'Enter picks, it never sends the form');
    assert.equal(f.value.value, 'news:n2', 'the type key and id, as the field posts it');
    assert.equal(f.picked.hidden, false);
    assert.equal(f.pickedLabel.textContent, 'About the news');
    assert.equal(f.pickedMeta.textContent, 'News · /news/about');
    assert.equal(f.list.hidden, true);
    assert.equal(f.search.getAttribute('aria-expanded'), 'false');
    assert.equal(f.search.value, '');

    // Escape closes the list (and only that), a second one clears the search.
    type(f.search, 'ab');
    await settle(260);
    assert.equal(f.list.hidden, false);
    assert.equal(f.search.keydown('Escape').defaultPrevented, true);
    assert.equal(f.list.hidden, true);
    assert.equal(f.search.value, 'ab');
    f.search.keydown('Escape');
    assert.equal(f.search.value, '');
    assert.equal(f.value.value, 'news:n2', 'the pick stays');

    // A click on an option picks it too.
    type(f.search, 'ab');
    await settle(260);
    options(f.list)[0].click();
    assert.equal(f.value.value, 'page:p1');
    assert.equal(f.pickedLabel.textContent, 'About');
    assert.equal(w.document.body.querySelector('dialog[data-removal-dialog]'), null);
});

test('test_drawer_links_open_the_drawer_and_a_save_reloads_the_list', () => {
    const { w, log } = admin();
    const doc = w.document;
    const link = doc.body.appendChild(doc.createElement('a'));
    link.setAttribute('href', '/folio/redirects/new?not_found=abc');
    link.setAttribute('data-folio-drawer', '');

    assert.equal(link.click({ metaKey: true }).defaultPrevented, false, 'a modified click opens the page itself');
    assert.equal(doc.body.querySelector('.folio-drawer'), null);

    assert.equal(link.click().defaultPrevented, true);
    assert.equal(doc.body.querySelector('.folio-drawer iframe').src, '/folio/redirects/new?not_found=abc&_drawer=1');

    // Cancel in the drawer closes it; nothing reloads.
    w.receive({ source: 'folio-drawer-cancel' });
    assert.equal(doc.body.querySelector('.folio-drawer'), null);
    assert.equal(log.reloaded, 0);

    const edit = doc.body.appendChild(doc.createElement('a'));
    edit.setAttribute('href', '/folio/redirects/r1');
    edit.setAttribute('data-folio-drawer', '');
    edit.click();
    assert.equal(doc.body.querySelector('.folio-drawer iframe').src, '/folio/redirects/r1?_drawer=1');
    w.receive({ source: 'folio-drawer', type: 'redirect', id: '' });
    assert.equal(doc.body.querySelector('.folio-drawer'), null);
    assert.equal(log.reloaded, 1, 'the list shows the saved redirect');

    // Another drawer's save (Edit alt text) is not this list's business.
    edit.click();
    w.receive({ source: 'folio-drawer', type: 'asset-alt', id: '' });
    assert.equal(log.reloaded, 1);
});

test('test_drawer_cancel_inside_the_drawer_tells_the_page', () => {
    const { w } = admin();
    const doc = w.document;
    const sent = [];
    w.parent = { postMessage: (data, origin) => sent.push([data, origin]) };
    const cancel = doc.body.appendChild(doc.createElement('a'));
    cancel.setAttribute('href', '/folio/redirects');
    cancel.setAttribute('data-folio-drawer-cancel', '');

    assert.equal(cancel.click().defaultPrevented, true);
    assert.equal(JSON.stringify(sent), JSON.stringify([[{ source: 'folio-drawer-cancel' }, w.location.origin]]));
});

test('test_delete_confirm_names_the_address', async () => {
    // An icon on the page tells the script where the sprite is (as the layouts' icons do).
    const { w } = admin((doc) => { doc.body.appendChild(doc.createElement('svg')).appendChild(doc.createElement('use')).setAttribute('href', '/assets/icons.svg#ui-x'); });
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    form.setAttribute('method', 'post');
    form.setAttribute('data-confirm', 'Delete this redirect?');
    form.setAttribute('data-confirm-item', '/docs/packages/blade');
    form.setAttribute('data-confirm-cancel', 'Keep');
    form.setAttribute('data-confirm-label', 'Delete');
    form.setAttribute('data-confirm-tone', 'danger');
    form.setAttribute('data-confirm-icon', 'ui-trash');
    const event = new w.Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);

    const d = doc.body.querySelector('dialog[data-folio-dialog]');
    const part = (name) => d.querySelector('[data-folio-dialog-' + name + ']');
    assert.equal(part('title').textContent, 'Delete this redirect?');
    assert.deepEqual(part('items').querySelectorAll('li').map((li) => li.textContent), ['/docs/packages/blade']);
    assert.equal(part('items').classList.contains('is-mono'), true, 'the address in mono');
    assert.equal(part('cancel').textContent, 'Keep');
    assert.equal(part('confirm').textContent, 'Delete');
    assert.equal(part('confirm').className, 'btn btn-danger');
    assert.match(d.querySelector('[data-folio-dialog-icon] use').getAttribute('href'), /#ui-trash$/);

    part('confirm').click();
    await settle();
    assert.deepEqual(form.sent, [null]);
});
