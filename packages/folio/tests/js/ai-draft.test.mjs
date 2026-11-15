// "Write a draft" in rich text (assets/ai-draft-core.js and ai-draft.js, 0.77): a button at the right
// of every Trix toolbar, also of an editor initialised later (a block card), never on a read-only form;
// the dialog posts the brief and shows the sanitised answer, copied element by element (never parsed
// into the page as markup), with the cost line. Insert at cursor puts it where the caret was when the
// dialog opened, Replace text over the whole text, both through Trix's editor API (its own parser, one
// undo step). Try again writes anew; Cancel, Escape and a failure leave the field unchanged.
// Run: node --test packages/folio/tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { createWindow, load } from './fake-dom.mjs';

const require = createRequire(import.meta.url);
const core = require('../../assets/ai-draft-core.js');
const CORE_JS = fileURLToPath(new URL('../../assets/ai-draft-core.js', import.meta.url));
const DRAFT_JS = fileURLToPath(new URL('../../assets/ai-draft.js', import.meta.url));

/** A Trix editor object (element.editor) that records what is done to it. */
function trixEditor(text = 'Hello world') {
    const calls = [];
    return {
        calls,
        range: [5, 5],
        getSelectedRange() { return this.range.slice(); },
        setSelectedRange(r) { calls.push(['setSelectedRange', r]); this.range = r; },
        getDocument() { return { getLength: () => text.length + 1, toString: () => text + '\n' }; },
        insertLineBreak() { calls.push(['insertLineBreak']); },
        insertHTML(html) { calls.push(['insertHTML', html]); },
        recordUndoEntry(name) { calls.push(['recordUndoEntry', name]); },
    };
}

test('apply inserts at the range the caret had, as one undo step', () => {
    const editor = trixEditor();
    assert.equal(core.apply(editor, '<p>Draft</p>', 'insert', [3, 3]), 'insert');
    assert.deepEqual(editor.calls, [['recordUndoEntry', 'Write a draft'], ['setSelectedRange', [3, 3]], ['insertLineBreak'], ['insertHTML', '<p>Draft</p>']]);
});

test('apply inserting inside a line starts a new block first, so the draft does not run into the text', () => {
    const editor = trixEditor('Hello world\nNext');
    core.apply(editor, '<h2>Draft</h2>', 'insert', [11, 11]);
    assert.deepEqual(editor.calls, [['recordUndoEntry', 'Write a draft'], ['setSelectedRange', [11, 11]], ['insertLineBreak'], ['insertHTML', '<h2>Draft</h2>']]);

    const atStart = trixEditor('Hello world\nNext');
    core.apply(atStart, '<p>Draft</p>', 'insert', [12, 12]);
    assert.deepEqual(atStart.calls.map((c) => c[0]), ['recordUndoEntry', 'setSelectedRange', 'insertHTML'], 'at the start of a line: none');
});

test('apply replaces the whole text', () => {
    const editor = trixEditor('Hello world');
    assert.equal(core.apply(editor, '<p>Draft</p>', 'replace', [3, 3]), 'replace');
    assert.deepEqual(editor.calls, [['recordUndoEntry', 'Write a draft'], ['setSelectedRange', [0, 12]], ['insertHTML', '<p>Draft</p>']]);
});

test('apply drops the line breaks between blocks, which Trix would keep as empty lines', () => {
    const editor = trixEditor();
    core.apply(editor, "<h2>Hours</h2>\n<p>We open at <strong>nine</strong> <em>sharp</em>.</p>\n\n<ul>\n  <li>One</li>\n  <li>Two</li>\n</ul>\n", 'insert', [0, 0]);
    assert.deepEqual(editor.calls.at(-1), ['insertHTML', '<h2>Hours</h2><p>We open at <strong>nine</strong> <em>sharp</em>.</p><ul><li>One</li><li>Two</li></ul>']);
});

test('params trim the brief and take the field label without its star', () => {
    assert.deepEqual(core.params({ type: 'article', field: ' Body  * ', brief: '  Hours \n', length: 'short', language: 'de', title: 'Hallo' }),
        { type: 'article', id: '', new: '0', field: 'Body', brief: 'Hours', length: 'short', language: 'de', title: 'Hallo' });
    assert.deepEqual({ ...core.params({ id: '', isNew: true }) }.new, '1', 'the create form says so');
    assert.equal(core.params({}).length, 'medium');
});

