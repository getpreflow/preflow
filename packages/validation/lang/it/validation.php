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
];
