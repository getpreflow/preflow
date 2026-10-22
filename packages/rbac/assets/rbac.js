/*
 * The rbac_permissions editor (preflow/rbac): what "Everything" or an "All" already grants is
 * shown checked and locked (disabled, so it is not posted on its own). Unlocking restores
 * the box's own state. Without this script every box stays usable; the server accepts any mix.
 */
(function () {
    'use strict';

    function lock(box, locked) {
        if (locked && !('rbacWas' in box.dataset)) {
            box.dataset.rbacWas = box.checked ? '1' : '0';
            box.checked = true;
        } else if (!locked && 'rbacWas' in box.dataset) {
            box.checked = box.dataset.rbacWas === '1';
            delete box.dataset.rbacWas;
        }
        box.disabled = locked;
    }

    function apply(field) {
        var everything = field.querySelector('[data-rbac-everything]');
        var rest = field.querySelector('[data-rbac-rest]');
        var all = !!(everything && everything.checked);
        if (rest) {
            rest.classList.toggle('is-locked', all);
        }
        // The "All" boxes first, so their own state is back before their members follow them.
        field.querySelectorAll('[data-rbac-all]').forEach(function (box) {
            lock(box, all);
        });
        field.querySelectorAll('[data-rbac-row], [data-rbac-group]').forEach(function (scope) {
            var whole = scope.querySelector('[data-rbac-all]');
            scope.querySelectorAll('input[type="checkbox"]:not([data-rbac-all])').forEach(function (box) {
                lock(box, all || !!(whole && whole.checked));
            });
        });
    }

    function init() {
        document.querySelectorAll('[data-rbac-permissions]').forEach(function (field) {
            field.addEventListener('change', function (event) {
                if (event.target.matches('[data-rbac-everything], [data-rbac-all]')) {
                    apply(field);
                }
            });
            apply(field);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
