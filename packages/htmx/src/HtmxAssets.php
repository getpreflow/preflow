<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;

/**
 * Inlines the vendored htmx build through the AssetCollector (same nonce and
 * dedup as component JS), so pages make no external request for it. Also hands
 * htmx the page nonce, so scripts/styles it inserts from fragment responses
 * are accepted by the page's CSP.
 *
 * Modes (config/htmx.php 'include'): always | on-demand | never.
 *
 * Since 0.81 whether a page needs htmx is kept per render frame (a frame slot), like the assets
 * themselves: a render asks for htmx in its own frame. With 'always', every frame starts out
 * needing it (boot registered it).
 */
final class HtmxAssets
{
    private const MODES = ['always', 'on-demand', 'never'];

    /** Whether htmx is needed outside every render. */
    private HtmxRequirement $own;

    /** @var array<string, string> the htmx build by path, read once per process */
    private static array $scripts = [];

    public function __construct(
        private readonly AssetCollector $assets,
        private readonly string $mode,
        private readonly string $scriptPath = __DIR__ . '/../assets/htmx.min.js',
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid htmx include mode "%s" in config/htmx.php; use one of: %s.',
                $mode,
                implode(', ', self::MODES),
            ));
        }
        $this->own = new HtmxRequirement();
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** The page's CSP nonce, which htmx's fragments announce in HX-Nonce. */
    public function nonce(): string
    {
        return $this->assets->getNonce();
    }

    public function require(): void
    {
        if ($this->mode === 'never') {
            return;
        }
        // Added every time, keyed: the collector keeps one of each per render frame, so every
        // render that needs htmx (verify's second render, a page cache rebuild) gets it.
        $this->requirement()->required = true;

        // The nonce of the render that prints the tag (boot registers it once for 'always').
        $nonce = AssetCollector::NONCE;
        $config = json_encode(
            ['inlineScriptNonce' => $nonce, 'inlineStyleNonce' => $nonce],
            JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // htmx reads this meta on DOM ready, so its position after the script is fine.
        $this->assets->addHeadTag('<meta name="htmx-config" content=\'' . $config . '\'>', 'preflow-htmx-config');
        $this->assets->addJs(self::$scripts[$this->scriptPath] ??= (string) file_get_contents($this->scriptPath), JsPosition::Head, 'preflow-htmx');
        $this->assets->addJs(self::NONCE_GUARD, JsPosition::Head, 'preflow-htmx-nonce-guard');
    }

    /**
     * htmx re-stamps every <script> it swaps in with the page nonce
     * (inlineScriptNonce), which would let an injected script run as trusted.
     * Before a swap, drop every script/style whose nonce is not the one the
     * server announced for that response in the HX-Nonce header. htmx does not
     * re-stamp <style>, so the guard gives the surviving ones (the server's own)
     * the page nonce, or the page's CSP would block the fragment's CSS.
     */
    private const NONCE_GUARD = <<<'JS'
document.addEventListener('htmx:beforeSwap', function (e) {
    var d = e.detail, html = d.serverResponse;
    if (typeof html !== 'string' || !/<(script|style)\b/i.test(html)) { return; }
    var nonce = d.xhr ? d.xhr.getResponseHeader('HX-Nonce') : null;
    var isDocument = /<html[\s>]/i.test(html);
    var root = isDocument ? new DOMParser().parseFromString(html, 'text/html') : document.createElement('template');
    if (!isDocument) { root.innerHTML = html; }
    var scope = isDocument ? root : root.content, changed = false;
    var pageNonce = window.htmx && htmx.config.inlineStyleNonce;
    scope.querySelectorAll('script, style').forEach(function (el) {
        if (!nonce || el.getAttribute('nonce') !== nonce) { el.parentNode.removeChild(el); changed = true; }
        else if (el.tagName === 'STYLE' && pageNonce && pageNonce !== nonce) { el.setAttribute('nonce', pageNonce); changed = true; }
    });
    if (changed) {
        d.serverResponse = isDocument ? '<!DOCTYPE html>' + root.documentElement.outerHTML : root.innerHTML;
    }
});
JS;

    /**
     * htmx, and the busy marker: while a request runs, the element that sent it (the element with
     * hx-get/hx-post) carries DrivesComponentActions::BUSY_CLASS (`preflow-busy`), beside htmx's
     * own `htmx-request` (on that element, or the hx-indicator it names), which stays as it is.
     * Asked for by every element written with DrivesComponentActions::callAttributes(), so a page
     * without such an element is unchanged.
     *
     * The script goes with the render's component JS (JsPosition::Body): at the end of a page,
     * and in a component's answer, so the marker also works when a page's first such element
     * arrives in an answer. It sets itself up once per page.
     *
     * @since 0.86.0
     */
    public function requireBusyMarker(): void
    {
        if ($this->mode === 'never') {
            return;
        }
        $this->require();
        $this->assets->addJs(
            self::BUSY_MARKER . "\npreflowBusyMarker('" . DrivesComponentActions::BUSY_CLASS . "');",
            JsPosition::Body,
            'preflow-htmx-busy',
        );
    }

    /**
     * Adds the busy class on htmx:beforeSend and takes it off on htmx:afterRequest (which htmx
     * fires after a load, an error, an abort and a timeout alike), on the request's element
     * (e.detail.elt). A count per element keeps the class while another request of the same
     * element still runs. A named function, called on its own line (inline scripts are joined
     * and minified), that sets up its listeners once per page.
     */
    private const string BUSY_MARKER = <<<'JS'
function preflowBusyMarker(busy) {
    if (window.preflowBusyMarkerOn) { return; }
    window.preflowBusyMarkerOn = true;
    document.addEventListener('htmx:beforeSend', function (e) {
        var el = e.detail && e.detail.elt;
        if (!el || !el.classList) { return; }
        el.preflowBusy = (el.preflowBusy || 0) + 1;
        el.classList.add(busy);
    });
    document.addEventListener('htmx:afterRequest', function (e) {
        var el = e.detail && e.detail.elt;
        if (!el || !el.classList || !el.preflowBusy) { return; }
        el.preflowBusy = el.preflowBusy - 1;
        if (el.preflowBusy === 0) { el.classList.remove(busy); }
    });
}
JS;

    /**
     * htmx, and the disabler for the action option `disable`, which htmx 2's hx-disabled-elt can't
     * do on its own (its `find` returns the first match only, `this` stands only alone, and the
     * `disabled` attribute it sets does nothing on a <form> or a <div>). The driver marks the
     * element with DISABLE_MARKER:
     *
     * - `submits` (`disable: 'self'`): the element itself when it is a button, and its submit
     *   controls (a form's own control list, so buttons linked with form="…" too: `button` whose
     *   type is submit, i.e. none, empty or submit, and `input` type submit or image; not
     *   type=button or reset). They are disabled as soon as the call is triggered, also while the
     *   request waits for another request of its component, so a second click or Enter finds them
     *   disabled.
     * - `fields #<region id>` (`disable: '<region>'`): the region's input, select, textarea and
     *   button elements, from when the request is sent (a waiting request still reads their values;
     *   a disabled field sends none).
     *
     * Only what the disabler disabled is enabled again: when the request ends (a load, an error,
     * an abort, a timeout; also when the answer replaced the element), or when a waiting request is
     * never sent (cancelled at a confirm or prompt, halted by validation, its target or element
     * gone).
     *
     * Asked for by every call with `disable`, so other pages are unchanged. Like the busy marker it
     * goes with the render's component JS (JsPosition::Body, under the page nonce) and sets itself
     * up once per page. In mode `never` nothing is added (the marker is still written).
     *
     * @since 0.87.0
     */
    public function requireDisabler(): void
    {
        if ($this->mode === 'never') {
            return;
        }
        $this->require();
        $this->assets->addJs(
            self::DISABLER . "\npreflowDisabler('" . self::DISABLE_MARKER . "');",
            JsPosition::Body,
            'preflow-htmx-disable',
        );
    }

    /** The attribute that tells the disabler what a call disables: `submits`, or `fields #<region id>`. */
    public const string DISABLE_MARKER = 'data-preflow-disable';

    /**
     * How it works with htmx 2's events. htmx:confirm fires before the hx-sync step; a microtask
     * afterwards (before any next click) sees whether htmx:beforeSend followed for that confirm. If
     * not and the component (the call's hx-sync element) has a request running, the request waits
     * in the queue: `submits` are disabled now. When the queue sends it, htmx:confirm fires again;
     * if beforeSend follows, the hold passes to the running request, otherwise (cancelled, or a
     * confirm another handler takes over) it is released. A waiting request whose element left the
     * page is dropped by htmx without an event: its hold is released when the component's running
     * request ends. Running requests per component are the XHRs seen at beforeSend that are still
     * open (readyState 1–3).
     *
     * A sent request releases when its XHR is done: on `readystatechange` with readyState 4, which
     * the XHR fires for a load, an error, an abort and a timeout before the matching event, so
     * before htmx's handlers swap and start the next queued request (which reads the fields again);
     * `loadend` is a fallback, once only. Both fire whether or not the request's element is still
     * on the page, so a region's fields, or a button linked to a form that the answer replaced,
     * are enabled again. Holds are counted per control: a control two requests hold is enabled
     * when the last one ends; one the page disabled itself is never taken over.
     */
    private const string DISABLER = <<<'JS'
function preflowDisabler(marker) {
    if (window.preflowDisablerOn) { return; }
    window.preflowDisablerOn = true;
    var running = new WeakMap(), waiting = new WeakMap();
    function isSubmit(c) {
        return (c.tagName === 'BUTTON' && c.type === 'submit') || (c.tagName === 'INPUT' && (c.type === 'submit' || c.type === 'image'));
    }
    function controls(el, how) {
        var list = [];
        if (how.indexOf('fields ') === 0) {
            var region = document.querySelector(how.slice(7));
            return region ? Array.prototype.slice.call(region.querySelectorAll('input, select, textarea, button')) : [];
        }
        if (el.tagName === 'BUTTON' || (el.tagName === 'INPUT' && (el.type === 'submit' || el.type === 'image' || el.type === 'button'))) { list.push(el); }
        var inner = el.tagName === 'FORM' ? Array.prototype.slice.call(el.elements) : Array.prototype.slice.call(el.querySelectorAll('button, input'));
        if (el.tagName === 'FORM') {
            Array.prototype.forEach.call(document.querySelectorAll('input[type=image]'), function (c) { if (c.form === el) { inner.push(c); } });
        }
        inner.forEach(function (c) { if (isSubmit(c) && list.indexOf(c) < 0) { list.push(c); } });
        return list;
    }
    function state(el) {
        if (!el.preflowDisable) { el.preflowDisable = { held: 0, disabled: [], queued: 0, transfer: 0, issue: null }; }
        return el.preflowDisable;
    }
    function hold(el, how) {
        var st = state(el);
        st.held = st.held + 1;
        if (st.held !== 1) { return; }
        st.disabled = [];
        controls(el, how).forEach(function (c) {
            if (c.preflowHolds > 0) { c.preflowHolds = c.preflowHolds + 1; st.disabled.push(c); return; }
            if (c.disabled) { return; }
            c.disabled = true;
            c.preflowHolds = 1;
            st.disabled.push(c);
        });
    }
    function release(el) {
        var st = state(el);
        if (st.held === 0) { return; }
        st.held = st.held - 1;
        if (st.held !== 0) { return; }
        st.disabled.forEach(function (c) {
            c.preflowHolds = c.preflowHolds - 1;
            if (c.preflowHolds === 0) { c.disabled = false; }
        });
        st.disabled = [];
    }
    function sync(el) {
        var spec = el.getAttribute && el.getAttribute('hx-sync');
        if (!spec) { return null; }
        var at = spec.lastIndexOf(':');
        var target = null;
        try { target = document.querySelector(spec.slice(0, at)); } catch (err) { target = null; }
        return target ? { el: target, queues: spec.slice(at + 1).trim().indexOf('queue') === 0 } : null;
    }
    function busy(target) {
        var xhrs = (running.get(target) || []).filter(function (x) { return x.readyState >= 1 && x.readyState <= 3; });
        running.set(target, xhrs);
        return xhrs.length > 0;
    }
    function dropDetached(target) {
        // htmx drops a waiting request whose element left the page, without an event.
        waiting.set(target, (waiting.get(target) || []).filter(function (el) {
            var st = state(el);
            if (st.queued === 0) { return false; }
            if (el.isConnected !== false) { return true; }
            var how = el.getAttribute(marker);
            while (st.queued > 0) {
                st.queued = st.queued - 1;
                if (how === 'submits') { release(el); }
            }
            return false;
        }));
    }
    function marked(e) {
        var el = e.detail && e.detail.elt;
        var how = el && el.getAttribute ? el.getAttribute(marker) : null;
        return how ? { el: el, how: how } : null;
    }
    document.addEventListener('htmx:confirm', function (e) {
        var m = marked(e);
        if (!m || e.target !== m.el) { return; }
        var st = state(m.el), s = sync(m.el), issue = { sent: false };
        var early = m.how === 'submits';
        var dequeued = st.queued > 0 && !(s && busy(s.el));
        st.issue = issue;
        if (dequeued) {
            st.queued = st.queued - 1;
            if (early) { st.transfer = st.transfer + 1; }
        }
        queueMicrotask(function () {
            if (issue.sent) { return; }
            if (dequeued) {
                if (early) { st.transfer = st.transfer - 1; release(m.el); }
                return;
            }
            if (e.defaultPrevented) { return; }
            if (s && s.queues && busy(s.el)) {
                st.queued = st.queued + 1;
                if (early) { hold(m.el, m.how); }
                var list = waiting.get(s.el) || [];
                if (list.indexOf(m.el) < 0) { list.push(m.el); }
                waiting.set(s.el, list);
            }
        });
    });
    document.addEventListener('htmx:targetError', function (e) {
        var m = marked(e);
        if (!m || e.target !== m.el) { return; }
        var st = state(m.el), s = sync(m.el);
        if (st.queued > 0 && !(s && busy(s.el))) {
            st.queued = st.queued - 1;
            if (m.how === 'submits') { release(m.el); }
        }
    });
    document.addEventListener('htmx:beforeSend', function (e) {
        var el = e.detail && e.detail.elt, xhr = e.detail && e.detail.xhr;
        if (!el || !xhr || e.target !== el) { return; }
        var s = sync(el);
        if (s) {
            busy(s.el);
            running.get(s.el).push(xhr);
            xhr.addEventListener('loadend', function () { dropDetached(s.el); });
        }
        var m = marked(e);
        if (!m) { return; }
        var st = state(el);
        if (st.issue) { st.issue.sent = true; st.issue = null; }
        if (st.transfer > 0) { st.transfer = st.transfer - 1; } else { hold(el, m.how); }
        // Released when the XHR is done (readyState 4: before htmx's load/error/abort/timeout handlers,
        // so before htmx starts the next queued request, which reads the fields); loadend as a fallback.
        var done = false;
        var end = function () {
            if (done) { return; }
            done = true;
            release(el);
        };
        xhr.addEventListener('readystatechange', function () { if (xhr.readyState === 4) { end(); } });
        xhr.addEventListener('loadend', end);
    });
}
JS;

    /**
     * htmx, and the body token's scope: a post call sends its token in the body
     * (ComponentEndpoint::TOKEN_FIELD in its hx-vals), and htmx passes an element's hx-vals to
     * every element inside it. Without this, every request from inside a call's element would also
     * send that call's token: a get (`hd.get`, an old-form call, a site's own hx-get, a boosted
     * link) in its URL, which large props would make too long. Before each htmx request
     * (htmx:configRequest) the field is dropped from the request's parameters when it holds the
     * value an ancestor's hx-vals gave it, unless the element that sends it has the field in its
     * own hx-vals (a post call's own token). A site's own `_token` field stays.
     *
     * Asked for by every call that puts its token in the body, so other pages are unchanged. Like
     * the busy marker it goes with the render's component JS (JsPosition::Body, under the page
     * nonce) and sets itself up once per page. In mode `never` nothing is added.
     *
     * @since 0.88.0
     */
    public function requireBodyTokenScope(): void
    {
        if ($this->mode === 'never') {
            return;
        }
        $this->require();
        $this->assets->addJs(
            self::BODY_TOKEN . "\npreflowBodyToken('" . ComponentEndpoint::TOKEN_FIELD . "');",
            JsPosition::Body,
            'preflow-htmx-body-token',
        );
    }

    /**
     * htmx 2 fires htmx:configRequest on the requesting element (detail.elt) with
     * detail.parameters, a proxy over the FormData it sends (after hx-vals are merged and
     * hx-params applied); deleting a key removes the field, and htmx reads the parameters again
     * after the event, for the URL of a get and the body of a post. htmx merges hx-vals (or
     * data-hx-vals) from the element up, the nearest first. The field stays when the element's own
     * hx-vals holds it (a post call's own token). Otherwise it is dropped only when its value is
     * the one the nearest ancestor's hx-vals holds: the inherited token. A site's own `_token`
     * field (a form input, its own value) stays. hx-vals is read as JSON, as the driver writes it;
     * one that isn't (a site's own js: value) holds no token of a call.
     */
    private const string BODY_TOKEN = <<<'JS'
function preflowBodyToken(field) {
    if (window.preflowBodyTokenOn) { return; }
    window.preflowBodyTokenOn = true;
    function held(el) {
        var raw = el.getAttribute ? (el.getAttribute('hx-vals') || el.getAttribute('data-hx-vals')) : null;
        if (!raw) { return null; }
        try {
            var vals = JSON.parse(raw);
            return vals !== null && typeof vals === 'object' && Object.prototype.hasOwnProperty.call(vals, field) ? vals : null;
        } catch (err) {
            return null;
        }
    }
    function holds(el) { return held(el) !== null; }
    document.addEventListener('htmx:configRequest', function (e) {
        var d = e.detail, el = d && d.elt;
        if (!d || !el || !d.parameters || d.parameters[field] === undefined) { return; }
        if (holds(el)) { return; }
        for (var p = el && el.parentElement; p; p = p.parentElement) {
            var from = held(p);
            if (from === null) { continue; }
            if (String(d.parameters[field]) === String(from[field])) { delete d.parameters[field]; }
            return;
        }
    });
}
JS;

    public function isRequired(): bool
    {
        return $this->requirement()->required;
    }

    /** The current render frame's requirement; the own one outside every render. */
    private function requirement(): HtmxRequirement
    {
        if (Contexts::depth() === 0) {
            return $this->own;
        }

        return Contexts::frame()->slot(HtmxRequirement::class, fn (): HtmxRequirement => new HtmxRequirement($this->mode === 'always'));
    }
}
