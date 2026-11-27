<?php

// Messages of preflow/validation's rules (scope public). Inside a render they follow the render
// context's language, on a public page too (since 0.84.2); outside every render, and for a
// validator built without a translator, these English texts are used. A site changes single keys
// in its own lang/en/validation.php, and adds a language this package does not ship with
// lang/{locale}/validation.php. A language without a text falls back to the site's fallback
// language, then English.
// :min, :max, :step are the rule's numbers; :values the allowed values, comma-separated; :field (a
// select's "options_from") the label of the field its choices come from; :kinds and
// :types (a link's) the allowed link kinds and record types, comma-separated; :refused the refused type; :values (a colour's palette) the colours offered; :value and :zone (a date-time that does not exist in a zone), :example (a date range), :start and :end (a range's ends); :providers the video hosts offered and :host the refused one (an embed's); :count and :max (a table's columns or rows).
// Page layouts' ("layout"): :key the refused layout key and :allowed the keys allowed, comma-separated;
// :layout, :slug and :type a pin's layout, page slug and type; :area an area's name, :min and :max its
// limits, :block the refused block key.
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
    'options_from' => 'No choices yet: add values to :field first.',
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
    'embed' => 'Must be the address of a video on :providers, such as https://www.youtube.com/watch?v=aqz-KE-bpKQ.',
    'embed_provider' => 'Videos from :host cannot be embedded here; paste the address of a video on :providers.',
    'embed_unregistered' => 'Videos from ":provider" cannot be saved here: the site has no video provider of that name. Register it in a service provider, or remove it from the field\'s "providers".',
    'embed_poster' => 'The poster must be an image of the media library; choose one or remove it.',
    'table' => 'Must be a table: an optional header row and rows of text cells.',
    'table_columns' => 'This table has :count columns; at most :max are allowed. Remove a column.',
    'table_rows' => 'This table has :count rows; at most :max are allowed. Remove a row.',
    'location' => 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.',
    'location_lat' => 'The latitude must be a number from -90 to 90 (north is positive), such as 47.3769.',
    'location_lng' => 'The longitude must be a number from -180 to 180 (east is positive), such as 8.5417.',
    'link' => [
        'kind' => 'Choose what the link points to: :kinds.',
        'target' => 'Choose or enter what the link points to.',
        'type' => 'This link cannot point to a record of type ":refused"; choose one of: :types.',
        'url' => 'Must be a web address starting with https:// or http://, or a path on this site starting with /.',
    ],
    'layout' => [
        'choice' => 'The layout ":key" is not one of the layouts this type allows (:allowed). Choose one of them.',
        'pinned' => 'This page is pinned to the layout ":layout" by the entry ":slug" of "layouts.pinned" in the type ":type". Change or remove that entry first, then rename the page.',
        'area_max' => 'The area ":area" takes at most :max block.|The area ":area" takes at most :max blocks.',
        'area_min' => 'The area ":area" takes at least :min block.|The area ":area" takes at least :min blocks.',
        'area_block' => 'The area ":area" does not take ":block" blocks. Move it to another area or remove it.',
        'none' => 'The type ":type" has no layouts; leave out "_layout" and "_areas".',
        'key' => 'Must be the key of a layout, such as "standard", or null for the type\'s default.',
        'areas' => 'Must be an object of area names, each with a list of blocks, such as {"main": [{"_block": "text"}]}.',
        'block' => 'Must be a block: an object whose "_block" is the key of its block type, such as {"_block": "text"}.',
        'unknown_block' => 'No block has the key ":block". Use the key of one of the site\'s blocks: :blocks.',
        'unknown_area' => 'The layout ":layout" has no area ":name". Use one of: :areas.',
        'uid_twice' => 'The uid ":uid" is given to more than one block. Give each block its own "_uid", or leave it out for a new one.',
        'uid_format' => 'The uid ":uid" is not a block uid: a uid is 16 hex digits (0-9, a-f), such as "3f9a1c2b4d5e6f70". Leave "_uid" out to get a fresh one.',
        'set_layout' => 'The layout ":key" is not one of the layouts of the set ":set" (:layouts). Choose one of them.',
        'areas_value' => 'Must be an object with "layout" (the key of one of the set\'s layouts, or null) and "areas" (area names, each with a list of blocks), such as {"layout": "1-1", "areas": {"c1": [{"_block": "text"}]}}.',
    ],
];
