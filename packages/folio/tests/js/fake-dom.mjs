// A minimal DOM for node tests of admin.js: elements, attributes, classes, simple selectors
// ("tag#id.class[attr][attr=\"v\"]", descendants with a space, groups with a comma, a leading
// ":scope >"), insertBefore, events,
// form owners (form="…") and requestSubmit(), a MutationObserver for appended elements, and a
// window that delivers postMessage events.
// load(file) runs a browser script against it.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

/** A text node: what setting textContent leaves inside an element (one per element here). */
class Text {
    constructor(data) { this.nodeType = 3; this.data = String(data); this.parentNode = null; }
    get textContent() { return this.data; }
    remove() {
        if (this.parentNode && this.parentNode._text === this) { this.parentNode._text = null; }
        this.parentNode = null;
    }
}

class Element {
    constructor(tag, doc) {
        this.nodeType = 1;
        this.tagName = tag.toUpperCase();
        this.ownerDocument = doc;
        this.children = [];
        this.parentNode = null;
        this.attributes = {};
        this.listeners = {};
        this.className = '';
        this._text = null;
        this.hidden = false;
        this.type = '';
        this.name = '';
        this.value = '';
        this.src = '';
        this.alt = '';
        // A frame's window: what a message event from that frame carries as its source.
        if (this.tagName === 'IFRAME') { this.contentWindow = { frame: this }; }
        // A <dialog>: showModal() opens it, close() closes it and sends "close".
        if (this.tagName === 'DIALOG') {
            this.open = false;
            this.showModal = () => { this.open = true; };
            this.close = () => {
                if (!this.open) { return; }
                this.open = false;
                this.dispatchEvent(new Event('close'));
            };
        }
    }
    /** As in the DOM: setting it replaces every child with one text node; reading it joins the text of all. */
    get textContent() { return (this._text ? this._text.data : '') + this.children.map((c) => c.textContent).join(''); }
    set textContent(value) {
        this.children.forEach((c) => { c.parentNode = null; });
        this.children = [];
        if (this._text) { this._text.parentNode = null; }
        this._text = null;
        const text = value == null ? '' : String(value);
        if (text !== '') { this._text = new Text(text); this._text.parentNode = this; }
    }
    /** A control's form owner, as in the DOM: the form its form="…" names, else the form around it. */
    get form() {
        if (!['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'FIELDSET', 'OUTPUT'].includes(this.tagName)) { return undefined; }
        const id = this.getAttribute('form');
        if (id !== null) {
            const owner = this.ownerDocument.getElementById(id);
            return owner && owner.tagName === 'FORM' ? owner : null;
        }
        return this.closest('form');
    }
    /** form.requestSubmit(submitter): a cancelable submit event carrying the submitter; each one that goes ahead is kept in form.sent. */
    requestSubmit(submitter = null) {
        if (this.tagName !== 'FORM') { throw new Error('fake-dom: requestSubmit on a ' + this.tagName); }
        const event = new Event('submit', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'submitter', { value: submitter });
        if (this.dispatchEvent(event)) { (this.sent = this.sent || []).push(submitter); }
        return event;
    }
    /** The text node (if any) first, then the element children. */
    get childNodes() { return [...(this._text ? [this._text] : []), ...this.children]; }
    get firstChild() { return this.childNodes[0] || null; }
    get id() { return this.getAttribute('id') || ''; }
    set id(v) { this.setAttribute('id', v); }
    get classList() {
        const el = this;
        const list = () => el.className.split(/\s+/).filter(Boolean);
        return {
            add: (...names) => { el.className = [...new Set([...list(), ...names])].join(' '); },
            remove: (...names) => { el.className = list().filter((n) => !names.includes(n)).join(' '); },
            contains: (name) => list().includes(name),
            toggle: (name, on) => {
                const add = on === undefined ? !list().includes(name) : on;
                if (add) { this.classList.add(name); } else { this.classList.remove(name); }
                return add;
            },
        };
    }
    get isConnected() {
        let node = this;
        while (node.parentNode) { node = node.parentNode; }
        return node === this.ownerDocument.documentElement;
    }
    set innerHTML(html) {
        if (html !== '') { throw new Error('fake-dom: innerHTML only clears'); }
        this.children.forEach((c) => { c.parentNode = null; });
        this.children = [];
    }
    get innerHTML() { return ''; }
    get previousElementSibling() { const s = this.parentNode ? this.parentNode.children : []; return s[s.indexOf(this) - 1] || null; }
    get nextElementSibling() { const s = this.parentNode ? this.parentNode.children : []; return s[s.indexOf(this) + 1] || null; }
    appendChild(child) {
        if (child.parentNode) { child.remove(); }
        if (child instanceof Text) {
            if (this._text) { this._text.data += child.data; } else { this._text = child; child.parentNode = this; }
            return child;
        }
        child.parentNode = this;
        this.children.push(child);
        notifyAdded(this, child);
        return child;
    }
    /** insertBefore(child, ref): child moves to just before ref (to the end without one). */
    insertBefore(child, ref) {
        if (!ref) { return this.appendChild(child); }
        if (child.parentNode) { child.remove(); }
        const at = this.children.indexOf(ref);
        if (at < 0) { throw new Error('fake-dom: insertBefore reference is not a child'); }
        child.parentNode = this;
        this.children.splice(at, 0, child);
        notifyAdded(this, child);
        return child;
    }
    removeChild(child) { child.remove(); return child; }
    remove() {
        if (!this.parentNode) { return; }
        this.parentNode.children = this.parentNode.children.filter((c) => c !== this);
        this.parentNode = null;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) {
        if (name in this.attributes) { return this.attributes[name]; }
        if (['value', 'name', 'type', 'src'].includes(name) && this[name] !== '') { return String(this[name]); }
        return null;
    }
    hasAttribute(name) { return this.getAttribute(name) !== null; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, fn) { (this.listeners[name] = this.listeners[name] || []).push(fn); }
    removeEventListener(name, fn) { this.listeners[name] = (this.listeners[name] || []).filter((f) => f !== fn); }
    dispatchEvent(event) {
        if (!event.target) { Object.defineProperty(event, 'target', { value: this, configurable: true }); }
        const path = [this];
        if (event.bubbles) {
            for (let up = this.parentNode; up; up = up.parentNode) { path.push(up); }
            if (path[path.length - 1] === this.ownerDocument.documentElement) { path.push(this.ownerDocument); }
        }
        path.forEach((node) => { (node.listeners[event.type] || []).slice().forEach((fn) => fn(event)); });
        return !event.defaultPrevented;
    }
    /** Focus as the browser moves it: the document's activeElement, focusout (with relatedTarget) on the old one, focusin on this. */
    focus() {
        const doc = this.ownerDocument;
        const prev = doc.activeElement;
        if (prev === this) { return; }
        doc.activeElement = this;
        if (prev && prev !== doc.body) {
            const out = new Event('focusout', { bubbles: true });
            Object.defineProperty(out, 'relatedTarget', { value: this });
            prev.dispatchEvent(out);
        }
        this.dispatchEvent(new Event('focusin', { bubbles: true }));
    }
    /** A key press: a keydown event with its key, bubbling and cancelable (the default action is the caller's). */
    keydown(key) {
        const event = new Event('keydown', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'key', { value: key });
        this.dispatchEvent(event);
        return event;
    }
    /** A click as the browser sends it: bubbles to the document. */
    click() { this.dispatchEvent(new Event('click', { bubbles: true, cancelable: true })); }
    matches(selector) { return selector.split(',').some((group) => matchesChain(this, group.trim().split(/\s+/))); }
    closest(selector) {
        for (let node = this; node && node instanceof Element; node = node.parentNode) {
            if (node.matches(selector)) { return node; }
        }
        return null;
    }
    descendants() { return this.children.flatMap((c) => [c, ...c.descendants()]); }
    /** Also ":scope > a b": the children matching a (or their descendants matching b), as a group of its own. */
    querySelectorAll(selector) {
        const scoped = selector.split(',').map((g) => g.trim()).filter((g) => g.startsWith(':scope'));
        const plain = selector.split(',').map((g) => g.trim()).filter((g) => !g.startsWith(':scope'));
        const found = new Set(plain.length ? this.descendants().filter((el) => el.matches(plain.join(','))) : []);
        scoped.forEach((group) => {
            const [first, ...rest] = group.replace(/^:scope\s*>\s*/, '').split(/\s+/);
            this.children.filter((c) => c.matches(first)).forEach((c) => {
                if (rest.length === 0) { found.add(c); } else { c.querySelectorAll(rest.join(' ')).forEach((d) => found.add(d)); }
            });
        });
        return this.descendants().filter((el) => found.has(el));
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; }
}

/** MutationObserver childList records for an appended element (delivered in a microtask, as in the DOM). */
function notifyAdded(parent, child) {
    const observers = parent.ownerDocument._observers || [];
    observers.forEach((o) => {
        for (let node = parent; node; node = node.parentNode) {
            if (node === o.target && (node === parent || o.options.subtree)) {
                queueMicrotask(() => o.callback([{ type: 'childList', target: parent, addedNodes: [child] }], o.observer));
                return;
            }
        }
    });
}

function matchesCompound(el, compound) {
    const re = /([a-zA-Z][\w-]*)|#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:([*^$]?=)"([^"]*)")?\]/g;
    let m, seen = 0;
    while ((m = re.exec(compound)) !== null) {
        seen += m[0].length;
        if (m[1] && el.tagName !== m[1].toUpperCase()) { return false; }
        if (m[2] && el.id !== m[2]) { return false; }
        if (m[3] && !el.className.split(/\s+/).includes(m[3])) { return false; }
        if (m[4]) {
            const v = el.getAttribute(m[4]);
            if (v === null) { return false; }
            if (m[5] === '=' && v !== m[6]) { return false; }
            if (m[5] === '*=' && !v.includes(m[6])) { return false; }
            if (m[5] === '^=' && !v.startsWith(m[6])) { return false; }
            if (m[5] === '$=' && !v.endsWith(m[6])) { return false; }
        }
    }
    if (seen !== compound.length) { throw new Error('fake-dom: unsupported selector ' + compound); }
    return true;
}

function matchesChain(el, parts) {
    if (!matchesCompound(el, parts[parts.length - 1])) { return false; }
    if (parts.length === 1) { return true; }
    for (let up = el.parentNode; up && up instanceof Element; up = up.parentNode) {
        if (matchesChain(up, parts.slice(0, -1))) { return true; }
    }
    return false;
}

export function createWindow({ origin = 'https://site.test', body = {} } = {}) {
    const document = {
        readyState: 'complete',
        listeners: {},
        createElement: (tag) => new Element(tag, document),
        createTextNode: (data) => new Text(data),
        createElementNS: (ns, tag) => new Element(tag, document),
        addEventListener: (name, fn) => { (document.listeners[name] = document.listeners[name] || []).push(fn); },
        removeEventListener() {},
        getElementById: (id) => document.documentElement.querySelector('#' + id),
        querySelector: (s) => document.documentElement.querySelector(s),
        querySelectorAll: (s) => document.documentElement.querySelectorAll(s),
    };
    document.documentElement = new Element('html', document);
    document.body = document.documentElement.appendChild(new Element('body', document));
    document.activeElement = document.body;
    Object.entries(body).forEach(([name, value]) => document.body.setAttribute(name, value));

    const window = {
        document,
        listeners: {},
        location: { origin, href: origin + '/folio' },
        addEventListener: (name, fn) => { (window.listeners[name] = window.listeners[name] || []).push(fn); },
        removeEventListener() {},
        /** A message event as postMessage delivers it; by default it comes from the first open iframe (the drawer). */
        receive: (data, fromOrigin = origin, source = document.documentElement.querySelector('iframe')?.contentWindow ?? null) => {
            (window.listeners.message || []).forEach((fn) => fn({ data, origin: fromOrigin, source }));
        },
        Event, CustomEvent, console, JSON, Math, Date,
        /** observe(target, {childList, subtree}): appended elements are reported (removals are not). */
        MutationObserver: class {
            constructor(callback) { this.callback = callback; }
            observe(target, options = {}) { (document._observers = document._observers || []).push({ target, options, callback: this.callback, observer: this }); }
            disconnect() { document._observers = (document._observers || []).filter((o) => o.observer !== this); }
        },
        // The page's timers (resolved at call time, so node:test mock.timers applies) never keep the test process alive.
        setTimeout: (fn, ms, ...args) => { const t = setTimeout(fn, ms, ...args); if (t && t.unref) { t.unref(); } return t; },
        clearTimeout: (t) => clearTimeout(t),
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        // A working session store; tests replace it with one that throws to play a browser that refuses storage.
        sessionStorage: (() => {
            const items = new Map();
            return { getItem: (k) => (items.has(k) ? items.get(k) : null), setItem: (k, v) => { items.set(k, String(v)); }, removeItem: (k) => { items.delete(k); } };
        })(),
    };
    window.window = window;
    window.self = window;
    window.parent = window;
    return window;
}

/** Runs a browser script file in the fake window (its globals are the window's properties). */
export function load(window, file) {
    vm.createContext(window);
    vm.runInContext(readFileSync(file, 'utf8'), window, { filename: file });
    return window;
}
