<?php

// Meldungen der Regeln von preflow/validation (öffentlich: auch Formulare der Site nutzen sie,
// sobald sie einen Translator übergeben). Eine Site ändert einzelne Schlüssel in ihrer lang/de/validation.php;
// diese Datei wirkt nur, wo ein Translator übergeben wird (Folios Admin tut das; ein Formular der Site muss einen übergeben).
return [
    'required' => 'Dieses Feld ist erforderlich.',
    'email' => 'Muss eine gültige E-Mail-Adresse sein.',
    'url' => 'Muss eine gültige URL sein.',
    'numeric' => 'Muss eine Zahl sein.',
    'integer' => 'Muss eine ganze Zahl sein.',
    'min' => [
        'number' => 'Muss mindestens :min sein.',
        'string' => 'Muss mindestens :min Zeichen lang sein.',
    ],
    'max' => [
        'number' => 'Darf höchstens :max sein.',
        'string' => 'Darf höchstens :max Zeichen lang sein.',
    ],
    'between' => 'Muss zwischen :min und :max liegen.',
    'step' => 'Muss ein Vielfaches von :step sein.',
    'in' => 'Muss eines davon sein: :values.',
    'regex' => 'Das Format ist ungültig.',
    'confirmed' => 'Die Bestätigung stimmt nicht überein.',
    'unique' => 'Dieser Wert ist bereits vergeben.',
];
