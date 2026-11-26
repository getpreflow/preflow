/* Folio conditional fields (show_when, 0.91): the browser's copy of Preflow\Data\FieldVisibility, so the
   editor shows and hides a field as its source changes without asking the server. Pure, so node can test it
   against the same cases as PHP (packages/data/tests/Fixtures/show-when-cases.json). The browser gets
   window.FolioShowWhenCore; fields.js wires the form. Rules reach the page checked (the registry refuses a
   malformed one at boot), so nothing here validates them.

     {"field": "kind", "=": "video"}   "!=", "in", "not_in" (a list), "empty": true, "filled": true
     {"all": [rule, …]}                {"any": [rule, …]}

   As FieldVisibility reads values: no value (missing, null, '' or an empty list) is empty; "0", 0,
   false and spaces are values. "=" true/false reads on/off (true, 1, '1', 'true', 'on' are on). Another
   value compares as text, exactly, or as whole numbers when the field's type is "number". */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.FolioShowWhenCore = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var OPERATORS = ['=', '!=', 'in', 'not_in', 'empty', 'filled'];

    function isEmpty(value) {
        if (value === undefined || value === null || value === '') { return true; }
        if (Array.isArray(value)) { return value.length === 0; }
        return typeof value === 'object' && Object.keys(value).length === 0;
    }

    function isOn(value) {
        return value === true || value === 1 || value === '1' || value === 'true' || value === 'on';
    }

    /** Condition::wholeNumber(): null for no value, the whole part of a number or numeric text, else 0. */
    function wholeNumber(value) {
        if (value === undefined || value === null || value === '') { return null; }
        if (typeof value === 'number') { return isNaN(value) ? 0 : Math.trunc(value); }
        if (typeof value === 'boolean') { return value ? 1 : 0; }
        if (typeof value === 'string' && /^-?\d+(?:\.\d*)?$/.test(value)) { return parseInt(value.split('.')[0], 10); }
        return 0;
    }

    /** Condition::text(): booleans as '1'/'0', lists and objects as JSON, numbers as PHP prints them. */
    function text(value) {
        if (value === undefined || value === null) { return null; }
        if (typeof value === 'boolean') { return value ? '1' : '0'; }
        if (typeof value === 'object') { return JSON.stringify(value); }
        return String(value);
    }

    function operatorOf(rule) {
        for (var i = 0; i < OPERATORS.length; i++) {
            if (Object.prototype.hasOwnProperty.call(rule, OPERATORS[i])) { return OPERATORS[i]; }
        }
        return null;
    }

    function matches(stored, wanted, number) {
        for (var i = 0; i < wanted.length; i++) {
            var value = wanted[i];
            if (typeof value === 'boolean') {
                if (isOn(stored) === value) { return true; }
                continue;
            }
            if (isEmpty(stored)) { continue; }
            if (number ? wholeNumber(stored) === wholeNumber(value) : text(stored) === text(value)) { return true; }
        }
        return false;
    }

    /**
     * Whether a rule holds on values (field => value; a missing key is an empty field). types: field =>
     * type for the fields the rule names ("number" compares as whole numbers).
     */
    function holds(rule, values, types) {
        values = values || {};
        types = types || {};
        if (!rule || typeof rule !== 'object') { return true; }
        if (Array.isArray(rule.all)) {
            return rule.all.every(function (one) { return holds(one, values, types); });
        }
        if (Array.isArray(rule.any)) {
            return rule.any.some(function (one) { return holds(one, values, types); });
        }
        var operator = operatorOf(rule);
        var stored = Object.prototype.hasOwnProperty.call(values, rule.field) ? values[rule.field] : null;
        var number = types[rule.field] === 'number';
        switch (operator) {
            case 'empty': return isEmpty(stored);
            case 'filled': return !isEmpty(stored);
            case '=': return matches(stored, [rule['=']], number);
            case '!=': return !matches(stored, [rule['!=']], number);
            case 'in': return matches(stored, rule['in'], number);
            case 'not_in': return !matches(stored, rule['not_in'], number);
        }
        return true;
    }

    /** The fields a rule names, each once. */
    function sources(rule) {
        var out = [];
        (function walk(one) {
            if (!one || typeof one !== 'object') { return; }
            var list = Array.isArray(one.all) ? one.all : (Array.isArray(one.any) ? one.any : null);
            if (list) { list.forEach(walk); return; }
            if (typeof one.field === 'string' && out.indexOf(one.field) < 0) { out.push(one.field); }
        }(rule));
        return out;
    }

    /**
     * FieldVisibility::hiddenFields(): the fields (name => {type, show_when}) whose rule does not hold on
     * values, in the order of fields. A field whose rule names a hidden field is hidden too.
     */
    function hiddenFields(fields, values) {
        var types = {};
        Object.keys(fields).forEach(function (name) { types[name] = (fields[name] && fields[name].type) || 'string'; });
        var hidden = {};
        function resolve(name, deciding) {
            if (Object.prototype.hasOwnProperty.call(hidden, name)) { return hidden[name]; }
            var rule = fields[name] && fields[name].show_when;
            if (!rule || deciding[name]) { return false; }
            deciding[name] = true;
            var named = sources(rule);
            for (var i = 0; i < named.length; i++) {
                if (resolve(named[i], deciding)) { hidden[name] = true; return true; }
            }
            hidden[name] = !holds(rule, values, types);
            return hidden[name];
        }
        Object.keys(fields).forEach(function (name) { resolve(name, {}); });
        return Object.keys(fields).filter(function (name) { return hidden[name] === true; });
    }

    return { holds: holds, sources: sources, hiddenFields: hiddenFields, isEmpty: isEmpty };
}));
