<?php

// Messages of preflow/validation's rules (scope public: site forms use them too, once they
// pass a translator). A site changes single keys in its own lang/en/validation.php.
// Without a translator the validator answers with these English texts.
// :min, :max are the rule's numbers; :values the allowed values, comma-separated.
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
    'in' => 'Must be one of: :values.',
    'regex' => 'Format is invalid.',
    'confirmed' => 'Confirmation does not match.',
];
