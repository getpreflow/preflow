<?php

// Messaggi delle regole di preflow/validation (pubblici). In un rendering seguono la lingua di
// questo, anche su una pagina pubblica (dalla 0.84.2); fuori da ogni rendering, e per un
// validatore creato senza traduttore, valgono i testi inglesi. Un sito modifica singole chiavi nel
// proprio lang/it/validation.php e aggiunge una lingua che il pacchetto non include con
// lang/{locale}/validation.php. Senza testo per una lingua vale la lingua di riserva del sito,
// poi l'inglese.
// :min, :max, :step sono i numeri della regola; :values i valori ammessi, separati da virgola.
return [
    'required' => 'Questo campo è obbligatorio.',
    'email' => 'Deve essere un indirizzo e-mail valido.',
    'url' => 'Deve essere un URL valido.',
    'numeric' => 'Deve essere un numero.',
    'integer' => 'Deve essere un numero intero.',
    'min' => [
        'number' => 'Deve essere almeno :min.',
        'string' => 'Deve contenere almeno :min caratteri.',
    ],
    'max' => [
        'number' => 'Deve essere al massimo :max.',
        'string' => 'Deve contenere al massimo :max caratteri.',
    ],
    'between' => 'Deve essere compreso tra :min e :max.',
    'step' => 'Deve essere un multiplo di :step.',
    'in' => 'Deve essere uno di questi valori: :values.',
    'regex' => 'Il formato non è valido.',
    'slug' => [
        'segment' => 'Solo lettere minuscole a–z, cifre e singoli trattini tra di esse, ad es. «chi-siamo».',
        'path' => 'Solo lettere minuscole a–z, cifre, singoli trattini e barre tra di esse, ad es. «doc/chi-siamo».',
    ],
    'confirmed' => 'La conferma non corrisponde.',
    'unique' => 'Questo valore è già in uso.',
];