test('write resolves the html and cost, and rejects an answer without html', async () => {
    const calls = [];
    const post = (url, params) => { calls.push({ url, params }); return Promise.resolve({ html: '<p>x</p>', cost: '< $0.01' }); };
    assert.deepEqual(await core.write(post, '/folio/_ai/draft', { brief: 'b' }), { html: '<p>x</p>', cost: '< $0.01' });
    assert.deepEqual(calls, [{ url: '/folio/_ai/draft', params: { brief: 'b' } }]);
    assert.deepEqual(await core.write(() => Promise.resolve({ html: '<p>x</p>', cost: null }), '/u', {}), { html: '<p>x</p>', cost: null });
    await assert.rejects(core.write(() => Promise.resolve({ html: '  ' }), '/u', {}));
    await assert.rejects(core.write(() => Promise.reject(new Error('No credit')), '/u', {}), /No credit/);
});

test('cost line', () => {
    const texts = { known: 'This draft cost :cost.', unknown: 'Unknown cost.' };
    assert.equal(core.costLine('< $0.01', texts), 'This draft cost < $0.01.');
    assert.equal(core.costLine('$1.20', texts), 'This draft cost $1.20.');
    assert.equal(core.costLine(null, texts), 'Unknown cost.');
});

test('safe links only', () => {
    assert.equal(core.safeHref('https://example.com/a'), 'https://example.com/a');
    assert.equal(core.safeHref('/about'), '/about');
    assert.equal(core.safeHref('#top'), '#top');
    assert.equal(core.safeHref('mailto:a@b.test'), 'mailto:a@b.test');
    assert.equal(core.safeHref(' javascript:alert(1)'), null);
    assert.equal(core.safeHref('JaVaScRiPt:alert(1)'), null);
    assert.equal(core.safeHref('data:text/html,x'), null);
    assert.equal(core.safeHref('//evil.test'), null);
    assert.equal(core.safeHref(''), null);
});

/** A parsed body as DOMParser would give it, built from [tag, attrs, ...children] / strings. */
function tree(doc, spec) {
    if (typeof spec === 'string') { return doc.createTextNode(spec); }
    const [tag, attrs, ...children] = spec;
    const el = doc.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
    children.forEach((c) => el.appendChild(tree(doc, c)));
    return el;
}

/** Element structure as [tag, attrs, children] for comparing. */
function shape(el) {
    return [el.tagName.toLowerCase(), { ...el.attributes }, el.childNodes.map((n) => (n.nodeType === 3 ? n.data : shape(n)))];
}

test('copySafe keeps the allowed elements and text, nothing that runs', () => {
    const w = createWindow();
    const doc = w.document;
    const body = tree(doc, ['body', {},
        ['p', { onclick: 'steal()', class: 'x' }, ['strong', {}, 'Hi']],
        ['script', {}, ['span', {}, 'alert(1)']],
        ['iframe', { src: 'https://evil.test' }],
        ['a', { href: 'javascript:alert(1)' }, ['em', {}, 'bad']],
        ['a', { href: 'https://example.com/', onmouseover: 'x()' }, ['em', {}, 'good']],
        ['div', { style: 'color:red' }, ['h2', {}, ['span', {}, 'T']]],
    ]);
    const target = doc.createElement('div');

    core.copySafe(doc, body, target);

    assert.deepEqual(target.children.map(shape), [
        ['p', {}, [['strong', {}, ['Hi']]]],
        ['em', {}, ['bad']],
        ['a', { href: 'https://example.com/', rel: 'noopener', target: '_blank' }, [['em', {}, ['good']]]],
        ['h2', {}, ['T']],
    ]);
    const tags = target.descendants().map((e) => e.tagName);
    assert.deepEqual(tags, ['P', 'STRONG', 'EM', 'A', 'EM', 'H2']);
    assert.equal(target.textContent, 'HibadgoodT', 'the script\'s text is dropped with it; an unknown element leaves its text');
    target.descendants().forEach((e) => {
        assert.deepEqual(Object.keys(e.attributes).filter((k) => !['href', 'rel', 'target'].includes(k)), [], e.tagName + ' keeps no other attribute');
    });
    assert.equal(target.descendants().find((e) => e.tagName === 'A').getAttribute('href'), 'https://example.com/');
});

