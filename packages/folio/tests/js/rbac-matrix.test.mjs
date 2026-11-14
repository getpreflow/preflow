// The role permissions matrix (assets/rbac/rbac.js, PermissionsFieldType, 0.76 board Roles). Review
// Focus 4: the matrix submits exactly the names the boxes show.
// - a row switch posts "{type}.*" as the old "All" box did and locks its row's boxes (not posted);
// - a column header toggles its verb within its section and sets the same permissions[] as clicking
//   each box would, never touching a delegation-denied box (it stays disabled) or a locked one;
// - "Only new" only hides rows, never drops their values;
// - "Allow everything" still sends "*" alone.
// Also the section sums, the unsaved count with Cancel next to Save, and Folio.unsaved.
// Run: node --test 'packages/folio/tests/js/*.test.mjs'
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));
const RBAC_JS = fileURLToPath(new URL('../../assets/rbac/rbac.js', import.meta.url));

function el(w, parent, tag, attrs = {}, text = '') {
    const node = parent.appendChild(w.document.createElement(tag));
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'class') { node.className = v; } else if (k === 'type' || k === 'name' || k === 'value') { node[k] = v; node.setAttribute(k, v); } else { node.setAttribute(k, v); }
    });
    if (text) { node.textContent = text; }
    return node;
}

const VERBS = ['view', 'create', 'edit', 'publish', 'delete'];

/**
 * The role editor as form.twig and PermissionsFieldType render it: the topbar's Save (form="folio-record"),
 * the record form with the permissions fieldset, a side panel field naming the form. sections:
 * {label: [[type, verbs it has, pattern?, new?]]}; selected: the stored names; denied: names the
 * editor can't grant; kept: hidden kept names.
 */
function screen({ sections, selected = [], denied = [], kept = [], admin = false, chips = [], saveable = true }) {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const topbar = el(w, doc.body, 'header', { class: 'folio-topbar' });
    const saveButtons = saveable ? el(w, topbar, 'div', { class: 'folio-save-buttons' }) : null;
    const save = saveable ? el(w, saveButtons, 'button', { type: 'submit', form: 'folio-record', 'data-folio-save': '' }, 'Save') : null;
    const main = el(w, doc.body, 'div', { class: 'folio-form folio-editor' });
    const form = el(w, main, 'form', { method: 'post', action: '/folio/rbac_role/r-editor', id: 'folio-record' });
    el(w, form, 'input', { type: 'hidden', name: '_csrf_token', value: 't' });
    const field = el(w, form, 'fieldset', { class: 'form-group rbac-permissions', 'data-rbac-permissions': '' });
    el(w, field, 'input', { type: 'hidden', name: 'permissions[]', value: '' });
    const changes = el(w, field, 'div', { class: 'rbac-changes', 'data-rbac-changes': '' });
    changes.hidden = true;
    const count = el(w, changes, 'span', { 'data-rbac-changes-count': '', 'data-text': ':count unsaved change|:count unsaved changes' });
    const cancel = el(w, changes, 'button', { type: 'button', 'data-rbac-cancel': '' }, 'Cancel');
    const box = (parent, value, attrs = {}) => {
        const input = el(w, parent, 'input', { type: 'checkbox', name: 'permissions[]', value, ...attrs });
        if (denied.includes(value)) { input.setAttribute('data-rbac-denied', ''); input.disabled = true; }
        input.checked = selected.includes(value);
        return input;
    };
    const label = el(w, field, 'label', { class: 'rbac-everything' });
    const everything = box(label, '*', { role: 'switch', 'data-rbac-everything': '' });
    const rest = el(w, field, 'div', { class: 'rbac-rest', 'data-rbac-rest': '' });
    kept.forEach((name) => el(w, rest, 'input', { type: 'hidden', name: 'permissions[]', value: name, 'data-rbac-kept': '' }));
    const filter = el(w, rest, 'button', { type: 'button', 'aria-pressed': 'false', 'data-rbac-fresh-filter': '', 'data-folio-no-edit': '' }, 'Only new');
    const wrap = el(w, rest, 'div', { class: 'folio-table-wrap' });
    const table = el(w, wrap, 'table', { class: 'folio-table rbac-matrix' });
    const rows = {}, columns = {}, sums = {}, bodies = {};
    Object.entries(sections).forEach(([section, types]) => {
        const body = bodies[section] = el(w, table, 'tbody', { 'data-rbac-section': '' });
        const head = el(w, body, 'tr', { class: 'rbac-section' });
        const th = el(w, head, 'th');
        sums[section] = el(w, th, 'span', { 'data-rbac-sum': '' }, '0');
        columns[section] = {};
        VERBS.forEach((verb) => { columns[section][verb] = el(w, el(w, head, 'th'), 'button', { type: 'button', 'data-rbac-column': verb }, verb); });
        types.forEach(([type, verbs, pattern, isNew]) => {
            const tr = el(w, body, 'tr', { class: 'rbac-row', 'data-rbac-row': '', ...(isNew ? { 'data-rbac-new': '' } : {}) });
            const cells = {};
            VERBS.forEach((verb) => {
                const td = el(w, tr, 'td');
                if (verbs.includes(verb)) { cells[verb] = box(td, type + '.' + verb, { class: 'rbac-box', 'data-rbac-verb': verb }); } else { el(w, td, 'span', { class: 'rbac-none' }, '–'); }
            });
            const all = pattern ? box(el(w, tr, 'td'), type + '.*', { class: 'folio-switch rbac-row-switch', role: 'switch', 'data-rbac-all': '' }) : null;
            rows[type] = { tr, cells, all };
        });
    });
    const others = el(w, rest, 'div', { 'data-rbac-others': '' });
    const group = el(w, others, 'div', { 'data-rbac-group': '' });
    const chipBoxes = {};
    chips.forEach(([name, isNew]) => {
        const chip = el(w, group, 'label', { class: 'rbac-chip', ...(isNew ? { 'data-rbac-new': '' } : {}) });
        chipBoxes[name] = { chip, box: box(chip, name) };
    });
    const side = el(w, doc.body, 'aside', { class: 'folio-side' });
    const roleLabel = el(w, side, 'input', { type: 'text', name: 'label', value: 'Editor', form: 'folio-record' });
    if (admin) { load(w, ADMIN_JS); }
    load(w, RBAC_JS);
    return { w, doc, form, field, save, saveButtons, topbar, changes, count, cancel, everything, filter, rows, columns, sums, bodies, chipBoxes, roleLabel };
}

