<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'To pole jest wymagane.',
    'email' => 'Musi być prawidłowym adresem e-mail.',
    'url' => 'Musi być prawidłowym adresem URL.',
    'numeric' => 'Musi być liczbą.',
    'integer' => 'Musi być liczbą całkowitą.',
    'min' => [
        'number' => 'Musi wynosić co najmniej :min.',
        'string' => 'Musi mieć co najmniej :min znaków.',
    ],
    'max' => [
        'number' => 'Może wynosić najwyżej :max.',
        'string' => 'Może mieć najwyżej :max znaków.',
    ],
    'between' => 'Musi mieścić się między :min a :max.',
    'step' => 'Musi być wielokrotnością :step.',
    'in' => 'Musi być jedną z wartości: :values.',
    'regex' => 'Format jest nieprawidłowy.',
    'slug' => [
        'segment' => 'Tylko małe litery a-z, cyfry i pojedyncze myślniki między nimi, np. "o-nas".',
        'path' => 'Tylko małe litery a-z, cyfry, pojedyncze myślniki i ukośniki między nimi, np. "docs/o-nas".',
    ],
    'confirmed' => 'Potwierdzenie nie pasuje.',
    'unique' => 'Ta wartość jest już zajęta.',
];