test('copySafe keeps only the elements the editor keeps, when given', () => {
    const w = createWindow();
    const doc = w.document;
    const body = tree(doc, ['body', {}, ['h1', {}, 'Big'], ['h2', {}, 'Small'], ['p', {}, ['em', {}, 'x']]]);
    const target = doc.createElement('div');

    core.copySafe(doc, body, target, ['p', 'h1', 'strong']);

    assert.deepEqual(target.children.map(shape), [['h1', {}, ['Big']], ['p', {}, ['x']]]);
    assert.match(target.textContent, /Small/, 'an element the editor lacks leaves its text');
});

test('the error a person sees: the server\'s own text, else one by what happened', () => {
    const texts = { offline: 'Offline.', forbidden: 'Not allowed.', generic: 'Something went wrong.' };
    assert.equal(core.errorText({ status: 503, error: 'AI can’t be used right now.' }, texts), 'AI can’t be used right now.');
    assert.equal(core.errorText({ status: 403, error: '' }, texts), 'Not allowed.', 'the CSRF refusal is a page, not JSON');
    assert.equal(core.errorText({ status: 500 }, texts), 'Something went wrong.');
    assert.equal(core.errorText({ status: 0 }, texts), 'Offline.', 'no answer at all');
});

/**
 * The edit form as the page has it: a rich-text field (label, hidden input, toolbar, editor), the
 * draft dialog (partials/ai-draft-dialog.twig), fetch answering from answers (in order; an Error rejects).
 */
function page({ answers = [{ status: 200, data: { html: '<p>Draft</p>', cost: '< $0.01' } }], readOnly = false } = {}) {
    const w = createWindow();
    const doc = w.document;
    const el = (parent, tag, attrs = {}, cls = '', text = '') => {
        const e = parent.appendChild(doc.createElement(tag));
        Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        if (cls) { e.className = cls; }
        if (text) { e.textContent = text; }
        return e;
    };
    const form = el(doc.body, 'form', { id: 'folio-record' });
    const token = el(form, 'input', { type: 'hidden', name: '_csrf_token' });
    token.value = 'tok';
    const fields = el(form, 'fieldset', readOnly ? { disabled: '' } : {}, 'folio-fields');

    /** A rich-text field in parent, as RichTextFieldType renders it once Trix has set it up. */
    const field = (parent, name, label) => {
        const group = el(parent, 'div', {}, 'form-group');
        el(group, 'label', { for: 'folio-rt-' + name }, '', label + ' *');
        el(group, 'input', { type: 'hidden', id: 'folio-rt-' + name, name });
        const toolbar = el(group, 'trix-toolbar', { id: 'trix-toolbar-' + name });
        const row = el(toolbar, 'div', {}, 'trix-button-row');
        el(row, 'span', {}, 'trix-button-group trix-button-group--text-tools');
        const editorEl = el(group, 'trix-editor', { input: 'folio-rt-' + name, toolbar: 'trix-toolbar-' + name }, 'folio-richtext');
        editorEl.toolbarElement = toolbar;
        editorEl.editor = trixEditor();
        return { editorEl, row };
    };
    const body = field(fields, 'body', 'Body');

    const root = el(doc.body, 'div', {
        'data-ai-draft': '', 'data-url': '/folio/_ai/draft', 'data-type': 'article', 'data-id': 'a1', 'data-new': '0', 'data-language': 'de', 'data-title': 'Hallo',
        'data-elements': 'p,h1,strong,em,a,ul,li,br', 'data-msg-offline': 'Offline.', 'data-msg-forbidden': 'Not allowed.', 'data-msg-generic': 'Something went wrong.',
        'data-icon': '/folio/_assets/icons.svg#ui-spark', 'data-msg-button': 'Write a draft', 'data-msg-for-field': 'For :field',
        'data-msg-writing': 'Writing…', 'data-msg-cost': 'This draft cost :cost.', 'data-msg-cost-unknown': 'Unknown cost.',
        'data-msg-error': 'The draft could not be written: :message', 'data-msg-inserted': 'Draft inserted.', 'data-msg-replaced': 'Text replaced.',
    }, 'folio-ai-draft');
    const dialog = el(root, 'dialog', { 'data-ai-draft-dialog': '' }, 'folio-ai-draft-dialog');
    const ui = {
        field: el(dialog, 'p', { 'data-ai-draft-field': '' }),
        brief: el(dialog, 'textarea', { 'data-ai-draft-brief': '' }),
        radios: ['short', 'medium', 'long'].map((v) => { const r = el(dialog, 'input', { type: 'radio', name: 'folio_ai_draft_length' }); r.value = v; r.checked = v === 'medium'; return r; }),
        result: el(dialog, 'section', { 'data-ai-draft-result': '' }),
        status: el(dialog, 'p', { 'data-ai-draft-status': '' }),
        error: el(dialog, 'p', { 'data-ai-draft-error': '' }),
        cancel: el(dialog, 'button', { 'data-ai-draft-cancel': '' }, '', 'Cancel'),
        again: el(dialog, 'button', { 'data-ai-draft-again': '' }, '', 'Try again'),
        replace: el(dialog, 'button', { 'data-ai-draft-replace': '' }, '', 'Replace text'),
        insert: el(dialog, 'button', { 'data-ai-draft-insert': '' }, '', 'Insert at cursor'),
        write: el(dialog, 'button', { 'data-ai-draft-write': '' }, '', 'Write'),
    };
    ui.preview = el(ui.result, 'div', { 'data-ai-draft-preview': '' });
    ui.cost = el(ui.result, 'p', { 'data-ai-draft-cost': '' });
    ui.result.hidden = true;
    ui.announce = el(root, 'p', { 'data-ai-draft-announce': '' });

    const sent = [];
    const queue = answers.slice();
    w.FormData = class { constructor() { this.entries = {}; } append(k, v) { this.entries[k] = String(v); } };
    w.fetch = (url, init) => {
        sent.push({ url, body: init.body.entries });
        const next = queue.length > 1 ? queue.shift() : queue[0];
        if (next instanceof Error) { return Promise.reject(next); }
        return Promise.resolve({ ok: next.status < 400, status: next.status, statusText: '', json: () => (next.data === null ? Promise.reject(new SyntaxError('not JSON')) : Promise.resolve(next.data)) });
    };
    // The inert parse of the answer: a body with one <p> holding the text between the tags.
    w.DOMParser = class {
        parseFromString(html) {
            const b = doc.createElement('body');
            const p = b.appendChild(doc.createElement('p'));
            p.appendChild(doc.createTextNode(html.replace(/<[^>]*>/g, '')));
            return { body: b };
        }
    };
    load(w, CORE_JS);
    load(w, DRAFT_JS);
    return { w, doc, form, fields, field, body, root, dialog, ui, sent };
}

