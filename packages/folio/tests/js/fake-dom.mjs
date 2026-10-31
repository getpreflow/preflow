// A minimal DOM for node tests of admin.js: elements, attributes, classes, simple selectors
// ("tag#id.class[attr][attr=\"v\"]", descendants with a space, groups with a comma), events,
// and a window that delivers postMessage events. load(file) runs a browser script against it.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

class Element {
    constructor(tag, doc) {
        this.tagName = tag.toUpperCase();
        this.ownerDocument = doc;
        this.children = [];
        this.parentNode = null;
        this.attributes = {};
        this.listeners = {};
        this.className = '';
        this.textContent = '';
        this.hidden = false;
        this.type = '';
        this.name = '';
        this.value = '';
        this.src = '';
        this.alt = '';
    }
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
        child.parentNode = this;
        this.children.push(child);
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
    querySelectorAll(selector) { return this.descendants().filter((el) => el.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; }
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
        createElementNS: (ns, tag) => new Element(tag, document),
        addEventListener: (name, fn) => { (document.listeners[name] = document.listeners[name] || []).push(fn); },
        removeEventListener() {},
        getElementById: (id) => document.documentElement.querySelector('#' + id),
        querySelector: (s) => document.documentElement.querySelector(s),
        querySelectorAll: (s) => document.documentElement.querySelectorAll(s),
    };
    document.documentElement = new Element('html', document);
    document.body = document.documentElement.appendChild(new Element('body', document));
    Object.entries(body).forEach(([name, value]) => document.body.setAttribute(name, value));

    const window = {
        document,
        listeners: {},
        location: { origin, href: origin + '/folio' },
        addEventListener: (name, fn) => { (window.listeners[name] = window.listeners[name] || []).push(fn); },
        removeEventListener() {},
        /** A message event as postMessage from a frame delivers it. */
        receive: (data, fromOrigin = origin) => { (window.listeners.message || []).forEach((fn) => fn({ data, origin: fromOrigin })); },
        Event, CustomEvent, console, setTimeout, clearTimeout, JSON, Math, Date,
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
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
