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
        'string' => 'Liczba znaków musi wynosić co najmniej :min.',
    ],
    'max' => [
        'number' => 'Nie może przekraczać :max.',
        'string' => 'Liczba znaków nie może przekraczać :max.',
    ],
    'between' => 'Musi wynosić od :min do :max.',
    'step' => 'Musi być wielokrotnością liczby :step.',
    'in' => 'Musi być jedną z wartości: :values.',
    'regex' => 'Format jest nieprawidłowy.',
    'slug' => [
        'segment' => 'Tylko małe litery a–z, cyfry i pojedyncze łączniki (-) między nimi, np. „o-nas”.',
        'path' => 'Tylko małe litery a–z, cyfry oraz pojedyncze łączniki (-) i ukośniki (/) między nimi, np. „docs/o-nas”.',
    ],
    'confirmed' => 'Potwierdzenie nie jest zgodne.',
    'unique' => 'Ta wartość jest już zajęta.',
];