/** Arrays made in the page's realm, as plain ones (deepStrictEqual compares prototypes). */
const plain = (v) => JSON.parse(JSON.stringify(v));

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function buttonOf(row) {
    return row.querySelector('[data-ai-draft-open]');
}

test('the button sits at the right of the toolbar, once', () => {
    const { body, w } = page();
    const groups = body.row.children;
    assert.equal(groups.length, 2);
    assert.equal(groups[1].className, 'trix-button-group folio-ai-draft-group', 'after the toolbar\'s own groups');
    const button = buttonOf(body.row);
    assert.equal(button.getAttribute('type'), 'button');
    assert.equal(button.getAttribute('aria-haspopup'), 'dialog');
    assert.equal(button.textContent, 'Write a draft');

    body.editorEl.dispatchEvent(new w.Event('trix-initialize', { bubbles: true }));
    assert.equal(body.row.querySelectorAll('[data-ai-draft-open]').length, 1, 'a second trix-initialize adds none');
});

test('an editor initialised later (a block card) gets the button too', () => {
    const { field, fields, w } = page();
    const card = fields.appendChild(w.document.createElement('div'));
    const later = field(card, 'sections[aaaa][body]', 'Text');
    assert.equal(buttonOf(later.row), null, 'not before Trix set it up');

    later.editorEl.dispatchEvent(new w.Event('trix-initialize', { bubbles: true }));

    assert.notEqual(buttonOf(later.row), null);
});

test('no button on a read-only form', () => {
    const { body } = page({ readOnly: true });
    assert.equal(buttonOf(body.row), null);
});

