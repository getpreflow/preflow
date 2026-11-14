// The scripts' words: admin.js's folioT reads #folio-i18n (FolioServiceProvider::jsTexts) and fills
// :params. A key it does not know gives '' and never the raw key; JsTextKeysTest checks that every key
// the scripts use exists in en, de and fr.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

function admin(texts) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const script = w.document.body.appendChild(w.document.createElement('script'));
    script.setAttribute('type', 'application/json');
    script.setAttribute('id', 'folio-i18n');
    script.textContent = JSON.stringify(texts);
    load(w, ADMIN_JS);
    return w;
}

test('folioT gives the text in the interface language with its params filled', () => {
    const w = admin({ close: 'Schliessen', removed: '„:label“ entfernt' });
    assert.equal(w.folioT('close'), 'Schliessen');
    assert.equal(w.folioT('removed', { label: 'Hero' }), '„Hero“ entfernt');
});

test('a key folioT does not know gives an empty text, never the raw key', () => {
    const w = admin({ close: 'Close' });
    assert.equal(w.folioT('no_such_key'), '');
    assert.equal(w.folioT('fields.no_such_key', { label: 'x' }), '');
});
