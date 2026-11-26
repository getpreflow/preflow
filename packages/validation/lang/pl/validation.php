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
    'phone' => 'Musi być numerem telefonu: cyfry, opcjonalnie z + na początku i spacjami, np. +41 44 123 45 67.',
    'color' => 'Musi być kolorem w zapisie szesnastkowym, np. #1a2b3c (lub #1a2b3c80 z przezroczystością).',
    'color_palette' => 'Wybierz jeden z proponowanych kolorów: :values.',
    'json' => 'Musi być poprawnym JSON-em, np. {"nazwa": "wartość"} lub [1, 2, 3].',
    'phone_region' => 'Nie wiadomo, z jakiego kraju jest ten numer: zacznij go od + i numeru kierunkowego kraju, np. +41 44 123 45 67.',
    'code_language' => 'Język musi być krótką nazwą, np. php lub javascript (litery, cyfry oraz + # . _ -).',
    'link' => [
        'kind' => 'Wybierz, do czego prowadzi link: :kinds.',
        'target' => 'Wybierz lub wpisz, do czego prowadzi link.',
        'type' => 'Ten link nie może prowadzić do wpisu typu :refused; wybierz jeden z: :types.',
        'url' => 'Musi być adresem internetowym zaczynającym się od https:// lub http:// albo ścieżką tej witryny zaczynającą się od /.',
    ],
];