/** A click on a box as the browser does it: it flips, then "change" bubbles. */
function click(w, input) {
    if (input.disabled) { return; }
    input.checked = !input.checked;
    input.dispatchEvent(new w.Event('change', { bubbles: true }));
}

/** What the browser posts as permissions[]: enabled controls of the form (inside it or naming it), checkboxes only when checked. */
function posted(p) {
    const inside = p.form.querySelectorAll('input');
    const named = p.doc.querySelectorAll('[form="folio-record"]').filter((c) => c.tagName === 'INPUT' && !inside.includes(c));
    return [...inside, ...named]
        .filter((c) => c.name === 'permissions[]' && !c.disabled && (c.type !== 'checkbox' || c.checked))
        .map((c) => c.value);
}

const CONTENT = {
    Pages: [['page', VERBS, true]],
    Content: [['post', ['view', 'create', 'edit', 'delete'], true], ['job', VERBS, true, true]],
};

test('a row switch posts "{type}.*" and locks its row; off again, the boxes are their own', () => {
    const p = screen({ sections: CONTENT, selected: ['page.view'] });
    const row = p.rows.page;
    click(p.w, row.all);
    assert.deepEqual(posted(p), ['', 'page.*']);
    assert.ok(VERBS.every((verb) => row.cells[verb].checked && row.cells[verb].disabled), 'shown granted, locked');
    assert.equal(p.sums.Pages.textContent, '5');

    click(p.w, row.all);
    assert.deepEqual(posted(p), ['', 'page.view']);
    assert.ok(VERBS.every((verb) => !row.cells[verb].disabled));
    assert.equal(p.sums.Pages.textContent, '1');
});

test('a column header sets exactly what clicking each of its boxes sets, within its section only', () => {
    const byColumn = screen({ sections: CONTENT, selected: ['post.view'] });
    byColumn.columns.Content.view.click();
    const byHand = screen({ sections: CONTENT, selected: ['post.view'] });
    click(byHand.w, byHand.rows.job.cells.view);
    assert.deepEqual(posted(byColumn), posted(byHand));
    assert.deepEqual(posted(byColumn), ['', 'post.view', 'job.view']);
    assert.equal(byColumn.rows.page.cells.view.checked, false, 'another section is left alone');
    assert.equal(byColumn.sums.Content.textContent, '2');

    // All on: the header turns the column off again.
    byColumn.columns.Content.view.click();
    assert.deepEqual(posted(byColumn), ['']);
    assert.equal(byColumn.sums.Content.textContent, '0');

    // A verb a type lacks ("–") is simply not in the column.
    byColumn.columns.Content.publish.click();
    assert.deepEqual(posted(byColumn), ['', 'job.publish']);
});

