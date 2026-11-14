// Settings → Translations (templates/admin/translations/index.twig, assets/admin.js initTranslations, 0.76):
// each row's text is an inline field; with script the per-row Save buttons give way to the sticky bar
// "N unsaved changes · in English · Discard · Save all", whose form posts every changed field (also rows
// that are hidden) in one request. Discard puts every field back. Leaving with changes goes through
// Folio.unsaved: the styled dialog for in-admin links and forms, the browser's own question only for a
// tab close, and not both. Review Focus 5 (no change is lost).
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

const settle = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

function el(w, parent, tag, attrs = {}, text = '') {
    const node = parent.appendChild(w.document.createElement(tag));
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'class') { node.className = v; } else if (k === 'type' || k === 'name' || k === 'value') { node[k] = v; node.setAttribute(k, v); } else { node.setAttribute(k, v); }
    });
    if (text) { node.textContent = text; }
    return node;
}

/**
 * The screen as index.twig renders it: the toolbar (a GET form, the language links, "Changed only"),
 * the save-all bar (hidden), and the table with one row form per key. rows: [key, saved override].
 * refused: {key: posted text} as after a 422 (the field holds the refused text, data-saved the saved one).
 */
function screen({ rows = [['folio.account.change_password', ''], ['folio.account.password_changed', 'Signed out elsewhere'], ['folio.account.repeat', '']], refused = {}, lang = null, intl = null } = {}) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    if (lang) { w.document.documentElement.setAttribute('lang', lang); }
    if (intl) { w.Intl = intl; }
    w.location.href = 'https://site.test/folio/translations?group=folio&locale=en';
    w.confirm = () => { throw new Error('window.confirm called'); };
    const doc = w.document;
    const nav = el(w, doc.body, 'nav');
    const dashboard = el(w, nav, 'a', { href: '/folio' }, 'Dashboard');
    const toolbar = el(w, doc.body, 'div', { class: 'folio-toolbar' });
    const filter = el(w, toolbar, 'form', { method: 'get', action: '/folio/translations', role: 'search', 'data-translations-filter': '' });
    const group = el(w, filter, 'select', { name: 'group' });
    const search = el(w, filter, 'input', { type: 'search', name: 'q' });
    const apply = el(w, filter, 'button', { type: 'submit', 'data-translations-apply': '' }, 'Search');
    const german = el(w, toolbar, 'a', { href: '/folio/translations?group=folio&locale=de' }, 'DE');
    const changedOnly = el(w, toolbar, 'a', { href: '/folio/translations?group=folio&locale=en&changed=1' }, 'Changed only · 1');

    const bar = el(w, doc.body, 'form', { method: 'post', action: '/folio/translations/save-all', class: 'folio-bulk-bar folio-translations-save-all', 'data-translations-save-all': '' });
    bar.hidden = Object.keys(refused).length === 0;
    el(w, bar, 'input', { type: 'hidden', name: 'locale', value: 'en' });
    const count = el(w, bar, 'strong', { 'data-translations-count': '' });
    const discard = el(w, bar, 'button', { type: 'button', 'data-translations-discard': '' }, 'Discard');
    discard.hidden = true;
    const saveAll = el(w, bar, 'button', { type: 'submit', 'data-folio-save': '' }, 'Save all');

    const alert = Object.keys(refused).length ? el(w, doc.body, 'p', { class: 'folio-help folio-translations-refused', role: 'alert', 'data-translations-refused': '' }, '1 text was not saved') : null;
    const table = el(w, doc.body, 'table', { class: 'folio-table folio-translations' });
    const tbody = el(w, table, 'tbody');
    const fields = {}, rowSaves = {}, rowForms = {}, resets = {}, trs = {};
    rows.forEach(([key, saved], i) => {
        const tr = el(w, tbody, 'tr', saved !== '' ? { class: 'is-changed' } : {});
        const td = el(w, tr, 'td');
        const form = el(w, td, 'form', { method: 'post', action: '/folio/translations', class: 'folio-translations-row' });
        el(w, form, 'input', { type: 'hidden', name: 'key', value: key });
        const text = key in refused ? refused[key] : saved;
        const attrs = { type: 'text', name: 'text', 'data-ai-path': key, value: text };
        if (key in refused) { attrs['data-saved'] = saved; attrs['data-unsaved'] = ''; }
        const input = el(w, form, 'input', attrs);
        input.defaultValue = text;
        if (key in refused) { el(w, form, 'div', { class: 'form-error' }, 'Plain text only'); }
        rowSaves[key] = el(w, form, 'button', { type: 'submit', 'data-translations-row-save': '' }, 'Save');
        if (saved !== '') {
            const resetForm = el(w, td, 'form', { method: 'post', action: '/folio/translations/reset', id: 'folio-translation-reset-' + i });
            el(w, resetForm, 'input', { type: 'hidden', name: 'key', value: key });
            resets[key] = { form: resetForm, button: el(w, form, 'button', { type: 'submit', form: 'folio-translation-reset-' + i }, '') };
        }
        fields[key] = input; rowForms[key] = form; trs[key] = tr;
    });
    load(w, ADMIN_JS);
    return { w, doc, alert, dashboard, filter, group, search, apply, german, changedOnly, bar, count, discard, saveAll, table, fields, rowSaves, rowForms, resets, trs };
}

