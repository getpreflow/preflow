/*
 * Folio's Edit image screen: Cropper.js on the turned and mirrored original. The image
 * itself is never changed here: each form posts one op to the server, which calls the
 * media core. This only draws the area and fills the forms' x, y, w, h (percent of the
 * image, from Cropper's getData() over the image's natural size).
 *
 * Modes: the image crop (ratio from the select), or one cover format's cut ("Adjust":
 * the box ratio, starting at the region the format shows now; "Save cut" posts it).
 * No inline styles: Cropper sizes its boxes through element.style (CSSOM).
 */
(function () {
    'use strict';

    function percent(el, prefix) {
        var keys = ['x', 'y', 'w', 'h'];
        var out = {};
        for (var i = 0; i < keys.length; i++) {
            var v = parseFloat(el.getAttribute('data-' + prefix + '-' + keys[i]));
            if (isNaN(v)) { return null; }
            out[keys[i]] = v;
        }
        return out;
    }

    function ratioOf(value) {
        var r = parseFloat(value);
        return r > 0 ? r : NaN; // "Free"
    }

    function boot(root) {
        var image = root.querySelector('[data-editor-image]');
        if (!image || typeof window.Cropper !== 'function') { return; }

        var select = root.querySelector('[data-editor-ratio]');
        var apply = root.querySelector('[data-editor-apply]');
        var imageCrop = percent(image, 'crop');
        var ready = false;
        var cropper;

        function natural() {
            var d = cropper.getImageData();
            return { w: d.naturalWidth || 1, h: d.naturalHeight || 1 };
        }

        // A percent area (or the whole image) as Cropper's pixel data.
        function show(area) {
            var n = natural();
            var a = area || { x: 0, y: 0, w: 100, h: 100 };
            cropper.setData({ x: a.x / 100 * n.w, y: a.y / 100 * n.h, width: a.w / 100 * n.w, height: a.h / 100 * n.h });
        }

        function fill(form) {
            if (!ready) { return; }
            var n = natural();
            var d = cropper.getData();
            var values = { x: d.x / n.w * 100, y: d.y / n.h * 100, w: d.width / n.w * 100, h: d.height / n.h * 100 };
            Object.keys(values).forEach(function (k) {
                var input = form.querySelector('input[name="' + k + '"]');
                if (input) { input.value = values[k].toFixed(4); }
            });
        }

        function imageMode() {
            root.querySelectorAll('[data-editor-save]').forEach(function (f) { f.hidden = true; });
            root.querySelectorAll('.folio-image-editor-format.is-active').forEach(function (r) { r.classList.remove('is-active'); });
            if (apply) { apply.disabled = false; }
            if (select) { select.disabled = false; }
            if (!ready) { return; }
            cropper.setAspectRatio(ratioOf(select ? select.value : ''));
            show(imageCrop);
        }

        function formatMode(button) {
            if (!ready) { return; }
            imageMode();
            var row = button.closest('.folio-image-editor-format');
            var save = row ? row.querySelector('[data-editor-save]') : null;
            if (row) { row.classList.add('is-active'); }
            if (save) { save.hidden = false; }
            if (apply) { apply.disabled = true; }
            if (select) { select.disabled = true; }
            cropper.setAspectRatio(ratioOf(button.getAttribute('data-ratio')));
            show(percent(button, 'region'));
        }

        cropper = new window.Cropper(image, {
            viewMode: 1,
            dragMode: 'crop',
            aspectRatio: NaN,
            autoCropArea: 1,
            rotatable: false,
            scalable: false,
            zoomable: false,
            checkOrientation: false, // the server already turned the image
            checkCrossOrigin: false,
            background: true,
            ready: function () {
                ready = true;
                show(imageCrop);
            }
        });

        if (select) {
            select.addEventListener('change', function () { imageMode(); });
        }
        root.addEventListener('click', function (e) {
            var adjust = e.target.closest ? e.target.closest('[data-editor-adjust]') : null;
            if (adjust) { formatMode(adjust); return; }
            var cancel = e.target.closest ? e.target.closest('[data-editor-cancel]') : null;
            if (cancel) { imageMode(); }
        });
        root.addEventListener('submit', function (e) {
            var form = e.target;
            if (form && form.hasAttribute && form.hasAttribute('data-editor-rect')) { fill(form); }
        });
    }

    function start() {
        document.querySelectorAll('[data-folio-image-editor]').forEach(boot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