test('column toggles never change a denied box or a locked one', () => {
    const p = screen({ sections: CONTENT, selected: ['job.*'], denied: ['post.edit', 'post.*'] });
    p.columns.Content.edit.click();
    const { cells } = p.rows.post;
    assert.equal(cells.edit.checked, false);
    assert.equal(cells.edit.disabled, true, 'denied stays disabled');
    assert.equal(p.rows.post.all.disabled, true);
    assert.deepEqual(posted(p), ['', 'job.*'], 'job is locked by its switch: nothing to toggle there, post.edit is denied');

    // A denied box that is checked (granted by someone else) is never switched off by a column either.
    const q = screen({ sections: CONTENT, selected: ['post.edit'], denied: ['post.edit'] });
    q.columns.Content.edit.click();
    assert.equal(q.rows.post.cells.edit.checked, true);
    assert.equal(q.rows.job.cells.edit.checked, true);
    q.columns.Content.edit.click();
    assert.equal(q.rows.post.cells.edit.checked, true, 'still on, still disabled');
    assert.equal(q.rows.job.cells.edit.checked, false);
});

test('"Only new" hides the other rows and chips but every value still posts', () => {
    const p = screen({ sections: CONTENT, selected: ['page.view', 'post.edit', 'job.view', 'folio.cache'], chips: [['folio.cache', false], ['shop.orders', true]] });
    const before = posted(p);
    p.filter.click();
    assert.equal(p.filter.getAttribute('aria-pressed'), 'true');
    assert.equal(p.rows.job.tr.hidden, false);
    assert.equal(p.rows.page.tr.hidden, true);
    assert.equal(p.rows.post.tr.hidden, true);
    assert.equal(p.bodies.Pages.hidden, true, 'a section without a new row hides');
    assert.equal(p.bodies.Content.hidden, false);
    assert.equal(p.chipBoxes['folio.cache'].chip.hidden, true);
    assert.equal(p.chipBoxes['shop.orders'].chip.hidden, false);
    assert.deepEqual(posted(p), before);

    p.filter.click();
    assert.equal(p.filter.getAttribute('aria-pressed'), 'false');
    assert.equal(p.rows.page.tr.hidden, false);
    assert.equal(p.bodies.Pages.hidden, false);
    assert.deepEqual(posted(p), before);
});

test('"Allow everything" sends "*" alone, kept names included; off again, the rest is back', () => {
    const p = screen({ sections: CONTENT, selected: ['page.*', 'post.view'], kept: ['page.publish'] });
    click(p.w, p.everything);
    assert.deepEqual(posted(p), ['', '*']);
    p.columns.Content.edit.click();
    assert.deepEqual(posted(p), ['', '*'], 'a column cannot change what Everything locks');
    assert.equal(p.sums.Content.textContent, '9');
    click(p.w, p.everything);
    assert.deepEqual(posted(p), ['', 'page.publish', 'page.*', 'post.view']);
});

test('the unsaved count sits next to Save; Cancel puts every control back', () => {
    const p = screen({ sections: CONTENT, selected: ['post.view'] });
    assert.equal(p.changes.parentNode, p.topbar, 'moved before the save buttons');
    assert.equal(p.topbar.children.indexOf(p.changes), p.topbar.children.indexOf(p.saveButtons) - 1);
    assert.equal(p.changes.hidden, true);

    click(p.w, p.rows.job.cells.view);
    assert.equal(p.changes.hidden, false);
    assert.equal(p.count.textContent, '1 unsaved change');
    click(p.w, p.rows.page.all); // the switch is one change; its locked boxes are not
    p.roleLabel.value = 'Editors';
    p.roleLabel.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    assert.equal(p.count.textContent, '3 unsaved changes');
    click(p.w, p.rows.job.cells.view);
    assert.equal(p.count.textContent, '2 unsaved changes');

    p.cancel.click();
    assert.equal(p.changes.hidden, true);
    assert.equal(p.roleLabel.value, 'Editor');
    assert.equal(p.rows.page.all.checked, false);
    assert.ok(VERBS.every((verb) => !p.rows.page.cells[verb].disabled && !p.rows.page.cells[verb].checked));
    assert.deepEqual(posted(p), ['', 'post.view']);
});

test('the roles form is under Folio.unsaved: a column toggle marks it, Cancel clears it, Save clears it', () => {
    const p = screen({ sections: CONTENT, selected: [], admin: true });
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), false);
    p.columns.Content.view.click();
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true, 'a toggle announces its changes');
    p.cancel.click();
    assert.equal(p.w.Folio.unsaved.isDirty(), false);

    click(p.w, p.rows.page.all);
    assert.equal(p.w.Folio.unsaved.isDirty(p.form), true);
    p.form.requestSubmit(p.save);
    assert.equal(p.w.Folio.unsaved.isDirty(), false);
});

test('without a Save button (read-only) the count stays in the field, hidden', () => {
    const p = screen({ sections: CONTENT, selected: [], saveable: false });
    assert.equal(p.changes.parentNode, p.field);
    click(p.w, p.rows.job.cells.view);
    assert.equal(p.changes.hidden, true);
});
