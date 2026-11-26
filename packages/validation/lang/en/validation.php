<?php

// Messages of preflow/validation's rules (scope public). Inside a render they follow the render
// context's language, on a public page too (since 0.84.2); outside every render, and for a
// validator built without a translator, these English texts are used. A site changes single keys
// in its own lang/en/validation.php, and adds a language this package does not ship with
// lang/{locale}/validation.php. A language without a text falls back to the site's fallback
// language, then English.
// :min, :max, :step are the rule's numbers; :values the allowed values, comma-separated; :kinds and
// :types (a link's) the allowed link kinds and record types, comma-separated; :refused the refused type; :values (a colour's palette) the colours offered; :value and :zone (a date-time that does not exist in a zone), :example (a date range), :start and :end (a range's ends).
return [
    'required' => 'This field is required.',
    'email' => 'Must be a valid email address.',
    'url' => 'Must be a valid URL.',
    'numeric' => 'Must be a number.',
    'integer' => 'Must be an integer.',
    'min' => [
        'number' => 'Must be at least :min.',
        'string' => 'Must be at least :min characters.',
    ],
    'max' => [
        'number' => 'Must be at most :max.',
        'string' => 'Must be at most :max characters.',
    ],
    'between' => 'Must be between :min and :max.',
    'step' => 'Must be a multiple of :step.',
    'in' => 'Must be one of: :values.',
    'regex' => 'Format is invalid.',
    'slug' => [
        'segment' => 'Only lowercase letters a-z, digits and single hyphens between them, e.g. "about-us".',
        'path' => 'Only lowercase letters a-z, digits, single hyphens and slashes between them, e.g. "docs/about-us".',
    ],
    'confirmed' => 'Confirmation does not match.',
    'unique' => 'This value is already taken.',
    'phone' => 'Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.',
    'color' => 'Must be a colour in hex form, such as #1a2b3c (or #1a2b3c80 with transparency).',
    'color_palette' => 'Choose one of the colours offered: :values.',
    'json' => 'Must be valid JSON, such as {"name": "value"} or [1, 2, 3].',
    'phone_region' => 'Cannot tell which country this number belongs to: start it with + and the country code, e.g. +41 44 123 45 67.',
    'datetime' => 'Must be a date and time, typed as 2026-03-29 14:30 in the editor (stored in UTC as 2026-03-29T12:30:00Z).',
    'datetime_gap' => ':value does not exist in :zone (clocks go forward). Choose another time.',
    'time' => 'Must be a time of day as hours and minutes, such as 14:30.',
    'daterange' => 'Must be a date range with a start and an end, such as :example.',
    'daterange_order' => 'The end (:end) is before the start (:start). Choose an end on or after :start.',
    'link' => [
        'kind' => 'Choose what the link points to: :kinds.',
        'target' => 'Choose or enter what the link points to.',
        'type' => 'This link cannot point to a record of type ":refused"; choose one of: :types.',
        'url' => 'Must be a web address starting with https:// or http://, or a path on this site starting with /.',
    ],
];
