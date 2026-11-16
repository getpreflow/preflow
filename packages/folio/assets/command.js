/* Folio's ⌘K palette and g-chords. Vanilla, no build step; runs after admin.js (folioT, Folio.shortcuts, Folio.navigate). */
(function () {
    'use strict';

    // What the palette knows before anyone types (partials/_commands.twig, CommandPalette::data()):
    // {search, screens: [{label, url, icon, family}], actions: [...], jumps: {d|a|p: {label, url}}}.
    // Records come from {search}?q= ({groups: [{type, label, family, icon, items: [{id, title, url}]}]})
    // 150 ms after the last key; an answer to an older term, or an aborted request, is dropped.
    // Every text goes in with textContent: titles are data, never markup.
    var DEBOUNCE_MS = 150;
    var MIN_TERM = 2;

    var source = document.getElementById('folio-commands');
    var Folio = window.Folio || {};
    if (!source || !Folio.shortcuts) { return; }
    var data;
    try { data = JSON.parse(source.textContent || '{}'); } catch (e) { return; }
    var screens = (data.screens || []).concat(data.actions || []);
    var jumps = data.jumps || {};

    var t = window.folioT || function () { return ''; };
    var mac = Folio.shortcuts.isMac();
    var keyHint = mac ? '⌘K' : 'Ctrl K';

    var dialog = null;
    var input = null;
    var list = null;
    var status = null;
    var enterMark = null;
    var opener = null;
    var rows = [];        // the option elements, in order
    var active = -1;
    var records = [];     // the last answer's groups
    var failed = false;   // the last request failed: a visible row says so until the next term or answer
    var timer = null;
    var seq = 0;          // the newest request; older answers are dropped
    var pending = null;   // the AbortController of the request in flight
    var uid = 0;

    function sprite(id) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'folio-icon');
        svg.setAttribute('aria-hidden', 'true');
        var any = document.querySelector('use[href*="icons.svg"]');
        if (any) {
            var use = document.createElementNS(ns, 'use');
            use.setAttribute('href', any.getAttribute('href').split('#')[0] + '#' + id);
            svg.appendChild(use);
        }
        return svg;
    }

    // Letters the server's matching table (preflow/data search-fold-table.php) folds that have no
    // decomposition to strip; ß, æ, ı and ŧ stay letters of their own there, so here too.
    var FOLD_LETTERS = { 'ø': 'o', 'ł': 'l', 'đ': 'd', 'ð': 'd', 'ħ': 'h', 'ſ': 's' };

    // Matching as the server's search does it, near enough for screen labels and titles: case and
    // accents ignored ("ubersicht" finds "Übersicht", "elise" finds "Élise").
    function fold(text) {
        return String(text).normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
            .replace(/[øłđðħſ]/g, function (c) { return FOLD_LETTERS[c]; });
    }

    function words(term) {
        return fold(term).split(/\s+/).filter(function (w) { return w !== ''; });
    }

    function matches(label, ws) {
        var l = fold(label);
        return ws.every(function (w) { return l.indexOf(w) !== -1; });
    }

    // The title in pieces: what any word matches in <strong>, the rest in plain spans (all textContent).
    // The title is folded character by character, so a match in the fold marks the characters it came
    // from ("eli" marks "Éli" in "Élise").
    function marked(title, ws) {
        var text = String(title == null ? '' : title);
        var folded = '';
        var from = [];
        var to = [];
        var pos = 0;
        Array.from(text).forEach(function (ch) {
            var f = fold(ch);
            for (var k = 0; k < f.length; k++) { from.push(pos); to.push(pos + ch.length); }
            folded += f;
            pos += ch.length;
        });
        var hit = [];
        ws.forEach(function (w) {
            for (var at = folded.indexOf(w); at !== -1; at = folded.indexOf(w, at + w.length)) {
                for (var i = from[at]; i < to[at + w.length - 1]; i++) { hit[i] = true; }
            }
        });
        var out = document.createElement('span');
        out.className = 'folio-command-title';
        var start = 0;
        for (var j = 1; j <= text.length; j++) {
            if (j === text.length || !!hit[j] !== !!hit[start]) {
                var part = document.createElement(hit[start] ? 'strong' : 'span');
                part.textContent = text.slice(start, j);
                out.appendChild(part);
                start = j;
            }
        }
        return out;
    }

    function build() {
        dialog = document.createElement('dialog');
        dialog.className = 'folio-command-dialog';
        dialog.setAttribute('aria-label', t('command_title'));

        var field = document.createElement('label');
        field.className = 'folio-command-field';
        field.appendChild(sprite('ui-search'));
        input = document.createElement('input');
        input.setAttribute('type', 'text');
        input.className = 'folio-command-input';
        input.setAttribute('aria-label', t('command_title'));
        input.setAttribute('placeholder', t('command_title') + '…');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', 'folio-command-list');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        field.appendChild(input);
        // Esc closes; the chip is a button too, for a touch screen without the key.
        var esc = document.createElement('button');
        esc.type = 'button';
        esc.className = 'folio-command-esc';
        esc.setAttribute('aria-label', 'esc, ' + t('close')); // the name starts with the visible text (WCAG 2.5.3)
        esc.textContent = 'esc';
        esc.addEventListener('click', close);
        field.appendChild(esc);
        dialog.appendChild(field);

        list = document.createElement('div');
        list.className = 'folio-command-list';
        list.id = 'folio-command-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', t('command_title'));
        dialog.appendChild(list);

        status = document.createElement('p');
        status.className = 'folio-command-status folio-sr'; // announced, not shown (the board has no count line)
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        dialog.appendChild(status);

        enterMark = document.createElement('span');
        enterMark.className = 'folio-command-enter';
        enterMark.setAttribute('aria-hidden', 'true');
        enterMark.textContent = '↵';

        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKey);
        list.addEventListener('click', function (e) {
            var row = e.target && e.target.closest ? e.target.closest('[role="option"]') : null;
            if (row) { go(row, !!(e.metaKey || e.ctrlKey)); }
        });
        // A click on the backdrop (the dialog element itself, outside its box) closes it.
        dialog.addEventListener('click', function (e) { if (e.target === dialog) { close(); } });
        dialog.addEventListener('close', function () {
            dialog.removeAttribute('open');
            stop();
            var back = opener;
            opener = null;
            if (back && back !== document.body && typeof back.focus === 'function' && back.isConnected !== false) { back.focus(); }
        });
        document.body.appendChild(dialog);
    }

    function open() {
        if (!dialog) { build(); }
        if (dialog.open) { return true; }
        opener = document.activeElement;
        input.value = '';
        records = [];
        failed = false;
        if (typeof dialog.showModal === 'function') { dialog.showModal(); }
        dialog.setAttribute('open', ''); // showModal() sets it too; without it the dialog shows in place
        render();
        input.focus();
        return true;
    }

    function close() {
        if (!dialog || !dialog.open) { return; }
        stop();
        if (typeof dialog.close === 'function') { dialog.close(); }
        dialog.removeAttribute('open');
    }

    // No request waits or runs any more; an answer still on its way is dropped.
    function stop() {
        if (timer !== null) { clearTimeout(timer); timer = null; }
        seq++;
        if (pending) { pending.abort(); pending = null; }
    }

    function onInput() {
        var term = input.value.trim();
        // A new term: whatever was asked for the old one is dropped, even inside the debounce window.
        stop();
        failed = false;
        if (term.length < MIN_TERM) {
            records = [];
        } else {
            timer = setTimeout(function () { timer = null; fetchRecords(term); }, DEBOUNCE_MS);
        }
        render();
    }

    function fetchRecords(term) {
        if (pending) { pending.abort(); pending = null; }
        var mine = ++seq;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        pending = controller;
        var options = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
        if (controller) { options.signal = controller.signal; }
        window.fetch(data.search + '?q=' + encodeURIComponent(term), options).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        }).then(function (answer) {
            if (mine !== seq) { return; } // a newer term asked since
            pending = null;
            failed = false;
            records = answer && Array.isArray(answer.groups) ? answer.groups : [];
            render();
        }).catch(function (err) {
            if (mine !== seq || (err && err.name === 'AbortError')) { return; }
            pending = null;
            records = [];
            failed = true;
            render();
        });
    }

    function group(label, entries, ws) {
        if (!entries.length) { return; }
        var box = document.createElement('div');
        box.className = 'folio-command-group';
        box.setAttribute('role', 'group');
        var head = document.createElement('span');
        head.className = 'folio-command-group-label';
        head.id = 'folio-command-g' + (++uid);
        head.textContent = label;
        box.setAttribute('aria-labelledby', head.id);
        box.appendChild(head);
        entries.forEach(function (entry) {
            var row = document.createElement('div');
            row.className = 'folio-command-row';
            row.id = 'folio-command-o' + (++uid);
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', 'false');
            row.setAttribute('data-url', String(entry.url || ''));
            var tile = document.createElement('span');
            tile.className = 'folio-type-icon';
            tile.setAttribute('data-family', entry.family || '');
            tile.setAttribute('aria-hidden', 'true');
            tile.appendChild(sprite(/^[a-z0-9-]+$/.test(String(entry.icon || '')) ? entry.icon : 'ui-file'));
            row.appendChild(tile);
            row.appendChild(marked(entry.label, ws));
            box.appendChild(row);
            rows.push(row);
        });
        list.appendChild(box);
    }

    // Screens and actions first, then the records by type (the answer's order: assets last).
    function render() {
        var ws = words(input.value);
        list.innerHTML = '';
        rows = [];
        group(t('command_screens'), screens.filter(function (s) { return matches(s.label, ws); }), ws);
        if (input.value.trim().length >= MIN_TERM) {
            records.forEach(function (g) {
                group(String(g.label || ''), (g.items || []).map(function (item) {
                    return { label: item.title, url: item.url, icon: g.icon, family: g.family };
                }), ws);
            });
        }
        if (failed) {
            // Shown in the list (the count line is for screen readers only) and announced there too.
            var error = document.createElement('p');
            error.className = 'folio-command-error';
            error.setAttribute('role', 'none');
            error.textContent = t('command_failed');
            list.appendChild(error);
        }
        status.textContent = failed ? t('command_failed') : rows.length === 0 ? t('command_none') : rows.length === 1 ? t('command_one') : t('command_many', { count: rows.length });
        input.setAttribute('aria-expanded', rows.length ? 'true' : 'false');
        select(rows.length ? 0 : -1);
    }

    function select(at) {
        if (rows[active]) { rows[active].setAttribute('aria-selected', 'false'); }
        active = at;
        if (enterMark.parentNode) { enterMark.remove(); }
        var row = rows[active];
        if (!row) {
            input.removeAttribute('aria-activedescendant');
            return;
        }
        row.setAttribute('aria-selected', 'true');
        row.appendChild(enterMark);
        input.setAttribute('aria-activedescendant', row.id);
        if (typeof row.scrollIntoView === 'function') { row.scrollIntoView({ block: 'nearest' }); }
    }

    function go(row, newTab) {
        var url = row.getAttribute('data-url');
        if (!url) { return; }
        if (newTab) {
            window.open(url, '_blank', 'noopener');
            return;
        }
        close();
        if (Folio.navigate) { Folio.navigate(url); } else { window.location.href = url; }
    }

    function onKey(e) {
        if (e.isComposing) { return; }
        var mod = !!(e.metaKey || e.ctrlKey);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!rows.length) { return; }
            select((active + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (rows[active]) { go(rows[active], mod); }
        } else if (mod && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault(); // ⌘K again: the browser's own search stays out of it
            if (typeof input.select === 'function') { input.select(); }
        }
    }

    // ⌘K / Ctrl K from anywhere, inside inputs too, while no other dialog is open (the registry's rule).
    Folio.shortcuts.add({ key: 'k', mod: true, typing: true, hint: keyHint, label: t('shortcut_command'), run: open });

    // g d, g a, g p: the dashboard, the assets and the first page type, where the person may go.
    ['d', 'a', 'p'].forEach(function (k) {
        var jump = jumps[k];
        if (!jump || !jump.url) { return; }
        Folio.shortcuts.add({ keys: 'g ' + k, label: t('jump_to', { label: jump.label }), run: function () {
            if (Folio.navigate) { Folio.navigate(jump.url); } else { window.location.href = jump.url; }
        } });
    });

    // The search entries (sidebar, rail): links to the dashboard without script, the palette with it.
    document.querySelectorAll('[data-folio-command]').forEach(function (entry) {
        entry.setAttribute('role', 'button');
        entry.setAttribute('aria-haspopup', 'dialog');
        entry.setAttribute('aria-keyshortcuts', mac ? 'Meta+K' : 'Control+K');
        var chip = entry.querySelector('[data-folio-command-key]');
        if (chip) { chip.textContent = keyHint; }
    });
    document.addEventListener('click', function (e) {
        var entry = e.target && e.target.closest ? e.target.closest('[data-folio-command]') : null;
        if (!entry || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        e.preventDefault();
        open();
    });
    // role="button": Space opens it too (Enter already clicks the link).
    document.addEventListener('keydown', function (e) {
        var entry = e.key === ' ' && e.target && e.target.closest ? e.target.closest('[data-folio-command]') : null;
        if (!entry) { return; }
        e.preventDefault();
        open();
    });

    Folio.command = { open: open, close: close };
})();
