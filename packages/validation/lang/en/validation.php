<?php

// Messages of preflow/validation's rules (scope public). Inside a render they follow the render
// context's language, on a public page too (since 0.84.2); outside every render, and for a
// validator built without a translator, these English texts are used. A site changes single keys
// in its own lang/en/validation.php, and adds a language this package does not ship with
// lang/{locale}/validation.php. A language without a text falls back to the site's fallback
// language, then English.
// :min, :max, :step are the rule's numbers; :values the allowed values, comma-separated.
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
];
