// Address fields (assets/admin.js): the inputs a page's public address is built from turn what the editor
// typed into lowercase a-z, digits and hyphens ("Über uns" → "ueber-uns") when the value changes and before
// the form is sent. Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

/** An edit form with one address field (mode "segment" or "path") and one ordinary text field. */
function editor(mode = 'segment') {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const form = doc.body.appendChild(doc.createElement('form'));
    const wrap = form.appendChild(doc.createElement('div'));
    wrap.setAttribute('data-folio-address', mode);
    const slug = wrap.appendChild(doc.createElement('input'));
    slug.type = 'text';
    const title = form.appendChild(doc.createElement('input'));
    title.type = 'text';
    load(w, ADMIN_JS);
    return { w, form, slug, title };
}

function change(w, input, value) {
    input.value = value;
    input.dispatchEvent(new w.Event('change', { bubbles: true }));
}

test('umlauts, capitals, spaces and accents become a clean address', () => {
    const { w, slug } = editor();
    const cases = {
        'Über uns': 'ueber-uns',
        'Straße & Plätze': 'strasse-plaetze',
        'ÖKO Häuser': 'oeko-haeuser',
        'Café crème': 'cafe-creme',
        '  --Top 10 Spiele!!  ': 'top-10-spiele',
        'a_b.c': 'a-b-c',
        'already-clean': 'already-clean',
        'a/b': 'a-b',
    };
    for (const [typed, expected] of Object.entries(cases)) {
        change(w, slug, typed);
        assert.equal(slug.value, expected, typed);
    }
});

test('a catch-all address keeps single slashes between parts', () => {
    const { w, slug } = editor('path');
    change(w, slug, '/Doku//Erste Schritte/');
    assert.equal(slug.value, 'doku/erste-schritte');
});

test('other fields are left as typed', () => {
    const { w, title } = editor();
    change(w, title, 'Über uns');
    assert.equal(title.value, 'Über uns');
});

test('sending the form cleans an address the editor never left', () => {
    const { w, form, slug } = editor();
    slug.value = 'Über uns';
    form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(slug.value, 'ueber-uns');
});

test('an empty address stays empty', () => {
    const { w, slug } = editor();
    change(w, slug, '');
    assert.equal(slug.value, '');
});

test('text with no a-z form is left as typed, for the server to explain', () => {
    const { w, slug } = editor();
    change(w, slug, 'Привет');
    assert.equal(slug.value, 'Привет');
});

test('a decomposed umlaut (u + U+0308, as pasted from some files) still becomes ue', () => {
    const { w, slug } = editor();
    change(w, slug, 'U\u0308ber');
    assert.equal(slug.value, 'ueber');
});

test('a textarea address field is cleaned too', () => {
    const { w, form } = editor();
    const wrap = form.appendChild(w.document.createElement('div'));
    wrap.setAttribute('data-folio-address', 'segment');
    const area = wrap.appendChild(w.document.createElement('textarea'));
    change(w, area, 'Über uns');
    assert.equal(area.value, 'ueber-uns');
});
