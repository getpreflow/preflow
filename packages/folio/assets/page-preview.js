/* Folio's whole-page preview in the editor (form.twig's preview stage, 0.81): "Live | Whole page".
 * Live is admin.js's preview of the form as typed (the type's template). Whole page loads the
 * record's real page (PagePreviewController: its route, layout, navigation, SEO, with the saved
 * working copies) into a frame of its own, and loads it again after each autosave, so it follows
 * the draft a few seconds behind the typing, and after a save in the drawer (folio:drawer-saved).
 * "View as" (shown with Whole page, when the editor may view as roles) reloads it as the chosen
 * role. The device switch sizes both frames. The chosen tab is remembered in localStorage (an admin
 * convenience). A record that was never saved has no page: its Whole page tab is disabled and says
 * why (the template). Vanilla, no build step.
 */
(function () {
    'use strict';

    var KEY = 'folio-preview-tab';

    function store(value) {
        try { localStorage.setItem(KEY, value); } catch (e) {}
    }

    function stored() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }

    function init() {
        var stage = document.querySelector('[data-folio-stage][data-page-preview]');
        if (!stage) { return; }
        var base = stage.getAttribute('data-page-preview-url') || '';
        var page = stage.querySelector('.folio-page-frame');
        var live = null;
        stage.querySelectorAll('.folio-preview-frame').forEach(function (frame) { if (frame !== page && !live) { live = frame; } });
        var asField = stage.querySelector('[data-preview-as-field]');
        var as = stage.querySelector('[data-preview-as]');
        var current = 'live';
        var stale = true;
        var keepScroll = null;

        function url() {
            var value = as ? as.value : '';
            return value ? base + (base.indexOf('?') < 0 ? '?' : '&') + 'as=' + encodeURIComponent(value) : base;
        }

        // Loads the page again where the editor was in it (its scroll position), or anew.
        function load() {
            if (!page || !base) { return; }
            stale = false;
            try {
                keepScroll = page.contentWindow && page.contentWindow.location.href !== 'about:blank' ? page.contentWindow.scrollY : null;
            } catch (e) { keepScroll = null; }
            page.setAttribute('src', url());
        }

        if (page) {
            page.addEventListener('load', function () {
                try { if (keepScroll !== null && page.contentWindow) { page.contentWindow.scrollTo(0, keepScroll); } } catch (e) {}
                keepScroll = null;
            });
        }

        function show(tab) {
            if (tab === 'page' && (!page || !base)) { tab = 'live'; }
            current = tab;
            stage.querySelectorAll('[data-preview-tab]').forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-preview-tab') === tab ? 'true' : 'false');
            });
            if (live) { live.hidden = tab === 'page'; }
            if (page) { page.hidden = tab !== 'page'; }
            if (asField) { asField.hidden = tab !== 'page'; }
            if (tab === 'page' && stale) { load(); }
        }

        stage.addEventListener('click', function (e) {
            var tab = e.target.closest ? e.target.closest('[data-preview-tab]') : null;
            if (tab && !tab.disabled) {
                var which = tab.getAttribute('data-preview-tab');
                store(which);
                show(which);
                return;
            }
            // The view switch (a type with "preview"): admin.js renders the chosen view in the live
            // frame, so the pane shows Live (and remembers it) rather than a hidden render.
            var view = e.target.closest ? e.target.closest('[data-preview-view]') : null;
            if (view) {
                if (current === 'page') {
                    store('live');
                    show('live');
                }
                return;
            }
            // The device switch (admin.js sizes the live frame): the page frame gets the same width.
            var device = e.target.closest ? e.target.closest('[data-preview-viewport]') : null;
            if (device && page) {
                var width = device.getAttribute('data-preview-viewport');
                page.style.width = width;
                page.classList.toggle('is-device', width !== '100%');
            }
        });

        if (as) {
            as.addEventListener('change', function () {
                if (current === 'page') { load(); } else { stale = true; }
            });
        }

        // After each autosave of the record form, the page shows the new working copy.
        document.addEventListener('htmx:afterRequest', function (e) {
            var elt = e.detail && e.detail.elt;
            if (!elt || elt.tagName !== 'FORM' || !e.detail.successful || (elt.getAttribute('hx-post') || '').indexOf('/autosave') < 0) { return; }
            if (current === 'page') { load(); } else { stale = true; }
        });

        // A record saved in the drawer (a linked record opened from either preview): the page shows it.
        document.addEventListener('folio:drawer-saved', function () {
            if (current === 'page') { load(); } else { stale = true; }
        });

        show(stored() === 'page' ? 'page' : 'live');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
