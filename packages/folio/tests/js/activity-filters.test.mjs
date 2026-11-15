// The All activity toolbar (templates/admin/activity.twig, assets/admin.js, 0.77): a GET form marked
// [data-folio-autosubmit] whose selects (type, person) submit it at once, so its Filter button hides;
// a select outside such a form is left alone. Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const ADMIN_JS = fileURLToPath(new URL('../../assets/admin.js', import.meta.url));

function page() {
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
    const form = el(doc.body, 'form', { class: 'folio-activity-toolbar', method: 'get', action: '/folio/activity', 'data-folio-autosubmit': '' });
    const type = el(form, 'select', { name: 'type' });
    const person = el(form, 'select', { name: 'by' });
    const button = el(form, 'button', { type: 'submit', 'data-folio-autosubmit-button': '' }, 'Filter');
    const other = el(doc.body, 'form', { method: 'get', action: '/folio/elsewhere' });
    const loose = el(other, 'select', { name: 'x' });
    const keep = el(other, 'button', { type: 'submit', 'data-folio-autosubmit-button': '' }, 'Go');
    load(w, ADMIN_JS);
    return { w, form, type, person, button, other, loose, keep };
}

test('a changed type or person submits the activity filters at once, without the Filter button', () => {
    const p = page();
    assert.equal(p.button.hidden, true, 'with script the button gives way');
    assert.notEqual(p.keep.hidden, true, 'a form without the marker keeps its button');

    const sent = [];
    p.form.requestSubmit = () => sent.push('activity');
    p.other.requestSubmit = () => sent.push('other');
    p.type.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    p.person.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    p.loose.dispatchEvent(new p.w.Event('change', { bubbles: true }));
    assert.deepEqual(sent, ['activity', 'activity']);
});

test('a dashboard part whose request fails leaves the row, so the content gets its width back', () => {
    const w = createWindow({ body: { 'data-folio-prefix': '/folio' } });
    const doc = w.document;
    const split = doc.body.appendChild(doc.createElement('div'));
    split.className = 'folio-dash-split';
    const content = split.appendChild(doc.createElement('section'));
    content.className = 'folio-dash-section folio-dash-content';
    const later = split.appendChild(doc.createElement('div'));
    later.className = 'folio-dash-later folio-dash-recent-later';
    const other = doc.body.appendChild(doc.createElement('form'));
    load(w, ADMIN_JS);

    other.dispatchEvent(new w.Event('htmx:responseError', { bubbles: true }));
    assert.equal(content.parentNode, split, 'another element\'s error changes nothing');
    assert.equal(later.parentNode, split);
    later.dispatchEvent(new w.Event('htmx:responseError', { bubbles: true }));
    assert.equal(later.parentNode, null, 'the placeholder is gone');
    assert.deepEqual(split.children, [content]);
});