function type(w, input, value) {
    input.value = value;
    input.dispatchEvent(new w.Event('input', { bubbles: true }));
}

function submitBy(w, f, submitter = null) {
    const event = new w.Event('submit', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'submitter', { value: submitter });
    f.dispatchEvent(event);
    return event;
}

/** The pairs the save-all form posts: [key, text] from its keys[] / texts[] fields, in order. */
function posted(bar) {
    const keys = bar.querySelectorAll('input[name="keys[]"]').map((i) => i.value);
    const texts = bar.querySelectorAll('input[name="texts[]"]').map((i) => i.value);
    return keys.map((k, i) => [k, texts[i]]);
}

/** Whether leaving now would ask the browser's own question. */
function asksToLeave(w) {
    const e = { type: 'beforeunload', defaultPrevented: false, returnValue: undefined, preventDefault() { this.defaultPrevented = true; } };
    (w.listeners.beforeunload || []).forEach((fn) => fn(e));
    return e.defaultPrevented;
}

const theDialog = (w) => w.document.body.querySelector('dialog[data-folio-dialog]');
const part = (w, name) => theDialog(w).querySelector('[data-folio-dialog-' + name + ']');
const isOpen = (w) => !!theDialog(w) && theDialog(w).open === true;

const A = 'folio.account.change_password';
const B = 'folio.account.password_changed';
const C = 'folio.account.repeat';

test('with script the per-row Save buttons give way to the bar, which stays hidden until a text changes', () => {
    const p = screen();
    Object.values(p.rowSaves).forEach((b) => assert.equal(b.hidden, true));
    assert.equal(p.bar.hidden, true);
    assert.equal(p.discard.hidden, false, 'Discard needs script, so it shows with it');
    assert.equal(p.apply.hidden, true, 'the group select applies itself; Enter in the search sends it');
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
    assert.equal(asksToLeave(p.w), false);
});

test('a typed change shows the bar with the count, marks the row and counts as unsaved', () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    assert.equal(p.bar.hidden, false);
    assert.equal(p.count.textContent, '1 unsaved change');
    assert.ok(p.trs[A].classList.contains('is-edited'));
    assert.ok(!p.trs[C].classList.contains('is-edited'));
    assert.equal(p.w.Folio.unsaved.isDirty(p.bar), true);
    const dirty = p.w.Folio.unsaved.dirtyForms();
    assert.equal(dirty.length, 1);
    assert.equal(dirty[0], p.bar);

    type(p.w, p.fields[C], 'Repeat it');
    assert.equal(p.count.textContent, '2 unsaved changes');

    type(p.w, p.fields[A], ''); // back to what it was
    type(p.w, p.fields[C], '');
    assert.equal(p.bar.hidden, true);
    assert.ok(!p.trs[A].classList.contains('is-edited'));
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
});

test('Save all posts every changed field, rows hidden by a filter included, and nothing unchanged', () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    type(p.w, p.fields[B], 'Signed out everywhere else');
    p.trs[B].hidden = true; // a row out of view (filtered away) keeps its change
    p.trs[A].setAttribute('hidden', '');
    const event = submitBy(p.w, p.bar, p.saveAll);
    assert.equal(event.defaultPrevented, false);
    assert.deepEqual(posted(p.bar), [[A, 'Change your password'], [B, 'Signed out everywhere else']]);
    assert.equal(isOpen(p.w), false, 'saving is no leave');
    assert.equal(asksToLeave(p.w), false, 'the save\'s own navigation is not asked about');

    // A second submit (the first one refused by the browser) posts the pairs once.
    submitBy(p.w, p.bar, p.saveAll);
    assert.equal(posted(p.bar).length, 2);
});

test('an AI fill (input and change events) counts like typing', () => {
    const p = screen();
    p.fields[C].value = 'Répétez';
    p.fields[C].dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal(p.bar.hidden, false);
    assert.equal(p.count.textContent, '1 unsaved change');
});

test('Discard puts every changed field back, hidden rows included, and the bar goes', () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    type(p.w, p.fields[B], 'Other');
    p.trs[B].hidden = true;
    p.discard.click();
    assert.equal(p.fields[A].value, '');
    assert.equal(p.fields[B].value, 'Signed out elsewhere');
    assert.equal(p.bar.hidden, true);
    assert.ok(!p.trs[A].classList.contains('is-edited'));
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
    assert.equal(asksToLeave(p.w), false);
});

test('after refused texts the bar shows at once; Discard brings back the saved text and drops the reason', () => {
    const p = screen({ refused: { [B]: 'Signed <b>out</b>' } });
    assert.equal(p.bar.hidden, false);
    assert.equal(p.count.textContent, '1 unsaved change');
    assert.equal(p.w.Folio.unsaved.isDirty(p.bar), true, 'the refused text is not saved');
    assert.ok(p.trs[B].classList.contains('is-edited'));

    assert.ok(p.alert.isConnected, 'the refusal is said above the table');
    p.discard.click();
    assert.equal(p.alert.isConnected, false, '"N texts were not saved" goes with the refused texts');
    assert.equal(p.fields[B].value, 'Signed out elsewhere', 'the saved text');
    assert.equal(p.fields[B].hasAttribute('data-unsaved'), false);
    assert.equal(p.rowForms[B].querySelector('.form-error'), null);
    assert.equal(p.bar.hidden, true);
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
});