test('write, then insert at the caret the field had', async () => {
    const { body, ui, dialog, sent, w } = page();
    body.editorEl.editor.range = [4, 4];
    buttonOf(body.row).click();
    assert.equal(dialog.open, true);
    assert.equal(ui.field.textContent, 'For Body');
    assert.equal(ui.write.disabled, true, 'nothing to write yet');
    body.editorEl.editor.range = [0, 0]; // the focus left the editor: the range stored at open counts

    ui.brief.value = '  Our opening hours ';
    ui.brief.dispatchEvent(new w.Event('input', { bubbles: true }));
    assert.equal(ui.write.disabled, false);
    ui.radios[0].checked = true; ui.radios[1].checked = false;
    ui.write.click();
    assert.equal(ui.write.textContent, 'Writing…');
    await settle();

    assert.deepEqual(sent, [{ url: '/folio/_ai/draft', body: {
        type: 'article', id: 'a1', new: '0', field: 'Body', brief: 'Our opening hours', length: 'short', language: 'de', title: 'Hallo', _csrf_token: 'tok',
    } }]);
    assert.equal(ui.result.hidden, false);
    assert.equal(ui.preview.textContent, 'Draft');
    assert.equal(ui.cost.textContent, 'This draft cost < $0.01.');
    assert.equal(ui.write.hidden, true);
    assert.equal(ui.insert.hidden, false);
    assert.equal(ui.write.textContent, 'Write');
    assert.deepEqual(plain(body.editorEl.editor.calls), [], 'nothing in the field before Insert');

    ui.insert.click();

    assert.deepEqual(plain(body.editorEl.editor.calls), [['recordUndoEntry', 'Write a draft'], ['setSelectedRange', [4, 4]], ['insertLineBreak'], ['insertHTML', '<p>Draft</p>']]);
    assert.equal(dialog.open, false);
    assert.equal(w.document.activeElement, body.editorEl, 'back in the field');
});

test('replace text', async () => {
    const { body, ui, w } = page();
    buttonOf(body.row).click();
    ui.brief.value = 'x';
    ui.brief.dispatchEvent(new w.Event('input', { bubbles: true }));
    ui.write.click();
    await settle();

    ui.replace.click();

    assert.deepEqual(plain(body.editorEl.editor.calls.slice(1)), [['setSelectedRange', [0, 12]], ['insertHTML', '<p>Draft</p>']]);
});

test('try again writes anew with the brief as it is now', async () => {
    const { body, ui, sent, w } = page({ answers: [{ status: 200, data: { html: '<p>One</p>', cost: '$0.01' } }, { status: 200, data: { html: '<p>Two</p>', cost: null } }] });
    buttonOf(body.row).click();
    ui.brief.value = 'first';
    ui.brief.dispatchEvent(new w.Event('input', { bubbles: true }));
    ui.write.click();
    await settle();
    assert.equal(ui.preview.textContent, 'One');

    ui.brief.value = 'second';
    ui.again.click();
    await settle();

    assert.equal(sent.length, 2);
    assert.equal(sent[1].body.brief, 'second');
    assert.equal(ui.preview.textContent, 'Two', 'the new draft only');
    assert.equal(ui.cost.textContent, 'Unknown cost.');
    ui.insert.click();
    assert.deepEqual(plain(body.editorEl.editor.calls.at(-1)), ['insertHTML', '<p>Two</p>']);
});

test('a failure leaves the field unchanged and says why', async () => {
    for (const [answer, shown] of [
        [{ status: 503, data: { error: 'AI can’t be used right now.' } }, 'AI can’t be used right now.'],
        [{ status: 403, data: null }, 'Not allowed.'],
        [new Error('Failed to fetch'), 'Offline.'],
    ]) {
        const { body, ui, dialog, w } = page({ answers: [answer] });
        buttonOf(body.row).click();
        ui.brief.value = 'x';
        ui.brief.dispatchEvent(new w.Event('input', { bubbles: true }));
        ui.write.click();
        await settle();

        assert.equal(ui.error.hidden, false);
        assert.equal(ui.error.textContent, 'The draft could not be written: ' + shown);
        assert.equal(ui.insert.hidden, true, 'nothing to insert');
        assert.equal(ui.write.disabled, false, 'may try again');
        assert.equal(dialog.open, true);
        assert.deepEqual(plain(body.editorEl.editor.calls), []);
    }
});

test('cancel and escape leave the field unchanged; a late answer is ignored', async () => {
    const { body, ui, dialog, w } = page();
    buttonOf(body.row).click();
    ui.brief.value = 'x';
    ui.brief.dispatchEvent(new w.Event('input', { bubbles: true }));
    ui.write.click();
    ui.cancel.click(); // before the answer came
    await settle();
    assert.equal(dialog.open, false);
    assert.equal(ui.result.hidden, true);

    buttonOf(body.row).click();
    assert.equal(ui.brief.value, 'x', 'the brief is kept for the field');
    dialog.escape();
    await settle();
    assert.equal(dialog.open, false);
    assert.equal(w.document.activeElement, buttonOf(body.row), 'focus back on the button');
    assert.deepEqual(plain(body.editorEl.editor.calls), []);
});
