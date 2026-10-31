// window.Folio.chooseAssets (assets/admin.js): the asset picker in the drawer for extension scripts,
// and the asset fields that use it. Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const ID = 'a'.repeat(32);
const ID2 = '0123456789abcdef0123456789abcdef';

function admin() {
    return load(createWindow({ body: { 'data-folio-prefix': '/folio' } }), ADMIN_JS);
}

const drawer = (w) => w.document.body.querySelector('.folio-drawer');
const frameSrc = (w) => drawer(w).querySelector('iframe').src;

test('chooseAssets opens the library picker in the drawer', () => {
    const w = admin();
    assert.equal(typeof w.Folio.chooseAssets, 'function');

    w.Folio.chooseAssets({}, () => {});
    assert.equal(frameSrc(w), '/folio/assets?picker=1&multiple=0', 'single, public and private alike by default');

    w.Folio.chooseAssets({ multiple: true, private: true }, () => {});
    assert.equal(frameSrc(w), '/folio/assets?picker=1&multiple=1&private=1');
    assert.equal(w.document.body.querySelectorAll('.folio-drawer').length, 1, 'one drawer at a time');

    w.Folio.chooseAssets({ private: false }, () => {});
    assert.equal(frameSrc(w), '/folio/assets?picker=1&multiple=0&private=0');

    w.Folio.chooseAssets({ url: '/admin/assets?picker=1&multiple=0' }, () => {});
    assert.equal(frameSrc(w), '/admin/assets?picker=1&multiple=0', 'options.url wins');
});

test('a pick calls back with the valid items and closes the drawer in single mode', () => {
    const w = admin();
    const calls = [];
    w.Folio.chooseAssets({}, (items) => calls.push(items));

    w.receive({ source: 'folio-assets', items: [{ id: ID, thumb: '/media/t.webp', label: 'Box' }] }, 'https://evil.test');
    assert.deepEqual(calls, [], 'another origin is ignored');
    assert.ok(drawer(w));

    w.receive({ source: 'folio-assets', items: [
        { id: '../x', thumb: '/t', label: 'Bad' },
        { id: ID, thumb: '/media/t.webp', label: 'Box' },
        { id: 42 },
    ] });
    assert.equal(calls.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0])), [{ id: ID, thumb: '/media/t.webp', label: 'Box' }]);
    assert.equal(drawer(w), null, 'single mode closes the drawer');

    w.receive({ source: 'folio-assets', items: [{ id: ID2 }] });
    assert.equal(calls.length, 1, 'nothing listens once the picker is closed');
});

test('multiple mode keeps the drawer open and calls back per pick', () => {
    const w = admin();
    const calls = [];
    w.Folio.chooseAssets({ multiple: true }, (items) => calls.push(items.map((i) => i.id)));

    w.receive({ source: 'folio-assets', multiple: true, items: [{ id: ID }] });
    w.receive({ source: 'folio-assets', multiple: true, items: [{ id: ID2, thumb: '', label: '' }] });
    w.receive({ source: 'folio-assets', multiple: true, items: [{ id: 'nope' }] });

    assert.deepEqual(JSON.parse(JSON.stringify(calls)), [[ID], [ID2]], 'no call without a valid item');
    assert.ok(drawer(w), 'stays open');

    drawer(w).querySelector('.folio-drawer-close').click();
    assert.equal(drawer(w), null);
    w.receive({ source: 'folio-assets', items: [{ id: ID }] });
    assert.equal(calls.length, 2, 'closing the drawer ends the pick');
});

test('asset fields still fill their selection from the picker', () => {
    const w = admin();
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    const field = form.appendChild(doc.createElement('div'));
    field.setAttribute('data-folio-asset-field', '');
    field.setAttribute('data-selection-name', 'hero_ids[]');
    field.setAttribute('data-multiple', '0');
    field.setAttribute('data-picker-url', '/folio/assets?picker=1&multiple=0&private=0');
    const list = field.appendChild(doc.createElement('ul'));
    list.className = 'folio-asset-selection';
    const choose = field.appendChild(doc.createElement('button'));
    choose.setAttribute('data-folio-asset-choose', '');
    let changes = 0;
    form.addEventListener('change', () => { changes++; });

    choose.click();
    assert.equal(frameSrc(w), '/folio/assets?picker=1&multiple=0&private=0', "the field's own picker URL");

    w.receive({ source: 'folio-assets', items: [{ id: 'bad' }, { id: ID, thumb: '/media/t.webp', label: 'Box' }] });
    const inputs = list.querySelectorAll('input');
    assert.equal(inputs.length, 1);
    assert.equal(inputs[0].name, 'hero_ids[]');
    assert.equal(inputs[0].value, ID);
    assert.equal(list.querySelector('img').src, '/media/t.webp');
    assert.equal(changes, 1, 'autosave and the unsaved guard hear about it');
    assert.equal(drawer(w), null);

    choose.click();
    w.receive({ source: 'folio-assets', items: [{ id: ID2, thumb: '', label: 'Rules' }] });
    assert.deepEqual(list.querySelectorAll('input').map((i) => i.value), [ID2], 'single replaces');
    assert.equal(list.querySelector('span').textContent, 'Rules', 'no thumbnail: the label');

    list.querySelector('[data-folio-asset-remove]').click();
    assert.equal(list.querySelectorAll('input').length, 0, 'Remove still works');
});