test('Enter in a row\'s field saves all, not just that row', () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    type(p.w, p.fields[C], 'Repeat it');
    const key = p.fields[A].keydown('Enter');
    assert.equal(key.defaultPrevented, true, 'the row form is not sent');
    assert.deepEqual(p.bar.sent, [p.saveAll]);
    assert.deepEqual(posted(p.bar), [[A, 'Change your password'], [C, 'Repeat it']]);
});

test('an in-admin link with changes opens the styled dialog, not the browser\'s question', async () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    const click = p.dashboard.click();
    assert.equal(click.defaultPrevented, true);
    assert.equal(isOpen(p.w), true);
    assert.equal(part(p.w, 'title').textContent, 'Leave without saving?');
    assert.equal(part(p.w, 'extra').hidden, false, '"Save and leave": Save all saves');
    part(p.w, 'cancel').click();
    await settle();
    assert.equal(p.w.location.href, 'https://site.test/folio/translations?group=folio&locale=en', 'stayed');
    assert.equal(p.fields[A].value, 'Change your password');
    assert.equal(asksToLeave(p.w), true, 'closing the tab still asks');
});

test('"Leave without saving" leaves, and the browser\'s own question is not asked as well', async () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    p.german.click(); // the language switch is a link like any other
    part(p.w, 'confirm').click();
    await settle();
    assert.equal(p.w.location.href, '/folio/translations?group=folio&locale=de');
    assert.equal(asksToLeave(p.w), false, 'suppressed once');
    assert.equal(asksToLeave(p.w), true, 'only once');
});

test('"Save and leave" (Changed only) posts every change with the link as _folio_next', async () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    type(p.w, p.fields[C], 'Repeat it');
    p.changedOnly.click();
    part(p.w, 'extra').click();
    await settle();
    assert.deepEqual(p.bar.sent, [p.saveAll]);
    assert.deepEqual(posted(p.bar), [[A, 'Change your password'], [C, 'Repeat it']]);
    assert.equal(p.bar.querySelector('input[name="_folio_next"]').value, '/folio/translations?group=folio&locale=en&changed=1');
    assert.equal(asksToLeave(p.w), false);
});

test('the toolbar\'s search (a GET form) with changes asks first', () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    const event = submitBy(p.w, p.filter, null);
    assert.equal(event.defaultPrevented, true);
    assert.equal(isOpen(p.w), true);
});

test('the group select sends the toolbar form, through the same guard', () => {
    const clean = screen();
    clean.group.value = 'site';
    clean.group.dispatchEvent(new clean.w.Event('change', { bubbles: true }));
    assert.equal((clean.filter.sent || []).length, 1, 'clean: sent at once');

    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    p.group.value = 'site';
    p.group.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.equal((p.filter.sent || []).length, 0);
    assert.equal(isOpen(p.w), true);
    assert.equal(p.w.Folio.unsaved.isDirty(p.bar), true, 'a filter change is no edit');
});

test('Reset of the only changed row is no leave; with other changes it asks', () => {
    const p = screen();
    type(p.w, p.fields[B], 'Other');
    const own = submitBy(p.w, p.resets[B].form, p.resets[B].button);
    assert.equal(own.defaultPrevented, false, 'its own edit goes with the reset');
    assert.equal(isOpen(p.w), false);
    assert.equal(asksToLeave(p.w), false);

    const q = screen();
    type(q.w, q.fields[A], 'Change your password');
    const other = submitBy(q.w, q.resets[B].form, q.resets[B].button);
    assert.equal(other.defaultPrevented, true);
    assert.equal(isOpen(q.w), true);
});

test('a Reset the dialog held (Stay) does not later excuse a tab close', async () => {
    const p = screen();
    type(p.w, p.fields[A], 'Change your password');
    type(p.w, p.fields[B], 'Other');
    const held = submitBy(p.w, p.resets[B].form, p.resets[B].button);
    assert.equal(held.defaultPrevented, true);
    part(p.w, 'cancel').click(); // Stay
    await settle();
    type(p.w, p.fields[A], ''); // now only B's row holds a change
    assert.equal(p.w.Folio.unsaved.isDirty(p.bar), true);
    assert.equal(asksToLeave(p.w), true, 'the reset never went, so closing the tab still asks');
});

test('the count is written in the interface language\'s digits', () => {
    const intl = { NumberFormat: function (lang) { return { format: (n) => lang + '#' + n }; } };
    const p = screen({ lang: 'de', intl });
    type(p.w, p.fields[A], 'x');
    type(p.w, p.fields[C], 'y');
    assert.equal(p.count.textContent, 'de#2 unsaved changes');
});
