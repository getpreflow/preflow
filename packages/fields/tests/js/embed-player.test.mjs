// The embed player's script (assets/embed-player.js): a click on a player's button puts the
// template's iframe in its place; before that, the page holds no iframe.
// Run: node --test packages/fields/tests/js/embed-player.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const SCRIPT = readFileSync(fileURLToPath(new URL('../../assets/embed-player.js', import.meta.url)), 'utf8');

/** Just enough of the DOM for the script: elements with attributes, closest(), querySelector() by tag and/or attribute, replaceWith(), a template's content. */
class El {
    constructor(tag, attrs = {}) {
        this.tagName = tag.toUpperCase();
        this.attrs = { ...attrs };
        this.children = [];
        this.parentNode = null;
        this.classes = new Set();
        this.classList = { add: (c) => this.classes.add(c) };
        this.focused = false;
    }
    append(...nodes) { for (const n of nodes) { n.parentNode = this; this.children.push(n); } return this; }
    matches(selector) {
        const m = /^([a-z]*)(?:\[([a-z-]+)\])?$/.exec(selector);
        return m !== null && (m[1] === '' || m[1].toUpperCase() === this.tagName) && (m[2] === undefined || m[2] in this.attrs);
    }
    closest(selector) { let n = this; while (n instanceof El) { if (n.matches(selector)) { return n; } n = n.parentNode; } return null; }
    querySelector(selector) {
        for (const c of this.children) {
            if (c.matches(selector)) { return c; }
            const found = c.querySelector(selector);
            if (found) { return found; }
        }
        return null;
    }
    replaceWith(fragment) {
        const siblings = this.parentNode.children;
        const nodes = fragment.children;
        nodes.forEach((n) => { n.parentNode = this.parentNode; });
        siblings.splice(siblings.indexOf(this), 1, ...nodes);
        this.parentNode = null;
    }
    remove() { const s = this.parentNode.children; s.splice(s.indexOf(this), 1); this.parentNode = null; }
    focus() { this.focused = true; }
}
class Fragment extends El {
    constructor(children) { super('#fragment'); this.append(...children); }
    cloneNode() { return new Fragment(this.children.map((c) => Object.assign(new El(c.tagName.toLowerCase(), c.attrs)))); }
}

function page() {
    const listeners = [];
    const document = { addEventListener: (type, fn) => listeners.push([type, fn]) };
    const iframe = new El('iframe', { src: 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1', title: 'Video' });
    const template = new El('template', { 'data-preflow-embed-frame': '' });
    template.content = new Fragment([iframe]);
    const button = new El('button', { 'data-preflow-embed-play': '' });
    const icon = new El('span', {});
    button.append(icon);
    const box = new El('div', { 'data-preflow-embed': '' }).append(button, template, new El('p', {}));
    const context = vm.createContext({ document, Element: El });
    const click = (target) => {
        let prevented = false;
        for (const [type, fn] of listeners) {
            if (type === 'click') { fn({ target, preventDefault: () => { prevented = true; } }); }
        }
        return prevented;
    };
    return { context, document, listeners, box, button, icon, template, click };
}

test('a click on the button (or inside it) loads the iframe in its place', () => {
    const p = page();
    vm.runInContext(SCRIPT, p.context);
    assert.equal(p.box.querySelector('iframe[src]'), null, 'no iframe before the click');

    assert.equal(p.click(p.icon), true);
    const iframe = p.box.querySelector('iframe[src]');
    assert.ok(iframe, 'the iframe is in the player');
    assert.equal(iframe.attrs.src, 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1');
    assert.equal(p.box.querySelector('button[data-preflow-embed-play]'), null, 'the button is gone');
    assert.equal(p.box.querySelector('template[data-preflow-embed-frame]'), null, 'the template is gone');
    assert.ok(p.box.classes.has('is-playing'));
    assert.ok(iframe.focused, 'the iframe has the focus');
});

test('a click elsewhere does nothing, and the script listens once however often it runs', () => {
    const p = page();
    vm.runInContext(SCRIPT, p.context);
    vm.runInContext(SCRIPT, p.context);
    assert.equal(p.listeners.length, 1);

    assert.equal(p.click(new El('a', {})), false);
    assert.equal(p.click('not an element'), false);
    assert.equal(p.box.querySelector('iframe[src]'), null);
});
