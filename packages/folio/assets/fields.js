/* Folio field controls (board LibFields, 0.75). Vanilla, no build step; loaded after admin.js where a
 * field needs it (the field types' assets()) and on every editor (before editor-frame.js). Uses what
 * admin.js shares on window.Folio (copyLater, openDrawer, fillAssetField) and its words (folioT).
 *
 * - Folio.relativeTime(value): "In 2 days", "Yesterday", in the admin language (Intl.RelativeTimeFormat);
 *   the date field's hint and the publish-at hint use it.
 * - Date: the hint under the input ("In 2 days · Europe/Zurich") and the clear button.
 * - String: the address copy button (the whole address) and a URL's open button.
 * - Text: the "58 / 160" counter, announced only near the limit.
 * - Number: the stepper's − and + (step, min, max).
 * - Relation: the search (embedded options or the search endpoint), picking, removing, open in the drawer.
 * - Rows with a grip (relation, matrix): the arrow keys and drag move them.
 * - Asset: a file dropped on the field goes to the library's upload route and is chosen.
 *
 * Every change a script makes is announced as a change event on a control of the record form, so the
 * autosave, the preview and the unsaved-changes guard hear it. Search boxes carry data-folio-no-edit.
 */
(function () {
    'use strict';

    var Folio = window.Folio = window.Folio || {};

    function t(key, params) {
        if (typeof window.folioT === 'function') { return window.folioT(key, params); }
        return key;
    }

    // A control a script inserted names the record form at once (admin.js's Folio.ownControls), so its
    // change reaches autosave and the guard even in the side panel.
    function own(node) {
        if (typeof Folio.ownControls === 'function') { Folio.ownControls(node); }
    }

    function changed(el) {
        if (el && el.dispatchEvent) { el.dispatchEvent(new Event('change', { bubbles: true })); }
    }

    function edited(el) {
        if (el && el.dispatchEvent) {
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function closest(el, selector) {
        return el && el.closest ? el.closest(selector) : null;
    }

    function adminLanguage() {
        return (document.documentElement && document.documentElement.getAttribute && document.documentElement.getAttribute('lang')) || undefined;
    }

    // A polite live region for what the scripts do (row moved, uploading).
    var liveRegion = null;
    function announce(text) {
        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.className = 'folio-sr';
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.setAttribute('data-folio-fields-live', '');
            document.body.appendChild(liveRegion);
        }
        liveRegion.textContent = '';
        setTimeout(function () { liveRegion.textContent = text; }, 30);
    }

    // ---- Relative time -------------------------------------------------------------------------
    // A date ("2026-11-14", midnight in the browser's zone, counted in calendar days) or a date and
    // time ("2026-11-14T08:00") as the admin language says how far it is from now ("In 2 days",
    // "Tomorrow", "3 hours ago"). '' for anything else.
    function parseWhen(value) {
        var text = String(value || '').trim();
        var day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
        if (day) { return { date: new Date(+day[1], +day[2] - 1, +day[3]), dateOnly: true }; }
        var at = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(text);
        if (at) { return { date: new Date(+at[1], +at[2] - 1, +at[3], +at[4], +at[5], +(at[6] || 0)), dateOnly: false }; }
        var other = text ? new Date(text) : null;
        return other && !isNaN(other.getTime()) ? { date: other, dateOnly: false } : null;
    }

    // Under a day: hours, minutes, seconds. From a day on, calendar days (Friday 08:00 seen on
    // Wednesday 23:00 is "In 2 days", not "Tomorrow"), then weeks, months and years.
    function relativeParts(when, now) {
        var seconds = Math.round((when.date.getTime() - now.getTime()) / 1000);
        if (when.dateOnly || Math.abs(seconds) >= 86400) {
            var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            var day = new Date(when.date.getFullYear(), when.date.getMonth(), when.date.getDate());
            var days = Math.round((day.getTime() - today.getTime()) / 86400000);
            if (Math.abs(days) >= 365) { return [Math.round(days / 365), 'year']; }
            if (Math.abs(days) >= 30) { return [Math.round(days / 30), 'month']; }
            if (Math.abs(days) >= 7 && days % 7 === 0) { return [days / 7, 'week']; }
            return [days, 'day'];
        }
        var units = [['hour', 3600], ['minute', 60]];
        for (var i = 0; i < units.length; i++) {
            if (Math.abs(seconds) >= units[i][1]) { return [Math.round(seconds / units[i][1]), units[i][0]]; }
        }
        return [seconds, 'second'];
    }

    function capitalize(text, lang) {
        if (!text) { return text; }
        return text.charAt(0).toLocaleUpperCase(lang) + text.slice(1);
    }

    function relativeTime(value, now) {
        var when = parseWhen(value);
        if (!when) { return ''; }
        var parts = relativeParts(when, now || new Date());
        var lang = adminLanguage();
        try {
            return capitalize(new Intl.RelativeTimeFormat(lang, { numeric: 'auto' }).format(parts[0], parts[1]), lang);
        } catch (e) {
            // Without Intl.RelativeTimeFormat: "in 2 day" from the folio.fields.date_in / date_ago words.
            var amount = Math.abs(parts[0]);
            var time = amount + ' ' + parts[1] + (amount === 1 ? '' : 's');
            try { time = new Intl.NumberFormat(lang, { style: 'unit', unit: parts[1], unitDisplay: 'long' }).format(amount); } catch (err) { /* plain */ }
            return capitalize(t(parts[0] < 0 ? 'fields.date_ago' : 'fields.date_in', { time: time }), lang);
        }
    }
    Folio.relativeTime = relativeTime;

    // ---- Date ----------------------------------------------------------------------------------
    function dateHint(box) {
        var input = box.querySelector('input');
        var described = input ? input.getAttribute('aria-describedby') : null;
        return described ? document.getElementById(described) : null;
    }

    function syncDate(box) {
        var input = box.querySelector('input');
        if (!input) { return; }
        var clear = box.querySelector('[data-folio-date-clear]');
        if (clear && box.hasAttribute('data-folio-date')) { clear.hidden = String(input.value || '') === ''; }
        var hint = box.hasAttribute('data-folio-date') ? dateHint(box) : null;
        if (!hint) { return; }
        var zone = hint.getAttribute('data-zone') || '';
        var when = String(input.value || '') !== '' ? relativeTime(input.value) : '';
        hint.textContent = [when, zone].filter(function (part) { return part !== ''; }).join(' · ');
    }

    function onDateClear(e) {
        var clear = closest(e.target, '[data-folio-date-clear]');
        if (!clear) { return; }
        var box = closest(clear, '.folio-date-input');
        var input = box ? box.querySelector('input') : null;
        if (!input) { return; }
        e.preventDefault();
        input.value = '';
        edited(input);
        if (typeof input.focus === 'function') { input.focus(); }
    }

    // The calendar icon in front of a date opens the browser's picker (its own indicator is hidden).
    function onDateIcon(e) {
        var icon = closest(e.target, '.folio-icon');
        var box = icon && icon.parentNode && icon.parentNode.classList && icon.parentNode.classList.contains('folio-date-input') ? icon.parentNode : null;
        var input = box ? box.querySelector('input') : null;
        if (!input || input.disabled || input.readOnly) { return; }
        if (typeof input.focus === 'function') { input.focus(); }
        try { if (typeof input.showPicker === 'function') { input.showPicker(); } } catch (err) { /* not allowed here: typing still works */ }
    }

    // ---- String: copy the address, open a URL ------------------------------------------------------
    function onCopyAddress(e) {
        var button = closest(e.target, '[data-folio-copy-address]');
        if (!button) { return; }
        e.preventDefault();
        var affix = closest(button, '[data-folio-affix]');
        var input = affix ? affix.querySelector('input') : null;
        var value = input ? String(input.value || '').trim() : '';
        if (value === '') { return; }
        var home = button.getAttribute('data-address-home');
        var text = home !== null && value === home
            ? button.getAttribute('data-address-root') || ''
            : (button.getAttribute('data-address-before') || '') + value + (button.getAttribute('data-address-after') || '');
        var copy = typeof Folio.copyLater === 'function' ? Folio.copyLater(Promise.resolve(text)) : Promise.resolve(false);
        copy.then(function (ok) {
            if (!ok) { return; }
            var copied = button.getAttribute('data-text-copied') || t('copied');
            button.classList.add('is-copied');
            announce(copied);
            clearTimeout(button._folioCopied);
            button._folioCopied = setTimeout(function () { button.classList.remove('is-copied'); }, 2000);
        });
    }

    var WEB_ADDRESS = /^https?:\/\/[^\s\/?#]+\S*$/i;

    function syncOpenUrl(input) {
        var affix = closest(input, '[data-folio-affix]');
        var link = affix ? affix.querySelector('[data-folio-open-url]') : null;
        if (!link) { return; }
        var value = String(input.value || '').trim();
        if (WEB_ADDRESS.test(value)) {
            link.setAttribute('href', value);
            link.hidden = false;
        } else {
            link.removeAttribute('href');
            link.hidden = true;
        }
    }

    // ---- Text: the counter -------------------------------------------------------------------------
    var NEAR = 0.9;

    function counterOf(input) {
        var ids = String(input.getAttribute('aria-describedby') || '').split(/\s+/);
        for (var i = 0; i < ids.length; i++) {
            var el = ids[i] ? document.getElementById(ids[i]) : null;
            if (el && el.hasAttribute('data-folio-counter')) { return el; }
        }
        return null;
    }

    function syncCounter(input) {
        var counter = counterOf(input);
        if (!counter) { return; }
        var max = parseInt(counter.getAttribute('data-max') || '0', 10);
        // As the server counts it (mb_strlen, a line break as one character).
        var count = Array.from(String(input.value || '').replace(/\r\n/g, '\n')).length;
        var near = max > 0 && count >= max * NEAR;
        counter.textContent = (counter.getAttribute('data-text') || ':count / :max').split(':count').join(String(count)).split(':max').join(String(max));
        counter.classList.toggle('is-near', near);
        counter.classList.toggle('is-over', count > max);
        if (near) { counter.setAttribute('aria-live', 'polite'); } else { counter.removeAttribute('aria-live'); }
    }

    // ---- Number: the stepper -------------------------------------------------------------------
    function decimals(n) {
        var text = String(n);
        if (/e-/i.test(text)) { return parseInt(text.split(/e-/i)[1], 10) || 0; }
        var dot = text.indexOf('.');
        return dot < 0 ? 0 : text.length - dot - 1;
    }

    function bound(stepper, input, name) {
        var raw = stepper.getAttribute('data-' + name);
        if (raw === null || raw === '') { raw = input.getAttribute(name); }
        var n = raw === null || raw === '' ? NaN : parseFloat(raw);
        return isNaN(n) ? null : n;
    }

    function syncStepper(stepper) {
        var input = stepper.querySelector('input');
        if (!input) { return; }
        var value = parseFloat(input.value);
        var min = bound(stepper, input, 'min');
        var max = bound(stepper, input, 'max');
        stepper.querySelectorAll('[data-folio-step]').forEach(function (button) {
            var up = parseInt(button.getAttribute('data-folio-step'), 10) > 0;
            button.disabled = !isNaN(value) && (up ? max !== null && value >= max : min !== null && value <= min);
        });
    }

    function step(stepper, direction) {
        var input = stepper.querySelector('input');
        if (!input || input.disabled || input.readOnly) { return; }
        var size = parseFloat(input.getAttribute('step'));
        if (isNaN(size) || size <= 0) { size = 1; }
        var min = bound(stepper, input, 'min');
        var max = bound(stepper, input, 'max');
        var value = parseFloat(input.value);
        var next;
        if (isNaN(value)) {
            next = direction > 0 ? (min !== null ? min : 0) : (max !== null ? max : 0);
        } else {
            next = value + direction * size;
        }
        var places = Math.min(Math.max(decimals(size), isNaN(value) ? 0 : decimals(value), min !== null ? decimals(min) : 0, max !== null ? decimals(max) : 0), 15);
        next = Number(next.toFixed(places));
        // Past a bound: the nearest value inside it that is still on the step's grid (multiples of
        // "step" from 0, as the server's step rule counts), else the bound itself.
        var stepped = !isNaN(parseFloat(input.getAttribute('step')));
        if (min !== null && next < min) {
            var up = stepped ? Number((Math.ceil(Number((min / size).toFixed(10))) * size).toFixed(places)) : min;
            next = max === null || up <= max ? up : min;
        }
        if (max !== null && next > max) {
            var down = stepped ? Number((Math.floor(Number((max / size).toFixed(10))) * size).toFixed(places)) : max;
            next = min === null || down >= min ? down : max;
        }
        input.value = String(next);
        edited(input);
        syncStepper(stepper);
    }

    function onStep(e) {
        var button = closest(e.target, '[data-folio-step]');
        var stepper = closest(button, '[data-folio-stepper]');
        if (!button || !stepper || button.disabled) { return; }
        e.preventDefault();
        step(stepper, parseInt(button.getAttribute('data-folio-step'), 10) > 0 ? 1 : -1);
    }

    // ---- Rows with a grip: arrow keys and drag ---------------------------------------------------
    var ROW = '[data-folio-relation-item], [data-matrix-row]';

    function sameRows(row) {
        return Array.prototype.filter.call(row.parentNode ? row.parentNode.children : [], function (el) {
            return el.matches && el.matches(ROW) && !el.hidden;
        });
    }

    function rowLabel(row) {
        var label = row.querySelector('.folio-relation-label, .folio-matrix-name');
        return label ? label.textContent : '';
    }

    function moved(row, handle) {
        var rows = sameRows(row);
        announce(t('at_position', { label: rowLabel(row), position: t('position', { index: rows.indexOf(row) + 1, total: rows.length }) }));
        changed(row.querySelector('input[type="hidden"]') || handle);
    }

    function onSortKeydown(e) {
        var handle = closest(e.target, '[data-folio-sort-handle]');
        if (!handle || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) { return; }
        var row = closest(handle, ROW);
        if (!row) { return; }
        var rows = sameRows(row);
        var at = rows.indexOf(row);
        var other = rows[at + (e.key === 'ArrowUp' ? -1 : 1)];
        e.preventDefault();
        if (!other) { return; }
        row.parentNode.insertBefore(row, e.key === 'ArrowUp' ? other : other.nextElementSibling);
        if (typeof handle.focus === 'function') { handle.focus(); }
        moved(row, handle);
    }

    var dragRow = null;

    function onSortPointerDown(e) {
        var handle = closest(e.target, '[data-folio-sort-handle]');
        var row = closest(handle, ROW);
        if (row) { row.draggable = true; }
    }

    function onSortDragStart(e) {
        var row = closest(e.target, ROW);
        if (!row || !row.draggable) { return; }
        dragRow = row;
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', rowLabel(row)); } catch (err) { /* old browsers */ }
        }
        row.classList.add('is-dragging');
    }

    function onSortDragOver(e) {
        if (!dragRow) { return; }
        var target = closest(e.target, ROW);
        if (!target || target === dragRow || target.parentNode !== dragRow.parentNode) { return; }
        e.preventDefault();
        var r = target.getBoundingClientRect();
        target.parentNode.insertBefore(dragRow, e.clientY < r.top + r.height / 2 ? target : target.nextElementSibling);
    }

    function onSortDragEnd() {
        if (!dragRow) { return; }
        var row = dragRow;
        dragRow = null;
        row.classList.remove('is-dragging');
        row.draggable = false;
        moved(row, row);
    }

    // ---- Relation --------------------------------------------------------------------------------
    var PAGE = 20;

    function fold(text) {
        var s = String(text || '').toLowerCase();
        return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    }

    function relationOf(el) {
        return closest(el, '[data-folio-relation]');
    }

    function relationParts(field) {
        return {
            rows: field.querySelector('[data-folio-relation-selected]'),
            find: field.querySelector('[data-folio-relation-find]'),
            results: field.querySelector('[data-folio-relation-results]'),
            template: field.querySelector('template[data-folio-relation-template]')
        };
    }

    function linkedIds(field) {
        var parts = relationParts(field);
        return Array.prototype.map.call(parts.rows ? parts.rows.querySelectorAll('[data-folio-relation-item] input[type="hidden"]') : [], function (i) { return i.value; });
    }

    function relationOptions(field) {
        if (field._folioOptions !== undefined) { return field._folioOptions; }
        var script = field.querySelector('[data-folio-relation-options]');
        var list = null;
        if (script) {
            try { list = JSON.parse(script.textContent || '[]'); } catch (e) { list = []; }
        }
        field._folioOptions = Array.isArray(list) ? list : null;
        return field._folioOptions;
    }

    function hideResults(field) {
        var parts = relationParts(field);
        if (parts.results) { parts.results.hidden = true; }
    }

    function resultItem(item) {
        var li = document.createElement('li');
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'folio-relation-result';
        button.setAttribute('data-folio-relation-pick', '');
        button.setAttribute('data-id', String(item.id));
        button.setAttribute('data-label', String(item.label || item.id));
        if (item.meta) { button.setAttribute('data-meta', String(item.meta)); }
        var label = document.createElement('span');
        label.className = 'folio-relation-result-label';
        label.textContent = String(item.label || item.id);
        button.appendChild(label);
        if (item.meta) {
            var meta = document.createElement('span');
            meta.className = 'folio-relation-result-meta';
            meta.textContent = String(item.meta);
            button.appendChild(meta);
        }
        li.appendChild(button);
        return li;
    }

    // Shows a page of results; append adds "Show more"'s next page under the ones shown.
    function showResults(field, items, more, append) {
        var parts = relationParts(field);
        if (!parts.results) { return; }
        var list = parts.results;
        var oldMore = list.querySelector('[data-folio-relation-more]');
        if (oldMore && oldMore.parentNode) { oldMore.parentNode.parentNode.removeChild(oldMore.parentNode); }
        if (!append) { list.innerHTML = ''; }
        items.forEach(function (item) { list.appendChild(resultItem(item)); });
        if (!append && items.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'folio-relation-empty';
            empty.textContent = list.getAttribute('data-text-empty') || t('no_matches');
            list.appendChild(empty);
        }
        if (more) {
            var li = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'folio-relation-more';
            button.setAttribute('data-folio-relation-more', '');
            button.textContent = list.getAttribute('data-text-more') || t('more');
            li.appendChild(button);
            list.appendChild(li);
        }
        list.hidden = false;
    }

    var searchSeq = 0;

    function search(field, page) {
        var parts = relationParts(field);
        if (!parts.find) { return; }
        var q = String(parts.find.value || '').trim();
        var linked = linkedIds(field);
        field._folioPage = page;
        var options = relationOptions(field);
        if (options) {
            var needle = fold(q);
            var hits = options.filter(function (o) {
                return linked.indexOf(String(o.id)) < 0 && (needle === '' || fold(o.label).indexOf(needle) >= 0 || fold(o.meta).indexOf(needle) >= 0);
            });
            showResults(field, hits.slice((page - 1) * PAGE, page * PAGE), hits.length > page * PAGE, page > 1);
            return;
        }
        var base = parts.find.getAttribute('data-folio-relation-url');
        if (!base || typeof window.fetch !== 'function') { return; }
        var seq = ++searchSeq;
        field._folioSeq = seq;
        window.fetch(base + (base.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(q) + '&page=' + page, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('relation search failed')); })
            .then(function (data) {
                if (field._folioSeq !== seq) { return; }
                var items = (data && Array.isArray(data.items) ? data.items : []).filter(function (item) { return linked.indexOf(String(item.id)) < 0; });
                showResults(field, items, !!(data && data.more), page > 1);
            })
            .catch(function () {});
    }

    function fillRow(row, field, id, label, meta) {
        var parts = { avatar: row.querySelector('.folio-relation-avatar'), label: row.querySelector('.folio-relation-label'), meta: row.querySelector('.folio-relation-meta'), input: row.querySelector('input[type="hidden"]') };
        if (parts.avatar) { parts.avatar.textContent = initials(label); }
        if (parts.label) { parts.label.textContent = label; }
        if (parts.meta) { parts.meta.textContent = meta || ''; parts.meta.hidden = !meta; }
        if (parts.input) { parts.input.value = id; parts.input.setAttribute('value', id); }
        var pattern = field.getAttribute('data-folio-relation-open');
        var open = row.querySelector('[data-folio-relation-open-item]');
        if (open && pattern) { open.setAttribute('href', pattern.split('{id}').join(encodeURIComponent(id))); }
        [['[data-folio-sort-handle]', 'fields.move_named'], ['[data-folio-relation-open-item]', 'fields.open_named'], ['[data-folio-relation-remove]', 'fields.remove_named']].forEach(function (pair) {
            var el = row.querySelector(pair[0]);
            if (el) {
                var text = t(pair[1], { label: label });
                el.setAttribute('aria-label', text);
                el.setAttribute('title', text);
            }
        });
    }

    function initials(label) {
        var words = String(label || '').trim().split(/[\s\-_.,·&\/]+/).filter(Boolean).slice(0, 2);
        var letters = words.map(function (w) { return Array.from(w)[0].toLocaleUpperCase(); }).join('');
        return letters || '?';
    }

    function newRow(field) {
        var template = relationParts(field).template;
        if (!template) { return null; }
        var source = template.content ? template.content.firstElementChild || template.content.firstChild : template.firstElementChild || template.children[0];
        return source ? source.cloneNode(true) : null;
    }

    function pick(field, button) {
        var parts = relationParts(field);
        var id = button.getAttribute('data-id') || '';
        if (!id || !parts.rows) { return; }
        var row = newRow(field);
        if (!row) { return; }
        fillRow(row, field, id, button.getAttribute('data-label') || id, button.getAttribute('data-meta') || '');
        if (!field.hasAttribute('data-folio-relation-multiple')) {
            Array.prototype.slice.call(parts.rows.children).forEach(function (child) { child.parentNode.removeChild(child); });
        }
        parts.rows.appendChild(row);
        own(row);
        if (parts.find) { parts.find.value = ''; }
        hideResults(field);
        changed(row.querySelector('input[type="hidden"]'));
        if (parts.find && typeof parts.find.focus === 'function') { parts.find.focus(); }
    }

    function removeRow(field, button) {
        var parts = relationParts(field);
        var row = closest(button, '[data-folio-relation-item]');
        if (!row || !parts.rows) { return; }
        var rows = sameRows(row);
        var next = rows[rows.indexOf(row) + 1] || rows[rows.indexOf(row) - 1] || null;
        row.parentNode.removeChild(row);
        var announceOn = field;
        if (!field.hasAttribute('data-folio-relation-multiple') && !parts.rows.querySelector('input')) {
            // An emptied single relation still posts its name, so removing the link saves.
            var holder = document.createElement('li');
            holder.hidden = true;
            var blank = document.createElement('input');
            blank.type = 'hidden';
            blank.name = field.getAttribute('data-folio-relation-name') || '';
            blank.value = '';
            holder.appendChild(blank);
            parts.rows.appendChild(holder);
            own(holder);
            announceOn = blank;
        }
        changed(announceOn);
        var focusTo = next ? next.querySelector('[data-folio-relation-remove]') : parts.find;
        if (focusTo && typeof focusTo.focus === 'function') { focusTo.focus(); }
    }

    function resultButtons(field) {
        var parts = relationParts(field);
        return parts.results && !parts.results.hidden
            ? Array.prototype.slice.call(parts.results.querySelectorAll('[data-folio-relation-pick], [data-folio-relation-more]'))
            : [];
    }

    function onRelationInput(e) {
        var find = closest(e.target, '[data-folio-relation-find]');
        var field = relationOf(find);
        if (!find || !field) { return; }
        clearTimeout(field._folioTimer);
        field._folioTimer = setTimeout(function () { search(field, 1); }, relationOptions(field) ? 0 : 250);
    }

    function onRelationKeydown(e) {
        var field = relationOf(e.target);
        if (!field) { return; }
        var find = closest(e.target, '[data-folio-relation-find]');
        var buttons = resultButtons(field);
        if (find) {
            if (e.key === 'Enter') {
                e.preventDefault(); // never the record form's implicit submit
                var first = buttons.filter(function (b) { return b.hasAttribute('data-folio-relation-pick'); })[0];
                if (first) { pick(field, first); }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (buttons.length) { buttons[0].focus(); } else { search(field, 1); }
            } else if (e.key === 'Escape' && buttons.length) {
                e.preventDefault();
                e.stopPropagation();
                hideResults(field);
            }
            return;
        }
        var current = buttons.indexOf(e.target);
        if (current < 0) { return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var to = buttons[current + (e.key === 'ArrowDown' ? 1 : -1)];
            if (to) { to.focus(); } else if (e.key === 'ArrowUp') { relationParts(field).find.focus(); }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            hideResults(field);
            relationParts(field).find.focus();
        }
    }

    function onRelationClick(e) {
        var target = e.target;
        var field = relationOf(target);
        if (field) {
            var pickButton = closest(target, '[data-folio-relation-pick]');
            if (pickButton) { e.preventDefault(); pick(field, pickButton); return; }
            var more = closest(target, '[data-folio-relation-more]');
            if (more) { e.preventDefault(); search(field, (field._folioPage || 1) + 1); return; }
            var remove = closest(target, '[data-folio-relation-remove]');
            if (remove) { e.preventDefault(); removeRow(field, remove); return; }
            var open = closest(target, '[data-folio-relation-open-item]');
            if (open && typeof Folio.openDrawer === 'function' && !e.metaKey && !e.ctrlKey && !e.shiftKey) {
                e.preventDefault();
                var href = open.getAttribute('href') || '';
                Folio.openDrawer(href + (href.indexOf('?') < 0 ? '?' : '&') + '_drawer=1');
                return;
            }
        }
        // A click elsewhere closes the open results.
        document.querySelectorAll('[data-folio-relation]').forEach(function (f) {
            if (f !== field) { hideResults(f); }
        });
    }

    // ---- Asset: drop a file on the field -----------------------------------------------------------
    function accepts(accept, file) {
        var tokens = String(accept || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
        if (!tokens.length) { return true; }
        var type = String(file.type || '').toLowerCase();
        var name = String(file.name || '').toLowerCase();
        return tokens.some(function (token) {
            if (token.charAt(0) === '.') { return name.slice(-token.length) === token; }
            if (token.slice(-2) === '/*') { return type.indexOf(token.slice(0, -1)) === 0; }
            return type === token;
        });
    }

    function assetError(field, text) {
        var slot = field.querySelector('[data-folio-asset-error]');
        if (!slot) { return; }
        slot.textContent = text;
        slot.hidden = text === '';
        field.classList.toggle('has-drop-error', text !== '');
    }

    function uploadName(field, text) {
        var slot = field.querySelector('[data-folio-upload-name]');
        if (slot) { slot.textContent = text; }
    }

    // Files dropped on an asset field, or chosen in its Upload button, go to the media library through
    // its upload route (POST {prefix}/assets: its permission, the page's CSRF token) asking for JSON:
    // {items: [cards], errors: [...]} (200, or 422 with the files it refused). The cards are chosen
    // (Folio.fillAssetField). Anything else (a 403, an expired session, no network) is a field error.
    function dropOn(field, files) {
        var accept = field.getAttribute('data-accept') || '';
        var multiple = field.getAttribute('data-multiple') === '1';
        var list = Array.prototype.slice.call(files || []);
        if (!multiple) { list = list.slice(0, 1); }
        var refused = list.filter(function (f) { return !accepts(accept, f); });
        var taken = list.filter(function (f) { return accepts(accept, f); });
        assetError(field, refused.map(function (f) { return (field.getAttribute('data-text-refused') || t('fields.drop_refused')).split(':file').join(f.name); }).join(' '));
        if (!taken.length) { return Promise.resolve([]); }

        var body = new FormData();
        taken.forEach(function (f) { body.append('files[]', f, f.name); });
        body.append('picker', '1');
        body.append('multiple', multiple ? '1' : '0');
        body.append('private', field.getAttribute('data-private') === '1' ? '1' : '0');
        if (field.getAttribute('data-locale')) { body.append('locale', field.getAttribute('data-locale')); }
        var token = document.querySelector('input[name="_csrf_token"]');
        if (token) { body.append('_csrf_token', token.value); }
        field.classList.add('is-uploading');
        field.setAttribute('aria-busy', 'true');
        uploadName(field, taken.map(function (f) { return f.name; }).join(', '));
        announce(field.getAttribute('data-text-uploading') || t('fields.uploading'));
        var failed = field.getAttribute('data-text-failed') || t('fields.upload_failed');
        var finish = function (items, errors) {
            field.classList.remove('is-uploading');
            field.removeAttribute('aria-busy');
            uploadName(field, '');
            if (items.length && typeof Folio.fillAssetField === 'function') { Folio.fillAssetField(field, items); }
            if (errors.length) { assetError(field, errors.join(' ')); }
            return items;
        };
        return window.fetch(field.getAttribute('data-upload-url') || '', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                var json = String((res.headers && res.headers.get && res.headers.get('Content-Type')) || '').indexOf('application/json') >= 0;
                if (!json || (res.status !== 200 && res.status !== 422)) { return finish([], [failed]); }
                return res.json().then(function (data) {
                    var items = data && Array.isArray(data.items) ? data.items.filter(function (i) { return i && /^[a-f0-9]{32}$/.test(String(i.id)); }) : [];
                    var errors = data && Array.isArray(data.errors) ? data.errors.map(String) : [];
                    return finish(items, res.status === 422 && !errors.length ? [failed] : errors);
                }, function () { return finish([], [failed]); });
            }, function () { return finish([], [failed]); });
    }

    // Upload chosen in the browser's file dialog: with the upload route, sent at once like a drop (the
    // input is cleared, so the save does not upload it again); without it, the chosen name is said.
    function onAssetFileChange(e) {
        var input = e.target;
        if (!input || !input.classList || !input.classList.contains('folio-asset-file')) { return; }
        var field = closest(input, '[data-folio-asset-field]');
        if (!field) { return; }
        var files = Array.prototype.slice.call(input.files || []);
        if (field.hasAttribute('data-upload-url') && typeof window.fetch === 'function' && typeof window.FormData === 'function' && files.length) {
            dropOn(field, files);
            input.value = '';
            return;
        }
        uploadName(field, files.map(function (f) { return f.name; }).join(', '));
    }

    function hasFiles(e) {
        var types = e.dataTransfer && e.dataTransfer.types;
        return !!types && Array.prototype.indexOf.call(types, 'Files') >= 0;
    }

    function dropField(e) {
        var field = closest(e.target, '[data-folio-asset-field]');
        return field && field.hasAttribute('data-upload-url') ? field : null;
    }

    function onAssetDragOver(e) {
        var field = dropField(e);
        if (!field && hasFiles(e) && closest(e.target, '.folio-form')) {
            e.preventDefault();
            if (e.dataTransfer) { e.dataTransfer.dropEffect = 'none'; }
            return;
        }
        if (!field || !hasFiles(e)) { return; }
        e.preventDefault();
        if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; }
        field.classList.add('is-dragover');
    }

    function onAssetDragLeave(e) {
        var field = dropField(e);
        if (!field) { return; }
        var to = e.relatedTarget;
        if (!to || !field.contains(to)) { field.classList.remove('is-dragover'); }
    }

    function onAssetDrop(e) {
        var field = dropField(e);
        if (!field && hasFiles(e) && closest(e.target, '.folio-form')) {
            e.preventDefault(); // a file dropped beside a field must not open in place of the editor
            return;
        }
        if (!field || !hasFiles(e)) { return; }
        e.preventDefault();
        field.classList.remove('is-dragover');
        dropOn(field, e.dataTransfer.files);
    }
    Folio.dropAssets = dropOn;

    // The focal dot on an asset card's thumbnail: data-focal="x,y" (percent), placed here so the markup
    // needs no style attribute.
    function placeFocal(dot) {
        var at = /^(\d{1,3}),(\d{1,3})$/.exec(String(dot.getAttribute('data-focal') || ''));
        if (!at || !dot.style) { return; }
        dot.style.left = Math.min(100, +at[1]) + '%';
        dot.style.top = Math.min(100, +at[2]) + '%';
        dot.hidden = false;
    }
    Folio.placeFocal = placeFocal;

    // ---- Wiring ----------------------------------------------------------------------------------
    function init(root) {
        if (!root || !root.querySelectorAll) { return; }
        root.querySelectorAll('.folio-asset-focal[data-focal]').forEach(placeFocal);
        root.querySelectorAll('[data-folio-date]').forEach(syncDate);
        root.querySelectorAll('[data-folio-stepper]').forEach(syncStepper);
        root.querySelectorAll('[data-folio-affix] input').forEach(syncOpenUrl);
        root.querySelectorAll('textarea[aria-describedby]').forEach(syncCounter);
    }

    function onInput(e) {
        var target = e.target;
        if (!target || !target.closest) { return; }
        var date = target.closest('[data-folio-date]');
        if (date) { syncDate(date); }
        if (target.closest('[data-folio-affix]')) { syncOpenUrl(target); }
        if (target.tagName === 'TEXTAREA') { syncCounter(target); }
        var stepper = target.closest('[data-folio-stepper]');
        if (stepper) { syncStepper(stepper); }
    }

    function boot() {
        // Script runs: the controls that have a scripted form show it (the drawer page sets no folio-js itself).
        if (document.documentElement && document.documentElement.classList) { document.documentElement.classList.add('folio-js'); }
        init(document);
        document.addEventListener('change', onAssetFileChange);
        document.addEventListener('input', onInput);
        document.addEventListener('change', onInput);
        document.addEventListener('click', onDateClear);
        document.addEventListener('click', onDateIcon);
        document.addEventListener('click', onCopyAddress);
        document.addEventListener('click', onStep);
        document.addEventListener('click', onRelationClick);
        document.addEventListener('input', onRelationInput);
        document.addEventListener('keydown', onRelationKeydown);
        document.addEventListener('keydown', onSortKeydown);
        document.addEventListener('pointerdown', onSortPointerDown);
        document.addEventListener('dragstart', onSortDragStart);
        document.addEventListener('dragover', onSortDragOver);
        document.addEventListener('dragend', onSortDragEnd);
        document.addEventListener('dragover', onAssetDragOver);
        document.addEventListener('dragleave', onAssetDragLeave);
        document.addEventListener('drop', onAssetDrop);
        // Fields htmx adds later (a block card): their hints, counters and steppers.
        document.addEventListener('htmx:load', function (e) { init((e.detail && e.detail.elt) || e.target); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
