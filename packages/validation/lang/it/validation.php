<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Questo campo è obbligatorio.',
    'email' => 'Deve essere un indirizzo e-mail valido.',
    'url' => 'Deve essere un URL valido.',
    'numeric' => 'Deve essere un numero.',
    'integer' => 'Deve essere un numero intero.',
    'min' => [
        'number' => 'Deve essere almeno :min.',
        'string' => 'Il numero di caratteri deve essere almeno :min.',
    ],
    'max' => [
        'number' => 'Deve essere al massimo :max.',
        'string' => 'Il numero di caratteri deve essere al massimo :max.',
    ],
    'between' => 'Deve essere compreso tra :min e :max.',
    'step' => 'Deve essere un multiplo di :step.',
    'in' => 'Deve essere uno dei seguenti valori: :values.',
    'regex' => 'Il formato non è valido.',
    'slug' => [
        'segment' => 'Solo lettere minuscole a–z, cifre e singoli trattini tra di esse, ad es. «chi-siamo».',
        'path' => 'Solo lettere minuscole a–z, cifre, singoli trattini e barre tra di esse, ad es. «docs/chi-siamo».',
    ],
    'confirmed' => 'La conferma non corrisponde.',
    'unique' => 'Questo valore è già in uso.',
    'phone' => 'Deve essere un numero di telefono: cifre, eventualmente con + iniziale e spazi, ad es. +41 44 123 45 67.',
    'color' => 'Deve essere un colore in formato esadecimale, ad es. #1a2b3c (oppure #1a2b3c80 con trasparenza).',
    'color_palette' => 'Scegli uno dei colori proposti: :values.',
    'json' => 'Deve essere un JSON valido, ad es. {"nome": "valore"} oppure [1, 2, 3].',
    'phone_region' => 'Non si capisce a quale paese appartenga questo numero: inizia con + e il prefisso del paese, ad es. +41 44 123 45 67.',
    'datetime' => 'Deve essere una data con ora, scritta ad es. 2026-03-29 14:30 nell’editor (salvata in UTC come 2026-03-29T12:30:00Z).',
    'datetime_gap' => ':value non esiste in :zone (gli orologi vanno avanti). Scegli un altro orario.',
    'time' => 'Deve essere un orario con ore e minuti, ad es. 14:30.',
    'daterange' => 'Deve essere un intervallo di date con inizio e fine, ad es. :example.',
    'daterange_order' => 'La fine (:end) è prima dell’inizio (:start). Scegli una fine uguale o successiva a :start.',
    'link' => [
        'kind' => 'Scegli a cosa punta il link: :kinds.',
        'target' => 'Scegli o inserisci a cosa punta il link.',
        'type' => 'Questo link non può puntare a un elemento di tipo :refused; scegline uno tra: :types.',
        'url' => 'Deve essere un indirizzo web che inizia con https:// o http://, oppure un percorso di questo sito che inizia con /.',
    ],
];
